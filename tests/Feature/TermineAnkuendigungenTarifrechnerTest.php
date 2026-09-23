<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\TarifrechnerLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KI-011 (Wissensbasis, 23.09.2026): Termine, Ankuendigungen und die
 * Linksammlung der Vergleichsportale hatten keine eigenen Funktionstests -
 * sie waren nur indirekt in Rechte- und Index-Tests beruehrt.
 *
 * Beim Schreiben der Tests fielen zwei echte Luecken auf (KI-019, KI-020):
 *  - JEDER Mitarbeiter konnte JEDE Ankuendigung loeschen, auch die der
 *    Leitung. Jetzt nur der Ersteller oder admin/manager.
 *  - Ein Termin nahm eine beliebige `assigned_to`-Kennung ungeprueft an;
 *    eine nicht existierende Nutzer-ID endete unter MySQL als 500er
 *    (Fremdschluessel). Jetzt validiert.
 */
class TermineAnkuendigungenTarifrechnerTest extends TestCase
{
    use RefreshDatabase;

    private function mitarbeiter(array $zusatz = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'employee',
            'can_see_all_customers' => false,
        ], $zusatz));
    }

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    private function zuweisen(User $mitarbeiter, Customer $kunde): void
    {
        DB::table('employee_customers')->insert([
            'user_id' => $mitarbeiter->id,
            'customer_id' => $kunde->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ Termine

    public function test_mitarbeiter_legt_termin_fuer_eigenen_kunden_an(): void
    {
        $ma = $this->mitarbeiter();
        $kunde = $this->kunde();
        $this->zuweisen($ma, $kunde);

        $this->actingAs($ma)->post(route('admin.appointments.store'), [
            'customer_id' => $kunde->id,
            'title' => 'Beratung Kfz',
            'starts_at' => now()->addDay()->format('Y-m-d H:i'),
            'ends_at' => now()->addDay()->addHour()->format('Y-m-d H:i'),
        ])->assertRedirect();

        $termin = Appointment::firstOrFail();
        $this->assertSame($ma->id, (int) $termin->assigned_to);
        $this->assertSame('scheduled', $termin->status);
        $this->assertDatabaseHas('customer_timeline', ['customer_id' => $kunde->id, 'type' => 'appointment']);
    }

    public function test_termin_fuer_fremden_kunden_wird_abgelehnt(): void
    {
        $ma = $this->mitarbeiter();
        $fremd = $this->kunde();

        $this->actingAs($ma)->post(route('admin.appointments.store'), [
            'customer_id' => $fremd->id,
            'title' => 'Fremd',
            'starts_at' => now()->addDay()->format('Y-m-d H:i'),
            'ends_at' => now()->addDay()->addHour()->format('Y-m-d H:i'),
        ])->assertForbidden();

        $this->assertSame(0, Appointment::count());
    }

    public function test_termin_mit_unbekanntem_zustaendigen_wird_abgelehnt(): void
    {
        $ma = $this->mitarbeiter();
        $kunde = $this->kunde();
        $this->zuweisen($ma, $kunde);

        $this->actingAs($ma)->post(route('admin.appointments.store'), [
            'customer_id' => $kunde->id,
            'assigned_to' => 999999,
            'title' => 'Beratung',
            'starts_at' => now()->addDay()->format('Y-m-d H:i'),
            'ends_at' => now()->addDay()->addHour()->format('Y-m-d H:i'),
        ])->assertSessionHasErrors('assigned_to');

        $this->assertSame(0, Appointment::count());
    }

    public function test_terminliste_zeigt_nur_eigene_kunden_und_status_ist_gescoped(): void
    {
        $ma = $this->mitarbeiter();
        $eigen = $this->kunde();
        $fremd = $this->kunde();
        $this->zuweisen($ma, $eigen);
        $admin = User::factory()->create(['role' => 'admin']);

        $eigenerTermin = Appointment::create(['customer_id' => $eigen->id, 'assigned_to' => $ma->id, 'title' => 'EIGENER-TERMIN',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'scheduled']);
        $fremderTermin = Appointment::create(['customer_id' => $fremd->id, 'assigned_to' => $admin->id, 'title' => 'FREMDER-TERMIN',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'scheduled']);

        $this->actingAs($ma)->get(route('admin.appointments'))
            ->assertOk()->assertSee('EIGENER-TERMIN')->assertDontSee('FREMDER-TERMIN');

        $this->actingAs($ma)->put(route('admin.appointments.update', $fremderTermin->id), ['status' => 'cancelled'])->assertForbidden();
        $this->actingAs($ma)->put(route('admin.appointments.update', $eigenerTermin->id), ['status' => 'completed'])->assertRedirect();

        $this->assertSame('scheduled', $fremderTermin->fresh()->status);
        $this->assertSame('completed', $eigenerTermin->fresh()->status);
    }

    // ------------------------------------------------------- Ankuendigungen

    public function test_mitarbeiter_legt_ankuendigung_an_und_sieht_sie(): void
    {
        $ma = $this->mitarbeiter();

        $this->actingAs($ma)->post(route('admin.announcements.store'), [
            'title' => 'Betriebsausflug',
            'body' => 'Am Freitag geschlossen.',
            'priority' => 'important',
        ])->assertRedirect();

        $this->assertDatabaseHas('announcements', ['title' => 'Betriebsausflug', 'created_by' => $ma->id, 'priority' => 'important']);
        $this->actingAs($ma)->get(route('admin.announcements'))->assertOk()->assertSee('Betriebsausflug');
    }

    public function test_unbekannte_prioritaet_wird_abgelehnt(): void
    {
        $this->actingAs($this->mitarbeiter())->post(route('admin.announcements.store'), [
            'title' => 'X', 'body' => 'Y', 'priority' => 'sofort',
        ])->assertSessionHasErrors('priority');

        $this->assertSame(0, Announcement::count());
    }

    public function test_mitarbeiter_kann_fremde_ankuendigung_nicht_loeschen(): void
    {
        $leitung = User::factory()->create(['role' => 'manager']);
        $ankuendigung = Announcement::create(['created_by' => $leitung->id, 'title' => 'Von der Leitung', 'body' => 'Wichtig', 'priority' => 'urgent']);

        $this->actingAs($this->mitarbeiter())
            ->delete(route('admin.announcements.destroy', $ankuendigung->id))
            ->assertForbidden();

        $this->assertNotNull($ankuendigung->fresh());
    }

    public function test_ersteller_und_leitung_duerfen_loeschen(): void
    {
        $ma = $this->mitarbeiter();
        $eigene = Announcement::create(['created_by' => $ma->id, 'title' => 'Eigene', 'body' => 'x', 'priority' => 'normal']);
        $this->actingAs($ma)->delete(route('admin.announcements.destroy', $eigene->id))->assertRedirect();
        $this->assertNull($eigene->fresh());

        $fremde = Announcement::create(['created_by' => $ma->id, 'title' => 'Fremde', 'body' => 'x', 'priority' => 'normal']);
        $this->actingAs(User::factory()->create(['role' => 'manager']))
            ->delete(route('admin.announcements.destroy', $fremde->id))->assertRedirect();
        $this->assertNull($fremde->fresh());
    }

    public function test_loeschknopf_nur_wo_geloescht_werden_darf(): void
    {
        $leitung = User::factory()->create(['role' => 'manager']);
        $ankuendigung = Announcement::create(['created_by' => $leitung->id, 'title' => 'Von der Leitung', 'body' => 'x', 'priority' => 'normal']);

        $this->actingAs($this->mitarbeiter())->get(route('admin.announcements'))
            ->assertOk()->assertDontSee(route('admin.announcements.destroy', $ankuendigung->id));
        $this->actingAs($leitung)->get(route('admin.announcements'))
            ->assertOk()->assertSee(route('admin.announcements.destroy', $ankuendigung->id));
    }

    // -------------------------------------------------------- Tarifrechner

    public function test_leitung_pflegt_links_und_reihenfolge(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager)->post(route('admin.tarifrechner.store'), [
            'category' => 'kfz', 'title' => 'Portal A', 'url' => 'https://a.example.org',
        ])->assertRedirect();
        $this->actingAs($manager)->post(route('admin.tarifrechner.store'), [
            'category' => 'kfz', 'title' => 'Portal B', 'url' => 'https://b.example.org',
        ])->assertRedirect();

        [$a, $b] = [TarifrechnerLink::where('title', 'Portal A')->first(), TarifrechnerLink::where('title', 'Portal B')->first()];
        $this->assertSame([0, 1], [(int) $a->sort_order, (int) $b->sort_order]);

        $this->actingAs($manager)->postJson(route('admin.tarifrechner.reorder'), ['order' => [(string) $b->id, (string) $a->id]])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame([1, 0], [(int) $a->fresh()->sort_order, (int) $b->fresh()->sort_order]);

        $this->actingAs($manager)->get(route('admin.tarifrechner'))->assertOk()->assertSee('Portal A');

        $this->actingAs($manager)->delete(route('admin.tarifrechner.destroy', $a->id))->assertRedirect();
        $this->assertNull($a->fresh());
    }

    public function test_link_wird_validiert(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('admin.tarifrechner.store'), [
            'category' => 'gibtesnicht', 'title' => 'X', 'url' => 'kein-link',
        ])->assertSessionHasErrors(['category', 'url']);

        $this->assertSame(0, TarifrechnerLink::count());
    }

    public function test_mitarbeiter_hat_keinen_zugriff_auf_die_linkpflege(): void
    {
        $ma = $this->mitarbeiter();
        $link = TarifrechnerLink::create(['category' => 'kfz', 'title' => 'Bleibt', 'url' => 'https://x.example.org', 'sort_order' => 0]);

        $this->actingAs($ma)->get(route('admin.tarifrechner'))->assertRedirect();
        $this->actingAs($ma)->delete(route('admin.tarifrechner.destroy', $link->id));

        $this->assertNotNull($link->fresh());
    }
}
