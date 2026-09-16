<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Substitution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EINE Regel fuer "wer sieht welchen Kunden" (Audit 15.09.2026).
 *
 * VORGESCHICHTE: In User standen DREI Regeln nebeneinander, und sie
 * waren nicht deckungsgleich:
 *   - canSeeAllCustomers()    : admin ODER manager ODER Flag
 *   - getAccessibleCustomers(): admin ODER Flag  -- manager FEHLTE
 *   - canSeeCustomer()        : admin ODER Flag, ohne Vertretung (tot)
 *
 * Nur der Kundenchat benutzte getAccessibleCustomers(). Ein MANAGER sah
 * dort deshalb NULL Unterhaltungen, waehrend ihm die Kundenliste alle
 * Kunden zeigte - ohne Fehlermeldung, ohne 403, einfach eine leere
 * Seite. Aus demselben Grund fehlten einem VERTRETER die Unterhaltungen
 * des Kollegen, den er gerade vertrat.
 *
 * Diese Tests halten die Gleichheit der Regeln fest: was
 * canAccessCustomer() erlaubt, muss die Query zeigen - und umgekehrt.
 */
class CustomerVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name, string $nummer): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => $nummer,
            'preferred_lang' => 'de',
        ]);
    }

    private function nachricht(Customer $kunde): void
    {
        CustomerMessage::create([
            'customer_id' => $kunde->id,
            'sender_id' => $kunde->user_id,
            'body' => 'Bitte um Rueckruf.',
            'from_staff' => false,
        ]);
    }

    // ================================================================
    // Die Kernregel: PHP-Pruefung und Query meinen dieselbe Menge
    // ================================================================

    public function test_admin_sieht_alle_kunden_in_pruefung_und_query(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = $this->kunde('Kunde A', '2600001');
        $b = $this->kunde('Kunde B', '2600002');

        $this->assertTrue($admin->canAccessCustomer($a->id));
        $this->assertTrue($admin->canAccessCustomer($b->id));
        $this->assertSame(2, $admin->getAccessibleCustomers()->count());
        $this->assertSame(2, Customer::query()->visibleTo($admin)->count());
    }

    /** DER GEMELDETE FEHLER: der Manager sah im Chat nichts. */
    public function test_manager_sieht_alle_kunden_in_pruefung_und_query(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'can_see_all_customers' => false]);
        $a = $this->kunde('Kunde A', '2600001');
        $this->kunde('Kunde B', '2600002');

        $this->assertTrue($manager->canSeeAllCustomers());
        $this->assertTrue($manager->canAccessCustomer($a->id));
        $this->assertSame(2, $manager->getAccessibleCustomers()->count(),
            'Der Manager muss ueber getAccessibleCustomers() dieselben Kunden sehen wie ueber canAccessCustomer().');
        $this->assertSame(2, Customer::query()->visibleTo($manager)->count());
    }

    public function test_manager_sieht_unterhaltungen_im_kundenchat(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'can_see_all_customers' => false]);
        $kunde = $this->kunde('Gesprächiger Kunde', '2600001');
        $this->nachricht($kunde);

        $this->actingAs($manager)->get(route('admin.customer_chat'))
            ->assertOk()
            ->assertSee('Gesprächiger Kunde');
    }

    public function test_support_ohne_zuweisung_sieht_keine_fremden_kunden(): void
    {
        $support = User::factory()->create(['role' => 'support', 'can_see_all_customers' => false]);
        $fremd = $this->kunde('Fremder Kunde', '2600001');

        $this->assertFalse($support->canAccessCustomer($fremd->id));
        $this->assertSame(0, $support->getAccessibleCustomers()->count());
        $this->assertSame(0, Customer::query()->visibleTo($support)->count());
    }

    public function test_mitarbeiter_sieht_nur_zugewiesene_kunden(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $meiner = $this->kunde('Mein Kunde', '2600001');
        $fremd = $this->kunde('Fremder Kunde', '2600002');
        $meiner->betreuer()->attach($employee->id);

        $this->assertTrue($employee->canAccessCustomer($meiner->id));
        $this->assertFalse($employee->canAccessCustomer($fremd->id));

        $sichtbar = $employee->getAccessibleCustomers()->pluck('id')->all();
        $this->assertSame([(string) $meiner->id], array_map('strval', $sichtbar));
        $this->assertSame(1, Customer::query()->visibleTo($employee)->count());
    }

    public function test_mitarbeiter_mit_flag_sieht_alle(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => true]);
        $this->kunde('Kunde A', '2600001');
        $this->kunde('Kunde B', '2600002');

        $this->assertSame(2, $employee->getAccessibleCustomers()->count());
    }

    // ================================================================
    // Vertretung
    // ================================================================

    public function test_vertreter_sieht_kunden_des_abwesenden_kollegen(): void
    {
        $abwesend = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $vertreter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $kunde = $this->kunde('Kunde des Kollegen', '2600001');
        $kunde->betreuer()->attach($abwesend->id);

        Substitution::create([
            'absent_user_id' => $abwesend->id,
            'substitute_user_id' => $vertreter->id,
            'from_date' => now()->subDay(),
            'to_date' => now()->addDay(),
            'created_by' => $abwesend->id,
        ]);

        $this->assertTrue($vertreter->canAccessCustomer($kunde->id));
        $this->assertSame(1, $vertreter->getAccessibleCustomers()->count(),
            'Die Vertretung muss AUCH im Kundenchat gelten - genau das fehlte.');
        $this->assertSame(1, Customer::query()->visibleTo($vertreter)->count());
    }

    public function test_vertreter_sieht_unterhaltungen_des_abwesenden(): void
    {
        $abwesend = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $vertreter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $kunde = $this->kunde('Kunde im Urlaubsfall', '2600001');
        $kunde->betreuer()->attach($abwesend->id);
        $this->nachricht($kunde);

        Substitution::create([
            'absent_user_id' => $abwesend->id,
            'substitute_user_id' => $vertreter->id,
            'from_date' => now()->subDay(),
            'to_date' => now()->addDay(),
            'created_by' => $abwesend->id,
        ]);

        $this->actingAs($vertreter)->get(route('admin.customer_chat'))
            ->assertOk()
            ->assertSee('Kunde im Urlaubsfall');
    }

    public function test_abgelaufene_vertretung_gibt_keinen_zugriff(): void
    {
        $abwesend = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $vertreter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $kunde = $this->kunde('Kunde des Kollegen', '2600001');
        $kunde->betreuer()->attach($abwesend->id);

        Substitution::create([
            'absent_user_id' => $abwesend->id,
            'substitute_user_id' => $vertreter->id,
            'from_date' => now()->subDays(10),
            'to_date' => now()->subDays(3),
            'created_by' => $abwesend->id,
        ]);

        $this->assertFalse($vertreter->canAccessCustomer($kunde->id));
        $this->assertSame(0, $vertreter->getAccessibleCustomers()->count());
    }

    // ================================================================
    // Kein Zugriff fuer Nicht-Personal (IDOR / Rechteausweitung)
    // ================================================================

    public function test_kunde_und_partner_sehen_ueber_diese_regel_nichts(): void
    {
        $kunde = $this->kunde('Ein Kunde', '2600001');
        $kundenUser = User::find($kunde->user_id);
        $partner = User::factory()->create(['role' => 'partner']);

        $this->assertFalse($kundenUser->canAccessCustomer($kunde->id));
        $this->assertFalse($partner->canAccessCustomer($kunde->id));
        $this->assertSame(0, Customer::query()->visibleTo($kundenUser)->count());
        $this->assertSame(0, Customer::query()->visibleTo($partner)->count());
    }

    public function test_ohne_angemeldeten_nutzer_liefert_der_scope_nichts(): void
    {
        $this->kunde('Kunde A', '2600001');

        $this->assertSame(0, Customer::query()->visibleTo(null)->count(),
            'Eine fehlende Anmeldung darf nie den gesamten Bestand oeffnen.');
    }

    public function test_mitarbeiter_bekommt_403_auf_fremden_kunden(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $meiner = $this->kunde('Mein Kunde', '2600001');
        $fremd = $this->kunde('Fremder Kunde', '2600002');
        $meiner->betreuer()->attach($employee->id);

        // (string) ist noetig: frisch angelegt ist ->id ein UUID-OBJEKT,
        // und route() laesst nicht-skalare Query-Parameter still weg -
        // die Anfrage traege dann gar keine Kunden-ID.
        $this->actingAs($employee)->get(route('admin.customer', (string) $meiner->id))->assertOk();
        $this->actingAs($employee)->get(route('admin.customer', (string) $fremd->id))->assertForbidden();
        $this->actingAs($employee)
            ->get(route('admin.customer_chat', ['kunde' => (string) $fremd->id]))->assertForbidden();
        $this->actingAs($employee)
            ->get(route('admin.customer_chat.feed', (string) $fremd->id))->assertForbidden();
    }

    // ================================================================
    // Frische: die Regel darf nie aus einem Zwischenspeicher antworten
    // ================================================================

    public function test_neue_zuweisung_wirkt_sofort_auch_in_derselben_anfrage(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $kunde = $this->kunde('Spaeter zugewiesen', '2600001');

        $this->assertFalse($employee->canAccessCustomer($kunde->id));

        $kunde->betreuer()->attach($employee->id);

        $this->assertTrue($employee->canAccessCustomer($kunde->id),
            'Die Sichtbarkeit darf nicht aus einem veralteten Zwischenspeicher kommen.');
        $this->assertSame(1, $employee->getAccessibleCustomers()->count());
    }

    /** Die drei Wege muessen ueber ALLE Rollen dieselbe Menge liefern. */
    public function test_pruefung_und_query_stimmen_fuer_jede_rolle_ueberein(): void
    {
        $kunden = [
            $this->kunde('Kunde A', '2600001'),
            $this->kunde('Kunde B', '2600002'),
            $this->kunde('Kunde C', '2600003'),
        ];

        $rollen = [
            'admin' => User::factory()->create(['role' => 'admin']),
            'manager' => User::factory()->create(['role' => 'manager']),
            'support' => User::factory()->create(['role' => 'support', 'can_see_all_customers' => false]),
            'employee' => User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]),
        ];
        $kunden[0]->betreuer()->attach($rollen['support']->id);
        $kunden[1]->betreuer()->attach($rollen['employee']->id);

        foreach ($rollen as $name => $nutzer) {
            $ausQuery = array_map('strval', Customer::query()->visibleTo($nutzer)->pluck('id')->all());
            sort($ausQuery);

            $ausPruefung = [];
            foreach ($kunden as $k) {
                if ($nutzer->canAccessCustomer($k->id)) {
                    $ausPruefung[] = (string) $k->id;
                }
            }
            sort($ausPruefung);

            $this->assertSame($ausPruefung, $ausQuery,
                "Rolle {$name}: canAccessCustomer() und visibleTo() meinen verschiedene Mengen.");
        }
    }
}
