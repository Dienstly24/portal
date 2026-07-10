<?php
namespace App\Services;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerContact;
use App\Models\CustomerFamily;
use App\Models\Document;

/**
 * Wendet einen GENEHMIGTEN Change Request auf die echten Daten an.
 * Wird ausschließlich aus dem Admin-Review-Controller innerhalb einer
 * Transaktion aufgerufen. Jeder Typ arbeitet mit einer strikten
 * Feld-Whitelist - Werte aus new_data werden nie blind übernommen.
 */
class ChangeRequestService
{
    /**
     * Zentraler Einreichungspfad für ALLE Kundenänderungen:
     * legt den Change Request an, benachrichtigt admin/manager/support
     * über die interne Glocke und schreibt den Audit-Log-Eintrag.
     */
    public function submit(Customer $customer, string $type, ?array $oldData, array $newData, string $auditText, ?int $requestedBy = null): CustomerChangeRequest
    {
        $changeRequest = CustomerChangeRequest::create([
            'customer_id' => $customer->id,
            'requested_by' => $requestedBy ?? auth()->id(),
            'type' => $type,
            'old_data' => $oldData,
            'new_data' => $newData,
            'status' => 'pending',
        ]);

        $recipients = \App\Models\User::whereIn('role', ['admin', 'manager', 'support'])
            ->where('is_active', true)->get();
        foreach ($recipients as $recipient) {
            \App\Models\InternalNotification::create([
                'user_id' => $recipient->id,
                'change_request_id' => $changeRequest->id,
            ]);
        }

        \App\Models\ActivityLog::create([
            'user_id' => $requestedBy ?? auth()->id(),
            'action' => 'change_request_created',
            'entity_type' => 'change_request',
            'entity_id' => $changeRequest->id,
            'meta' => json_encode([
                'customer_id' => (string) $customer->id,
                'type' => $type,
                'text' => $auditText,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return $changeRequest;
    }

    public function apply(CustomerChangeRequest $request): void
    {
        $customer = Customer::findOrFail($request->customer_id);
        $data = $request->new_data ?? [];

        match ($request->type) {
            'family' => $this->applyFamily($customer, $data),
            'address' => $this->applyAddress($customer, $data),
            'email', 'phone' => $this->applyContact($customer, $request->type, $data),
            'bank' => $this->applyBank($customer, $data),
            'contract' => $this->applyContract($customer, $data),
            'profile' => $this->applyProfile($customer, $data),
            default => throw new \InvalidArgumentException('Unbekannter Change-Request-Typ: ' . $request->type),
        };
    }

    private function applyFamily(Customer $customer, array $data): void
    {
        $fields = [
            'name' => $data['name'] ?? null,
            'relation' => $data['relation'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
        ];

        if (!empty($data['id'])) {
            CustomerFamily::where('customer_id', $customer->id)
                ->where('id', $data['id'])
                ->firstOrFail()
                ->update(array_filter($fields, fn($v) => $v !== null));
        } else {
            CustomerFamily::create(['customer_id' => $customer->id] + $fields);
        }
    }

    private function applyAddress(Customer $customer, array $data): void
    {
        $fields = [
            'type' => $data['type'] ?? 'other',
            'street' => $data['street'] ?? '',
            'zip' => $data['zip'] ?? '',
            'city' => $data['city'] ?? '',
            'country' => $data['country'] ?? 'Deutschland',
        ];

        if (!empty($data['id'])) {
            CustomerAddress::where('customer_id', $customer->id)
                ->where('id', $data['id'])
                ->firstOrFail()
                ->update($fields);
        } else {
            CustomerAddress::create(['customer_id' => $customer->id] + $fields);
        }
    }

    private function applyContact(Customer $customer, string $type, array $data): void
    {
        $fields = [
            'type' => $type,
            'label' => $data['label'] ?? 'privat',
            'value' => $data['value'] ?? '',
        ];

        if (!empty($data['id'])) {
            CustomerContact::where('customer_id', $customer->id)
                ->where('id', $data['id'])
                ->firstOrFail()
                ->update($fields);
        } else {
            CustomerContact::create(['customer_id' => $customer->id] + $fields);
        }
    }

    private function applyBank(Customer $customer, array $data): void
    {
        $customer->update([
            'iban' => $data['iban'] ?? $customer->iban,
            'account_holder' => $data['account_holder'] ?? $customer->account_holder,
        ]);
    }

    private function applyContract(Customer $customer, array $data): void
    {
        $contract = Contract::create([
            'customer_id' => $customer->id,
            'type' => $data['type'] ?? 'andere',
            'insurer' => $data['insurer'] ?? '',
            'contract_number' => $data['contract_number'] ?? '', // Spalte ist NOT NULL
            // Gemeldete Verträge starten als 'pending' (In Bearbeitung),
            // damit das Team die Übernahme abschließen kann.
            'status' => 'pending',
        ]);

        if (!empty($data['document_path'])) {
            Document::create([
                'customer_id' => $customer->id,
                'category' => 'contract',
                'file_name' => $data['document_name'] ?? basename($data['document_path']),
                'file_path' => $data['document_path'],
                // Ältere, vor der Umstellung eingereichte Anträge liegen noch auf 'public'
                'disk' => $data['document_disk'] ?? 'public',
            ]);
        }
    }

    private function applyProfile(Customer $customer, array $data): void
    {
        // Strikte Whitelist unkritischer Profilfelder
        $allowed = ['gender', 'marital_status', 'nationality', 'occupation', 'address', 'phone', 'first_name', 'last_name', 'birth_date',
            'birth_place', 'address_street', 'address_house_number', 'address_house_suffix', 'address_zip', 'address_city',
            'health_insurance_number', 'pension_insurance_number', 'tax_id'];
        $update = array_intersect_key($data, array_flip($allowed));
        if (isset($update['gender']) && !array_key_exists($update['gender'], Customer::GENDERS)) {
            unset($update['gender']);
        }
        if ($update) {
            $customer->update($update);
        }
    }
}
