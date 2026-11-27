<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AiLead;
use App\Models\Contract;
use App\Models\ContractCommission;
use App\Models\Customer;
use App\Models\User;
use App\Services\Dashboard\DashboardUebersicht;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI-Modernisierung 27.11.2026: Dashboard als Verwaltungszentrale,
 * Vertragsliste (Sortierung, Auswahl, Export), Interessenten
 * (Zustaendigkeit, Umwandlung), Rueckmeldungen und schmale Seitenleiste.
 */
class UiModernisierungTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name = 'Erika Muster'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => 'k-'.uniqid().'@kunde.de']);

        return Customer::create(['user_id' => $user->id, 'customer_number' => 'K-'.uniqid()]);
    }

    private function vertrag(Customer $kunde, array $attr = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $kunde->id, 'type' => 'kfz', 'insurer' => 'DA Direkt',
            'status' => 'active', 'start_date' => now()->subMonths(2)->toDateString(),
        ], $attr));
    }

    public function test_provisionen_auf_dem_dashboard_nur_fuer_den_admin(): void
    {
        ContractCommission::create(['amount' => 123.45, 'commission_date' => now()->toDateString(), 'status' => 'offen', 'dedupe_key' => 'test-1']);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Provisionen (Monat)')->assertSee('123,45 €');

        // Manager MIT Provisionsrecht: sieht es trotzdem nicht auf dem Dashboard.
        $manager = User::factory()->create(['role' => 'manager', 'can_manage_commissions' => true]);
        $this->actingAs($manager)->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('Provisionen (Monat)')->assertDontSee('123,45');

        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        $this->actingAs($mitarbeiter)->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('Provisionen (Monat)')->assertDontSee('123,45');
    }

    public function test_neukunden_kachel_zaehlt_wie_ihre_liste_und_verlinkt_sie(): void
    {
        $this->kunde('Neu Eins');
        $this->kunde('Neu Zwei');
        $alt = $this->kunde('Alt Kunde');
        $alt->forceFill(['created_at' => now()->subMonths(3)])->save();

        $admin = User::factory()->create(['role' => 'admin']);
        $zahlen = (new DashboardUebersicht(null, $admin))->kennzahlen();
        $this->assertSame(2, $zahlen['neukunden']);

        $this->actingAs($admin)->get($zahlen['neukunden_link'])->assertOk()
            ->assertSee('Neu Eins')->assertSee('Neu Zwei')->assertDontSee('Alt Kunde');
    }

    public function test_dashboard_zeigt_einem_mitarbeiter_keine_fremden_kunden_und_vertraege(): void
    {
        $eigen = $this->kunde('Eigene Kundin');
        $fremd = $this->kunde('Fremder Kunde');
        $this->vertrag($eigen);
        $this->vertrag($fremd, ['type' => 'strom', 'insurer' => 'Fremdwerk']);
        $this->vertrag($fremd, ['type' => 'strom', 'insurer' => 'Fremdwerk']);

        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $eigen->betreuer()->attach($mitarbeiter->id);

        $res = $this->actingAs($mitarbeiter)->get(route('admin.dashboard'))->assertOk();
        $res->assertSee('Eigene Kundin')->assertDontSee('Fremder Kunde');

        // Sparten-Diagramm: vorher ueber den GESAMTEN Bestand gezaehlt.
        $sparten = (new DashboardUebersicht($mitarbeiter->visibleCustomerIdsWithSubstitution(), $mitarbeiter))->sparten();
        $this->assertSame([['label' => Contract::TYPES['kfz']['label'], 'wert' => 1, 'sparte' => 'kfz']], $sparten);
    }

    public function test_trend_ohne_vergleichsbasis_ist_neu_statt_hundert_prozent(): void
    {
        $this->assertSame('neu', DashboardUebersicht::trend(5, 0)['art']);
        $this->assertSame('+50 %', DashboardUebersicht::trend(15, 10)['text']);
        $this->assertSame('runter', DashboardUebersicht::trend(5, 10)['art']);
    }

    public function test_vertragsliste_sortiert_nach_gesellschaft(): void
    {
        $k = $this->kunde();
        $this->vertrag($k, ['insurer' => 'Zurich']);
        $this->vertrag($k, ['insurer' => 'Allianz']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.contracts', ['sort' => 'gesellschaft', 'richtung' => 'asc']))
            ->assertOk()->assertSeeInOrder(['Allianz', 'Zurich']);
        $this->actingAs($admin)->get(route('admin.contracts', ['sort' => 'gesellschaft', 'richtung' => 'desc']))
            ->assertOk()->assertSeeInOrder(['Zurich', 'Allianz']);
        // Unbekannte Sortierung wird verworfen, nicht in die Abfrage gereicht.
        $this->actingAs($admin)->get(route('admin.contracts', ['sort' => 'id; drop table', 'richtung' => 'quer']))->assertOk();
    }

    public function test_export_der_auswahl_nur_mit_den_gewaehlten_vertraegen_und_protokolliert(): void
    {
        $k = $this->kunde();
        $a = $this->vertrag($k, ['contract_number' => 'NR-AAA-111']);
        $this->vertrag($k, ['contract_number' => 'NR-BBB-222']);
        $admin = User::factory()->create(['role' => 'admin']);

        $res = $this->actingAs($admin)->post(route('admin.contracts.export'), [
            'format' => 'csv', 'vertraege' => [$a->id],
        ])->assertOk();
        $this->assertStringContainsString('NR-AAA-111', $res->getContent());
        $this->assertStringNotContainsString('NR-BBB-222', $res->getContent());

        $log = ActivityLog::where('action', 'contracts_export')->firstOrFail();
        $this->assertSame(1, $log->meta['zeilen']);
        $this->assertStringNotContainsString('Erika', json_encode($log->meta));

        // "Alle Treffer" folgt der Ansicht.
        $alle = $this->actingAs($admin)->post(route('admin.contracts.export'), ['format' => 'csv', 'alle' => 1, 'gruppe' => 'aktiv'])->assertOk();
        $this->assertStringContainsString('NR-BBB-222', $alle->getContent());
    }

    public function test_export_ist_fuer_mitarbeiter_gesperrt(): void
    {
        $k = $this->kunde();
        $a = $this->vertrag($k);
        $mitarbeiter = User::factory()->create(['role' => 'employee']);

        $res = $this->actingAs($mitarbeiter)->post(route('admin.contracts.export'), ['format' => 'csv', 'vertraege' => [$a->id]]);
        // Die Rollen-Middleware leitet um - entscheidend: keine Datei.
        $this->assertNotSame(200, $res->getStatusCode());
        $this->assertStringNotContainsString('Kundennummer;', (string) $res->getContent());
        $this->actingAs($mitarbeiter)->get(route('admin.contracts'))->assertOk()
            ->assertDontSee('Auswahl als Excel');
    }

    public function test_interessent_wird_in_kunden_umgewandelt_und_verknuepft(): void
    {
        $lead = AiLead::create(['source' => 'website', 'contact' => ['name' => 'Lara Interessent', 'email' => 'lara@example.org', 'phone' => '0171 2345678']]);
        $mitarbeiter = User::factory()->create(['role' => 'employee']);

        $this->actingAs($mitarbeiter)->post(route('admin.leads.convert', $lead->id))->assertRedirect();

        $lead->refresh();
        $this->assertNotNull($lead->customer_id);
        $kunde = Customer::with('user')->findOrFail($lead->customer_id);
        $this->assertSame('Lara Interessent', $kunde->user->name);
        $this->assertSame('website', $kunde->source);
        $this->assertSame($mitarbeiter->id, $lead->assigned_employee_id);

        // Zweiter Klick legt nichts doppelt an.
        $this->actingAs($mitarbeiter)->post(route('admin.leads.convert', $lead->id))
            ->assertRedirect(route('admin.customer', $kunde->id));
        $this->assertSame(1, Customer::count());
    }

    public function test_umwandlung_legt_bei_vorhandenem_kunden_nichts_an(): void
    {
        $vorhanden = $this->kunde('Lara Interessent');
        $vorhanden->user->forceFill(['email' => 'lara@example.org'])->save();
        $lead = AiLead::create(['source' => 'website', 'contact' => ['name' => 'Lara Interessent', 'email' => 'lara@example.org']]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.leads.convert', $lead->id))->assertSessionHas('warning');
        $this->assertSame(1, Customer::count());
        $this->assertNull($lead->fresh()->customer_id);
    }

    public function test_zustaendig_nur_aktives_personal(): void
    {
        $lead = AiLead::create(['source' => 'website']);
        $admin = User::factory()->create(['role' => 'admin']);
        $kundenkonto = User::factory()->create(['role' => 'customer']);

        $this->actingAs($admin)->post(route('admin.leads.assign', $lead->id), ['assigned_employee_id' => $kundenkonto->id])
            ->assertSessionHasErrors('assigned_employee_id');
        $this->actingAs($admin)->post(route('admin.leads.assign', $lead->id), ['assigned_employee_id' => $admin->id]);
        $this->assertSame($admin->id, $lead->fresh()->assigned_employee_id);

        $this->actingAs($admin)->get(route('admin.leads.index', ['zustaendig' => 'niemand']))->assertOk();

        // Ein Mitarbeiter nimmt einen fremd zugewiesenen Fall nicht still weg.
        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        $this->actingAs($mitarbeiter)->post(route('admin.leads.assign', $lead->id), ['assigned_employee_id' => $mitarbeiter->id])
            ->assertForbidden();
        $this->assertSame($admin->id, $lead->fresh()->assigned_employee_id);
    }

    public function test_rueckmeldung_und_schmale_seitenleiste_im_layout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->withSession(['success' => 'Gespeichert!'])
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="toast-stapel"[^>]*data-toasts/', $html);
        $this->assertMatchesRegularExpression('/data-toast-auto>.*Gespeichert!/s', $html);
        $this->assertStringContainsString('id="nav-schmal-btn"', $html);
        // Tooltip fuer den schmalen Zustand: jeder Menuepunkt traegt seinen Namen.
        $this->assertMatchesRegularExpression('/class="nav-item[^"]*"\s+data-nav-key="[^"]+"\s+title="[^"]+"/', $html);
    }
}
