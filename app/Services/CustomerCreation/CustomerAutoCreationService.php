<?php
namespace App\Services\CustomerCreation;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerNumberGenerator;
use App\Services\Matching\CustomerMatchingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automatische Kundenanlage bei fehlendem Match (Architekturplan
 * Abschnitt 6). Nutzt denselben CustomerNumberGenerator wie jede
 * andere Kundenanlage im System (Web-Formular, Self-Service, Import,
 * Lexoffice) - keine zweite Nummernvergabe, keine zweite Kundenliste.
 */
class CustomerAutoCreationService
{
    public function __construct(
        private readonly CustomerMatchingService $matcher,
        private readonly CustomerNumberGenerator $numberGenerator,
    ) {
    }

    /**
     * @param array{
     *     full_name?: ?string, first_name?: ?string, last_name?: ?string,
     *     email?: ?string, birth_date?: ?string, phone?: ?string,
     *     street?: ?string, zip?: ?string, city?: ?string, address?: ?string,
     * } $data
     *
     * @throws DuplicateCustomerException wenn trotz vermeintlich fehlendem
     *         Match doch ein Kandidat gefunden wird (Duplikatsschutz).
     */
    public function createFromUnmatched(array $data, string $source, ?int $createdBy = null): Customer
    {
        if (!in_array($source, Customer::SOURCES, true)) {
            throw new \InvalidArgumentException("Unbekannte Kundenquelle: $source");
        }

        // Sicherheitsnetz: auch hier nochmal prüfen statt dem Aufrufer blind zu vertrauen.
        $check = $this->matcher->match($data);
        if ($check->tier() !== 'manual') {
            throw new DuplicateCustomerException($check);
        }

        return DB::transaction(function () use ($data, $source, $createdBy) {
            $name = trim($data['full_name'] ?? trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')));
            $name = $name !== '' ? $name : 'Unbekannter Kunde';

            // Keine Platzhalter-/Dummy-E-Mail mehr: fehlt die echte Adresse,
            // bleibt das Feld LEER (NULL). Der Mitarbeiter erkennt so, bei
            // welchem Kunden die E-Mail noch nachzutragen ist; eine erfundene
            // .internal-Adresse hilft weder ihm noch dem Kunden.
            $email = $data['email'] ?? null;
            $user = User::create([
                'name' => $name,
                'email' => ($email !== null && $email !== '') ? $email : null,
                'password' => $this->placeholderPassword(),
                'role' => 'customer',
            ]);

            // Zusammengesetzte Alt-Adresse (Kompatibilität) aus strukturierten Feldern.
            $address = $data['address'] ?? trim(
                trim(($data['street'] ?? '') . ', ' . ($data['zip'] ?? '') . ' ' . ($data['city'] ?? '')),
                ', '
            );

            // Eindeutige Mobilnummer gehoert ins Feld "Handy" (mobile), nicht
            // ins Festnetz-Feld "Telefon" (z.B. die Handynummer aus dem
            // CHECK24-Beratungsprotokoll).
            $phone = $data['phone'] ?? null;
            $isMobile = $phone !== null && \App\Support\GermanPhone::isMobile($phone);

            // Nur gesetzte Felder übernehmen – so bleibt der Aufruf aus der
            // E-Mail-/Fonds-Pipeline (nur Basisdaten) unverändert, während der
            // Lexoffice-Import zusätzlich strukturierte Felder mitliefern kann.
            $attributes = array_filter([
                'birth_date'            => $data['birth_date'] ?? null,
                'phone'                 => $isMobile ? null : $phone,
                'mobile'                => $isMobile ? $phone : null,
                'email2'                => $data['email2'] ?? null,
                'gender'                => $data['gender'] ?? null,
                'address'               => $address !== '' ? $address : null,
                'address_street'        => $data['street'] ?? null,
                'address_house_number'  => $data['house_number'] ?? null,
                'address_zip'           => $data['zip'] ?? null,
                'address_city'          => $data['city'] ?? null,
                'company_name'          => $data['company_name'] ?? null,
                'company_type'          => $data['company_type'] ?? null,
                'customer_type'         => $data['customer_type'] ?? null,
                'iban'                  => $data['iban'] ?? null,
                'birth_place'           => $data['birth_place'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            // Importierte Kunden behalten ihre Quellnummer mit Jahrespräfix
            // ("25" + Originalnummer); Neuanlagen bekommen JJ+laufende Nummer.
            $number = !empty($data['import_number'])
                ? $this->numberGenerator->generateForImport((string) $data['import_number'])
                : $this->numberGenerator->generate();

            $customer = Customer::create(array_merge([
                'user_id'         => $user->id,
                'customer_number' => $number,
                'source'          => $source,
            ], $attributes));

            // Externe Kennungen (z. B. Lexoffice-Kundennummer) an ihren
            // dafür vorgesehenen Ort – nicht als interne Kundennummer.
            foreach ($data['external_references'] ?? [] as $ref) {
                if (empty($ref['type']) || empty($ref['value'])) {
                    continue;
                }
                $customer->externalReferences()->create([
                    'type'   => $ref['type'],
                    'value'  => (string) $ref['value'],
                    'source' => $ref['source'] ?? $source,
                ]);
            }

            ActivityLog::create([
                'user_id' => $createdBy,
                'action' => 'customer_auto_created',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                'meta' => json_encode(['source' => $source, 'name' => $name], JSON_UNESCAPED_UNICODE),
            ]);

            return $customer;
        });
    }

    /**
     * Unbrauchbares Startpasswort fuer eine automatisch angelegte Akte.
     *
     * WARUM GEMERKT UND NICHT JEDESMAL NEU: bcrypt ist mit Absicht teuer
     * (~230 ms bei Kostenstufe 12). Beim Einlesen einer Abrechnung entstehen
     * mehrere hundert Akten auf einmal - das waren gemessene 86 von 88
     * Sekunden Laufzeit, aufgewendet fuer ein Passwort, das per Konstruktion
     * NIE jemand benutzt: der Klartext ist zufaellig, wird nirgends
     * gespeichert, nicht ausgegeben und nicht verschickt. Den Zugang stellt
     * spaeter `PortalAccessService` her, und der setzt ueber
     * `User::setPassword()` ohnehin einen neuen Wert.
     *
     * Der Wert gilt nur fuer DIESEN Vorgang (eine Instanz des Dienstes);
     * ein neuer Lauf erzeugt einen neuen. Er wird bewusst nie
     * zurueckgegeben oder protokolliert.
     */
    private ?string $placeholderPassword = null;

    private function placeholderPassword(): string
    {
        return $this->placeholderPassword ??= bcrypt(Str::random(40));
    }

    /** Bestehende (z. B. bereits hochgeladene) Dokumente nachträglich mit dem neuen Kunden verknüpfen. */
    public function attachDocuments(Customer $customer, iterable $documents): void
    {
        foreach ($documents as $document) {
            $document->customer_id = $customer->id;
            $document->save();
        }
    }

    /** Bestehende (z. B. aus einer E-Mail/Fonds-Finanz-Meldung erzeugte) Verträge nachträglich verknüpfen. */
    public function attachContracts(Customer $customer, iterable $contracts): void
    {
        foreach ($contracts as $contract) {
            $contract->customer_id = $customer->id;
            $contract->save();
        }
    }

}
