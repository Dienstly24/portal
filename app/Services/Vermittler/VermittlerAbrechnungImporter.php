<?php
namespace App\Services\Vermittler;

use App\Models\Contract;
use App\Models\VermittlerImport;
use App\Models\VermittlerMatchEvent;
use App\Models\VermittlerSettlement;
use Illuminate\Support\Carbon;

/**
 * Import + Matching + Reconciliation der Vermittler-Abrechnung
 * (Betreiber-Auftrag 20.08.2026).
 *
 * DIE VIER GRUNDREGELN - sie stehen ueber jeder Bequemlichkeit:
 *
 * 1. NIE RATEN. Jede Unstimmigkeit (abweichende Referenz-Nr., doppelte
 *    Referenz-Nr., unbekannter Status-Code) fuehrt zu "Prüfung erforderlich"
 *    und NICHT zu einer automatischen Zuordnung. Eine falsche Verknuepfung
 *    ist teurer als eine offene Zeile.
 * 2. NIE VERTRAGSDATEN AENDERN. Der Import schreibt ausschliesslich die
 *    Abrechnungs-Spalten (vermittler_*). Die einzige Ausnahme ist das
 *    ERGAENZEN einer leeren Referenz-Nr. - Ergaenzen ist kein Ueberschreiben.
 * 3. NIE LOESCHEN. Fehlt ein Vertrag in der Abrechnung, heisst das
 *    "Nicht in Abrechnung gefunden" - nie "geloescht" und nie "storniert".
 * 4. NIE DOPPELT. Natuerlicher Schluessel ist die `Id` des Vermittlers; ein
 *    erneuter Import derselben Datei aktualisiert die Zeilen, er legt sie
 *    nicht erneut an. Unveraenderte Zeilen melden "Bereits importiert".
 */
class VermittlerAbrechnungImporter
{
    /** @var array<string,string> normalisierte Vermittler-ID => contract_id */
    private array $byVermittlerId = [];

    /** @var array<string,array<int,string>> normalisierte Referenz => contract_ids */
    private array $byReference = [];

    /** @var array<string,Contract> geladene Vertraege je contract_id */
    private array $contracts = [];

    public function __construct(private VermittlerCsvReader $reader) {}

    /**
     * Liest die Datei, ordnet jede Zeile zu und liefert den Import-Lauf.
     *
     * @param bool $reconcile Vertraege ohne Treffer als "Nicht in Abrechnung
     *        gefunden" markieren (Abgleich in beide Richtungen).
     */
    public function import(string $path, string $filename, ?int $userId = null, bool $reconcile = true): VermittlerImport
    {
        $parsed = $this->reader->read($path);

        $import = VermittlerImport::create([
            'filename' => mb_substr($filename, 0, 255),
            'file_hash' => hash_file('sha256', $path),
            'imported_by' => $userId,
        ]);

        $this->loadContractIndex();

        $counts = [
            'rows_total' => 0, 'rows_matched' => 0, 'rows_new_link' => 0,
            'rows_unmatched' => 0, 'rows_review' => 0, 'rows_storno' => 0,
            'rows_unchanged' => 0, 'rows_invalid' => 0,
        ];
        $seenIds = [];
        $referenceShapes = [];
        $maxDate = null;

        foreach ($parsed['rows'] as $row) {
            $counts['rows_total']++;

            $vermittlerId = VermittlerReference::display($row['vermittler_id'] ?? null);
            if ($vermittlerId === null) {
                // Ohne die Id des Vermittlers ist der Datensatz nicht
                // zuordenbar und auch nicht wiedererkennbar - er wird
                // gezaehlt, aber nicht gespeichert (sonst entstuenden
                // Karteileichen ohne Schluessel).
                $counts['rows_invalid']++;
                continue;
            }
            $seenIds[$vermittlerId] = true;

            $date = VermittlerCsvReader::date($row['datum'] ?? null);
            if ($date && (!$maxDate || $date->greaterThan($maxDate))) {
                $maxDate = $date->copy();
            }
            $refDisplay = VermittlerReference::display($row['reference_number'] ?? null);
            $refKey = VermittlerReference::key($refDisplay);
            if ($refKey !== null && ctype_digit($refKey)) {
                $referenceShapes[strlen($refKey)] = true;
            }

            $settlement = $this->storeRow($import, $vermittlerId, $row, $date, $refDisplay, $refKey, $userId);

            $counts[match ($settlement->importResult()) {
                'matched' => 'rows_matched',
                'linked' => 'rows_new_link',
                'review' => 'rows_review',
                'unchanged' => 'rows_unchanged',
                default => 'rows_unmatched',
            }]++;
            if ($settlement->isStorno()) {
                $counts['rows_storno']++;
            }
        }

        $counts['contracts_not_found'] = $reconcile
            ? $this->markMissingContracts($import, array_keys($referenceShapes), $maxDate, $userId)
            : 0;

        $import->update($counts);

        return $import->refresh();
    }

    /**
     * Eine Zeile speichern und zuordnen. Der natuerliche Schluessel ist die
     * Vermittler-ID - vorhandene Zeilen werden aktualisiert, nie dupliziert.
     */
    private function storeRow(
        VermittlerImport $import,
        string $vermittlerId,
        array $row,
        ?Carbon $date,
        ?string $refDisplay,
        ?string $refKey,
        ?int $userId
    ): VermittlerSettlement {
        $statusCode = trim((string) ($row['status'] ?? ''));
        $stornoReason = VermittlerReference::display($row['storno_reason'] ?? null);
        $provision = VermittlerCsvReader::amount($row['provision'] ?? null);

        $payload = [
            'produkt' => mb_substr(trim((string) ($row['produkt'] ?? '')), 0, 190) ?: null,
            'statement_date' => $date?->toDateString(),
            'status_code' => $statusCode !== '' ? mb_substr($statusCode, 0, 20) : null,
            'provision' => $provision,
            'tracking_id' => mb_substr(trim((string) ($row['tracking_id'] ?? '')), 0, 100) ?: null,
            'storno_reason' => $stornoReason ? mb_substr($stornoReason, 0, 255) : null,
            'reference_number' => $refDisplay,
            'reference_key' => $refKey,
        ];
        $rowHash = sha1(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '');

        $settlement = VermittlerSettlement::where('vermittler_id', $vermittlerId)->first();
        $isKnownRow = $settlement !== null && $settlement->row_hash === $rowHash;

        $match = $this->match($vermittlerId, $refDisplay, $refKey, $statusCode, $stornoReason);

        if ($settlement === null) {
            $settlement = new VermittlerSettlement(['vermittler_id' => $vermittlerId]);
        }

        // Unveraenderte Zeile, deren Zuordnung sich ebenfalls nicht geaendert
        // hat: nichts anfassen, nur "Bereits importiert" melden.
        if ($isKnownRow && $settlement->contract_id === ($match['contract']?->id) && $settlement->match_result === $this->durableResult($match['result'])) {
            $settlement->update(['import_id' => $import->id, 'import_result' => 'unchanged']);
            return $settlement;
        }

        $contract = $match['contract'];
        $settlement->fill($payload + [
            'import_id' => $import->id,
            'contract_id' => $contract?->id,
            'customer_id' => $contract?->customer_id,
            'contract_label' => $contract ? mb_substr(trim($contract->insurer . ' · ' . ($contract->contract_number ?: $contract->typeLabel())), 0, 190) : $settlement->contract_label,
            'customer_label' => $contract ? mb_substr((string) ($contract->customer?->user?->name ?? ''), 0, 190) ?: $settlement->customer_label : $settlement->customer_label,
            'match_result' => $this->durableResult($match['result']),
            'import_result' => $match['result'],
            'match_note' => $match['note'],
            'row_hash' => $rowHash,
        ]);
        $settlement->save();

        if ($contract) {
            $this->applyToContract($contract, $settlement, $import, $match, $userId);
        }

        return $settlement;
    }

    /**
     * Dauerhafter Zustand aus dem Ergebnis eines Laufs: 'linked' ist nur die
     * AUSSAGE dieses Laufs ("in diesem Import neu verknuepft"); dauerhaft ist
     * die Zeile danach schlicht zugeordnet.
     */
    private function durableResult(string $result): string
    {
        return $result === 'linked' ? 'matched' : $result;
    }

    /**
     * Kern der Zuordnung. Liefert Vertrag, Ergebnis und Begruendung.
     *
     * @return array{contract: ?Contract, result: string, note: ?string, status: ?string}
     */
    private function match(string $vermittlerId, ?string $refDisplay, ?string $refKey, string $statusCode, ?string $stornoReason): array
    {
        $idKey = VermittlerReference::key($vermittlerId);
        $viaId = $idKey !== null ? ($this->contracts[$this->byVermittlerId[$idKey] ?? ''] ?? null) : null;
        $viaRef = $refKey !== null ? ($this->byReference[$refKey] ?? []) : [];

        $status = VermittlerStatusMap::forCode($statusCode);
        // Unbekannter Status-Code: der Datensatz wird gespeichert, aber der
        // Vertrag bekommt keinen erfundenen Zustand.
        $statusUnknown = !VermittlerStatusMap::isKnown($statusCode);
        // Widerspruch in der Datei selbst: ein Stornogrund an einem nicht
        // stornierten Datensatz. Ein Vertrag wird NUR storniert, wenn die
        // Abrechnung ihn auch als storniert ausweist.
        $stornoConflict = $stornoReason !== null && $status !== Contract::VERMITTLER_STORNIERT;

        if ($viaId !== null) {
            $note = null;
            $result = 'matched';

            if ($refKey !== null && $viaId->referenceKey() !== null && $viaId->referenceKey() !== $refKey) {
                // Gefaehrlichster Fall: dieselbe ID, andere Referenz-Nr.
                // Nie automatisch "korrigieren" - der Unterschied wird gezeigt.
                return [
                    'contract' => $viaId,
                    'result' => 'review',
                    'note' => 'Referenz-Nr. weicht ab: Vertrag "' . $viaId->reference_number . '" / Abrechnung "' . $refDisplay . '"',
                    'status' => Contract::VERMITTLER_PRUEFUNG,
                ];
            }
            if ($statusUnknown) {
                $result = 'review';
                $note = VermittlerStatusMap::codeLabel($statusCode);
            } elseif ($stornoConflict) {
                $result = 'review';
                $note = 'Stornogrund trotz Status "' . VermittlerStatusMap::codeLabel($statusCode) . '"';
            }

            return [
                'contract' => $viaId,
                'result' => $result,
                'note' => $note,
                'status' => $result === 'review' ? Contract::VERMITTLER_PRUEFUNG : $status,
            ];
        }

        if (count($viaRef) > 1) {
            return [
                'contract' => null,
                'result' => 'review',
                'note' => 'Doppelte Referenz-Nr.: ' . count($viaRef) . ' Verträge tragen "' . $refDisplay . '"',
                'status' => null,
            ];
        }

        if (count($viaRef) === 1) {
            $contract = $this->contracts[$viaRef[0]] ?? null;
            if ($contract === null) {
                return ['contract' => null, 'result' => 'unmatched', 'note' => null, 'status' => null];
            }
            if (filled($contract->vermittler_id) && !VermittlerReference::same($contract->vermittler_id, $vermittlerId)) {
                return [
                    'contract' => $contract,
                    'result' => 'review',
                    'note' => 'Vertrag trägt bereits die Vermittler-ID "' . $contract->vermittler_id . '"',
                    'status' => Contract::VERMITTLER_PRUEFUNG,
                ];
            }
            if ($statusUnknown || $stornoConflict) {
                return [
                    'contract' => $contract,
                    'result' => 'review',
                    'note' => $statusUnknown
                        ? VermittlerStatusMap::codeLabel($statusCode)
                        : 'Stornogrund trotz Status "' . VermittlerStatusMap::codeLabel($statusCode) . '"',
                    'status' => Contract::VERMITTLER_PRUEFUNG,
                ];
            }
            return ['contract' => $contract, 'result' => 'linked', 'note' => null, 'status' => $status];
        }

        return [
            'contract' => null,
            'result' => 'unmatched',
            'note' => $refDisplay ? 'Weder ID noch Referenz-Nr. im Bestand gefunden' : 'ID nicht im Bestand gefunden',
            'status' => null,
        ];
    }

    /**
     * Ergebnis am Vertrag festhalten. Beruehrt AUSSCHLIESSLICH die
     * Abrechnungs-Spalten - plus das Ergaenzen einer leeren Referenz-Nr.
     */
    private function applyToContract(Contract $contract, VermittlerSettlement $settlement, VermittlerImport $import, array $match, ?int $userId): void
    {
        $events = [];
        $update = [
            'vermittler_last_import_id' => $import->id,
            'vermittler_last_imported_at' => now(),
        ];

        if (blank($contract->vermittler_id) && $match['result'] !== 'review') {
            $update['vermittler_id'] = $settlement->vermittler_id;
            $events[] = ['id_linked', 'Vermittler-ID ' . $settlement->vermittler_id . ' zugeordnet'];
        }

        // Leere Referenz-Nr. ERGAENZEN (nie ueberschreiben): die Abrechnung
        // liefert sie manchmal nach, wenn sie bei der Anlage fehlte.
        if (blank($contract->reference_number) && filled($settlement->reference_number)) {
            $update['reference_number'] = $settlement->reference_number;
            $events[] = ['reference_stored', 'Referenz-Nr. ' . $settlement->reference_number . ' aus der Abrechnung ergänzt'];
        }

        $newStatus = $match['status'];
        if ($newStatus !== null && $newStatus !== $contract->vermittlerStatus()) {
            $update['vermittler_status'] = $newStatus;
            $update['vermittler_matched_at'] = now();
            $events[] = ['status_changed', Contract::VERMITTLER_STATUSES[$newStatus]['label']
                . ($settlement->storno_reason ? ' – ' . $settlement->storno_reason : '')];
        } elseif ($newStatus !== null && blank($contract->vermittler_matched_at)) {
            $update['vermittler_matched_at'] = now();
        }

        if ($match['result'] === 'review' && $match['note']) {
            $events[] = ['conflict', $match['note']];
        }

        $contract->forceFill($update)->saveQuietly();
        $this->rememberContract($contract);

        foreach ($events as [$action, $detail]) {
            VermittlerMatchEvent::record($action, [
                'contract_id' => $contract->id,
                'reference_number' => $contract->reference_number,
                'vermittler_id' => $contract->vermittler_id ?: $settlement->vermittler_id,
                'detail' => mb_substr($detail, 0, 255),
                'import_id' => $import->id,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Gegenrichtung: Vertraege, die in der Abrechnung FEHLEN.
     *
     * Bewusst eng gefasst, damit kein Vertrag aus einer ganz anderen Quelle
     * (Energieportal, Maklerpool) faelschlich als "vom Vermittler nicht
     * abgerechnet" dasteht. Markiert werden nur Vertraege, die
     *  - noch nie in einer Abrechnung standen (VERMITTLER_PRE_MATCH),
     *  - keine Vermittler-ID tragen,
     *  - eine Referenz-Nr. im FORMAT DIESER DATEI haben (gleiche Laenge,
     *    reine Ziffern) - der Massstab kommt aus der Datei selbst,
     *  - und aelter sind als der juengste Datensatz der Datei (spaeter
     *    angelegte Vertraege KOENNEN noch nicht enthalten sein).
     *
     * @param array<int,int> $shapes Laengen der Referenz-Nr. in dieser Datei
     */
    private function markMissingContracts(VermittlerImport $import, array $shapes, ?Carbon $maxDate, ?int $userId): int
    {
        if ($shapes === [] || $maxDate === null) {
            return 0;
        }

        $cutoff = $maxDate->copy()->endOfDay();
        $marked = 0;

        Contract::query()
            ->whereNull('vermittler_id')
            ->whereNotNull('reference_number')
            ->where('reference_number', '!=', '')
            ->where(function ($q) {
                $q->whereNull('vermittler_status')
                    ->orWhereIn('vermittler_status', Contract::VERMITTLER_PRE_MATCH);
            })
            ->where('created_at', '<=', $cutoff)
            ->chunkById(200, function ($contracts) use (&$marked, $import, $shapes, $userId) {
                foreach ($contracts as $contract) {
                    $key = $contract->referenceKey();
                    if ($key === null || !ctype_digit($key) || !in_array(strlen($key), $shapes, true)) {
                        continue;
                    }
                    $marked++;
                    if ($contract->vermittlerStatus() === Contract::VERMITTLER_NICHT_GEFUNDEN) {
                        continue; // schon gemeldet - keine zweite Historien-Zeile
                    }
                    $contract->forceFill([
                        'vermittler_status' => Contract::VERMITTLER_NICHT_GEFUNDEN,
                        'vermittler_last_import_id' => $import->id,
                        'vermittler_last_imported_at' => now(),
                    ])->saveQuietly();
                    VermittlerMatchEvent::record('not_found', [
                        'contract_id' => $contract->id,
                        'reference_number' => $contract->reference_number,
                        'detail' => 'In der Abrechnung "' . mb_substr($import->filename, 0, 120) . '" nicht enthalten',
                        'import_id' => $import->id,
                        'user_id' => $userId,
                    ]);
                }
            });

        return $marked;
    }

    /** Vertraege mit Referenz-Nr. oder Vermittler-ID einmalig in den Index laden. */
    private function loadContractIndex(): void
    {
        Contract::with('customer.user')
            ->where(function ($q) {
                $q->where('reference_number', '!=', '')->whereNotNull('reference_number')
                    ->orWhere(fn ($w) => $w->where('vermittler_id', '!=', '')->whereNotNull('vermittler_id'));
            })
            ->chunkById(500, fn ($chunk) => $chunk->each(fn ($c) => $this->rememberContract($c)));
    }

    private function rememberContract(Contract $contract): void
    {
        $this->contracts[$contract->id] = $contract;

        $idKey = VermittlerReference::key($contract->vermittler_id);
        if ($idKey !== null) {
            $this->byVermittlerId[$idKey] = $contract->id;
        }

        $refKey = $contract->referenceKey();
        if ($refKey !== null && !in_array($contract->id, $this->byReference[$refKey] ?? [], true)) {
            $this->byReference[$refKey][] = $contract->id;
        }
    }
}
