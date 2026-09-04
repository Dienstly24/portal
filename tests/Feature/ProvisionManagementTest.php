<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Provision;
use App\Models\ProvisionAuditLog;
use App\Models\ProvisionRate;
use App\Models\User;
use App\Services\Provision\ContractProvisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Provisions-Management (Betreiber-Vorgabe 25.07.2026): Automatische
 * Provision je Neuvertrag (Satz je Sparte und Empfaenger), Storno-
 * Gegenbuchung bei Kuendigung/Loeschung, Freigabe-Workflow, Betrags-
 * Anpassung nur mit Grund + Audit-Log, Monatsbericht mit Excel-/PDF-
 * Export, Dashboard - alles NUR fuer admin/manager.
 */
class ProvisionManagementTest extends TestCase
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
            'role' => 'customer', 'name' => 'Max Mustermann', 'email' => 'kunde-'.uniqid().'@kunde.de',
        ], $userAttrs));

        return Customer::create(array_merge([
            'user_id' => $user->id, 'customer_number' => 'K-'.uniqid(),
        ], $customerAttrs));
    }

    private function employee(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'employee', 'can_see_all_customers' => false,
        ], $attrs));
    }

    /** Werber-Mitarbeiter mit KFZ-Sparten-Satz (50 EUR fix + 10 %). */
    private function werberMitKfzSatz(): User
    {
        $werber = $this->employee(['name' => 'Willi Werber']);
        ProvisionRate::create([
            'user_id' => $werber->id, 'contract_type' => 'kfz',
            'amount_fixed' => 50, 'amount_percent' => 10,
        ]);
        return $werber;
    }

    private function kfzContract(Customer $customer, array $attrs = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK Coburg',
            'status' => 'active', 'contract_number' => 'V-'.uniqid(),
            'premium_amount' => 50, 'premium_interval' => 'monthly', // 600 EUR/Jahr
        ], $attrs));
    }

    // ---------- Automatische Anlage ----------

    public function test_neuvertrag_bucht_provision_mit_sparten_satz_automatisch(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);

        $contract = $this->kfzContract($customer);

        // 50 fix + 10% von 600 EUR Jahresbeitrag = 110,00 EUR
        $provision = Provision::first();
        $this->assertNotNull($provision);
        $this->assertSame('110.00', (string) $provision->amount);
        $this->assertSame('neuvertrag', $provision->type);
        $this->assertSame('offen', $provision->status);
        $this->assertSame($werber->id, $provision->user_id);
        $this->assertSame((string) $customer->id, $provision->customer_id);
        $this->assertSame($contract->id, $provision->contract_id);
        $this->assertSame('kfz', $provision->contract_type);
        $this->assertSame('HUK Coburg', $provision->insurer);

        // Anlage steht im Audit-Log (System-Buchung).
        $log = ProvisionAuditLog::where('provision_id', $provision->id)->where('action', 'created')->first();
        $this->assertNotNull($log);
        $this->assertSame('110.00', $log->new_value);
    }

    public function test_sparten_satz_geht_vor_globalem_satz(): void
    {
        $werber = $this->werberMitKfzSatz();
        $werber->forceFill(['provision_fixed' => 20, 'provision_percent' => null])->save();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);

        $this->kfzContract($customer, ['premium_amount' => null]); // Sparten-Satz: 50 fix
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'strom', 'insurer' => 'Vattenfall',
            'status' => 'active', // kein Sparten-Satz -> globaler Satz 20 fix
        ]);

        $amounts = Provision::orderBy('amount')->pluck('amount')->map(fn ($a) => (string) $a)->all();
        $this->assertSame(['20.00', '50.00'], $amounts);
    }

    public function test_partner_werber_bekommt_seinen_eigenen_satz(): void
    {
        $partner = Partner::create(['name' => 'Fonds Finanz']);
        ProvisionRate::create([
            'partner_id' => $partner->id, 'contract_type' => 'kfz', 'amount_fixed' => 60,
        ]);
        $customer = $this->customer([], ['acquired_by_partner_id' => $partner->id]);

        $this->kfzContract($customer, ['premium_amount' => null]);

        $provision = Provision::first();
        $this->assertSame($partner->id, $provision->partner_id);
        $this->assertNull($provision->user_id);
        $this->assertSame('60.00', (string) $provision->amount);
    }

    public function test_ohne_werber_und_ohne_satz_keine_buchung(): void
    {
        // Kunde ohne Werber -> nichts.
        $this->kfzContract($this->customer());
        $this->assertSame(0, Provision::count());

        // Werber ohne jeden Satz -> ebenfalls nichts (keine erfundenen Betraege).
        $werber = $this->employee();
        $this->kfzContract($this->customer([], ['acquired_by' => $werber->id]));
        $this->assertSame(0, Provision::count());
    }

    public function test_bereits_gekuendigter_vertrag_erzeugt_keine_provision(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);

        $this->kfzContract($customer, ['status' => 'cancelled']);

        $this->assertSame(0, Provision::count());
    }

    public function test_auto_buchung_ist_idempotent(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $contract = $this->kfzContract($customer);

        app(ContractProvisionService::class)->createForContract($contract->fresh());

        $this->assertSame(1, Provision::count());
    }

    public function test_manuelle_vertragsanlage_ueber_formular_bucht_automatisch(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);

        $this->actingAs($this->admin)->post(route('admin.contract.store', $customer->id), [
            'type' => 'kfz', 'insurer' => 'Allianz', 'status' => 'active',
            'premium_amount' => 50, 'premium_interval' => 'monthly',
        ])->assertRedirect();

        $provision = Provision::first();
        $this->assertNotNull($provision);
        $this->assertSame('110.00', (string) $provision->amount);
        $this->assertSame($this->admin->id, $provision->created_by);
    }

    public function test_werber_nachtraeglich_setzen_bucht_bestehende_vertraege(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer();
        $this->kfzContract($customer); // noch ohne Werber -> keine Buchung
        $this->assertSame(0, Provision::count());

        $this->actingAs($this->admin)
            ->post(route('admin.reports.neukunden.werber', $customer->id), ['werber' => 'u:'.$werber->id])
            ->assertRedirect();

        $provision = Provision::first();
        $this->assertNotNull($provision);
        $this->assertSame('110.00', (string) $provision->amount);
        $this->assertSame($werber->id, $provision->user_id);
    }

    // ---------- Storno-Gegenbuchung ----------

    public function test_kuendigung_erzeugt_negative_gegenbuchung_und_laesst_original_stehen(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $contract = $this->kfzContract($customer);
        $original = Provision::first();

        $contract->update(['status' => 'cancelled']);

        $this->assertSame(2, Provision::count());
        $storno = Provision::where('type', 'storno')->first();
        $this->assertNotNull($storno);
        $this->assertSame('-110.00', (string) $storno->amount);
        $this->assertSame($original->id, $storno->related_provision_id);
        $this->assertSame($werber->id, $storno->user_id);

        // Original bleibt unveraendert in der Datenbank (Finanzhistorie).
        $original->refresh();
        $this->assertSame('110.00', (string) $original->amount);
        $this->assertSame('neuvertrag', $original->type);

        // Netto ueber alle Buchungen: 0.
        $this->assertSame(0.0, (float) Provision::sum('amount'));
    }

    public function test_vertragsloeschung_erzeugt_gegenbuchung_und_buchungen_bleiben(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $contract = $this->kfzContract($customer);

        $this->actingAs($this->admin)
            ->delete(route('admin.contract.destroy', $contract->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('contracts', ['id' => $contract->id]);
        $this->assertSame(2, Provision::count());
        $storno = Provision::where('type', 'storno')->first();
        $this->assertSame('-110.00', (string) $storno->amount);
        // Vertrag ist weg -> Referenz genullt, Sparte/Gesellschaft bleiben denormalisiert erhalten.
        $this->assertNull($storno->fresh()->contract_id);
        $this->assertSame('kfz', $storno->contract_type);
    }

    public function test_keine_doppelte_gegenbuchung_bei_kuendigung_und_loeschung(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $contract = $this->kfzContract($customer);

        $contract->update(['status' => 'cancelled']);
        $contract->delete();

        $this->assertSame(1, Provision::where('type', 'storno')->count());
        $this->assertSame(2, Provision::count());
    }

    // ---------- Workflow: Freigabe, Auszahlung, Anpassung ----------

    public function test_statusfluss_freigeben_und_auszahlen_mit_audit(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $this->kfzContract($customer);
        $provision = Provision::first();

        $this->actingAs($this->admin)->post(route('admin.provisions.status', $provision->id), [
            'status' => 'freigegeben',
        ])->assertRedirect();
        $provision->refresh();
        $this->assertSame('freigegeben', $provision->status);
        $this->assertSame($this->admin->id, $provision->approved_by);
        $this->assertNotNull($provision->approved_at);

        $this->actingAs($this->admin)->post(route('admin.provisions.status', $provision->id), [
            'status' => 'ausgezahlt',
        ])->assertRedirect();
        $provision->refresh();
        $this->assertSame('ausgezahlt', $provision->status);
        $this->assertSame($this->admin->id, $provision->paid_by);
        $this->assertNotNull($provision->paid_at);

        $this->assertSame(2, ProvisionAuditLog::where('provision_id', $provision->id)
            ->where('action', 'status_changed')->count());
    }

    public function test_unerlaubter_statuswechsel_wird_abgelehnt(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $this->kfzContract($customer);
        $provision = Provision::first();
        $provision->update(['status' => 'ausgezahlt']);

        $this->actingAs($this->admin)->post(route('admin.provisions.status', $provision->id), [
            'status' => 'freigegeben',
        ])->assertSessionHas('error');

        $this->assertSame('ausgezahlt', $provision->fresh()->status);
    }

    public function test_betrag_anpassen_braucht_grund_und_schreibt_audit(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $this->kfzContract($customer);
        $provision = Provision::first();

        // Ohne Grund -> Validierungsfehler, Betrag unveraendert.
        $this->actingAs($this->admin)->post(route('admin.provisions.amount', $provision->id), [
            'amount' => '95.50',
        ])->assertSessionHasErrors('grund');
        $this->assertSame('110.00', (string) $provision->fresh()->amount);

        // Mit Grund -> angepasst + Audit-Eintrag alt/neu/Grund.
        $this->actingAs($this->admin)->post(route('admin.provisions.amount', $provision->id), [
            'amount' => '95.50', 'grund' => 'Korrektur Jahresbeitrag',
        ])->assertRedirect();
        $this->assertSame('95.50', (string) $provision->fresh()->amount);

        $log = ProvisionAuditLog::where('provision_id', $provision->id)
            ->where('action', 'amount_changed')->first();
        $this->assertNotNull($log);
        $this->assertSame('110.00', $log->old_value);
        $this->assertSame('95.50', $log->new_value);
        $this->assertSame('Korrektur Jahresbeitrag', $log->reason);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_ausgezahlte_buchung_kann_nicht_angepasst_werden(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $this->kfzContract($customer);
        $provision = Provision::first();
        $provision->update(['status' => 'ausgezahlt']);

        $this->actingAs($this->admin)->post(route('admin.provisions.amount', $provision->id), [
            'amount' => '10', 'grund' => 'Versuch',
        ])->assertSessionHas('error');

        $this->assertSame('110.00', (string) $provision->fresh()->amount);
    }

    public function test_bonus_und_abzug_manuell_erfassen(): void
    {
        $werber = $this->employee(['name' => 'Bonus Traeger']);

        // Bonus positiv.
        $this->actingAs($this->admin)->post(route('admin.provisions.store'), [
            'empfaenger' => 'u:'.$werber->id, 'art' => 'bonus',
            'amount' => '25', 'note' => 'Kampagne Juli',
        ])->assertRedirect();

        // Abzug wird negativ gebucht (Eingabe positiv).
        $this->actingAs($this->admin)->post(route('admin.provisions.store'), [
            'empfaenger' => 'u:'.$werber->id, 'art' => 'abzug',
            'amount' => '10', 'note' => 'Fehlbuchung Juni',
        ])->assertRedirect();

        // Ohne Grund kein Abzug (Pflichtfeld).
        $this->actingAs($this->admin)->post(route('admin.provisions.store'), [
            'empfaenger' => 'u:'.$werber->id, 'art' => 'abzug', 'amount' => '5',
        ])->assertSessionHasErrors('note');

        $this->assertSame(2, Provision::count());
        $this->assertSame('25.00', (string) Provision::where('type', 'bonus')->first()->amount);
        $this->assertSame('-10.00', (string) Provision::where('type', 'abzug')->first()->amount);
    }

    // ---------- Zugriffsschutz ----------

    public function test_mitarbeiter_hat_keinen_zugriff_auf_das_provisionsmodul(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $this->kfzContract($customer);
        $provision = Provision::first();

        $employee = $this->employee();
        $dashboard = route('admin.dashboard');

        // Alle Seiten: Rollen-Middleware leitet Nicht-Verwaltung um.
        foreach ([
            route('admin.provisions'),
            route('admin.provisions.rates'),
            route('admin.provisions.report'),
            route('admin.provisions.report.export', ['format' => 'csv']),
            route('admin.provisions.dashboard'),
            route('admin.provisions.show', $provision->id),
        ] as $url) {
            $this->actingAs($employee)->get($url)->assertRedirect($dashboard);
        }

        // Alle Schreibwege ebenso.
        $this->actingAs($employee)->post(route('admin.provisions.store'), [
            'empfaenger' => 'u:'.$employee->id, 'amount' => '10',
        ])->assertRedirect($dashboard);
        $this->actingAs($employee)->post(route('admin.provisions.status', $provision->id), [
            'status' => 'ausgezahlt',
        ])->assertRedirect($dashboard);
        $this->actingAs($employee)->post(route('admin.provisions.amount', $provision->id), [
            'amount' => '999', 'grund' => 'x',
        ])->assertRedirect($dashboard);
        $this->actingAs($employee)->post(route('admin.provisions.rates.save'), [
            'empfaenger' => 'u:'.$employee->id, 'global_fixed' => '99',
        ])->assertRedirect($dashboard);

        $this->assertSame(1, Provision::count());
        $this->assertSame('110.00', (string) $provision->fresh()->amount);
        $this->assertSame('offen', $provision->fresh()->status);
    }

    // ---------- Filter ----------

    public function test_liste_filtert_nach_sparte_und_monat(): void
    {
        $werber = $this->werberMitKfzSatz();
        ProvisionRate::create([
            'user_id' => $werber->id, 'contract_type' => 'strom', 'amount_fixed' => 25,
        ]);
        $kfzKunde = $this->customer(['name' => 'Kunde Kaefer'], ['acquired_by' => $werber->id]);
        $stromKunde = $this->customer(['name' => 'Kunde Strommer'], ['acquired_by' => $werber->id]);
        $this->kfzContract($kfzKunde);
        Contract::create([
            'customer_id' => $stromKunde->id, 'type' => 'strom', 'insurer' => 'Vattenfall',
            'status' => 'active',
        ]);

        // Sparten-Filter.
        $this->actingAs($this->admin)->get(route('admin.provisions', ['sparte' => 'kfz']))
            ->assertOk()->assertSee('Kunde Kaefer')->assertDontSee('Kunde Strommer');

        // Monats-Filter: alte Buchung ausblenden.
        Provision::where('customer_id', $stromKunde->id)
            ->first()->forceFill(['created_at' => now()->subMonths(2)])->save();
        $this->actingAs($this->admin)->get(route('admin.provisions', ['monat' => now()->format('Y-m')]))
            ->assertOk()->assertSee('Kunde Kaefer')->assertDontSee('Kunde Strommer');

        // Art-Filter: nur Storno-Buchungen.
        Contract::where('customer_id', $kfzKunde->id)->first()->update(['status' => 'cancelled']);
        $this->actingAs($this->admin)->get(route('admin.provisions', ['typ' => 'storno']))
            ->assertOk()->assertSee('Kunde Kaefer');
    }

    // ---------- Monatsbericht + Export ----------

    public function test_monatsbericht_aggregiert_provision_abzuege_und_netto(): void
    {
        $werber = $this->employee(['name' => 'Willi Werber']);
        ProvisionRate::create([
            'user_id' => $werber->id, 'contract_type' => 'kfz', 'amount_fixed' => 47.11,
        ]);
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $c1 = $this->kfzContract($customer, ['premium_amount' => null]);
        $this->kfzContract($customer, ['premium_amount' => null]);
        $c1->update(['status' => 'cancelled']); // Storno -47,11

        $response = $this->actingAs($this->admin)->get(route('admin.provisions.report'));

        $response->assertOk()
            ->assertSee('Willi Werber')
            ->assertSee('94,22')   // Provision: 2 x 47,11
            ->assertSee('-47,11')  // Abzuege
            ->assertSee('47,11');  // Netto
    }

    public function test_bericht_export_excel_csv_und_druckansicht(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $this->kfzContract($customer);

        // Excel (xlsx via ZipArchive).
        $xlsx = $this->actingAs($this->admin)
            ->get(route('admin.provisions.report.export', ['format' => 'xlsx']));
        $xlsx->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));

        // CSV (Excel-kompatibel, Semikolon).
        $csv = $this->actingAs($this->admin)
            ->get(route('admin.provisions.report.export', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));
        $this->assertStringContainsString('Willi Werber', $csv->getContent());

        // PDF-Druckansicht.
        $this->actingAs($this->admin)
            ->get(route('admin.provisions.report.export', ['format' => 'pdf']))
            ->assertOk()->assertSee('Provisionsbericht')->assertSee('Willi Werber');
    }

    // ---------- Saetze-Konfiguration ----------

    public function test_sparten_saetze_speichern_aendern_und_loeschen(): void
    {
        $werber = $this->employee(['name' => 'Satz Traeger']);

        $this->actingAs($this->admin)->post(route('admin.provisions.rates.save'), [
            'empfaenger' => 'u:'.$werber->id,
            'global_fixed' => '20',
            'saetze' => [
                'kfz' => ['fixed' => '50', 'percent' => '10'],
                'strom' => ['fixed' => '', 'percent' => ''],
            ],
        ])->assertRedirect();

        $werber->refresh();
        $this->assertSame('20.00', (string) $werber->provision_fixed);
        $rate = ProvisionRate::where('user_id', $werber->id)->where('contract_type', 'kfz')->first();
        $this->assertNotNull($rate);
        $this->assertSame('50.00', (string) $rate->amount_fixed);
        $this->assertSame('10.00', (string) $rate->amount_percent);
        $this->assertSame(1, ProvisionRate::count());

        // Beide Felder leeren -> Satz wird geloescht.
        $this->actingAs($this->admin)->post(route('admin.provisions.rates.save'), [
            'empfaenger' => 'u:'.$werber->id,
            'saetze' => ['kfz' => ['fixed' => '', 'percent' => '']],
        ])->assertRedirect();

        $this->assertSame(0, ProvisionRate::count());
        $this->assertNull($werber->fresh()->provision_fixed);
    }

    // ---------- Detailseite + Dashboard ----------

    public function test_detailseite_zeigt_buchung_und_audit_log(): void
    {
        $werber = $this->werberMitKfzSatz();
        $customer = $this->customer([], ['acquired_by' => $werber->id]);
        $this->kfzContract($customer);
        $provision = Provision::first();

        $this->actingAs($this->admin)->get(route('admin.provisions.show', $provision->id))
            ->assertOk()
            ->assertSee('Willi Werber')
            ->assertSee('110,00')
            ->assertSee('Änderungsprotokoll')
            ->assertSee('Automatische Anlage bei Vertragsanlage');
    }

    public function test_dashboard_zeigt_kennzahlen_und_beste_vermittler(): void
    {
        $werber = $this->werberMitKfzSatz();
        $partner = Partner::create(['name' => 'Fonds Finanz']);
        ProvisionRate::create([
            'partner_id' => $partner->id, 'contract_type' => 'kfz', 'amount_fixed' => 60,
        ]);
        $this->kfzContract($this->customer([], ['acquired_by' => $werber->id]));
        $this->kfzContract($this->customer([], ['acquired_by_partner_id' => $partner->id]));

        $this->actingAs($this->admin)->get(route('admin.provisions.dashboard'))
            ->assertOk()
            ->assertSee('Bester Mitarbeiter')
            ->assertSee('Willi Werber')
            ->assertSee('Bester Partner')
            ->assertSee('Fonds Finanz');
    }
}
