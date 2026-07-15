<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerFamily;
use App\Models\InternalMessage;
use App\Models\InternalNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
            'iban' => 'DE00ALTALTALTALTALT00',
        ]);
    }

    // Test 1: Kunde kann Familienmitglied beantragen
    public function test_customer_can_request_family_member(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.family.store'), [
            'name' => 'Lisa Mustermann',
            'relation' => 'kind',
            'birth_date' => '2018-05-01',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('customer_change_requests', [
            'customer_id' => (string) $customer->id,
            'type' => 'family',
            'status' => 'pending',
        ]);
        // Kein echter Datensatz vor der Genehmigung!
        $this->assertSame(0, CustomerFamily::count());
    }

    // Test 2: Kunde kann Bankänderung beantragen
    public function test_customer_can_request_bank_change(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000',
            'account_holder' => 'Max Mustermann',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('customer_change_requests', [
            'customer_id' => (string) $customer->id,
            'type' => 'bank',
            'status' => 'pending',
        ]);
    }

    // Test 3: Kunde kann keine direkte Änderung durchführen
    public function test_customer_request_does_not_change_data_directly(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000',
            'account_holder' => 'Max Mustermann',
        ]);

        // IBAN unverändert, solange nicht genehmigt
        $this->assertSame('DE00ALTALTALTALTALT00', $customer->fresh()->iban);

        // Admin-Endpunkte sind für Kunden unerreichbar
        $this->actingAs($customer->user)
            ->put(route('admin.customer.update', $customer->id), ['first_name' => 'Hack'])
            ->assertRedirect(route('portal.dashboard'));
    }

    // Test 4: Kunde kann keine Verträge löschen
    public function test_customer_cannot_delete_contracts(): void
    {
        $customer = $this->makeCustomer();
        $contract = Contract::create([
            'customer_id' => $customer->id,
            'type' => 'kfz', 'insurer' => 'Allianz', 'status' => 'active',
            'contract_number' => 'KFZ-999',
        ]);

        // Es existiert bewusst keine Portal-Lösch-Route; der Admin-Endpunkt
        // (Kunde löschen inkl. Verträge) leitet Kunden weg.
        $this->actingAs($customer->user)
            ->delete(route('admin.customers.delete', $customer->id))
            ->assertRedirect(route('portal.dashboard'));

        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        // Ausnahme: portal.family.delete ist KEINE direkte Löschung, sondern
        // erzeugt nur einen Change Request (Löschung erst nach Admin-Freigabe).
        $portalRoutes = collect(app('router')->getRoutes()->getRoutesByName())
            ->keys()->filter(fn($n) => str_starts_with($n, 'portal.'))
            ->reject(fn($n) => $n === 'portal.family.delete');
        $this->assertTrue(
            $portalRoutes->filter(fn($n) => str_contains($n, 'delete') || str_contains($n, 'destroy'))->isEmpty(),
            'Das Portal darf keine direkten Lösch-Routen anbieten.'
        );
    }

    // Test 5: Mitarbeiter sieht offene Änderungsanfragen (+ Scoping)
    public function test_staff_sees_pending_requests_scoped_to_portfolio(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000', 'account_holder' => 'Max',
        ]);

        // Admin sieht die Anfrage
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.change_requests'))
            ->assertOk()->assertSee('Bankverbindung');

        // Zugewiesener Mitarbeiter sieht sie
        $assigned = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $assigned->assignedCustomers()->attach((string) $customer->id);
        $this->actingAs($assigned)->get(route('admin.change_requests'))
            ->assertOk()->assertSee('Bankverbindung');

        // Nicht zugewiesener Mitarbeiter sieht sie NICHT
        $unassigned = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $this->actingAs($unassigned)->get(route('admin.change_requests'))
            ->assertOk()->assertDontSee('Bankverbindung');
    }

    // Test 6 + 7: Admin kann genehmigen; nach Genehmigung werden Daten aktualisiert
    public function test_admin_approval_applies_bank_change(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000', 'account_holder' => 'Max Mustermann',
        ]);
        $request = CustomerChangeRequest::first();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $request->id), [
            'action' => 'approve',
        ])->assertSessionHas('success');

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);

        // Daten wurden erst JETZT übernommen
        $customer->refresh();
        $this->assertSame('DE89370400440532013000', $customer->iban);
        $this->assertSame('Max Mustermann', $customer->account_holder);

        // Audit-Log: "Admin ... genehmigte ..."
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'change_request_approved',
            'user_id' => $admin->id,
        ]);
    }

    public function test_approval_of_family_request_creates_family_member(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.family.store'), [
            'name' => 'Lisa Mustermann', 'relation' => 'kind', 'birth_date' => '2018-05-01',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', CustomerChangeRequest::first()->id), [
            'action' => 'approve',
        ]);

        $this->assertDatabaseHas('customer_family', [
            'customer_id' => (string) $customer->id,
            'name' => 'Lisa Mustermann',
            'relation' => 'kind',
        ]);
    }

    public function test_rejection_does_not_apply_data_and_stores_note(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000', 'account_holder' => 'Max',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', CustomerChangeRequest::first()->id), [
            'action' => 'reject', 'notes' => 'IBAN nicht verifizierbar.',
        ]);

        $this->assertSame('DE00ALTALTALTALTALT00', $customer->fresh()->iban);
        $this->assertDatabaseHas('customer_change_requests', ['status' => 'rejected', 'notes' => 'IBAN nicht verifizierbar.']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'change_request_rejected']);
    }

    // Test 8: Kunde sieht keine internen Notizen (auch auf den neuen Seiten)
    public function test_customer_never_sees_internal_notes_on_self_service_pages(): void
    {
        $customer = $this->makeCustomer();
        $admin = User::factory()->create(['role' => 'admin']);
        InternalMessage::create([
            'customer_id' => $customer->id, 'sender_id' => $admin->id,
            'message' => 'STRENG-INTERN-GEHEIM', 'type' => 'note',
        ]);

        foreach (['portal.family', 'portal.addresses', 'portal.contacts', 'portal.bank', 'portal.change_requests'] as $routeName) {
            $this->actingAs($customer->user)->get(route($routeName))
                ->assertOk()->assertDontSee('STRENG-INTERN-GEHEIM');
        }
    }

    // --- Zusätzliche Sicherheits- und Workflow-Fälle ---

    public function test_customers_cannot_see_or_review_other_customers_requests(): void
    {
        $victim = $this->makeCustomer();
        $this->actingAs($victim->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000', 'account_holder' => 'Opfer',
        ]);
        $request = CustomerChangeRequest::first();

        $attacker = $this->makeCustomer();
        // Fremde Anfragen tauchen in der eigenen Liste nicht auf
        // ('Bankverbindung' steht auch in der Sidebar, daher gezielt auf die Liste prüfen)
        $this->actingAs($attacker->user)->get(route('portal.change_requests'))
            ->assertOk()->assertSee('noch keine Änderungsanfragen');
        // Kunden erreichen den Review-Endpunkt nie
        $this->actingAs($attacker->user)
            ->post(route('admin.change_requests.action', $request->id), ['action' => 'approve'])
            ->assertRedirect(route('portal.dashboard'));
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_unassigned_employee_cannot_review_foreign_request(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000', 'account_holder' => 'Max',
        ]);
        $request = CustomerChangeRequest::first();

        $unassigned = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $this->actingAs($unassigned)
            ->post(route('admin.change_requests.action', $request->id), ['action' => 'approve'])
            ->assertForbidden();
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_new_request_notifies_admin_manager_support(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'manager']);
        $support = User::factory()->create(['role' => 'support']);
        $employee = User::factory()->create(['role' => 'employee']);

        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000', 'account_holder' => 'Max',
        ]);

        foreach ([$admin, $manager, $support] as $u) {
            $this->assertDatabaseHas('internal_notifications', ['user_id' => $u->id]);
        }
        // Punkt 10: Empfänger sind admin/manager/support - nicht employee
        $this->assertDatabaseMissing('internal_notifications', ['user_id' => $employee->id]);
    }

    public function test_already_reviewed_request_cannot_be_reviewed_again(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000', 'account_holder' => 'Max',
        ]);
        $request = CustomerChangeRequest::first();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $request->id), ['action' => 'reject']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $request->id), ['action' => 'approve'])
            ->assertSessionHas('error');

        // Ablehnung bleibt bestehen, Daten unverändert
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame('DE00ALTALTALTALTALT00', $customer->fresh()->iban);
    }

    public function test_customer_can_request_contract_change_and_admin_approval_applies_it(): void
    {
        $customer = $this->makeCustomer();
        $contract = Contract::create([
            'customer_id' => $customer->id,
            'type' => 'kfz', 'insurer' => 'Allianz', 'status' => 'active',
            'contract_number' => 'KFZ-100',
        ]);

        // Kunde beantragt eine Aenderung an seinem eigenen Vertrag
        $this->actingAs($customer->user)->post(route('portal.contracts.change', $contract->id), [
            'type' => 'kfz',
            'insurer' => 'HUK-Coburg',
            'contract_number' => 'KFZ-200',
            'notes' => 'Bitte Tarif pruefen.',
        ])->assertSessionHas('success');

        // Vor der Freigabe bleibt der Vertrag unveraendert
        $this->assertSame('Allianz', $contract->fresh()->insurer);

        $request = CustomerChangeRequest::where('type', 'contract')->firstOrFail();
        $this->assertSame('pending', $request->status);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $request->id), [
            'action' => 'approve',
        ])->assertSessionHas('success');

        // Jetzt ist die Aenderung uebernommen - KEIN neuer Vertrag entstanden
        $contract->refresh();
        $this->assertSame('HUK-Coburg', $contract->insurer);
        $this->assertSame('KFZ-200', $contract->contract_number);
        $this->assertSame('Bitte Tarif pruefen.', $contract->notes);
        $this->assertSame(1, Contract::count());
    }

    public function test_customer_cannot_request_change_on_foreign_contract(): void
    {
        $owner = $this->makeCustomer();
        $contract = Contract::create([
            'customer_id' => $owner->id,
            'type' => 'kfz', 'insurer' => 'Allianz', 'status' => 'active',
        ]);

        $attacker = $this->makeCustomer();
        $this->actingAs($attacker->user)->post(route('portal.contracts.change', $contract->id), [
            'type' => 'kfz', 'insurer' => 'Fremd',
        ])->assertNotFound();

        $this->assertSame(0, CustomerChangeRequest::count());
    }

    public function test_customer_can_assign_uploaded_document_to_own_contract(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $customer = $this->makeCustomer();
        $contract = Contract::create([
            'customer_id' => $customer->id,
            'type' => 'rechtsschutz', 'insurer' => 'ARAG', 'status' => 'active',
        ]);

        $this->actingAs($customer->user)->post(route('portal.documents.upload'), [
            'category' => 'contract',
            'contract_id' => $contract->id,
            'document' => \Illuminate\Http\UploadedFile::fake()->create('vertrag.pdf', 200, 'application/pdf'),
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('documents', [
            'customer_id' => (string) $customer->id,
            'contract_id' => (string) $contract->id,
            'category' => 'contract',
        ]);
    }

    public function test_document_cannot_be_assigned_to_foreign_contract(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $owner = $this->makeCustomer();
        $foreign = Contract::create([
            'customer_id' => $owner->id,
            'type' => 'kfz', 'insurer' => 'Allianz', 'status' => 'active',
        ]);

        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.documents.upload'), [
            'category' => 'other',
            'contract_id' => $foreign->id,
            'document' => \Illuminate\Http\UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg'),
        ])->assertSessionHas('success');

        // Datei wurde hochgeladen, aber NICHT dem fremden Vertrag zugeordnet
        $this->assertDatabaseHas('documents', [
            'customer_id' => (string) $customer->id,
            'contract_id' => null,
        ]);
    }

    public function test_contract_report_creates_pending_contract_on_approval(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.contracts.report'), [
            'type' => 'kfz', 'insurer' => 'HUK', 'contract_number' => 'KFZ-123',
        ])->assertSessionHas('success');

        $this->assertSame(0, Contract::count());

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', CustomerChangeRequest::first()->id), [
            'action' => 'approve',
        ]);

        $this->assertDatabaseHas('contracts', [
            'customer_id' => (string) $customer->id,
            'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'pending',
        ]);
    }
}
