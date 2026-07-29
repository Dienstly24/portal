<?php
namespace App\Services;

use App\Jobs\VerifyChangeRequestProofJob;
use App\Models\ActivityLog;
use App\Models\ChangeRequestDocument;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerContact;
use App\Models\CustomerFamily;
use App\Models\Document;
use App\Models\User;
use App\Services\ChangeRequest\ChangeProofPolicy;
use App\Services\ChangeRequest\InsurerNotificationBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Zentrale Stelle für Kundenänderungen: Einreichen (inkl. Nachweis),
 * Freigeben, Ablehnen und Anwenden auf die echten Daten.
 *
 * Jeder Typ arbeitet beim Anwenden mit einer strikten Feld-Whitelist -
 * Werte aus new_data werden nie blind übernommen.
 */
class ChangeRequestService
{
    /**
     * Zentraler Einreichungspfad für ALLE Kundenänderungen:
     * legt den Change Request an, speichert die Nachweise, benachrichtigt
     * admin/manager/support über die interne Glocke und schreibt den
     * Audit-Log-Eintrag. Liegen Nachweise vor, startet die automatische
     * Prüfung (ChangeProofVerifier) im Hintergrund.
     *
     * @param array<string, UploadedFile|null> $proofFiles Nachweise je Art (id_front, bank_proof, ...)
     * @param string|null $effectiveFrom "Gültig ab" - vom Kunden erfasst
     */
    public function submit(
        Customer $customer,
        string $type,
        ?array $oldData,
        array $newData,
        string $auditText,
        ?int $requestedBy = null,
        array $proofFiles = [],
        ?string $effectiveFrom = null,
    ): CustomerChangeRequest {
        $requiresProof = app(ChangeProofPolicy::class)->requiresProof($type, $newData);

        $changeRequest = CustomerChangeRequest::create([
            'customer_id' => $customer->id,
            'requested_by' => $requestedBy ?? auth()->id(),
            'type' => $type,
            'old_data' => $oldData,
            'new_data' => $newData,
            'status' => 'pending',
            'effective_from' => $effectiveFrom ?: null,
            'proof_status' => $requiresProof ? 'missing' : 'none',
        ]);

        $stored = $this->storeProofs($changeRequest, $customer, $proofFiles);
        if ($stored > 0) {
            $changeRequest->update(['proof_status' => 'pending']);
            VerifyChangeRequestProofJob::dispatch((string) $changeRequest->id);
        }

        $recipients = User::whereIn('role', ['admin', 'manager', 'support'])
            ->where('is_active', true)->pluck('id');
        \App\Support\Facades\Notify::pushMany($recipients, [
            'type' => \App\Services\Notifications\NotificationService::TYPE_CHANGE_REQUEST,
            'change_request_id' => $changeRequest->id,
            'dedup_key' => 'change-request-' . $changeRequest->id,
        ]);

        ActivityLog::create([
            'user_id' => $requestedBy ?? auth()->id(),
            'action' => 'change_request_created',
            'entity_type' => 'change_request',
            'entity_id' => $changeRequest->id,
            'meta' => json_encode([
                'customer_id' => (string) $customer->id,
                'type' => $type,
                'text' => $auditText,
                'proofs' => $stored,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return $changeRequest;
    }

    /**
     * Nachweise auf der PRIVATEN Disk ablegen: unter
     * customers/{id}/nachweise - dieses Verzeichnis wird bei einer
     * Kundenlöschung mitgelöscht (DSGVO) und ist nie per URL erreichbar.
     *
     * @param array<string, UploadedFile|null> $proofFiles
     */
    private function storeProofs(CustomerChangeRequest $request, Customer $customer, array $proofFiles): int
    {
        $stored = 0;
        foreach ($proofFiles as $kind => $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            ChangeRequestDocument::create([
                'change_request_id' => $request->id,
                'kind' => array_key_exists($kind, ChangeRequestDocument::KINDS) ? $kind : 'other',
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $file->store('customers/' . $customer->id . '/nachweise', 'local'),
                'disk' => 'local',
                'mime' => $file->getClientMimeType() ?: null,
                'size' => $file->getSize() ?: null,
                'check_status' => 'pending',
            ]);
            $stored++;
        }
        return $stored;
    }

    /**
     * Freigabe: wendet die Daten an, protokolliert, informiert den Kunden
     * und bereitet die Mitteilungen an die Gesellschaften vor.
     *
     * @param User|null $reviewer null = automatische Freigabe durch das System
     * @return array{ok: bool, notifications: int, error: string|null}
     */
    public function approve(CustomerChangeRequest $request, ?User $reviewer, ?string $notes = null, bool $auto = false): array
    {
        if ($request->status !== 'pending') {
            return ['ok' => false, 'notifications' => 0, 'error' => 'Diese Anfrage wurde bereits bearbeitet.'];
        }

        DB::transaction(function () use ($request, $reviewer, $notes, $auto) {
            // Erst anwenden - schlägt das fehl, bleibt der Antrag pending
            $this->apply($request);

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
                'notes' => $notes,
                'auto_approved' => $auto,
            ]);

            ActivityLog::create([
                'user_id' => $reviewer?->id,
                'action' => 'change_request_approved',
                'entity_type' => 'change_request',
                'entity_id' => $request->id,
                'meta' => json_encode([
                    'customer' => $request->customer?->user?->name,
                    'customer_id' => (string) $request->customer_id,
                    'type' => $request->type,
                    'type_label' => $request->typeLabel(),
                    'notes' => $notes,
                    'auto' => $auto,
                    'proof_status' => $request->proof_status,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        });

        $notifications = app(InsurerNotificationBuilder::class)->prepare($request);

        $this->notifyCustomer($request, 'approve', $notes);
        $this->notifyStaffAboutDecision($request, $notifications, $auto);

        return ['ok' => true, 'notifications' => $notifications, 'error' => null];
    }

    /** Ablehnung: nichts wird übernommen, der Kunde erfährt den Grund. */
    public function reject(CustomerChangeRequest $request, ?User $reviewer, ?string $notes = null): array
    {
        if ($request->status !== 'pending') {
            return ['ok' => false, 'notifications' => 0, 'error' => 'Diese Anfrage wurde bereits bearbeitet.'];
        }

        DB::transaction(function () use ($request, $reviewer, $notes) {
            $request->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
                'notes' => $notes,
            ]);

            ActivityLog::create([
                'user_id' => $reviewer?->id,
                'action' => 'change_request_rejected',
                'entity_type' => 'change_request',
                'entity_id' => $request->id,
                'meta' => json_encode([
                    'customer' => $request->customer?->user?->name,
                    'customer_id' => (string) $request->customer_id,
                    'type' => $request->type,
                    'type_label' => $request->typeLabel(),
                    'notes' => $notes,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        });

        $this->notifyCustomer($request, 'reject', $notes);

        return ['ok' => true, 'notifications' => 0, 'error' => null];
    }

    /** Statusmeldung an den Kunden (Portal-Glocke). */
    public function notifyCustomer(CustomerChangeRequest $request, string $action, ?string $notes): void
    {
        $userId = $request->customer?->user_id;
        if (!$userId) {
            return;
        }
        $approved = $action === 'approve';
        \App\Support\Facades\Notify::push($userId, [
            'type' => \App\Services\Notifications\NotificationService::TYPE_CHANGE_REQUEST,
            'title' => 'Änderungsanfrage ' . ($approved ? 'genehmigt' : 'abgelehnt'),
            'body' => 'Ihre Änderung (' . $request->typeLabel() . ') wurde '
                . ($approved ? 'genehmigt und übernommen.' : 'abgelehnt.' . ($notes ? ' Grund: ' . \Illuminate\Support\Str::limit($notes, 120) : '')),
            'link' => route('portal.change_requests'),
            'dedup_key' => 'change-request-decision-' . $request->id,
        ]);
    }

    /**
     * Nach der Freigabe: die Verwaltung erfährt, dass Daten geändert wurden
     * und wie viele Mitteilungen an Gesellschaften bereitliegen. Bei
     * automatischer Freigabe ist das die EINZIGE Stelle, an der ein Mensch
     * davon erfährt - deshalb immer, nicht nur bei Auffälligkeiten.
     */
    private function notifyStaffAboutDecision(CustomerChangeRequest $request, int $notifications, bool $auto): void
    {
        $recipients = User::whereIn('role', ['admin', 'manager', 'support'])
            ->where('is_active', true)->pluck('id');
        if ($recipients->isEmpty()) {
            return;
        }

        $name = $request->customer?->user?->name ?: 'Kunde';
        \App\Support\Facades\Notify::pushMany($recipients, [
            'type' => \App\Services\Notifications\NotificationService::TYPE_CHANGE_REQUEST,
            'title' => ($auto ? '✅ Automatisch übernommen: ' : '✅ Übernommen: ') . $request->typeLabel(),
            'body' => $name . ' – Daten aktualisiert.'
                . ($notifications > 0
                    ? ' ' . $notifications . ' Mitteilung(en) an Gesellschaften liegen zum Versand bereit.'
                    : ''),
            'link' => $notifications > 0
                ? route('admin.change_requests.notifications', $request->id)
                : route('admin.change_requests', ['status' => 'approved']),
            'change_request_id' => $request->id,
            'dedup_key' => 'change-request-applied-' . $request->id,
        ]);
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
        // Genehmigte LÖSCHUNG eines Familienmitglieds (Vier-Augen-Prinzip:
        // der Kunde beantragt, ein Mitarbeiter prüft und gibt frei).
        if (!empty($data['delete']) && !empty($data['id'])) {
            CustomerFamily::where('customer_id', $customer->id)
                ->where('id', $data['id'])
                ->firstOrFail()
                ->delete();
            return;
        }

        $fields = array_filter([
            'name' => $data['name'] ?? null,
            'relation' => $data['relation'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'gender' => $data['gender'] ?? null,
            'birth_place' => $data['birth_place'] ?? null,
            'health_insurance_number' => $data['health_insurance_number'] ?? null,
            'pension_insurance_number' => $data['pension_insurance_number'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
        ], fn($v) => $v !== null);

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
        // Aenderung an einem BESTEHENDEN Vertrag: der Kunde beantragt die
        // Anpassung ueber das Portal, ein Mitarbeiter prueft und gibt frei
        // (Vier-Augen-Prinzip). Nur eine strikte Feld-Whitelist wird
        // uebernommen - status und customer_id bleiben unberuehrt.
        if (!empty($data['id'])) {
            $contract = Contract::where('customer_id', $customer->id)
                ->where('id', $data['id'])->firstOrFail();

            $update = [];
            if (!empty($data['insurer'])) {
                $update['insurer'] = $data['insurer'];
            }
            if (isset($data['type']) && in_array($data['type'], Contract::typeKeys(), true)) {
                $update['type'] = $data['type'];
            }
            // Datumsfelder: leerer String -> NULL (Feld bewusst geleert)
            foreach (['start_date', 'end_date', 'cancellation_date'] as $dateField) {
                if (array_key_exists($dateField, $data)) {
                    $update[$dateField] = $data[$dateField] !== '' ? $data[$dateField] : null;
                }
            }
            if (array_key_exists('contract_number', $data)) {
                // NULL statt Leerstring wegen Unique-Index auf contract_number
                $update['contract_number'] = $data['contract_number'] !== '' ? $data['contract_number'] : null;
            }
            if (array_key_exists('notes', $data)) {
                $update['notes'] = $data['notes'] !== '' ? $data['notes'] : null;
            }

            if ($update) {
                $contract->update($update);
            }
            return;
        }

        $contract = Contract::create([
            'customer_id' => $customer->id,
            'type' => in_array($data['type'] ?? null, Contract::typeKeys(), true) ? $data['type'] : 'andere',
            'insurer' => $data['insurer'] ?? '',
            // NULL statt Leerstring: mehrere gemeldete Verträge ohne Nummer
            // würden sich am Unique-Index sonst gegenseitig blockieren.
            'contract_number' => !empty($data['contract_number']) ? $data['contract_number'] : null,
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

        // Name (Vor-/Nachname) und Login-E-Mail liegen auf dem User-Model und
        // werden getrennt uebernommen. Erst nach der Freigabe wirksam.
        $user = $customer->user;
        if ($user) {
            $userUpdate = [];
            if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data)) {
                $full = trim(trim((string) ($data['first_name'] ?? '')) . ' ' . trim((string) ($data['last_name'] ?? '')));
                if ($full !== '') {
                    $userUpdate['name'] = $full;
                }
            }
            // E-Mail nur uebernehmen, wenn gueltig und noch frei (Login bleibt
            // eindeutig; zwischen Antrag und Freigabe koennte sie belegt sein).
            if (!empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL)
                && !\App\Models\User::where('email', $data['email'])->where('id', '!=', $user->id)->exists()) {
                $userUpdate['email'] = $data['email'];
            }
            if ($userUpdate) {
                $user->update($userUpdate);
            }
        }
    }
}
