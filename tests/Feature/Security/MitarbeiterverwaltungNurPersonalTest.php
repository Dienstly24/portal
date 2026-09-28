<?php

namespace Tests\Feature\Security;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Substitution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Mitarbeiterverwaltung fasst nur Personal-Konten an
 * (System-Audit 28.09.2026, KI-040).
 *
 * Vorher lud sie JEDES Konto ueber die ID: ein Manager konnte ein
 * Kundenkonto sperren (sonst admin-only) oder ihm die Rolle manager geben,
 * ein Admin eine Kundenakte am CustomerDeletionService vorbei loeschen.
 */
class MitarbeiterverwaltungNurPersonalTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(): User
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => uniqid().'@kunde.de']);
        Customer::create(['user_id' => $user->id, 'customer_number' => 'K-'.uniqid()]);

        return $user;
    }

    public function test_manager_macht_aus_einem_kundenkonto_keinen_manager(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $kunde = $this->kunde();

        $this->actingAs($manager)->put(route('admin.employees.update', $kunde->id), [
            'name' => 'Befoerdert', 'role' => 'manager',
        ])->assertNotFound();

        $this->assertSame('customer', $kunde->fresh()->role);
    }

    public function test_manager_sperrt_kein_kundenkonto_ueber_die_mitarbeiterverwaltung(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $kunde = $this->kunde();

        $this->actingAs($manager)->put(route('admin.employees.toggle', $kunde->id))->assertNotFound();

        $this->assertNotFalse($kunde->fresh()->is_active);
    }

    public function test_admin_loescht_keine_kundenakte_ueber_die_mitarbeiterverwaltung(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde();

        $this->actingAs($admin)->delete(route('admin.employees.destroy', $kunde->id))->assertNotFound();

        $this->assertNotNull($kunde->fresh());
        $this->assertSame(1, Customer::where('user_id', $kunde->id)->count());
    }

    public function test_mitarbeiter_bleiben_verwaltbar(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'name' => 'Alt']);

        $this->actingAs($manager)->put(route('admin.employees.update', $mitarbeiter->id), [
            'name' => 'Neu', 'role' => 'employee',
        ])->assertRedirect();

        $this->assertSame('Neu', $mitarbeiter->fresh()->name);
    }

    public function test_postfach_weist_keine_unterhaltung_an_ein_kundenkonto_zu(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde();
        $unterhaltung = Conversation::create([
            'channel_id' => Channel::where('key', 'portal')->firstOrFail()->id,
            'customer_id' => Customer::where('user_id', $kunde->id)->value('id'),
            'status' => Conversation::STATUS_OPEN,
        ]);

        $this->actingAs($admin)->post(route('admin.postfach.reassign', $unterhaltung->id), [
            'employee_id' => $kunde->id,
        ])->assertSessionHas('error');

        $this->assertNull($unterhaltung->fresh()->assigned_employee_id);
    }

    public function test_ein_kundenkonto_wird_kein_vertreter(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        $kunde = $this->kunde();

        $this->actingAs($manager)->post(route('admin.team.substitution.store'), [
            'absent_user_id' => $mitarbeiter->id, 'substitute_user_id' => $kunde->id,
            'from_date' => now()->toDateString(), 'to_date' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('substitute_user_id');

        $this->assertSame(0, Substitution::count());
    }
}
