<?php

namespace App\Services\Vermittler;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\VermittlerSettlement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Auswertungen ueber die Vermittler-Abrechnung (Betreiber-Ziel: welche
 * Vertraege sind wirklich Geld wert und arbeitet der Vermittler sauber?).
 *
 * Alle Zahlen kommen aus den GESPEICHERTEN Abrechnungsdaten - es wird nichts
 * hochgerechnet und nichts geschaetzt. Stornierte Datensaetze gehen NIE in
 * die Provisionssumme ein (der Vermittler zahlt sie nicht aus), werden aber
 * getrennt ausgewiesen, damit der Verlust sichtbar bleibt.
 */
class VermittlerReportService
{
    /** Kennzahlen des Bestands: was haben wir eingereicht, was kam zurueck? */
    public function performance(): array
    {
        $rows = Contract::query()
            ->whereNotNull('vermittler_status')
            ->where('vermittler_status', '!=', Contract::VERMITTLER_NEU)
            ->groupBy('vermittler_status')
            ->select('vermittler_status', DB::raw('count(*) as anzahl'))
            ->pluck('anzahl', 'vermittler_status')
            ->all();

        $eingereicht = array_sum($rows);
        // Bestaetigt heisst: vom Vermittler verifiziert oder bezahlt. Code 1
        // ("offen") zaehlt NICHT - bis 23.09.2026 tat er es und blaehte die
        // Bestaetigungsquote mit Positionen auf, ueber die nichts entschieden war.
        $abgerechnet = ($rows[Contract::VERMITTLER_VERIFIZIERT] ?? 0)
            + ($rows[Contract::VERMITTLER_ABGERECHNET] ?? 0)
            + ($rows[Contract::VERMITTLER_BEZAHLT_BELEGT] ?? 0);

        return [
            'eingereicht' => $eingereicht,
            'abgerechnet' => $abgerechnet,
            'storniert' => $rows[Contract::VERMITTLER_STORNIERT] ?? 0,
            'nicht_gefunden' => $rows[Contract::VERMITTLER_NICHT_GEFUNDEN] ?? 0,
            'pruefung' => $rows[Contract::VERMITTLER_PRUEFUNG] ?? 0,
            'offen' => ($rows[Contract::VERMITTLER_REFERENZ] ?? 0) + ($rows[Contract::VERMITTLER_ID_ZUGEORDNET] ?? 0)
                + ($rows[Contract::VERMITTLER_IN_ABRECHNUNG] ?? 0),
            'belegt' => $rows[Contract::VERMITTLER_BEZAHLT_BELEGT] ?? 0,
            // Bestaetigungsquote: nur aussagekraeftig, wenn ueberhaupt etwas
            // eingereicht wurde - sonst bleibt sie leer statt "0 %".
            'quote' => $eingereicht > 0 ? round($abgerechnet / $eingereicht * 100, 1) : null,
        ];
    }

    /**
     * Eine Abrechnungszeile fachlich einordnen. Seit 23.09.2026 getrennt in
     * offen / bestaetigt / storniert - vorher galt alles ausser Storno als
     * "bestaetigt", also auch Code 1 (offen), und seine Provision stand als
     * "tatsaechlich abgerechnet" in der Auswertung.
     */
    private function bucket(?string $statusCode): string
    {
        return match (VermittlerStatusMap::forCode($statusCode)) {
            Contract::VERMITTLER_STORNIERT => 'storniert',
            Contract::VERMITTLER_VERIFIZIERT, Contract::VERMITTLER_ABGERECHNET => 'bestaetigt',
            default => 'offen',
        };
    }

    /**
     * Zeilen je Gruppe mit getrennten Summen: `provision` ist, was die CSV
     * ERWARTEN laesst; `provision_belegt` ist, was eine Rechnung bestaetigt
     * hat. Nur Letzteres ist Geld, das nachweislich geflossen ist.
     */
    private function aggregate(string $groupColumn): Collection
    {
        return VermittlerSettlement::query()
            ->selectRaw($groupColumn.', status_code, count(*) as anzahl, sum(provision) as provision,'
                .' sum(case when payment_confirmed_at is null then 0 else 1 end) as belegt,'
                .' sum(case when payment_confirmed_at is null then 0 else invoice_amount end) as provision_belegt')
            ->groupBy($groupColumn, 'status_code')
            ->get();
    }

    /** @return array<string,mixed> */
    private function emptyEntry(): array
    {
        return [
            'anzahl' => 0, 'offen' => 0, 'bestaetigt' => 0, 'storniert' => 0, 'belegt' => 0,
            'provision' => 0.0, 'provision_belegt' => 0.0, 'provision_storno' => 0.0,
        ];
    }

    /** @param array<string,mixed> $entry */
    private function add(array &$entry, object $row): void
    {
        $bucket = $this->bucket($row->status_code);
        $entry['anzahl'] += (int) $row->anzahl;
        $entry[$bucket] += (int) $row->anzahl;
        $entry['belegt'] += (int) $row->belegt;
        $entry['provision_belegt'] += (float) $row->provision_belegt;
        if ($bucket === 'storniert') {
            $entry['provision_storno'] += (float) $row->provision;
        } else {
            $entry['provision'] += (float) $row->provision;
        }
    }

    /** Je Produkt des Vermittlers: Anzahl, Stand und Provision (erwartet / belegt). */
    public function byProduct(): array
    {
        $result = [];
        foreach ($this->aggregate('produkt') as $row) {
            $produkt = trim((string) $row->produkt) ?: 'Ohne Produktangabe';
            $result[$produkt] ??= ['produkt' => $produkt] + $this->emptyEntry();
            $this->add($result[$produkt], $row);
        }

        usort($result, fn ($a, $b) => $b['anzahl'] <=> $a['anzahl']);

        return $result;
    }

    /** Je Kunde: wie viel hat dieser Kunde eingebracht (erwartet / belegt)? */
    public function byCustomer(int $limit = 50): array
    {
        $result = [];
        foreach ($this->aggregate('customer_id') as $row) {
            if ($row->customer_id === null) {
                continue;
            }
            $result[$row->customer_id] ??= ['customer_id' => $row->customer_id] + $this->emptyEntry();
            $this->add($result[$row->customer_id], $row);
        }

        usort($result, fn ($a, $b) => [$b['provision_belegt'], $b['provision']] <=> [$a['provision_belegt'], $a['provision']]);
        $result = array_slice($result, 0, $limit);

        $customers = Customer::with('user')
            ->whereIn('id', array_column($result, 'customer_id'))->get()->keyBy('id');
        foreach ($result as &$entry) {
            $entry['customer'] = $customers[$entry['customer_id']] ?? null;
        }

        return $result;
    }
}
