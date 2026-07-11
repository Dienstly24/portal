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

            $user = User::create([
                'name' => $name,
                'email' => $data['email'] ?? $this->placeholderEmail(),
                'password' => bcrypt(Str::random(32)),
                'role' => 'customer',
            ]);

            $address = $data['address'] ?? trim(
                trim(($data['street'] ?? '') . ', ' . ($data['zip'] ?? '') . ' ' . ($data['city'] ?? '')),
                ', '
            );

            $customer = Customer::create([
                'user_id' => $user->id,
                'customer_number' => $this->numberGenerator->generate(),
                'source' => $source,
                'birth_date' => $data['birth_date'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $address !== '' ? $address : null,
            ]);

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

    /**
     * Platzhalter-Adresse für automatisch angelegte Kunden ohne bekannte
     * E-Mail (z. B. reine Fonds-Finanz-Papierpost). Die .internal-Domain
     * ist bereits Konvention im System (siehe dienstly_mailable() in
     * routes/console.php) - keine echten Mails gehen an diese Adresse.
     */
    private function placeholderEmail(): string
    {
        return 'import-' . Str::uuid() . '@dienstly24.internal';
    }
}
