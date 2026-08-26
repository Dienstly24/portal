<?php
namespace App\Services\CommissionImport;

use App\Models\CommissionImport;
use App\Models\CommissionImportRow;
use App\Models\Contract;
use App\Models\ContractCommission;
use App\Services\Vermittler\VermittlerReference;
use App\Support\CommissionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Der Import in ZWEI STUFEN (Betreiber-Auftrag 26.08.2026).
 *
 * `analyze()` liest, deutet, ordnet zu und legt das Ergebnis als ENTWURF ab -
 * es wird KEINE Provision geschrieben. `confirm()` uebernimmt genau das, was
 * in der Vorschau stand.
 *
 * WARUM DIESE TRENNUNG die eigentliche Anforderung ist: Ein einstufiger
 * Import zeigt sein Ergebnis erst, NACHDEM er die Daten veraendert hat. Wer
 * dann sieht "3 Verträge nicht gefunden, 2 Datensätze fehlerhaft", hat keine
 * Wahl mehr - die anderen 120 sind schon drin. Die Vorschau ist deshalb kein
 * Komfort, sondern die Stelle, an der eine falsch erkannte Spalte noch
 * folgenlos bleibt.
 */
class CommissionImportService
{
    public function __construct(
        private TableReader $reader,
        private CommissionMatcher $matcher,
        private CommissionAuditLogger $audit,
    ) {
    }

    /**
     * Datei lesen und als Entwurf pruefen.
     *
     * @param array<string,int|null>|null $columnMap vom Admin gewaehlte Zuordnung
     */
    public function analyze(
        string $path,
        string $filename,
        ?int $userId = null,
        ?string $delimiter = null,
        ?string $encoding = null,
        ?string $sheetName = null,
        ?array $columnMap = null,
    ): CommissionImport {
        $table = $this->reader->read($path, $delimiter, $encoding, $sheetName);
        $map = $this->cleanMap($columnMap ?? ColumnMap::suggest($table->header));

        $import = CommissionImport::create([
            'filename' => mb_substr($filename, 0, 255),
            'file_hash' => (string) hash_file('sha256', $path),
            'format' => $table->format,
            'delimiter' => $table->delimiter,
            'encoding' => $table->encoding,
            'sheet_name' => $table->sheetName,
            'sheet_names' => $table->sheetNames,
            'header' => $table->header,
            'column_map' => $map,
            'status' => CommissionImport::ENTWURF,
            'rows_total' => $table->rowCount(),
            'imported_by' => $userId,
        ]);

        $this->buildRows($import, $table, $map);

        $this->audit->log('datei_hochgeladen', null, [
            'import_id' => $import->id,
            'source_file' => $import->filename,
            'new_value' => $import->rows_total . ' Zeilen, Format ' . strtoupper($import->format),
        ]);

        return $import->fresh();
    }

    /**
     * Zuordnung aendern und den Entwurf neu bewerten - ohne die Datei erneut
     * hochzuladen. Die Rohzeilen liegen bereits im Entwurf; sie erneut zu
     * lesen wuerde eine zwischengespeicherte Datei voraussetzen, die es nach
     * dem Upload bewusst nicht mehr gibt.
     *
     * @param array<string,int|null> $columnMap
     */
    public function remap(CommissionImport $import, array $columnMap): CommissionImport
    {
        if (!$import->isDraft()) {
            throw new \RuntimeException('Ein bereits übernommener Import kann nicht neu zugeordnet werden.');
        }
        $map = $this->cleanMap($columnMap);

        $rows = $import->rows()->orderBy('row_number')->get();
        $table = new TableFile(
            format: $import->format,
            header: (array) $import->header,
            rows: $rows->map(fn ($r) => (array) $r->raw)->all(),
            delimiter: $import->delimiter,
            encoding: $import->encoding,
            sheetName: $import->sheet_name,
            sheetNames: (array) ($import->sheet_names ?? []),
        );

        $import->rows()->delete();
        $import->update(['column_map' => $map]);
        $this->buildRows($import, $table, $map);

        return $import->fresh();
    }

    /**
     * Den Entwurf uebernehmen. Nur Zeilen mit Ergebnis "neu" oder
     * "aktualisiert" werden geschrieben - Duplikate, fehlerhafte und nicht
     * zugeordnete Zeilen bleiben stehen und sind spaeter noch da.
     */
    public function confirm(CommissionImport $import, ?int $userId = null): CommissionImport
    {
        if (!$import->isDraft()) {
            throw new \RuntimeException('Dieser Import wurde bereits übernommen.');
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($import, $userId, &$created, &$updated) {
            $import->rows()->orderBy('row_number')->chunkById(200, function ($rows) use ($import, $userId, &$created, &$updated) {
                foreach ($rows as $row) {
                    if (!$row->willApply()) {
                        continue;
                    }
                    $result = $this->apply($import, $row, $userId);
                    $result === 'neu' ? $created++ : $updated++;
                }
            });

            $import->update([
                'status' => CommissionImport::IMPORTIERT,
                'confirmed_at' => now(),
                'rows_new' => $created,
                'rows_updated' => $updated,
            ]);
        });

        $this->audit->log('import_bestaetigt', null, [
            'import_id' => $import->id,
            'source_file' => $import->filename,
            'new_value' => $created . ' neu, ' . $updated . ' aktualisiert',
        ]);

        return $import->fresh();
    }

    public function discard(CommissionImport $import): CommissionImport
    {
        if (!$import->isDraft()) {
            throw new \RuntimeException('Nur ein Entwurf kann verworfen werden.');
        }
        $import->update(['status' => CommissionImport::VERWORFEN]);
        $this->audit->log('import_verworfen', null, [
            'import_id' => $import->id,
            'source_file' => $import->filename,
        ]);
        return $import->fresh();
    }

    // ---------------------------------------------------------------- intern

    /**
     * @param array<string,int|null> $map
     * @return array<string,int>
     */
    private function cleanMap(array $map): array
    {
        $clean = [];
        foreach ($map as $field => $index) {
            if (!in_array($field, ColumnMap::keys(), true) || $index === null || $index === '') {
                continue;
            }
            $clean[$field] = (int) $index;
        }
        return $clean;
    }

    /** @param array<string,int> $map */
    private function buildRows(CommissionImport $import, TableFile $table, array $map): void
    {
        $mapErrors = ColumnMap::validate($map);
        $this->matcher->load();

        // Duplikate INNERHALB der Datei: eine Abrechnung enthaelt dieselbe
        // Position gelegentlich zweimal. Ohne diese Menge wuerde die zweite
        // Zeile als "neu" gezaehlt und beim Schreiben am Unique-Schluessel
        // scheitern - der Admin saehe eine Zahl in der Vorschau, die nicht
        // eintritt.
        $seen = [];
        $existing = [];

        $counters = ['neu' => 0, 'aktualisiert' => 0, 'duplikat' => 0, 'nicht_zugeordnet' => 0, 'fehlerhaft' => 0];
        $rowNumber = 1; // 1 = Kopfzeile

        foreach ($table->rows as $raw) {
            $rowNumber++;
            $mapped = $this->mapRow($raw, $map);
            $errors = $mapErrors === [] ? $this->validateRow($mapped) : $mapErrors;

            $record = [
                'import_id' => $import->id,
                'row_number' => $rowNumber,
                'raw' => array_map(fn ($v) => mb_substr((string) $v, 0, 500), array_values($raw)),
                'mapped' => $this->serialize($mapped),
                'contract_id' => null,
                'match_reason' => null,
                'dedupe_key' => null,
                'message' => null,
            ];

            if ($errors !== []) {
                $record['result'] = CommissionImportRow::FEHLERHAFT;
                $record['message'] = mb_substr(implode(' ', $errors), 0, 500);
                $counters['fehlerhaft']++;
                CommissionImportRow::create($record);
                continue;
            }

            $match = $this->matcher->match($mapped);
            $contract = $match['contract'];
            $conflict = $contract !== null ? $this->matcher->conflict($contract, $mapped) : null;

            $dedupe = $this->dedupeKey($mapped);
            $record['dedupe_key'] = $dedupe;

            if ($contract === null || $conflict !== null) {
                $record['result'] = CommissionImportRow::NICHT_ZUGEORDNET;
                $record['message'] = mb_substr($conflict ?? ($match['note'] ?? 'Kein Vertrag gefunden.'), 0, 500);
                $counters['nicht_zugeordnet']++;
                CommissionImportRow::create($record);
                continue;
            }

            $record['contract_id'] = $contract->id;
            $record['match_reason'] = $match['reason'];

            if (isset($seen[$dedupe])) {
                $record['result'] = CommissionImportRow::DUPLIKAT;
                $record['message'] = 'Dieselbe Provision steht bereits in Zeile ' . $seen[$dedupe] . ' dieser Datei.';
                $counters['duplikat']++;
                CommissionImportRow::create($record);
                continue;
            }
            $seen[$dedupe] = $rowNumber;

            $existing[$dedupe] ??= ContractCommission::where('dedupe_key', $dedupe)->first();
            $known = $existing[$dedupe];

            if ($known === null) {
                $record['result'] = CommissionImportRow::NEU;
                $record['message'] = 'Wird angelegt und ' . $contract->contract_number . ' zugeordnet ('
                    . $match['reason'] . ').';
                $counters['neu']++;
            } elseif ($known->row_hash === $this->rowHash($mapped)) {
                $record['result'] = CommissionImportRow::DUPLIKAT;
                $record['message'] = 'Bereits importiert – unverändert (' . ($known->created_at?->format('d.m.Y') ?? '') . ').';
                $counters['duplikat']++;
            } else {
                $record['result'] = CommissionImportRow::AKTUALISIERT;
                $record['message'] = 'Vorhandene Provision wird aktualisiert (geänderte Angaben in der Datei).';
                $counters['aktualisiert']++;
            }

            CommissionImportRow::create($record);
        }

        $import->update([
            'rows_new' => $counters['neu'],
            'rows_updated' => $counters['aktualisiert'],
            'rows_duplicate' => $counters['duplikat'],
            'rows_unmatched' => $counters['nicht_zugeordnet'],
            'rows_invalid' => $counters['fehlerhaft'],
        ]);
    }

    /**
     * Rohzeile in Systemfelder deuten.
     *
     * @param array<int,string> $raw
     * @param array<string,int> $map
     * @return array<string,mixed>
     */
    public function mapRow(array $raw, array $map): array
    {
        $out = [];
        foreach ($map as $field => $index) {
            $value = TableFile::cell($raw, $index);
            $out[$field] = match (ColumnMap::type($field)) {
                'zahl' => ValueParser::amount($value),
                'datum' => ValueParser::date($value),
                default => ValueParser::text($value, $field === 'notes' ? 1000 : 190),
            };
            // Der Rohwert bleibt erhalten - er wird gebraucht, um "Datum
            // konnte nicht erkannt werden" von "Spalte war leer" zu
            // unterscheiden. Ohne diese Unterscheidung meldet der Import
            // leere Spalten als Fehler.
            $out['_raw_' . $field] = $value;
        }
        return $out;
    }

    /**
     * Zeile pruefen. Gemeldet wird in KLARTEXT und mit dem Wert, der nicht
     * gelesen werden konnte - "Datum ungültig" allein hilft niemandem beim
     * Korrigieren der Datei.
     *
     * @param array<string,mixed> $mapped
     * @return array<int,string>
     */
    public function validateRow(array $mapped): array
    {
        $errors = [];

        if (($mapped['amount'] ?? null) === null) {
            $rawAmount = trim((string) ($mapped['_raw_amount'] ?? ''));
            $errors[] = $rawAmount === ''
                ? 'Provisionsbetrag fehlt.'
                : 'Provisionsbetrag ist ungültig: „' . $rawAmount . '“.';
        }

        foreach (['commission_date' => 'Provisionsdatum', 'due_date' => 'Fälligkeitsdatum', 'payment_date' => 'Zahlungsdatum'] as $field => $label) {
            $raw = trim((string) ($mapped['_raw_' . $field] ?? ''));
            if ($raw !== '' && ($mapped[$field] ?? null) === null) {
                $errors[] = $label . ' konnte nicht erkannt werden: „' . $raw . '“.';
            }
        }

        $hasKey = false;
        foreach (ColumnMap::KEY_FIELDS as $field) {
            if (VermittlerReference::key(is_string($mapped[$field] ?? null) ? $mapped[$field] : null) !== null) {
                $hasKey = true;
                break;
            }
        }
        if (!$hasKey) {
            $errors[] = 'Die Zeile enthält keine verwertbare Kennung (Interne Vertragsnummer, Referenz-Nr., Id oder Auftr.-Nr.).';
        }

        return $errors;
    }

    /**
     * Natuerlicher Schluessel gegen Doppel-Import.
     *
     * Bestandteile sind bewusst genau diese: Kennung, Provisionsart, Datum,
     * Datensatz-Nr. der Quelle UND Betrag. Der BETRAG gehoert dazu, weil in
     * echten Abrechnungen zwei Positionen desselben Vertrags am selben Tag
     * mit derselben Art vorkommen (Teilbetraege je Faelligkeit) - ohne ihn
     * verschluckte der Import die zweite.
     *
     * @param array<string,mixed> $mapped
     */
    public function dedupeKey(array $mapped): string
    {
        $identity = null;
        foreach (ColumnMap::KEY_FIELDS as $field) {
            $key = VermittlerReference::key(is_string($mapped[$field] ?? null) ? $mapped[$field] : null);
            if ($key !== null) {
                $identity = $field . ':' . $key;
                break;
            }
        }

        $parts = [
            $identity ?? '',
            mb_strtolower((string) ($mapped['commission_type'] ?? '')),
            ($mapped['commission_date'] ?? null)?->format('Y-m-d') ?? '',
            VermittlerReference::key(is_string($mapped['external_id'] ?? null) ? $mapped['external_id'] : null) ?? '',
            number_format((float) ($mapped['amount'] ?? 0), 2, '.', ''),
        ];
        return hash('sha256', implode('|', $parts));
    }

    /**
     * Fingerabdruck ALLER Nutzdaten - unterscheidet "unveraendert" (Duplikat)
     * von "geaendert" (Aktualisierung). Der Status gehoert dazu: eine
     * Abrechnung, die dieselbe Position spaeter als storniert meldet, ist
     * genau der Fall, den ein erneuter Import erfassen soll.
     *
     * @param array<string,mixed> $mapped
     */
    public function rowHash(array $mapped): string
    {
        $values = [];
        foreach (ColumnMap::keys() as $field) {
            $value = $mapped[$field] ?? null;
            $values[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * Eine Zeile des Entwurfs in eine Provision ueberfuehren.
     *
     * @return string 'neu' | 'aktualisiert'
     */
    private function apply(CommissionImport $import, CommissionImportRow $row, ?int $userId): string
    {
        $mapped = $this->deserialize((array) $row->mapped);
        $contract = $row->contract_id ? Contract::with('customer.user')->find($row->contract_id) : null;

        $amount = $mapped['amount'] ?? null;
        $paid = $mapped['paid_amount'] ?? null;
        $status = ContractCommission::derive(
            is_string($mapped['status'] ?? null) ? $mapped['status'] : null,
            $mapped['payment_date'] ?? null,
            $mapped['due_date'] ?? null,
            $paid,
            $amount,
        );
        // Ein Stornogrund ohne Storno-Status ist ein Widerspruch. Er wird
        // NICHT stillschweigend zu "storniert" gemacht (das waere geraten) -
        // die Provision geht als "unklar" auf den Tisch des Admins.
        if (filled($mapped['storno_reason'] ?? null) && $status !== CommissionStatus::STORNIERT) {
            $status = CommissionStatus::UNKLAR;
        }

        $attributes = [
            'import_id' => $import->id,
            'internal_contract_number' => VermittlerReference::display($mapped['internal_contract_number'] ?? null),
            'internal_key' => VermittlerReference::key($mapped['internal_contract_number'] ?? null),
            'external_contract_number' => VermittlerReference::display($mapped['external_contract_number'] ?? null),
            'reference_number' => VermittlerReference::display($mapped['reference_number'] ?? null),
            'vermittler_id' => VermittlerReference::display($mapped['vermittler_id'] ?? null),
            'order_number' => VermittlerReference::display($mapped['order_number'] ?? null),
            'external_id' => VermittlerReference::display($mapped['external_id'] ?? null),
            'contract_id' => $contract?->id,
            'customer_id' => $contract?->customer_id,
            'contract_label' => $contract ? trim($contract->typeLabel() . ' · ' . ($contract->contract_number ?: $contract->reference_number ?: '')) : null,
            'customer_label' => ValueParser::text($contract?->customer?->user?->name ?? ($mapped['customer_name'] ?? null)),
            'match_status' => $contract ? ContractCommission::MATCH_ZUGEORDNET : ContractCommission::MATCH_OFFEN,
            'match_reason' => $row->match_reason,
            'recipient_name' => $mapped['recipient_name'] ?? null,
            'recipient_number' => $mapped['recipient_number'] ?? null,
            'commission_type' => $mapped['commission_type'] ?? null,
            'product_name' => $mapped['product_name'] ?? null,
            'company' => $mapped['company'] ?? null,
            'sparte' => ValueParser::text($mapped['sparte'] ?? null, 60),
            'amount' => $amount,
            'currency' => ValueParser::currency(is_string($mapped['currency'] ?? null) ? $mapped['currency'] : (string) ($mapped['_raw_amount'] ?? '')),
            'vat_amount' => $mapped['vat_amount'] ?? null,
            'reserve_amount' => $mapped['reserve_amount'] ?? null,
            'paid_amount' => $paid,
            'commission_date' => $mapped['commission_date'] ?? null,
            'due_date' => $mapped['due_date'] ?? null,
            'payment_date' => $mapped['payment_date'] ?? null,
            'status' => $status,
            'storno_reason' => ValueParser::text($mapped['storno_reason'] ?? null, 255),
            'invoice_number' => ValueParser::text($mapped['invoice_number'] ?? null, 60),
            'notes' => $mapped['notes'] ?? null,
            'source_file' => $import->filename,
            'dedupe_key' => $row->dedupe_key,
            'row_hash' => $this->rowHash($mapped),
            'updated_by' => $userId,
        ];

        $commission = ContractCommission::where('dedupe_key', $row->dedupe_key)->first();

        if ($commission === null) {
            $commission = ContractCommission::create($attributes + ['created_by' => $userId]);
            $this->audit->log('provision_angelegt', $commission, [
                'import_id' => $import->id,
                'new_value' => $commission->amountLabel() . ' · ' . CommissionStatus::label($status),
            ]);
            $outcome = 'neu';
        } else {
            $before = $commission->getOriginal();
            // Eine bereits erfasste ZAHLUNG wird von einer Datei nie
            // zurueckgenommen: der Betrieb weiss, dass das Geld da ist, die
            // Abrechnungsdatei kann aelter sein.
            if ($commission->status === CommissionStatus::BEZAHLT && $status !== CommissionStatus::STORNIERT) {
                unset($attributes['status'], $attributes['payment_date'], $attributes['paid_amount']);
            }
            $commission->fill($attributes)->save();
            $this->audit->changes('provision_aktualisiert', $commission, $before, $commission->getAttributes(), [
                'amount', 'status', 'payment_date', 'due_date', 'paid_amount', 'storno_reason', 'contract_id',
            ]);
            $outcome = 'aktualisiert';
        }

        if ($contract !== null) {
            // Die Bruecke dauerhaft machen: fehlende Kennungen am Vertrag
            // ergaenzen, damit die naechste Datei ohne Referenz-Nr. auskommt.
            foreach ($this->matcher->completeIdentifiers($contract, $mapped) as $filled) {
                $this->audit->log('vertragsnummer_geaendert', $commission, [
                    'contract_id' => $contract->id,
                    'field' => $filled['field'],
                    'old_value' => $filled['old'],
                    'new_value' => $filled['new'],
                    'import_id' => $import->id,
                ]);
            }
        }

        return $outcome;
    }

    /**
     * Datumswerte sind in JSON nicht selbstbeschreibend - sie werden beim
     * Ablegen in ein festes Format gebracht und beim Lesen wieder zu Carbon.
     *
     * @param array<string,mixed> $mapped
     * @return array<string,mixed>
     */
    private function serialize(array $mapped): array
    {
        return array_map(
            fn ($v) => $v instanceof \DateTimeInterface ? ['__date' => $v->format('Y-m-d')] : $v,
            $mapped
        );
    }

    /**
     * @param array<string,mixed> $mapped
     * @return array<string,mixed>
     */
    private function deserialize(array $mapped): array
    {
        return array_map(
            fn ($v) => is_array($v) && isset($v['__date'])
                ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $v['__date'])->startOfDay()
                : $v,
            $mapped
        );
    }
}
