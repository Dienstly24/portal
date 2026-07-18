<?php
namespace App\Services\DocumentIntake;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\Document;
use App\Models\InternalNotification;
use App\Services\Matching\CustomerMatchingService;
use Illuminate\Support\Facades\Storage;

/**
 * Gemeinsame Logik des Smart Document Upload nach der KI-Analyse:
 * Kunden-Matching, Zuordnung eines Eingangs-Dokuments zu einem Kunden,
 * Uebernahme extrahierter Daten in die Kundenakte und Vertragsanlage/-
 * verknuepfung. Wird vom Analyse-Job (automatische Stufe) und von der
 * Mitarbeiter-Review-UI (Freigabe-Stufe) gleichermassen genutzt.
 *
 * Grundregeln:
 * - Extrahierte Daten fuellen nur LEERE Kundenfelder, nie bestehende.
 * - Automatisch zugeordnet wird nur bei eindeutigem Match (tier 'auto',
 *   Score > 90) - analog zur HITL-Logik des E-Mail-Postfachs.
 */
class DocumentIntakeService
{
    public function __construct(private readonly CustomerMatchingService $matcher)
    {
    }

    /** Match-Kriterien aus dem validierten Analyse-Ergebnis ableiten. */
    public function matchCriteria(array $extracted): array
    {
        $person = $extracted['person'] ?? [];
        return array_filter([
            'first_name' => $person['first_name'] ?? null,
            'last_name' => $person['last_name'] ?? null,
            'full_name' => trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')) ?: null,
            'birth_date' => $person['birth_date'] ?? null,
            'email' => $person['email'] ?? null,
            'phone' => $person['phone'] ?? null,
            'street' => $person['street'] ?? null,
            'house_number' => $person['house_number'] ?? null,
            'zip' => $person['zip'] ?? null,
            'city' => $person['city'] ?? null,
        ]);
    }

    /** Ausweis-Dokumente liefern die verlaesslichsten Personendaten. */
    private const IDENTITY_TYPES = ['personalausweis', 'reisepass'];
    private const LICENSE_TYPES = ['fuehrerschein'];

    /**
     * Analyse-Ergebnisse MEHRERER Dokumente eines Kunden zu einem Ergebnis
     * verschmelzen (Betreiber-Vorgabe: Hoheit je Feld nach Dokumenttyp):
     * - Person (Name/Geburtsdatum/Adresse): Ausweis-Dokumente zuerst.
     * - Bank/IBAN, Fahrzeug/Tarif, Gesundheit: jeweils erster nicht-leerer Wert.
     * Bewusst NICHT aus einem Beratungsprotokoll uebernommen: Fuehrerschein-
     * datum, weitere Fahrer (dort oft ungenau).
     *
     * Stimmen Name auf Ausweis und Fuehrerschein nicht ueberein, wird ein
     * Konflikt gemeldet (_conflicts) -> keine automatische Anlage.
     *
     * @param iterable<Document> $documents
     * @return array<string,mixed>
     */
    public function mergeExtractions(iterable $documents): array
    {
        $docs = collect($documents);
        $merged = ['person' => [], 'versicherung' => [], 'kfz' => [], 'gesundheit' => [], 'bank' => [], 'energie' => []];

        // Fuer Personendaten Ausweis-Dokumente zuerst; sonst Reihenfolge egal.
        $personFirst = $docs->sortByDesc(fn ($d) => $this->personPriority($d->ai_type));

        foreach (array_keys($merged) as $group) {
            $source = $group === 'person' ? $personFirst : $docs;
            foreach ($source as $doc) {
                $values = ($doc->ai_extracted[$group] ?? []);
                if (!is_array($values)) {
                    continue;
                }
                foreach ($values as $field => $value) {
                    if ($value === null || $value === '' || $value === []) {
                        continue;
                    }
                    $current = $merged[$group][$field] ?? null;
                    if ($current === null || $current === '') {
                        $merged[$group][$field] = $value;
                    }
                }
            }
        }

        $merged['_conflicts'] = $this->nameConflicts($docs);

        return $merged;
    }

    private function personPriority(?string $aiType): int
    {
        if (in_array($aiType, self::IDENTITY_TYPES, true)) {
            return 3;
        }
        if (in_array($aiType, self::LICENSE_TYPES, true)) {
            return 2;
        }
        return 1;
    }

    /**
     * Namens-Abgleich Ausweis vs. Fuehrerschein. Weichen die Namen ab, ist
     * die Zuordnung unsicher -> Mitarbeiter muss manuell pruefen.
     *
     * @return array<string,string>
     */
    private function nameConflicts(\Illuminate\Support\Collection $docs): array
    {
        $idName = null;
        $licenseName = null;
        foreach ($docs as $doc) {
            $person = $doc->ai_extracted['person'] ?? [];
            $name = $this->normalizeName(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (in_array($doc->ai_type, self::IDENTITY_TYPES, true)) {
                $idName ??= $name;
            } elseif (in_array($doc->ai_type, self::LICENSE_TYPES, true)) {
                $licenseName ??= $name;
            }
        }

        if ($idName !== null && $licenseName !== null && $idName !== $licenseName) {
            return ['name' => 'Name auf Ausweis und Fuehrerschein stimmen nicht ueberein - bitte manuell pruefen.'];
        }
        return [];
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^a-zäöüß ]/u', '', $name) ?? $name;
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Kunden zum Analyse-Ergebnis suchen.
     *
     * @return array{customer_id: string, name: ?string, customer_number: ?string, score: int, tier: string}|null
     */
    public function findMatch(array $extracted): ?array
    {
        $criteria = $this->matchCriteria($extracted);
        if ($criteria === []) {
            return null;
        }

        $result = $this->matcher->match($criteria);
        if (!$result->hasMatch() || $result->score < 40) {
            return null; // zu schwach, gar nicht erst anzeigen
        }

        return [
            'customer_id' => (string) $result->customer->id,
            'name' => $result->customer->user?->name,
            'customer_number' => $result->customer->customer_number,
            'score' => $result->score,
            'tier' => $result->tier(),
        ];
    }

    /**
     * Eingangs-Dokument einem Kunden zuordnen: Datei in den Kundenordner
     * verschieben, Zuordnung speichern, protokollieren. $auto = durch die
     * Analyse (eindeutiger Match), sonst durch einen Mitarbeiter.
     *
     * Die Uebernahme ist atomisch (UPDATE ... WHERE customer_id IS NULL):
     * pruefen zwei Mitarbeiter dasselbe Eingangs-Dokument gleichzeitig,
     * gewinnt genau einer - sonst koennte ein Dokument von Kunde B im
     * Dateiordner von Kunde A landen (und dessen DSGVO-Purge zum Opfer
     * fallen).
     *
     * @return bool false, wenn das Dokument inzwischen einem ANDEREN Kunden gehoert
     */
    public function assignToCustomer(Document $document, Customer $customer, ?int $byUserId, bool $auto = false): bool
    {
        $claimed = Document::whereKey($document->id)
            ->whereNull('customer_id')
            ->update(['customer_id' => $customer->id]);
        if (!$claimed) {
            $document->refresh();
            // Idempotent: derselbe Kunde ist ok, ein anderer nicht.
            return (string) $document->customer_id === (string) $customer->id;
        }
        $document->customer_id = $customer->id;

        $disk = $document->disk ?: 'local';
        $target = 'customers/' . $customer->id . '/documents/' . basename($document->file_path);
        if ($document->file_path !== $target && Storage::disk($disk)->exists($document->file_path)) {
            if (Storage::disk($disk)->exists($target)) {
                $target = 'customers/' . $customer->id . '/documents/' . uniqid() . '_' . basename($document->file_path);
            }
            Storage::disk($disk)->move($document->file_path, $target);
            $document->file_path = $target;
        }

        $document->save();

        ActivityLog::create([
            'user_id' => $byUserId,
            'action' => $auto ? 'document_auto_assigned' : 'document_assigned',
            'entity_type' => 'document',
            'entity_id' => $document->id,
            'meta' => json_encode([
                'customer_id' => (string) $customer->id,
                'file' => $document->file_name,
                'ai_type' => $document->ai_type,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if ($auto) {
            // Betreuer informieren, dass die KI ein Dokument zugeordnet hat.
            $recipients = $customer->betreuer()->get();
            if ($recipients->isEmpty()) {
                $recipients = \App\Models\User::whereIn('role', ['admin', 'manager'])->where('is_active', true)->get();
            }
            foreach ($recipients as $recipient) {
                InternalNotification::create([
                    'user_id' => $recipient->id,
                    'title' => 'Dokument automatisch zugeordnet: ' . ($document->aiTypeLabel() ?? $document->file_name),
                    'body' => 'Die KI-Analyse hat ein Dokument dem Kunden ' . ($customer->user?->name ?? $customer->customer_number) . ' zugeordnet.',
                    'link' => route('admin.customer', $customer->id) . '#tab-dokumente',
                ]);
            }
        }

        return true;
    }

    /**
     * Extrahierte Daten in LEERE Felder der Kundenakte uebernehmen.
     * $keys sind Gruppen-Schluessel aus der Review-UI; ohne Angabe wird
     * nichts uebernommen (Mitarbeiter entscheidet explizit).
     *
     * @param list<string> $keys z.B. ['birth_date','address','phone','health_insurance','iban','email2','nationality','birth_place']
     * @return list<string> tatsaechlich befuellte Kundenfelder
     */
    public function applyExtractedToCustomer(Document $document, Customer $customer, array $keys, ?int $byUserId, ?array $extracted = null): array
    {
        // $extracted erlaubt das Anwenden EINES aus mehreren Dokumenten
        // zusammengefuehrten Ergebnisses (mergeExtractions); ohne Angabe wird
        // das Analyse-Ergebnis des Dokuments selbst genutzt.
        $data = $extracted ?? ($document->ai_extracted ?? []);
        $person = $data['person'] ?? [];
        $health = $data['gesundheit'] ?? [];
        $bank = $data['bank'] ?? [];

        $updates = [];
        $set = function (string $attribute, $value) use ($customer, &$updates): void {
            if ($value !== null && $value !== '' && blank($customer->{$attribute})) {
                $updates[$attribute] = $value;
            }
        };

        foreach (array_unique($keys) as $key) {
            match ($key) {
                'birth_date' => $set('birth_date', $person['birth_date'] ?? null),
                'birth_place' => $set('birth_place', $person['birth_place'] ?? null),
                'phone' => $set('phone', $person['phone'] ?? null),
                'nationality' => $set('nationality', $person['nationality'] ?? null),
                'email2' => $set('email2', $person['email'] ?? null),
                'address' => (function () use ($set, $person): void {
                    $set('address_street', $person['street'] ?? null);
                    $set('address_house_number', $person['house_number'] ?? null);
                    $set('address_zip', $person['zip'] ?? null);
                    $set('address_city', $person['city'] ?? null);
                })(),
                'health_insurance' => (function () use ($set, $health): void {
                    $set('health_insurance_company', $health['health_insurance_company'] ?? null);
                    $set('health_insurance_number', $health['health_insurance_number'] ?? null);
                })(),
                'iban' => (function () use ($set, $bank): void {
                    $set('iban', $bank['iban'] ?? null);
                    $set('account_holder', $bank['account_holder'] ?? null);
                })(),
                default => null,
            };
        }

        if ($updates === []) {
            return [];
        }

        $customer->fill($updates)->save();

        ActivityLog::create([
            'user_id' => $byUserId,
            'action' => 'document_data_applied',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode([
                'document_id' => (string) $document->id,
                'fields' => array_keys($updates),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return array_keys($updates);
    }

    /**
     * Vertrag aus dem Analyse-Ergebnis anlegen (Mitarbeiter-Freigabe).
     * Existiert beim Kunden bereits ein Vertrag mit derselben
     * Vertragsnummer, wird dieser verknuepft statt ein Duplikat anzulegen.
     */
    public function createContractFromExtraction(Document $document, Customer $customer, ?int $byUserId, ?array $extracted = null): ?Contract
    {
        $data = $extracted ?? ($document->ai_extracted ?? []);
        $ins = $data['versicherung'] ?? [];
        $kfz = $data['kfz'] ?? [];
        $energie = $data['energie'] ?? [];

        if (blank($ins['insurer'] ?? null) && blank($ins['contract_number'] ?? null)) {
            return null;
        }

        if (!blank($ins['contract_number'] ?? null)) {
            $existing = Contract::where('customer_id', $customer->id)
                ->where('contract_number', $ins['contract_number'])->first();
            if ($existing) {
                $document->contract_id = $existing->id;
                $document->save();
                return $existing;
            }
        }

        $type = $ins['sparte']
            ?? ($document->ai_type === 'kfz_vertrag' ? 'kfz' : 'andere');

        $contract = Contract::create([
            'customer_id' => $customer->id,
            'contract_number' => $ins['contract_number'] ?? null,
            'type' => $type,
            'insurer' => $ins['insurer'] ?? null,
            'status' => 'active',
            'start_date' => $ins['start_date'] ?? null,
            'end_date' => $ins['end_date'] ?? null,
            'premium_amount' => $ins['premium_amount'] ?? null,
            'premium_interval' => $ins['premium_interval'] ?? 'monthly',
        ]);

        if ($type === 'kfz' && $kfz !== []) {
            ContractVehicleDetail::create(array_filter([
                'contract_id' => $contract->id,
                'license_plate' => $kfz['license_plate'] ?? null,
                'vin' => $kfz['vin'] ?? null,
                'hsn' => $kfz['hsn'] ?? null,
                'tsn' => $kfz['tsn'] ?? null,
                'manufacturer' => $kfz['manufacturer'] ?? null,
                'model' => $kfz['model'] ?? null,
                'first_registration' => $kfz['first_registration'] ?? null,
                // Zusaetzliche Tarif-/Fahrzeugfakten (validiert in
                // ValidatesExtractedFields::validatedVehicle).
                'has_teilkasko' => $kfz['has_teilkasko'] ?? null,
                'teilkasko_deductible' => $kfz['teilkasko_deductible'] ?? null,
                'has_vollkasko' => $kfz['has_vollkasko'] ?? null,
                'vollkasko_deductible' => $kfz['vollkasko_deductible'] ?? null,
                'holder_type' => $kfz['holder_type'] ?? null,
                'annual_mileage' => $kfz['annual_mileage'] ?? null,
            ], fn ($v) => $v !== null));
        }

        // Energie-Vertrag (Strom/Gas): Zaehler-/Tarifdaten aus dem Auftrag
        // bzw. Zaehlerfoto in die Energie-Detailtabelle uebernehmen.
        if (in_array($type, Contract::ENERGY_TYPES, true) && $energie !== []) {
            \App\Models\ContractEnergyDetail::create(array_filter([
                'contract_id' => $contract->id,
                'meter_number' => $energie['meter_number'] ?? null,
                'malo_id' => $energie['malo_id'] ?? null,
                'meter_reading' => $energie['meter_reading'] ?? null,
                'consumption_kwh' => $energie['consumption_kwh'] ?? null,
                'tariff' => $energie['tariff'] ?? null,
                'customer_number' => $energie['customer_number'] ?? null,
                'payment_amount' => $ins['premium_amount'] ?? null,
                'payment_interval' => $ins['premium_interval'] ?? null,
            ], fn ($v) => $v !== null));
        }

        $document->contract_id = $contract->id;
        $document->save();

        // Vertragsverlauf starten (Betreiber-Vorgabe: fuer alle Sparten).
        app(\App\Services\ContractHistoryService::class)->record([
            'customer_id' => (string) $customer->id,
            'contract_id' => (string) $contract->id,
            'branch' => $type,
            'provider' => $ins['insurer'] ?? null,
            'effective_from' => $ins['start_date'] ?? null,
            'reason' => 'initial',
            'source_document_id' => (string) $document->id,
            'created_by' => $byUserId,
        ]);

        ActivityLog::create([
            'user_id' => $byUserId,
            'action' => 'contract_created_from_document',
            'entity_type' => 'contract',
            'entity_id' => $contract->id,
            'meta' => json_encode([
                'document_id' => (string) $document->id,
                'customer_id' => (string) $customer->id,
                'type' => $type,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return $contract;
    }

    /**
     * Bestehenden Vertrag des Kunden anhand Vertragsnummer oder
     * KFZ-Kennzeichen verknuepfen (rein additive Automatik: nur
     * contract_id des Dokuments wird gesetzt).
     */
    public function linkMatchingContract(Document $document, Customer $customer): ?Contract
    {
        if ($document->contract_id) {
            return null;
        }
        $data = $document->ai_extracted ?? [];
        $number = $data['versicherung']['contract_number'] ?? null;
        $plate = $data['kfz']['license_plate'] ?? null;

        $contract = null;
        if (!blank($number)) {
            $contract = Contract::where('customer_id', $customer->id)
                ->where('contract_number', $number)->first();
        }
        if (!$contract && !blank($plate)) {
            // Nur Trennzeichen entfernen (wie die SQL-Normalisierung unten),
            // NICHT alle Nicht-ASCII-Zeichen - deutsche Kennzeichen mit
            // Umlaut-Ortskennung (WÜ, GÖ, KÖN, SÜW, ...) wuerden sonst nie
            // matchen, weil das alte \A-Z0-9\ das Ü/Ö einfach entfernte.
            $normalized = mb_strtoupper((string) preg_replace('/[\s\-]+/u', '', $plate));
            $contract = Contract::where('customer_id', $customer->id)
                ->whereHas('vehicleDetail', function ($q) use ($normalized) {
                    $q->whereRaw("replace(replace(upper(license_plate), '-', ''), ' ', '') = ?", [$normalized]);
                })->first();
        }

        if ($contract) {
            $document->contract_id = $contract->id;
            $document->save();
        }

        return $contract;
    }
}
