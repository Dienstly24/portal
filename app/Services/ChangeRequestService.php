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
            'contract_number' => $data['contract_number'] ?? null,
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
            ]);
        }
    }

    private function applyProfile(Customer $customer, array $data): void
    {
        // Strikte Whitelist unkritischer Profilfelder
        $allowed = ['gender', 'marital_status', 'nationality', 'occupation'];
        $update = array_intersect_key($data, array_flip($allowed));
        if (isset($update['gender']) && !array_key_exists($update['gender'], Customer::GENDERS)) {
            unset($update['gender']);
        }
        if ($update) {
            $customer->update($update);
        }
    }
}
