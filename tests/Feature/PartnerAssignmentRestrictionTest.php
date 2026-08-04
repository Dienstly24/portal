<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * partner_id steuert eine Datensicht (der zugeordnete Partner sieht den Kunden
 * in SEINEM Portal). Diese Zuordnung darf - analog zum Werber (acquired_by) -
 * NUR die Verwaltung (admin/manager) aendern. Ein Mitarbeiter (employee) mit
 * Portfolio-Zugriff darf den Kunden zwar bearbeiten, aber nicht heimlich einem
 * Partner zuschieben (kein stiller Datenabfluss).
 */
class PartnerAssignmentRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(?string $partnerId): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-PA-' . $user->id,
            'partner_id' => $partnerId,
        ]);
    }

    private function payload(array $o = []): array
    {
        return array_merge([
            'first_name' => 'Max', 'last_name' => 'Muster',
            'preferred_lang' => 'de', 'customer_type' => 'privat',
        ], $o);
    }

    public function test_employee_cannot_change_partner_assignment(): void
    {
        $partnerA = Partner::create(['name' => 'Partner A']);
        $partnerB = Partner::create(['name' => 'Partner B']);
        // Selbst ein Mitarbeiter mit voller Kundensicht bleibt an der
        // Partner-Zuordnung ausgesperrt - es haengt an der Rolle, nicht am
        // Portfolio-Zugriff (der hier bewusst gegeben ist, damit der Update
        // nicht schon an authorizeCustomerAccess scheitert).
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => true]);
        $customer = $this->makeCustomer($partnerA->id);

        $this->actingAs($employee)
            ->put(route('admin.customer.update', $customer->id), $this->payload([
                'partner_id' => $partnerB->id,
            ]))->assertRedirect();

        // Zuordnung unveraendert - der Mitarbeiter kann keine fremde Datensicht oeffnen.
        $this->assertSame($partnerA->id, $customer->fresh()->partner_id);
    }

    public function test_admin_can_change_partner_assignment(): void
    {
        $partnerA = Partner::create(['name' => 'Partner A']);
        $partnerB = Partner::create(['name' => 'Partner B']);
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer($partnerA->id);

        $this->actingAs($admin)
            ->put(route('admin.customer.update', $customer->id), $this->payload([
                'partner_id' => $partnerB->id,
            ]))->assertRedirect();

        $this->assertSame($partnerB->id, $customer->fresh()->partner_id);
    }
}
