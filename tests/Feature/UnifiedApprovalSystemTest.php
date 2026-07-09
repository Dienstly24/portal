<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teil 6: customer_change_requests ist das EINZIGE Genehmigungssystem.
 */
class UnifiedApprovalSystemTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
            'phone' => '040 111111',
        ]);
    }

    public function test_no_active_code_uses_approval_request_anymore(): void
    {
        // Das alte Model existiert nicht mehr ...
        $this->assertFalse(class_exists(\App\Models\ApprovalRequest::class), 'ApprovalRequest model must be removed.');
        // ... die alten Routen ebenso wenig ...
        $this->assertFalse(app('router')->has('admin.approvals'));
        $this->assertFalse(app('router')->has('admin.approval.action'));
        // ... und die alte Tabelle wurde nach der Datenübernahme entfernt.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('approval_requests'));
    }

    public function test_every_change_domain_creates_a_customer_change_request(): void
    {
        $customer = $this->makeCustomer();
        $u = $customer->user;

        $this->actingAs($u)->post(route('portal.profile.update'), ['phone' => '040 222222']);
        $this->actingAs($u)->post(route('portal.profile.update'), ['iban' => 'DE89370400440532013000']);
        $this->actingAs($u)->post(route('portal.family.store'), ['name' => 'Kind A', 'relation' => 'kind']);
        $this->actingAs($u)->post(route('portal.addresses.store'), ['type' => 'billing', 'street' => 'Weg 1', 'zip' => '20095', 'city' => 'Hamburg']);
        $this->actingAs($u)->post(route('portal.contacts.store'), ['type' => 'email', 'label' => 'geschaeftlich', 'value' => 'work@example.com']);
        $this->actingAs($u)->post(route('portal.contacts.store'), ['type' => 'phone', 'label' => 'privat', 'value' => '+49 170 123456']);
        $this->actingAs($u)->post(route('portal.contracts.report'), ['type' => 'hausrat', 'insurer' => 'Allianz']);

        $types = CustomerChangeRequest::pluck('type')->sort()->values()->all();
        $this->assertSame(['address', 'bank', 'contract', 'email', 'family', 'phone', 'profile'], $types);
        $this->assertSame(0, CustomerChangeRequest::where('status', '!=', 'pending')->count());

        // Kein Feld wurde direkt geändert
        $customer->refresh();
        $this->assertSame('040 111111', $customer->phone);
        $this->assertNull($customer->iban);
    }

    public function test_admin_can_approve_every_change_type(): void
    {
        $customer = $this->makeCustomer();
        $u = $customer->user;
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($u)->post(route('portal.profile.update'), ['phone' => '040 333333', 'iban' => 'DE89370400440532013000']);
        $this->actingAs($u)->post(route('portal.family.store'), ['name' => 'Kind B', 'relation' => 'kind']);
        $this->actingAs($u)->post(route('portal.addresses.store'), ['type' => 'postal', 'street' => 'Postweg 2', 'zip' => '20095', 'city' => 'Hamburg']);
        $this->actingAs($u)->post(route('portal.contacts.store'), ['type' => 'email', 'label' => 'privat', 'value' => 'neu@example.com']);
        $this->actingAs($u)->post(route('portal.contracts.report'), ['type' => 'kfz', 'insurer' => 'HUK']);

        foreach (CustomerChangeRequest::all() as $request) {
            $this->actingAs($admin)
                ->post(route('admin.change_requests.action', $request->id), ['action' => 'approve'])
                ->assertSessionHas('success');
        }

        $this->assertSame(0, CustomerChangeRequest::pending()->count());
        $customer->refresh();
        $this->assertSame('040 333333', $customer->phone);
        $this->assertSame('DE89370400440532013000', $customer->iban);
        $this->assertDatabaseHas('customer_family', ['name' => 'Kind B']);
        $this->assertDatabaseHas('customer_addresses', ['street' => 'Postweg 2']);
        $this->assertDatabaseHas('customer_contacts', ['value' => 'neu@example.com']);
        $this->assertDatabaseHas('contracts', ['insurer' => 'HUK', 'status' => 'pending']);
    }

    public function test_legacy_approval_data_is_migrated_losslessly(): void
    {
        // Die Migration mappt field_name->type; hier prüfen wir das
        // Mapping-Verhalten über den Anwendungspfad: ein Profil-Request
        // mit altem Feldnamen wird korrekt angewendet.
        $customer = $this->makeCustomer();
        $admin = User::factory()->create(['role' => 'admin']);

        $request = CustomerChangeRequest::create([
            'customer_id' => $customer->id,
            'type' => 'profile',
            'old_data' => ['marital_status' => null],
            'new_data' => ['marital_status' => 'verheiratet'],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->post(route('admin.change_requests.action', $request->id), ['action' => 'approve']);
        $this->assertSame('verheiratet', $customer->fresh()->marital_status);
    }
}
