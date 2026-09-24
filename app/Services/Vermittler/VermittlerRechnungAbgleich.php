<?php

namespace App\Services\Vermittler;

use App\Models\Contract;
use App\Models\VermittlerInvoice;
use App\Models\VermittlerMatchEvent;
use App\Models\VermittlerSettlement;
use Illuminate\Support\Facades\DB;

/**
 * Rechnung/Gutschrift des Vermittlers gegen die Abrechnung pruefen
 * (Betreiber-Auftrag 23.09.2026).
 *
 * Ablauf des Betriebs: (1) Vertrag mit Referenz-Nr. erfassen, (2) monatlich
 * die CSV mit Id und Status einlesen, (3) die Rechnung hochladen. Erst
 * Schritt 3 BELEGT die Zahlung - Status 4 der CSV ist nur die Meldung des
 * Vermittlers, dass er zahlen will bzw. gezahlt hat.
 *
 * DIE GRUNDREGEL IST DIESELBE WIE UEBERALL IM ABGLEICH: nie raten.
 *  - Gesucht werden AUSSCHLIESSLICH Kennungen, die wir schon kennen (Id bzw.
 *    Referenz-Nr. aus einer eingelesenen CSV). Eine freie Zahlensuche in
 *    einer Rechnung fischt Betraege, Steuernummern und Kundennummern mit
 *    heraus; die Liste der bekannten Kennungen ist der Anker.
 *  - Bestaetigt ist eine Position nur, wenn auf ihrer Zeile GENAU der
 *    erwartete Betrag steht. Ein anderer Betrag ist eine Abweichung, zwei
 *    moegliche Betraege sind "nicht eindeutig" - beides wird gezeigt, nie
 *    geglaettet.
 *  - Ein im Vertrag stornierter Vorgang, der in der Rechnung auftaucht, ist
 *    ein Widerspruch und geht in die Pruefung.
 *  - Es entsteht nichts Neues: kein Vertrag, keine Abrechnungszeile. Eine
 *    Rechnungsposition ohne bekannte Kennung wird nur als Rest-Betrag
 *    ausgewiesen.
 */
class VermittlerRechnungAbgleich
{
    /** Betrag in deutscher ("1.234,56") oder technischer ("1234.56") Schreibweise. */
    private const AMOUNT_CORE = '(?<![\d.,])(-?\s?\d{1,3}(?:\.\d{3})*,\d{2}|-?\s?\d+,\d{2}|-?\s?\d+\.\d{2})(?![\d.,]*\d)';
    private const AMOUNT = '/'.self::AMOUNT_CORE.'/u';

    /**
     * Rechnung lesen und das Ergebnis als ENTWURF ablegen. Schreibt nichts an
     * Vertraege oder Abrechnungszeilen.
     */
    public function analyze(string $text, string $filename, string $fileHash, ?string $filePath, string $source, ?int $userId): VermittlerInvoice
    {
        $lines = $this->lines($text);
        $positions = $this->positions($lines);

        $counts = ['bestaetigt' => 0, 'abweichung' => 0, 'offen' => 0];
        foreach ($positions as $p) {
            $key = match ($p['outcome']) {
                'bestaetigt' => 'bestaetigt',
                'abweichung', 'storniert' => 'abweichung',
                default => 'offen',
            };
            $counts[$key]++;
        }

        $meta = $this->meta($text);
        $sum = round(array_sum(array_map(fn ($p) => (float) ($p['invoice_amount'] ?? 0), $positions)), 2);

        return VermittlerInvoice::create([
            'filename' => mb_substr($filename, 0, 255),
            'file_path' => $filePath,
            'file_hash' => $fileHash,
            'invoice_number' => $meta['number'],
            'invoice_date' => $meta['date'],
            'total_amount' => $meta['total'],
            'status' => VermittlerInvoice::STATUS_ENTWURF,
            'source' => $source,
            'rows_found' => count($positions),
            'rows_confirmed' => $counts['bestaetigt'],
            'rows_deviation' => $counts['abweichung'],
            'rows_open' => $counts['offen'],
            'result' => [
                'positions' => $positions,
                'sum_matched' => $sum,
                // Nennt die Rechnung eine Gesamtsumme, muss sie aufgehen. Tut
                // sie das nicht, stehen Positionen darin, die wir (noch) nicht
                // kennen - das wird gezeigt, nicht verschwiegen.
                'rest' => $meta['total'] !== null ? round($meta['total'] - $sum, 2) : null,
            ],
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Den Entwurf uebernehmen. Idempotent: ein bestaetigter Entwurf wird
     * nicht ein zweites Mal geschrieben.
     */
    public function confirm(VermittlerInvoice $invoice, ?int $userId): VermittlerInvoice
    {
        if (! $invoice->isDraft()) {
            return $invoice;
        }

        DB::transaction(function () use ($invoice, $userId) {
            foreach ($invoice->result['positions'] ?? [] as $p) {
                $settlement = VermittlerSettlement::find($p['settlement_id'] ?? null);
                if ($settlement === null) {
                    continue;
                }
                $this->applyPosition($invoice, $settlement, $p, $userId);
            }

            $invoice->forceFill([
                'status' => VermittlerInvoice::STATUS_BESTAETIGT,
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ])->save();
        });

        return $invoice->refresh();
    }

    private function applyPosition(VermittlerInvoice $invoice, VermittlerSettlement $settlement, array $p, ?int $userId): void
    {
        $outcome = $p['outcome'];
        $label = 'Rechnung '.($invoice->invoice_number ?: $invoice->filename);

        if ($outcome === 'bestaetigt') {
            $settlement->forceFill([
                'invoice_id' => $invoice->id,
                'invoice_amount' => $p['invoice_amount'],
                'payment_confirmed_at' => now(),
            ])->save();

            $contract = $settlement->contract;
            if ($contract !== null) {
                $contract->forceFill([
                    'vermittler_status' => Contract::VERMITTLER_BEZAHLT_BELEGT,
                    'vermittler_matched_at' => $contract->vermittler_matched_at ?: now(),
                ])->saveQuietly();
            }
            $this->event('invoice_confirmed', $settlement, $invoice, $userId,
                $label.': '.$this->money($p['invoice_amount']).' bezahlt');
            return;
        }

        if (in_array($outcome, ['abweichung', 'storniert'], true)) {
            // Die Rechnung wird trotzdem an der Zeile vermerkt - sie IST
            // eingegangen. Bestaetigt ist die Zahlung damit nicht.
            $note = $outcome === 'storniert'
                ? 'Storniert, steht aber in '.$label
                : $label.' nennt '.$this->money($p['invoice_amount']).', erwartet '.$this->money($p['expected_amount']);
            $settlement->forceFill([
                'invoice_id' => $invoice->id,
                'invoice_amount' => $p['invoice_amount'],
                'match_result' => 'review',
                'match_note' => mb_substr($note, 0, 255),
            ])->save();

            $contract = $settlement->contract;
            if ($contract !== null) {
                $contract->forceFill(['vermittler_status' => Contract::VERMITTLER_PRUEFUNG])->saveQuietly();
            }
            $this->event('invoice_deviation', $settlement, $invoice, $userId, $note);
        }
        // 'ohne_betrag' / 'ohne_vergleich': bewusst NICHTS schreiben. Die
        // Vorschau hat es gezeigt; eine Zahlung, deren Betrag wir nicht
        // lesen konnten, ist nicht belegt.
    }

    /**
     * Positionen der Rechnung: jede BEKANNTE Kennung, die im Text vorkommt,
     * mit dem Betrag ihrer Zeile.
     *
     * @param array<int,string> $lines
     * @return array<int,array<string,mixed>>
     */
    private function positions(array $lines): array
    {
        $text = implode("\n", $lines);

        // Kandidaten fuer die Id: allein stehende 6-10-stellige Zahlen. Daten
        // und gruppierte Nummern (Referenz-Nr., IBAN-Bloecke) fallen durch
        // die Nachbar-Pruefung heraus.
        preg_match_all('/(?<![\d.,\/-])\d{6,10}(?![\d.,\/-]*\d)/', $text, $m);
        $idCandidates = array_values(array_unique($m[0]));

        // Referenz-Nr. im Format des Vermittlers ("1477-6741-9200-53").
        preg_match_all('/\b\d{4}[-\s]\d{4}[-\s]\d{4}[-\s]\d{2}\b/', $text, $r);
        $refKeys = array_values(array_unique(array_filter(array_map(
            fn ($v) => VermittlerReference::key($v), $r[0]
        ))));

        $settlements = collect();
        if ($idCandidates !== []) {
            $settlements = VermittlerSettlement::with('contract.customer.user')
                ->whereIn('vermittler_id', $idCandidates)->get();
        }
        if ($refKeys !== []) {
            $viaRef = VermittlerSettlement::with('contract.customer.user')
                ->whereIn('reference_key', $refKeys)
                ->whereNotIn('id', $settlements->pluck('id'))->get();
            $settlements = $settlements->concat($viaRef);
        }

        $positions = [];
        foreach ($settlements as $settlement) {
            $amounts = $this->amountsNear($lines, $settlement);
            $expected = $settlement->provision !== null ? round((float) $settlement->provision, 2) : null;

            [$outcome, $amount] = $this->judge($settlement, $amounts, $expected);

            $positions[] = [
                'settlement_id' => $settlement->id,
                'vermittler_id' => $settlement->vermittler_id,
                'reference_number' => $settlement->reference_number,
                'contract_id' => $settlement->contract_id,
                'contract_label' => $settlement->contract?->typeLabel() ?? $settlement->contract_label,
                'customer_label' => $settlement->contract->customer->user->name ?? $settlement->customer_label,
                'produkt' => $settlement->produkt,
                'status_code' => $settlement->status_code,
                'expected_amount' => $expected,
                'invoice_amount' => $amount,
                'amounts_seen' => $amounts,
                'outcome' => $outcome,
            ];
        }

        usort($positions, fn ($a, $b) => strcmp((string) $a['vermittler_id'], (string) $b['vermittler_id']));

        return $positions;
    }

    /**
     * @param array<int,float> $amounts
     * @return array{0:string,1:?float}
     */
    private function judge(VermittlerSettlement $settlement, array $amounts, ?float $expected): array
    {
        if ($amounts === []) {
            return ['ohne_betrag', null];
        }

        // Einer der gelesenen Betraege ist genau der erwartete: damit ist die
        // Zeile eindeutig, auch wenn daneben z.B. ein Zwischensaldo steht.
        $exact = $expected !== null
            ? array_values(array_filter($amounts, fn ($a) => abs($a - $expected) < 0.005))
            : [];

        if ($settlement->isStorno()) {
            return ['storniert', $exact[0] ?? (count($amounts) === 1 ? $amounts[0] : null)];
        }
        if ($expected === null) {
            return ['ohne_vergleich', count($amounts) === 1 ? $amounts[0] : null];
        }
        if ($exact !== []) {
            return ['bestaetigt', $exact[0]];
        }
        if (count($amounts) === 1) {
            return ['abweichung', $amounts[0]];
        }

        return ['ohne_betrag', null];
    }

    /**
     * Betraege auf der Zeile der Kennung; steht dort keiner, die Folgezeile
     * (Tabellen umbrechen bei schmalen Spalten).
     *
     * @param array<int,string> $lines
     * @return array<int,float>
     */
    private function amountsNear(array $lines, VermittlerSettlement $settlement): array
    {
        $needles = array_filter([
            $settlement->vermittler_id ? '/(?<![\d.,\/-])'.preg_quote($settlement->vermittler_id, '/').'(?![\d.,\/-]*\d)/' : null,
            $settlement->reference_key ? $this->referencePattern($settlement->reference_key) : null,
        ]);

        $found = [];
        foreach ($lines as $i => $line) {
            foreach ($needles as $needle) {
                if (! preg_match($needle, $line)) {
                    continue;
                }
                $onLine = $this->amounts($this->withoutIdentifiers($line, $settlement));
                if ($onLine === [] && isset($lines[$i + 1])) {
                    $onLine = $this->amounts($this->withoutIdentifiers($lines[$i + 1], $settlement));
                }
                $found = array_merge($found, $onLine);
                break;
            }
        }

        return array_values(array_unique($found, SORT_REGULAR));
    }

    /** Referenz-Nr. mit beliebigem Trenner zwischen den Bloecken. */
    private function referencePattern(string $key): string
    {
        if (! preg_match('/^\d{14}$/', $key)) {
            return '/'.preg_quote($key, '/').'/';
        }
        $parts = [substr($key, 0, 4), substr($key, 4, 4), substr($key, 8, 4), substr($key, 12, 2)];
        return '/'.implode('[-\s]?', $parts).'/';
    }

    /** Kennungen aus der Zeile nehmen, damit sie nie als Betrag gelesen werden. */
    private function withoutIdentifiers(string $line, VermittlerSettlement $settlement): string
    {
        $line = preg_replace('/\b\d{1,2}\.\d{1,2}\.\d{2,4}\b/', ' ', $line) ?? $line;
        $line = preg_replace('/\b\d{4}[-\s]\d{4}[-\s]\d{4}[-\s]\d{2}\b/', ' ', $line) ?? $line;
        if ($settlement->vermittler_id) {
            $line = str_replace($settlement->vermittler_id, ' ', $line);
        }
        return $line;
    }

    /** @return array<int,float> */
    private function amounts(string $line): array
    {
        if (! preg_match_all(self::AMOUNT, $line, $m)) {
            return [];
        }
        return array_map(fn ($v) => $this->toFloat($v), $m[1]);
    }

    private function toFloat(string $value): float
    {
        $v = str_replace([' ', "\u{00A0}"], '', $value);
        if (str_contains($v, ',')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        }
        return round((float) $v, 2);
    }

    /**
     * Kopfdaten - nur mit Beschriftung, nie geraten.
     *
     * @return array{number:?string,date:?string,total:?float}
     */
    private function meta(string $text): array
    {
        $number = null;
        if (preg_match('/(?:rechnungs|gutschrifts|beleg)[-\s]?(?:nummer|nr\.?)\s*[:.]?\s*([A-Z0-9][A-Z0-9\-\/]{2,40})/iu', $text, $m)) {
            $number = $m[1];
        }

        $date = null;
        if (preg_match('/(?:rechnungs|gutschrifts|beleg)?datum\s*[:.]?\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/iu', $text, $m)
            && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        // Die LETZTE Summenzeile gilt - Zwischensummen stehen davor.
        $total = null;
        if (preg_match_all('/(?:gesamtbetrag|rechnungsbetrag|auszahlungsbetrag|gutschriftsbetrag|summe\s+brutto|gesamtsumme|zahlbetrag)[^\n\d-]*'.self::AMOUNT_CORE.'/iu', $text, $m)) {
            $total = $this->toFloat(end($m[1]));
        }

        return ['number' => $number, 'date' => $date, 'total' => $total];
    }

    /** @return array<int,string> */
    private function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
    }

    private function event(string $action, VermittlerSettlement $settlement, VermittlerInvoice $invoice, ?int $userId, string $detail): void
    {
        VermittlerMatchEvent::record($action, [
            'contract_id' => $settlement->contract_id,
            'reference_number' => $settlement->reference_number,
            'vermittler_id' => $settlement->vermittler_id,
            'detail' => mb_substr($detail, 0, 255),
            'import_id' => null,
            'user_id' => $userId,
        ]);
    }

    private function money(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 2, ',', '.').' €';
    }
}
