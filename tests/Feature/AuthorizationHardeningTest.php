<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressionstests fuer die Autorisierungs-Luecken aus dem System-Audit
 * (07.08.2026): Mitarbeiter/Support duerfen den Portfolio-Scope nicht
 * ueber Termine, Aufgaben oder die Mitarbeiter-Kundensuche umgehen.
 */
class AuthorizationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $name, string $number, array $extra = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);
        return Customer::create(array_merge([
            'user_id' => $user->id, 'customer_number' => $number,
            'gender' => 'male', 'preferred_lang' => 'de',
        ], $extra));
    }

    /* -------- Mitarbeiter-Kundensuche: Portfolio-weite PII-Suche gesperrt -------- */

    public function test_employee_cannot_reach_employee_customer_search(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $this->customer('Geheim Kunde', '2600001');

        // role:admin,manager leitet unberechtigte Staff-Rollen aufs Dashboard
        // um (kein JSON-Leak der Kundendaten).
        $response = $this->actingAs($employee)
            ->get(route('admin.employees.customer-search', ['q' => 'Geheim']));
        $response->assertRedirect(route('admin.dashboard'));
        $this->assertStringNotContainsString('Geheim Kunde', $response->getContent());
    }

    public function test_support_cannot_reach_employee_customer_search(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $this->actingAs($support)
            ->get(route('admin.employees.customer-search', ['q' => 'ab']))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_manager_can_reach_employee_customer_search(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->customer('Sichtbar Kunde', '2600002');

        $this->actingAs($manager)
            ->get(route('admin.employees.customer-search', ['q' => 'Sichtbar']))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Sichtbar Kunde']);
    }

    /* -------- Termine: Portfolio-Scope bei store/update -------- */

    public function test_employee_cannot_create_appointment_for_foreign_customer(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $foreign = $this->customer('Fremd Kunde', '2600003');

        $this->actingAs($employee)->post(route('admin.appointments.store'), [
            'customer_id' => (string) $foreign->id,
            'title' => 'Unerlaubter Termin',
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDay()->addHour()->toDateTimeString(),
        ])->assertForbidden();

        $this->assertDatabaseMissing('appointments', ['title' => 'Unerlaubter Termin']);
        $this->assertDatabaseMissing('customer_timeline', ['customer_id' => (string) $foreign->id]);
    }

    public function test_employee_can_create_appointment_for_own_customer(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $mine = $this->customer('Mein Kunde', '2600004');
        $mine->betreuer()->attach($employee->id);

        $this->actingAs($employee)->post(route('admin.appointments.store'), [
            'customer_id' => (string) $mine->id,
            'title' => 'Erlaubter Termin',
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDay()->addHour()->toDateTimeString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('appointments', ['title' => 'Erlaubter Termin']);
    }

    public function test_employee_cannot_update_foreign_appointment(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $foreign = $this->customer('Fremd Kunde 2', '2600005');
        $appointment = Appointment::create([
            'customer_id' => $foreign->id, 'assigned_to' => null,
            'title' => 'Fremd', 'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(), 'status' => 'scheduled',
        ]);

        $this->actingAs($employee)->put(route('admin.appointments.update', $appointment->id), [
            'status' => 'cancelled',
        ])->assertForbidden();

        $this->assertSame('scheduled', $appointment->fresh()->status);
    }

    /* -------- Aufgaben: IDOR bei update/destroy -------- */

    public function test_employee_cannot_delete_foreign_task(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $other = User::factory()->create(['role' => 'employee']);
        $task = Task::create([
            'assigned_to' => $other->id, 'created_by' => $other->id,
            'title' => 'Fremde Aufgabe', 'type' => 'other', 'status' => 'open', 'priority' => 'medium',
        ]);

        $this->actingAs($employee)->delete(route('admin.tasks.destroy', $task->id))
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_employee_cannot_quickclose_foreign_task(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $other = User::factory()->create(['role' => 'employee']);
        $task = Task::create([
            'assigned_to' => $other->id, 'created_by' => $other->id,
            'title' => 'Fremde Aufgabe 2', 'type' => 'other', 'status' => 'open', 'priority' => 'medium',
        ]);

        $this->actingAs($employee)->put(route('admin.tasks.update', $task->id), [
            'status' => 'done',
        ])->assertForbidden();

        $this->assertSame('open', $task->fresh()->status);
    }

    public function test_employee_can_close_own_task(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $task = Task::create([
            'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'title' => 'Eigene Aufgabe', 'type' => 'other', 'status' => 'open', 'priority' => 'medium',
        ]);

        $this->actingAs($employee)->put(route('admin.tasks.update', $task->id), [
            'status' => 'done',
        ])->assertRedirect();

        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_admin_can_delete_any_task(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'employee']);
        $task = Task::create([
            'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'title' => 'Beliebige Aufgabe', 'type' => 'other', 'status' => 'open', 'priority' => 'medium',
        ]);

        $this->actingAs($admin)->delete(route('admin.tasks.destroy', $task->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    /* -------- Mitarbeiter-Deaktivierung wirkt wirklich (kein still verworfenes Update) -------- */

    public function test_deactivating_employee_actually_persists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.employees.toggle', $employee->id))
            ->assertRedirect();

        // Kern des Audit-P0: der Kontostatus muss in der DB wirklich umschlagen.
        $this->assertFalse((bool) $employee->fresh()->is_active);

        // Und wieder aktivieren funktioniert ebenso.
        $this->actingAs($admin)->put(route('admin.employees.toggle', $employee->id))
            ->assertRedirect();
        $this->assertTrue((bool) $employee->fresh()->is_active);
    }

    // Audit AUTH-4: 'role' darf NICHT ueber Mass-Assignment gesetzt werden -
    // sonst waere ein kuenftiges User::create($request->all()) auf einer
    // kundenerreichbaren Route eine Rechte-Eskalation.
    public function test_role_is_not_mass_assignable(): void
    {
        // Anlegen mit role im Array -> role wird verworfen (nicht fillable).
        $user = User::create([
            'name' => 'Eskalation',
            'email' => 'esk@example.com',
            'password' => bcrypt('geheim-123'),
            'role' => 'admin',
        ]);
        $this->assertNotSame('admin', $user->fresh()->role);

        // Update mit role im Array -> ignoriert, andere Felder gehen durch.
        $customer = User::factory()->create(['role' => 'customer']);
        $customer->update(['role' => 'admin', 'name' => 'Geaendert']);
        $this->assertSame('customer', $customer->fresh()->role);
        $this->assertSame('Geaendert', $customer->fresh()->name);
    }
}
