<?php
namespace App\Services\Energy;

use App\Models\Contract;
use App\Models\ContractEnergyDetail;
use App\Models\Customer;
use App\Models\Document;
use App\Models\MeterReading;
use Carbon\Carbon;

/**
 * Zaehlerstaende: Zuordnung ueber die Zaehlernummer und Fortschreibung der
 * Verbrauchshistorie (Betreiber-Vorgabe 29.07.2026).
 *
 * Der Betrieb (oder der Kunde selbst) laedt ein Foto des Zaehlers hoch; die
 * Zaehlernummer darauf ist die Bruecke zum bereits erfassten Energievertrag
 * und damit zum Kunden - eine Personenangabe steht auf einem Zaehler nicht.
 * Jede Ablesung wird als eigene Zeile gespeichert, damit sich der Verbrauch
 * zwischen zwei Staenden ausrechnen laesst.
 *
 * Grundsaetze:
 * - Die Zaehlernummer ist ein HARTES Identitaetsmerkmal (wie eine FIN):
 *   trifft sie genau einen Kunden, ist die Zuordnung eindeutig. Treffen
 *   MEHRERE Kunden zu, wird NICHT geraten - das Dokument bleibt im Eingang.
 * - Nichts wird erfunden: ohne lesbaren Stand entsteht keine Ablesung.
 * - Doppelte Meldungen (dieselbe Datei zweimal analysiert) erzeugen keinen
 *   zweiten Eintrag.
 */
class MeterReadingService
{
    /** Mindestlaenge fuer den Teiltreffer - kuerzere Nummern waeren unsicher. */
    private const MIN_PARTIAL_LENGTH = 6;

    /**
     * Energievertrag zu einer Zaehlernummer suchen - ueber alle Kunden hinweg.
     *
     * Ein Kunde kann fuer denselben Zaehler mehrere Vertraege haben (der
     * uebliche Anbieterwechsel: alter Vertrag gekuendigt, neuer aktiv). Dann
     * gewinnt der Vertrag, der den Ablesetag abdeckt, sonst der aktive,
     * sonst der zuletzt begonnene. Verteilen sich die Treffer dagegen auf
     * VERSCHIEDENE Kunden, wird bewusst nichts zugeordnet.
     *
     * @return array{detail: ContractEnergyDetail, contract: Contract, customer: Customer}|null
     */
    public function locate(?string $meterNumber, ?string $readingDate = null): ?array
    {
        $candidates = $this->candidates($meterNumber);
        if ($candidates === []) {
            return null;
        }

        $customerIds = [];
        foreach ($candidates as $detail) {
            $customerIds[(string) $detail->contract->customer_id] = true;
        }
        if (count($customerIds) > 1) {
            return null; // mehrdeutig - der Mitarbeiter entscheidet
        }

        $detail = $this->preferredDetail($candidates, $readingDate);
        $contract = $detail->contract;
        $customer = $contract?->customer;
        if (!$contract || !$customer) {
            return null;
        }

        return ['detail' => $detail, 'contract' => $contract, 'customer' => $customer];
    }

    /**
     * Alle Energie-Details, deren Zaehlernummer zur gesuchten passt: zuerst
     * exakt (normalisiert), sonst als Teiltreffer. Der Teiltreffer faengt den
     * Alltag ab, dass im Vertrag die kurze Werksnummer ("92283078") steht,
     * auf dem Zaehler aber die vollstaendige Identifikationsnummer
     * ("1LOG0092283078") - und umgekehrt.
     *
     * @return list<ContractEnergyDetail>
     */
    public function candidates(?string $meterNumber): array
    {
        $normalized = ContractEnergyDetail::normalizeMeter($meterNumber);
        if ($normalized === null || mb_strlen($normalized) < self::MIN_PARTIAL_LENGTH) {
            return [];
        }

        $exact = ContractEnergyDetail::where('meter_number_normalized', $normalized)
            ->with('contract.customer')->get()
            ->filter(fn ($d) => $d->contract && $d->contract->customer)->values();
        if ($exact->isNotEmpty()) {
            return $exact->all();
        }

        // Teiltreffer: eine der beiden Nummern endet auf der anderen. Die
        // Vorauswahl in SQL haelt die Menge klein, der eigentliche Vergleich
        // passiert danach exakt in PHP.
        $tail = mb_substr($normalized, -self::MIN_PARTIAL_LENGTH);
        return ContractEnergyDetail::whereNotNull('meter_number_normalized')
            ->where('meter_number_normalized', 'like', '%' . $tail . '%')
            ->with('contract.customer')->get()
            ->filter(function ($detail) use ($normalized) {
                $stored = (string) $detail->meter_number_normalized;
                if (mb_strlen($stored) < self::MIN_PARTIAL_LENGTH || !$detail->contract || !$detail->contract->customer) {
                    return false;
                }
                return str_ends_with($normalized, $stored) || str_ends_with($stored, $normalized);
            })->values()->all();
    }

    /**
     * Ablesung erfassen. Idempotent: derselbe Stand am selben Tag im selben
     * Zaehlwerk wird nicht doppelt gespeichert (erneute Analyse derselben
     * Datei, Doppelklick im Portal).
     *
     * @param array{register?: string, unit?: string, reading_date?: string|null, captured_at?: mixed, source?: string, document_id?: string|null, created_by?: string|null, note?: string|null, meter_number?: string|null} $options
     */
    public function record(ContractEnergyDetail $detail, float $reading, array $options = []): ?MeterReading
    {
        if ($reading < 0 || $reading >= 100000000) {
            return null;
        }

        $register = $options['register'] ?? MeterReading::REGISTER_DEFAULT;
        if (!isset(MeterReading::REGISTERS[$register])) {
            $register = MeterReading::REGISTER_DEFAULT;
        }
        $readingDate = !empty($options['reading_date'])
            ? Carbon::parse($options['reading_date'])->toDateString()
            : now()->toDateString();

        $existing = MeterReading::where('contract_energy_detail_id', $detail->id)
            ->where('register', $register)
            ->whereDate('reading_date', $readingDate)
            ->get()
            ->first(fn ($r) => abs((float) $r->reading - $reading) < 0.001);
        if ($existing) {
            return $existing;
        }

        // Ein Zaehler laeuft nicht rueckwaerts: ein niedrigerer Stand als
        // zuletzt ist entweder ein Zaehlerwechsel oder ein Lesefehler. Der
        // Wert wird als Tatsache gespeichert (nie stillschweigend korrigiert),
        // aber vermerkt - und er ueberschreibt den Bestandswert nicht.
        $previous = MeterReading::where('contract_energy_detail_id', $detail->id)
            ->where('register', $register)
            ->orderByDesc('reading_date')->orderByDesc('created_at')->first();
        $goesBackwards = $previous && (float) $previous->reading > $reading;

        $note = $options['note'] ?? null;
        if ($goesBackwards) {
            $hint = 'Niedriger als der vorherige Stand (' . MeterReading::formatValue((float) $previous->reading, $previous->unit ?: 'kWh')
                . ') - bitte pruefen (Zaehlerwechsel?).';
            $note = $note ? $note . ' ' . $hint : $hint;
        }

        $entry = MeterReading::create([
            'contract_energy_detail_id' => $detail->id,
            'meter_number' => $options['meter_number'] ?? $detail->meter_number,
            'register' => $register,
            'reading' => $reading,
            'unit' => $options['unit'] ?? $detail->readingUnit($register),
            'reading_date' => $readingDate,
            // Der EXAKTE Zeitpunkt der Meldung - der Betreiber will wissen,
            // wann das Foto kam, nicht nur an welchem Tag.
            'captured_at' => $options['captured_at'] ?? now(),
            'source' => $options['source'] ?? 'staff',
            'document_id' => $options['document_id'] ?? null,
            'created_by' => $options['created_by'] ?? null,
            'note' => $note,
        ]);

        // Bestandsfeld "aktueller Zaehlerstand" mitfuehren, solange der neue
        // Stand tatsaechlich der juengste des Bezugszaehlwerks ist.
        if (!$goesBackwards && $register === MeterReading::REGISTER_DEFAULT) {
            $newest = MeterReading::where('contract_energy_detail_id', $detail->id)
                ->where('register', $register)
                ->orderByDesc('reading_date')->orderByDesc('created_at')->first();
            if ($newest && (string) $newest->id === (string) $entry->id) {
                $detail->meter_reading = rtrim(rtrim(number_format($reading, 3, '.', ''), '0'), '.');
                $detail->save();
            }
        }

        $detail->unsetRelation('meterReadings');

        return $entry;
    }

    /**
     * Zaehlerstand aus einem analysierten Zaehlerfoto uebernehmen. Der
     * passende Vertrag wird ueber die erkannte Zaehlernummer gesucht - beim
     * Portal-Upload eingeschraenkt auf die Vertraege dieses Kunden, damit ein
     * Foto nie in einer fremden Akte landet.
     */
    public function recordFromDocument(Document $document, ?Customer $customer = null): ?MeterReading
    {
        $energie = ($document->ai_extracted ?? [])['energie'] ?? [];
        $reading = $energie['meter_reading'] ?? null;
        if (!is_numeric($reading)) {
            return null;
        }

        $detail = $this->detailForDocument($document, $customer, $energie);
        if (!$detail) {
            return null;
        }

        return $this->record($detail, (float) $reading, [
            'register' => $energie['meter_register'] ?? MeterReading::REGISTER_DEFAULT,
            'unit' => $energie['meter_unit'] ?? null,
            // Der Upload-Zeitpunkt ist der Ablesezeitpunkt: das Foto zeigt den
            // Stand von genau diesem Moment.
            'reading_date' => ($document->created_at ?? now())->toDateString(),
            'captured_at' => $document->created_at ?? now(),
            'source' => 'document',
            'document_id' => (string) $document->id,
            'meter_number' => $energie['meter_number'] ?? null,
            'note' => 'Automatisch aus dem hochgeladenen Zaehlerfoto uebernommen.',
        ]);
    }

    /** Energie-Detail zum Dokument bestimmen (Kundenbindung geht vor). */
    private function detailForDocument(Document $document, ?Customer $customer, array $energie): ?ContractEnergyDetail
    {
        $customerId = $customer?->id ?? $document->customer_id;

        // Ist der Vertrag schon am Dokument vermerkt, ist die Sache klar.
        if ($document->contract_id) {
            $contract = Contract::with('energyDetail')->find($document->contract_id);
            if ($contract?->energyDetail) {
                return $contract->energyDetail;
            }
        }

        $number = $energie['meter_number'] ?? null;
        if ($customerId) {
            // Innerhalb der Kundenakte: der Zaehler des Kunden. Ohne lesbare
            // Nummer greift der einzige Energievertrag des Kunden - gibt es
            // mehrere, entscheidet die Nummer (sonst nichts).
            $details = ContractEnergyDetail::whereHas('contract', fn ($q) => $q->where('customer_id', $customerId))
                ->with('contract.customer')->get();
            if ($details->isEmpty()) {
                return null;
            }

            $normalized = ContractEnergyDetail::normalizeMeter($number);
            if ($normalized !== null) {
                $matches = $details->filter(function ($detail) use ($normalized) {
                    $stored = (string) $detail->meter_number_normalized;
                    return $stored !== '' && (
                        $stored === $normalized
                        || (mb_strlen($stored) >= self::MIN_PARTIAL_LENGTH
                            && (str_ends_with($normalized, $stored) || str_ends_with($stored, $normalized)))
                    );
                })->values();
                if ($matches->isNotEmpty()) {
                    return $this->preferredDetail($matches->all(), $document->created_at?->toDateString());
                }
            }

            return $details->count() === 1 ? $details->first() : null;
        }

        $located = $this->locate($number, $document->created_at?->toDateString());
        return $located['detail'] ?? null;
    }

    /**
     * Aus mehreren Vertraegen desselben Zaehlers den passenden waehlen:
     * der zum Ablesetag laufende Vertrag, sonst der aktive, sonst der
     * zuletzt begonnene.
     *
     * @param list<ContractEnergyDetail> $candidates
     */
    private function preferredDetail(array $candidates, ?string $readingDate): ContractEnergyDetail
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $date = $readingDate ? Carbon::parse($readingDate) : now();
        foreach ($candidates as $detail) {
            $contract = $detail->contract;
            $start = $contract?->start_date ? Carbon::parse($contract->start_date) : null;
            $end = $contract?->effectiveCancellationDate() ?? ($contract?->end_date ? Carbon::parse($contract->end_date) : null);
            if ($start && $start->lte($date) && (!$end || Carbon::parse($end)->gte($date))) {
                return $detail;
            }
        }

        // Zweite Stufe: der AKTIVE Vertrag (Contract::isCurrentlyActive - nicht
        // der rohe Status; ein zum Ablauf gekuendigter Vertrag ist beendet und
        // soll die Ablesung nicht bekommen, solange ein laufender existiert).
        foreach ($candidates as $detail) {
            if ($detail->contract?->isCurrentlyActive()) {
                return $detail;
            }
        }

        usort($candidates, function ($a, $b) {
            return strcmp((string) ($b->contract?->start_date ?? ''), (string) ($a->contract?->start_date ?? ''));
        });
        return $candidates[0];
    }
}
