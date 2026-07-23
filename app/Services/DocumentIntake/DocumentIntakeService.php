<?php
namespace App\Services\DocumentIntake;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\Document;
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
            // Firmenname (Gewerbekunde) - wird bei der Neuanlage uebernommen.
            'company_name' => $person['company_name'] ?? null,
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
            \App\Support\Facades\Notify::pushMany($recipients->pluck('id'), [
                'type' => \App\Services\Notifications\NotificationService::TYPE_DOCUMENT,
                'title' => 'Dokument automatisch zugeordnet: ' . ($document->aiTypeLabel() ?? $document->file_name),
                'body' => 'Die KI-Analyse hat ein Dokument dem Kunden ' . ($customer->user?->name ?? $customer->customer_number) . ' zugeordnet.',
                'link' => route('admin.customer', $customer->id) . '#tab-dokumente',
                'dedup_key' => 'doc-auto-' . $document->id,
            ]);
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

        // Die aus dem Dokument gelesene E-Mail ist die Kontaktadresse des
        // Kunden und soll - wenn moeglich - die HAUPT-Login-Adresse
        // (users.email) werden: erst damit laesst sich der Portal-Zugang
        // aktivieren und die Willkommens-/Portal-Mail versenden. Nur wenn der
        // Kunde bereits eine echte Haupt-Adresse hat ODER die Adresse schon
        // einem ANDEREN Nutzer gehoert (users.email ist unique), wandert sie
        // in die Zweitadresse (email2).
        $userEmail = null;
        $applyEmail = function (?string $email) use ($customer, &$updates, &$userEmail): void {
            $email = $email !== null ? trim($email) : null;
            if ($email === null || $email === '') {
                return;
            }
            $user = $customer->user;
            // Gehoert die Adresse bereits diesem Kunden (Haupt- oder Zweit-
            // adresse), ist nichts zu tun - keine Dopplung.
            if ($user !== null && strcasecmp((string) $user->email, $email) === 0) {
                return;
            }
            if (strcasecmp((string) $customer->email2, $email) === 0) {
                return;
            }
            $takenByOther = \App\Models\User::where('email', $email)
                ->when($customer->user_id, fn ($q) => $q->where('id', '!=', $customer->user_id))
                ->exists();
            if ($user !== null && !$user->hasRealEmail() && !$takenByOther) {
                // Haupt-Login-Adresse setzen -> aktiviert den Portal-Zugang.
                $userEmail = $email;
            } elseif (blank($customer->email2)) {
                // Fallback: als Zweitadresse hinterlegen (nur wenn noch leer).
                $updates['email2'] = $email;
            }
        };

        foreach (array_unique($keys) as $key) {
            match ($key) {
                'birth_date' => $set('birth_date', $person['birth_date'] ?? null),
                'birth_place' => $set('birth_place', $person['birth_place'] ?? null),
                // Eindeutige Mobilnummer gehoert ins Feld "Handy", nicht ins
                // Festnetz-Feld "Telefon" (z.B. die Handynummer aus dem
                // CHECK24-Beratungsprotokoll).
                'phone' => (function () use ($set, $person): void {
                    $phone = $person['phone'] ?? null;
                    if ($phone !== null && \App\Support\GermanPhone::isMobile($phone)) {
                        $set('mobile', $phone);
                    } else {
                        $set('phone', $phone);
                    }
                })(),
                'nationality' => $set('nationality', $person['nationality'] ?? null),
                'marital_status' => $set('marital_status', $person['marital_status'] ?? null),
                'gender' => $set('gender', $person['gender'] ?? null),
                // Bewusst KEIN reines email2: die gelesene Adresse soll primaer
                // die Haupt-Login-Adresse werden (Portal-Zugang), sonst email2.
                'email2' => $applyEmail($person['email'] ?? null),
                'address' => (function () use ($set, $person): void {
                    $set('address_street', $person['street'] ?? null);
                    $set('address_house_number', $person['house_number'] ?? null);
                    $set('address_zip', $person['zip'] ?? null);
                    $set('address_city', $person['city'] ?? null);
                })(),
                'health_insurance' => (function () use ($set, $health): void {
                    $set('health_insurance_company', $health['health_insurance_company'] ?? null);
                    $set('health_insurance_number', $health['health_insurance_number'] ?? null);
                    $set('health_insurance_type', $health['health_insurance_type'] ?? null);
                    // Renten-/Sozialversicherungsnummer (aus der Beitrittserklaerung).
                    $set('pension_insurance_number', $health['pension_number'] ?? null);
                })(),
                'iban' => (function () use ($set, $bank): void {
                    $set('iban', $bank['iban'] ?? null);
                    $set('account_holder', $bank['account_holder'] ?? null);
                })(),
                default => null,
            };
        }

        if ($updates === [] && $userEmail === null) {
            return [];
        }

        $applied = [];
        if ($userEmail !== null && $customer->user !== null) {
            // Haupt-Login-Adresse am User setzen (aktiviert den Portal-Zugang).
            $customer->user->forceFill(['email' => $userEmail])->save();
            $applied[] = 'email';
        }
        if ($updates !== []) {
            $customer->fill($updates)->save();
            $applied = array_merge($applied, array_keys($updates));
        }

        ActivityLog::create([
            'user_id' => $byUserId,
            'action' => 'document_data_applied',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode([
                'document_id' => (string) $document->id,
                'fields' => $applied,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return $applied;
    }

    /**
     * Vertrag aus dem Analyse-Ergebnis anlegen ODER - wenn bereits ein
     * passender Vertrag existiert - diesen aktualisieren (Mitarbeiter-Freigabe).
     *
     * Betreiber-Vorgabe (23.07.2026): Ein neu importiertes Dokument fuer ein
     * bereits erfasstes Fahrzeug/eine bereits erfasste Police erzeugt KEIN
     * Duplikat mehr. Zuerst wird anhand der Vertrags-Identitaet
     * (Vertragsnummer, FIN/VIN, Kennzeichen, Energie-Zaehler/MaLo) ein
     * bestehender Vertrag gesucht. Trifft einer zu, wird nur er aktualisiert
     * und jede geaenderte Angabe in der Version History (ContractRevision)
     * festgehalten. Nur wenn kein passender Vertrag existiert, wird ein neuer
     * angelegt. So sieht der Kunde genau EINEN Vertrag je Fahrzeug (Single
     * Source of Truth) mit vollstaendigem Aenderungsverlauf.
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

        // Duplikat-Schutz: passenden Bestandsvertrag anhand der Identitaet
        // suchen und stattdessen aktualisieren (mit Audit-Log).
        $existing = $this->findExistingContractByIdentity($customer, $data);
        if ($existing) {
            return $this->updateContractFromExtraction($existing, $document, $customer, $byUserId, $data);
        }

        $type = $ins['sparte']
            ?? ($document->ai_type === 'kfz_vertrag' ? 'kfz' : 'andere');

        // Bei der gesetzlichen Krankenversicherung den Subtyp 'gkv' setzen -
        // erst damit greift die 12-Monats-Wechsel-Erinnerung (§175 SGB V) im
        // ContractSwitchReminderService (Bindungsfrist ab Mitgliedsbeginn).
        $healthType = ($data['gesundheit'] ?? [])['health_insurance_type'] ?? null;
        $subtype = $type === 'krankenversicherung'
            ? match ($healthType) {
                'gesetzlich' => 'gkv',
                'privat' => 'pkv',
                default => null,
            }
            : null;

        // E-Scooter: Einmalbeitrag als Standard-Zahlweise (kein laufender
        // Beitrag). Contract::saving erzwingt zudem den Saison-Ablauf.
        $defaultInterval = $type === 'escooter' ? 'einmalig' : 'monthly';

        $contract = Contract::create([
            'customer_id' => $customer->id,
            'contract_number' => $ins['contract_number'] ?? null,
            'type' => $type,
            'subtype' => $subtype,
            'insurer' => $ins['insurer'] ?? null,
            'status' => 'active',
            'start_date' => $ins['start_date'] ?? null,
            'end_date' => $ins['end_date'] ?? null,
            'premium_amount' => $ins['premium_amount'] ?? null,
            'premium_interval' => $ins['premium_interval'] ?? $defaultInterval,
        ]);

        // Fahrzeug-Detaildaten fuer KFZ und E-Scooter (beide nutzen die
        // Fahrzeugtabelle). Beim E-Scooter wird der Fahrzeugtyp gesetzt, damit
        // Anzeige und Bearbeitung ihn als E-Scooter erkennen.
        if (in_array($type, ['kfz', 'escooter'], true) && $kfz !== []) {
            ContractVehicleDetail::create(array_filter([
                'contract_id' => $contract->id,
                'vehicle_type' => $type === 'escooter' ? 'escooter' : ($kfz['vehicle_type'] ?? null),
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
                // Zusatzleistungen (z.B. Werkstattbindung/Schutzbrief) aus dem
                // Beratungsprotokoll - Schluessel bereits gegen den Katalog
                // validiert (ValidatesExtractedFields::validatedVehicle).
                'extras' => !empty($kfz['extras']) ? $kfz['extras'] : null,
                // Vorversicherung (bisheriger Kfz-Versicherer beim Wechsel).
                'previous_insurer' => $ins['previous_insurer'] ?? null,
                'previous_insurance_since' => $ins['previous_insurance_since'] ?? null,
                'previous_insurance_terminated_by_insurer' => $ins['previous_insurance_terminated'] ?? null,
                // Schadenfreiheitsklassen (z.B. aus der ADAC-Beitragsinformation
                // oder dem CHECK24-Protokoll) - inkl. Sondereinstufung: die
                // gewaehrte Klasse ist dann NICHT uebertragbar, die echte
                // (uebertragbare) Klasse steht in sf_liability_real_class.
                'sf_liability_class' => $kfz['sf_liability_class'] ?? null,
                'sf_liability_type' => $kfz['sf_liability_type'] ?? null,
                'sf_liability_special_reason' => $kfz['sf_liability_special_reason'] ?? null,
                'sf_liability_real_class' => $kfz['sf_liability_real_class'] ?? null,
                'sf_comprehensive_class' => $kfz['sf_comprehensive_class'] ?? null,
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
                // Vorversorger (bisheriger Lieferant beim Wechsel) + dessen
                // Kundennummer - aus dem Strom-/Gas-Auftrag.
                'previous_provider' => $energie['previous_provider'] ?? null,
                'previous_customer_number' => $energie['previous_customer_number'] ?? null,
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
     * Bestehenden Vertrag des Kunden anhand der Vertrags-Identitaet
     * verknuepfen (rein additive Automatik: nur contract_id des Dokuments
     * wird gesetzt, KEINE Feldaenderung - das bleibt der Freigabe-Stufe
     * vorbehalten). Nutzt dieselbe Identitaets-Suche wie der Duplikat-Schutz
     * beim Anlegen.
     */
    public function linkMatchingContract(Document $document, Customer $customer): ?Contract
    {
        if ($document->contract_id) {
            return null;
        }

        $contract = $this->findExistingContractByIdentity($customer, $document->ai_extracted ?? []);
        if ($contract) {
            $document->contract_id = $contract->id;
            $document->save();
        }

        return $contract;
    }

    /**
     * Bestehenden Vertrag des Kunden anhand der Vertrags-Identitaet suchen -
     * Grundlage des Duplikat-Schutzes. Geprueft wird (in dieser Reihenfolge,
     * jeweils streng):
     *   1. Vertragsnummer (Vertragsnummer)
     *   2. Fahrzeug-Identnummer (FIN/VIN)
     *   3. Kennzeichen (normalisiert)
     *   4. Energie: MaLo-ID bzw. Zaehlernummer
     * Der erste Treffer gewinnt. Bewusst nur harte Identitaetsmerkmale, damit
     * nicht faelschlich zwei verschiedene Vertraege verschmolzen werden.
     */
    public function findExistingContractByIdentity(Customer $customer, array $data): ?Contract
    {
        $ins = $data['versicherung'] ?? [];
        $kfz = $data['kfz'] ?? [];
        $energie = $data['energie'] ?? [];

        // 1. Vertragsnummer
        if (!blank($ins['contract_number'] ?? null)) {
            $byNumber = Contract::where('customer_id', $customer->id)
                ->where('contract_number', $ins['contract_number'])->first();
            if ($byNumber) {
                return $byNumber;
            }
        }

        // 2. FIN/VIN (nur Leerzeichen entfernen, Grossschreibung normalisieren)
        if (!blank($kfz['vin'] ?? null)) {
            $vin = mb_strtoupper((string) preg_replace('/\s+/u', '', $kfz['vin']));
            $byVin = Contract::where('customer_id', $customer->id)
                ->whereHas('vehicleDetail', function ($q) use ($vin) {
                    $q->whereRaw("replace(upper(vin), ' ', '') = ?", [$vin]);
                })->first();
            if ($byVin) {
                return $byVin;
            }
        }

        // 3. Kennzeichen (Trennzeichen entfernen, Umlaut-Ortskennung erhalten)
        if (!blank($kfz['license_plate'] ?? null)) {
            $plate = mb_strtoupper((string) preg_replace('/[\s\-]+/u', '', $kfz['license_plate']));
            $byPlate = Contract::where('customer_id', $customer->id)
                ->whereHas('vehicleDetail', function ($q) use ($plate) {
                    $q->whereRaw("replace(replace(upper(license_plate), '-', ''), ' ', '') = ?", [$plate]);
                })->first();
            if ($byPlate) {
                return $byPlate;
            }
        }

        // 4. Energie: MaLo-ID (11-stellig, eindeutig) bzw. Zaehlernummer
        foreach (['malo_id', 'meter_number'] as $field) {
            if (!blank($energie[$field] ?? null)) {
                $byMeter = Contract::where('customer_id', $customer->id)
                    ->whereHas('energyDetail', function ($q) use ($field, $energie) {
                        $q->where($field, $energie[$field]);
                    })->first();
                if ($byMeter) {
                    return $byMeter;
                }
            }
        }

        return null;
    }

    /**
     * Bestehenden Vertrag aus einem neu importierten Dokument aktualisieren:
     * geaenderte Sachfelder (Beitrag, Beginn/Ende, Deckung, Zusatzleistungen
     * ...) werden uebernommen und jede Aenderung in der Version History
     * (ContractRevision) mit altem und neuem Wert protokolliert. Leere neue
     * Werte ueberschreiben nie einen bestehenden (kein Datenverlust);
     * Zusatzleistungen werden ergaenzt, nie entfernt.
     */
    private function updateContractFromExtraction(Contract $contract, Document $document, Customer $customer, ?int $byUserId, array $data): Contract
    {
        $ins = $data['versicherung'] ?? [];
        $kfz = $data['kfz'] ?? [];
        $energie = $data['energie'] ?? [];

        $recorder = app(ContractRevisionRecorder::class);
        $ctx = [
            'source' => 'document',
            'source_document_id' => (string) $document->id,
            'changed_by' => $byUserId,
            'batch_id' => $recorder->newBatchId(),
        ];

        // ---- Vertragsstammdaten -------------------------------------------
        $contractProposed = [
            'insurer' => $ins['insurer'] ?? null,
            'start_date' => $ins['start_date'] ?? null,
            'end_date' => $ins['end_date'] ?? null,
            'premium_amount' => $ins['premium_amount'] ?? null,
            'premium_interval' => $ins['premium_interval'] ?? null,
        ];
        // Vertragsnummer nur ERGAENZEN, wenn bislang leer und noch nicht
        // anderweitig vergeben (unique) - nie eine bestehende ueberschreiben.
        $newNumber = $ins['contract_number'] ?? null;
        if (blank($contract->contract_number) && !blank($newNumber)
            && !Contract::where('contract_number', $newNumber)->where('id', '!=', $contract->id)->exists()) {
            $contractProposed['contract_number'] = $newNumber;
        }
        $changed = $recorder->apply($contract, $contract, $contractProposed, $this->contractRevisionSpec(), $ctx);

        // ---- Fahrzeug-Detaildaten (KFZ / E-Scooter) -----------------------
        if (in_array($contract->type, ['kfz', 'escooter'], true) && $kfz !== []) {
            $veh = $contract->vehicleDetail
                ?: ContractVehicleDetail::create(['contract_id' => $contract->id]);

            $vehProposed = [
                'license_plate' => $kfz['license_plate'] ?? null,
                'vin' => $kfz['vin'] ?? null,
                'hsn' => $kfz['hsn'] ?? null,
                'tsn' => $kfz['tsn'] ?? null,
                'manufacturer' => $kfz['manufacturer'] ?? null,
                'model' => $kfz['model'] ?? null,
                'first_registration' => $kfz['first_registration'] ?? null,
                'has_teilkasko' => $kfz['has_teilkasko'] ?? null,
                'teilkasko_deductible' => $kfz['teilkasko_deductible'] ?? null,
                'has_vollkasko' => $kfz['has_vollkasko'] ?? null,
                'vollkasko_deductible' => $kfz['vollkasko_deductible'] ?? null,
                'holder_type' => $kfz['holder_type'] ?? null,
                'annual_mileage' => $kfz['annual_mileage'] ?? null,
                'sf_liability_class' => $kfz['sf_liability_class'] ?? null,
                'sf_comprehensive_class' => $kfz['sf_comprehensive_class'] ?? null,
                'previous_insurer' => $ins['previous_insurer'] ?? null,
            ];
            // Zusatzleistungen ERGAENZEN (nie entfernen): so geht z.B. ein
            // bereits erfasster Schutzbrief nicht verloren, wenn ihn ein
            // spaeteres Dokument nicht erneut auffuehrt.
            if (!empty($kfz['extras'])) {
                $vehProposed['extras'] = array_values(array_unique(
                    array_merge($veh->extras ?? [], $kfz['extras'])
                ));
            }
            // Feste Fahrzeug-Identitaets-/Stammfelder nur ERGAENZEN, wenn leer -
            // eine abweichende Schreibweise (z.B. "S-AB 1234" vs "S-AB1234")
            // ist keine echte Aenderung und darf den Bestand nicht ueberschreiben.
            foreach (['license_plate', 'vin', 'hsn', 'tsn', 'manufacturer', 'model', 'first_registration'] as $static) {
                if (filled($veh->{$static})) {
                    unset($vehProposed[$static]);
                }
            }
            $changed = array_merge($changed, $recorder->apply($contract, $veh, $vehProposed, $this->vehicleRevisionSpec(), $ctx));
        }

        // ---- Energie-Detaildaten (Strom / Gas) ----------------------------
        if (in_array($contract->type, Contract::ENERGY_TYPES, true) && $energie !== []) {
            $en = $contract->energyDetail
                ?: \App\Models\ContractEnergyDetail::create(['contract_id' => $contract->id]);

            $enProposed = [
                'tariff' => $energie['tariff'] ?? null,
                'consumption_kwh' => $energie['consumption_kwh'] ?? null,
                'meter_number' => $energie['meter_number'] ?? null,
                'malo_id' => $energie['malo_id'] ?? null,
                'meter_reading' => $energie['meter_reading'] ?? null,
                'customer_number' => $energie['customer_number'] ?? null,
                'payment_amount' => $ins['premium_amount'] ?? null,
                'previous_provider' => $energie['previous_provider'] ?? null,
            ];
            // Physische Zaehler-Identitaet nur ergaenzen, wenn leer (nie eine
            // bestehende MaLo-ID/Zaehlernummer durch eine Schreibvariante ersetzen).
            foreach (['malo_id', 'meter_number'] as $static) {
                if (filled($en->{$static})) {
                    unset($enProposed[$static]);
                }
            }
            $changed = array_merge($changed, $recorder->apply($contract, $en, $enProposed, $this->energyRevisionSpec(), $ctx));
        }

        // Dokument mit dem (aktualisierten) Vertrag verknuepfen.
        if (!$document->contract_id) {
            $document->contract_id = $contract->id;
            $document->save();
        }

        ActivityLog::create([
            'user_id' => $byUserId,
            'action' => 'contract_updated_from_document',
            'entity_type' => 'contract',
            'entity_id' => $contract->id,
            'meta' => json_encode([
                'document_id' => (string) $document->id,
                'customer_id' => (string) $customer->id,
                'changed_fields' => $changed,
                'batch_id' => $ctx['batch_id'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return $contract;
    }

    /** Anzeige-Spezifikation (Label + Formatter) der Vertragsstammfelder. */
    private function contractRevisionSpec(): array
    {
        return [
            'insurer' => ['label' => 'Versicherer'],
            'contract_number' => ['label' => 'Vertragsnummer'],
            'start_date' => ['label' => 'Vertragsbeginn', 'format' => [$this, 'fmtDate']],
            'end_date' => ['label' => 'Vertragsende', 'format' => [$this, 'fmtDate']],
            'premium_amount' => ['label' => 'Beitrag', 'format' => [$this, 'fmtEuro']],
            'premium_interval' => ['label' => 'Zahlweise', 'format' => [$this, 'fmtInterval']],
        ];
    }

    /** Anzeige-Spezifikation der Fahrzeug-Detailfelder. */
    private function vehicleRevisionSpec(): array
    {
        return [
            'license_plate' => ['label' => 'Kennzeichen'],
            'vin' => ['label' => 'FIN'],
            'hsn' => ['label' => 'HSN'],
            'tsn' => ['label' => 'TSN'],
            'manufacturer' => ['label' => 'Hersteller'],
            'model' => ['label' => 'Modell'],
            'first_registration' => ['label' => 'Erstzulassung', 'format' => [$this, 'fmtDate']],
            'has_teilkasko' => ['label' => 'Teilkasko'],
            'teilkasko_deductible' => ['label' => 'SB Teilkasko', 'format' => [$this, 'fmtDeductible']],
            'has_vollkasko' => ['label' => 'Vollkasko'],
            'vollkasko_deductible' => ['label' => 'SB Vollkasko', 'format' => [$this, 'fmtDeductible']],
            'holder_type' => ['label' => 'Halter'],
            'annual_mileage' => ['label' => 'Jahresfahrleistung', 'format' => [$this, 'fmtKm']],
            'sf_liability_class' => ['label' => 'SF-Klasse Haftpflicht'],
            'sf_comprehensive_class' => ['label' => 'SF-Klasse Vollkasko'],
            'previous_insurer' => ['label' => 'Vorversicherer'],
            'extras' => ['label' => 'Zusatzleistungen', 'format' => [$this, 'fmtExtras']],
        ];
    }

    /** Anzeige-Spezifikation der Energie-Detailfelder. */
    private function energyRevisionSpec(): array
    {
        return [
            'tariff' => ['label' => 'Tarif'],
            'consumption_kwh' => ['label' => 'Verbrauch', 'format' => [$this, 'fmtKwh']],
            'meter_number' => ['label' => 'Zaehlernummer'],
            'malo_id' => ['label' => 'MaLo-ID'],
            'meter_reading' => ['label' => 'Zaehlerstand'],
            'customer_number' => ['label' => 'Kundennummer (Anbieter)'],
            'payment_amount' => ['label' => 'Abschlag', 'format' => [$this, 'fmtEuro']],
            'previous_provider' => ['label' => 'Vorversorger'],
        ];
    }

    public function fmtEuro($v): string
    {
        return number_format((float) $v, 2, ',', '.') . ' €';
    }

    public function fmtDate($v): string
    {
        try {
            return \Carbon\Carbon::parse($v)->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $v;
        }
    }

    public function fmtInterval($v): string
    {
        return Contract::PREMIUM_INTERVALS[$v]['label'] ?? (string) $v;
    }

    public function fmtDeductible($v): string
    {
        return ContractVehicleDetail::deductibleLabel((int) $v);
    }

    public function fmtKm($v): string
    {
        return number_format((int) $v, 0, ',', '.') . ' km';
    }

    public function fmtKwh($v): string
    {
        return number_format((int) $v, 0, ',', '.') . ' kWh';
    }

    public function fmtExtras($v): string
    {
        $keys = (array) $v;
        $labels = array_values(array_intersect_key(ContractVehicleDetail::EXTRAS, array_flip($keys)));
        return implode(', ', $labels ?: $keys);
    }
}
