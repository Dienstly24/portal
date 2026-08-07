<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Provision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Neukunden-Bericht (admin.reports.neukunden): Neukunden des Monats mit
 * Anleger, Werber (Mitarbeiter/Partner), Mitarbeiter-Sichtbarkeit und
 * Vertraegen (Gesellschaft, Laufzeit) - plus Vermittler-Provisionen.
 */
class NewCustomerReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Chef Admin']);
    }

    private function customer(array $userAttrs = [], array $customerAttrs = []): Customer
    {
        $user = User::factory()->create(array_merge([
            'role' => 'customer', 'name' => 'Max Mustermann', 'email' => 'kunde-' . uniqid() . '@kunde.de',
        ], $userAttrs));

        return Customer::create(array_merge([
            'user_id' => $user->id, 'customer_number' => 'K-' . uniqid(),
        ], $customerAttrs));
    }

    private function employee(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'employee', 'can_see_all_customers' => false,
        ], $attrs));
    }

    // ---------- Bericht ----------

    public function test_bericht_zeigt_neukunden_mit_anleger_werber_und_vertrag(): void
    {
        $werber = $this->employee(['name' => 'Willi Werber']);
        $customer = $this->customer(['name' => 'Nina Neu'], [
            'created_by' => $this->admin->id,
            'acquired_by' => $werber->id,
        ]);
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK Coburg',
            'status' => 'active', 'contract_number' => 'V-100',
            'start_date' => now()->format('Y-m-d'), 'end_date' => now()->addYear()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.reports.neukunden'));

        $response->assertOk()
            ->assertSee('Nina Neu')
            ->assertSee('Chef Admin')        // angelegt von
            ->assertSee('Willi Werber')      // geworben von
            ->assertSee('HUK Coburg')        // Gesellschaft
            ->assertSee(now()->addYear()->format('d.m.Y')); // Vertragsende
    }

    public function test_monatsfilter_blendet_kunden_anderer_monate_aus(): void
    {
        $alt = $this->customer(['name' => 'Alt Kunde']);
        $alt->forceFill(['created_at' => now()->subMonths(2)])->save();
        $this->customer(['name' => 'Neu Kunde']);

        $response = $this->actingAs($this->admin)->get(route('admin.reports.neukunden'));

        $response->assertOk()->assertSee('Neu Kunde')->assertDontSee('Alt Kunde');
    }

    public function test_werber_filter_zeigt_nur_kunden_des_werbers(): void
    {
        $werber = $this->employee(['name' => 'Willi Werber']);
        $this->customer(['name' => 'Von Willi'], ['acquired_by' => $werber->id]);
        $this->customer(['name' => 'Ohne Werber Kunde']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.neukunden', ['werber' => 'u:' . $werber->id]));

        $response->assertOk()->assertSee('Von Willi')->assertDontSee('Ohne Werber Kunde');
    }

    public function test_mitarbeiter_sieht_nur_sein_portfolio(): void
    {
        $employee = $this->employee();
        $meiner = $this->customer(['name' => 'Meiner Sichtbar']);
        $meiner->betreuer()->sync([$employee->id]);
        $this->customer(['name' => 'Fremder Kunde']);

        $response = $this->actingAs($employee)->get(route('admin.reports.neukunden'));

        $response->assertOk()->assertSee('Meiner Sichtbar')->assertDontSee('Fremder Kunde');
    }

    // ---------- Werber setzen ----------

    public function test_admin_setzt_mitarbeiter_als_werber(): void
    {
        $werber = $this->employee();
        $partner = Partner::create(['name' => 'Fonds Finanz']);
        $customer = $this->customer([], ['acquired_by_partner_id' => $partner->id]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.reports.neukunden.werber', $customer->id), ['werber' => 'u:' . $werber->id]);

        $response->assertRedirect();
        $customer->refresh();
        $this->assertSame($werber->id, $customer->acquired_by);
        // Exklusiv: Partner-Werber wird geleert.
        $this->assertNull($customer->acquired_by_partner_id);
    }

    public function test_admin_setzt_partner_als_werber(): void
    {
        $werber = $this->employee();
        $partner = Partner::create(['name' => 'Fonds Finanz']);
        $customer = $this->customer([], ['acquired_by' => $werber->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.neukunden.werber', $customer->id), ['werber' => 'p:' . $partner->id])
            ->assertRedirect();

        $customer->refresh();
        $this->assertSame($partner->id, $customer->acquired_by_partner_id);
        $this->assertNull($customer->acquired_by);
    }

    public function test_mitarbeiter_darf_werber_nicht_setzen(): void
    {
        $employee = $this->employee();
        $customer = $this->customer();

        // Rollen-Middleware leitet Nicht-Verwaltung zum Dashboard um -
        // entscheidend ist: der Werber wird NICHT gesetzt.
        $this->actingAs($employee)
            ->post(route('admin.reports.neukunden.werber', $customer->id), ['werber' => 'u:' . $employee->id])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertNull($customer->fresh()->acquired_by);
    }

    // ---------- Sichtbarkeit ----------

    public function test_sichtbarkeit_synchronisiert_betreuer_zuweisung(): void
    {
        $e1 = $this->employee(['name' => 'Erste Kraft']);
        $e2 = $this->employee(['name' => 'Zweite Kraft']);
        $customer = $this->customer();
        $customer->betreuer()->sync([$e1->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.neukunden.sichtbarkeit', $customer->id), ['sichtbar' => [$e2->id]])
            ->assertRedirect();

        $this->assertSame([$e2->id], $customer->fresh()->betreuer()->pluck('users.id')->all());
    }

    public function test_sichtbarkeit_entfernen_macht_kunden_fuer_mitarbeiter_unsichtbar(): void
    {
        $employee = $this->employee();
        $customer = $this->customer(['name' => 'Wechsel Kunde']);
        $customer->betreuer()->sync([$employee->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.reports.neukunden.sichtbarkeit', $customer->id), [])
            ->assertRedirect();

        $this->actingAs($employee)->get(route('admin.reports.neukunden'))
            ->assertOk()->assertDontSee('Wechsel Kunde');
    }

    // ---------- created_by ----------

    public function test_created_by_wird_bei_manueller_anlage_automatisch_gesetzt(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.customers.store'), [
            'first_name' => 'Neu', 'last_name' => 'Angelegt',
        ]);

        $response->assertRedirect();
        $customer = Customer::whereHas('user', fn($q) => $q->where('name', 'Neu Angelegt'))->first();
        $this->assertNotNull($customer);
        $this->assertSame($this->admin->id, $customer->created_by);
        $this->assertSame('manual', $customer->source);
    }

    public function test_werber_kann_bei_der_anlage_gesetzt_werden(): void
    {
        $werber = $this->employee();

        $this->actingAs($this->admin)->post(route('admin.customers.store'), [
            'first_name' => 'Mit', 'last_name' => 'Werber', 'werber' => 'u:' . $werber->id,
        ])->assertRedirect();

        $customer = Customer::whereHas('user', fn($q) => $q->where('name', 'Mit Werber'))->first();
        $this->assertSame($werber->id, $customer->acquired_by);
    }

    // ---------- Provisionen ----------

    public function test_provision_fuer_mitarbeiter_erfassen_und_auszahlen(): void
    {
        $werber = $this->employee(['name' => 'Willi Werber']);

        $this->actingAs($this->admin)->post(route('admin.provisions.store'), [
            'empfaenger' => 'u:' . $werber->id,
            'amount' => '125.50',
            'note' => 'Neukunden Juli',
        ])->assertRedirect();

        $provision = Provision::first();
        $this->assertNotNull($provision);
        $this->assertSame($werber->id, $provision->user_id);
        $this->assertSame('offen', $provision->status);
        $this->assertSame('125.50', (string) $provision->amount);

        $this->actingAs($this->admin)->post(route('admin.provisions.status', $provision->id), [
            'status' => 'ausgezahlt',
        ])->assertRedirect();

        $provision->refresh();
        $this->assertSame('ausgezahlt', $provision->status);
        $this->assertSame($this->admin->id, $provision->paid_by);
        $this->assertNotNull($provision->paid_at);
    }

    // Audit PROV-1: die automatische Neuvertrag-Provision (Contract::created)
    // traegt keine Periode. Der fruehere "bereits erfasst"-Filter suchte nur
    // ueber period_from/to und sah sie NIE -> die Ein-Klick-Erfassung buchte
    // ein zweites Mal. Jetzt zaehlt die Erkennung ueber die Neukunden dieses
    // Zeitraums und die Ein-Klick-Nachbuchung entfaellt, sobald gebucht ist.
    public function test_neukunden_report_erkennt_automatische_buchung_und_bietet_keine_doppel_erfassung(): void
    {
        $werber = $this->employee(['name' => 'Auto Werber', 'provision_fixed' => 25.00, 'provision_percent' => 0]);
        $customer = $this->customer(['name' => 'Auto Kunde'], ['acquired_by' => $werber->id]);
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK',
            'status' => 'active', 'start_date' => now()->format('Y-m-d'),
            'premium_amount' => 300, 'premium_interval' => 'jaehrlich',
        ]);

        // Genau EINE automatische Provision entstanden.
        $this->assertSame(1, Provision::where('type', 'neuvertrag')->count());

        $response = $this->actingAs($this->admin)->get(route('admin.reports.neukunden'));
        $response->assertOk()
            ->assertSee('bereits gebucht')            // Automatik wird erkannt
            ->assertSee('Automatisch gebucht')        // Ein-Klick durch Hinweis ersetzt
            ->assertDontSee('name="amount"', false);  // keine Doppel-Erfassung angeboten
    }

    public function test_neukunden_report_bietet_ein_klick_wenn_noch_nichts_gebucht(): void
    {
        // Werber OHNE Satz -> keine automatische Buchung -> Ein-Klick als Fallback.
        $werber = $this->employee(['name' => 'Ohne Satz', 'provision_fixed' => null, 'provision_percent' => null]);
        $customer = $this->customer(['name' => 'Fallback Kunde'], ['acquired_by' => $werber->id]);
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK',
            'status' => 'active', 'start_date' => now()->format('Y-m-d'),
            'premium_amount' => 300, 'premium_interval' => 'jaehrlich',
        ]);

        $this->assertSame(0, Provision::count());

        $this->actingAs($this->admin)->get(route('admin.reports.neukunden'))
            ->assertOk()
            ->assertSee('name="amount"', false);  // Ein-Klick-Erfassung verfuegbar
    }

    public function test_provision_fuer_partner_erfassen(): void
    {
        $partner = Partner::create(['name' => 'Fonds Finanz']);

        $this->actingAs($this->admin)->post(route('admin.provisions.store'), [
            'empfaenger' => 'p:' . $partner->id,
            'amount' => '80',
        ])->assertRedirect();

        $provision = Provision::first();
        $this->assertSame($partner->id, $provision->partner_id);
        $this->assertNull($provision->user_id);
    }

    public function test_mitarbeiter_hat_keinen_zugriff_auf_provisionen(): void
    {
        $employee = $this->employee();

        // Rollen-Middleware leitet Nicht-Verwaltung zum Dashboard um.
        $this->actingAs($employee)->get(route('admin.provisions'))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($employee)->post(route('admin.provisions.store'), [
            'empfaenger' => 'u:' . $employee->id, 'amount' => '10',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertSame(0, Provision::count());
    }

    public function test_provisionssatz_am_mitarbeiter_speicherbar(): void
    {
        $employee = $this->employee(['name' => 'Satz Traeger']);

        $this->actingAs($this->admin)->put(route('admin.employees.update', $employee->id), [
            'name' => 'Satz Traeger',
            'provision_fixed' => '25.00',
            'provision_percent' => '10',
        ])->assertRedirect();

        $employee->refresh();
        $this->assertSame('25.00', (string) $employee->provision_fixed);
        $this->assertSame('10.00', (string) $employee->provision_percent);
    }

    public function test_provisionssatz_am_partner_speicherbar(): void
    {
        $partner = Partner::create(['name' => 'Fonds Finanz']);

        $this->actingAs($this->admin)->put(route('admin.partners.update', $partner->id), [
            'name' => 'Fonds Finanz',
            'provision_fixed' => '15.50',
            'provision_percent' => '5',
        ])->assertRedirect();

        $partner->refresh();
        $this->assertSame('15.50', (string) $partner->provision_fixed);
        $this->assertSame('5.00', (string) $partner->provision_percent);
    }

    public function test_provisionsvorschau_nutzt_saetze_des_werbers(): void
    {
        $werber = $this->employee(['name' => 'Willi Werber']);
        $werber->forceFill(['provision_fixed' => 20, 'provision_percent' => 10])->save();

        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        // Jahresbeitrag: 50 EUR monatlich => 600 EUR/Jahr.
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK',
            'status' => 'active', 'contract_number' => 'V-200',
            'premium_amount' => 50, 'premium_interval' => 'monthly',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.reports.neukunden'));

        // 1 Vertrag x 20 EUR fix + 10% von 600 EUR = 80,00 EUR Vorschlag.
        $response->assertOk()->assertSee('80,00');
    }
}
