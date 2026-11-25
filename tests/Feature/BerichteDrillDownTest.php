<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Reporting\AnalyticsFilters;
use App\Services\Reporting\DashboardAnalyticsService;
use App\Support\Bundesland;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BERICHTE 2.0 - DRILL-DOWN (Betreiber-Auftrag 25.11.2026).
 *
 * Die Kernaussage, die diese Tests festhalten: "Die Zahl im Dashboard und
 * die Zahl in der Detailansicht muessen immer exakt uebereinstimmen."
 * Dazu die Befunde F1-F4 an der Seite vom 25.11.2026 (Karte, ueberfaellige
 * Vertraege, Ticket-Summe, Vergleichsbasis) und die neuen Filter.
 */
class BerichteDrillDownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kunde(array $overrides = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $overrides['name'] ?? 'Max Muster']);
        unset($overrides['name']);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5($user->id.microtime()), 0, 8)),
            'address_zip' => '24103',
            'address_city' => 'Kiel',
            'customer_type' => 'privat',
        ], $overrides));
    }

    private function vertrag(Customer $kunde, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $kunde->id,
            'type' => 'kfz',
            'insurer' => 'Allianz',
            'status' => 'active',
            'signing_date' => '2026-11-10',
            'premium_amount' => 50,
            'premium_interval' => 'monthly',
        ], $overrides));
    }

    private function filter(array $query = []): AnalyticsFilters
    {
        return AnalyticsFilters::ausRequest(Request::create('/admin/reports', 'GET', $query));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Ein gemischter Bestand, auf dem jede Liste etwas zu zaehlen hat. */
    private function bestand(): void
    {
        $a = $this->kunde(['name' => 'Anna Abschluss']);
        $this->vertrag($a);
        $this->vertrag($a, ['type' => 'strom', 'insurer' => 'EWE']);
        $this->vertrag($a, ['type' => 'gas', 'insurer' => 'EWE', 'signing_date' => '2026-11-12']);

        $b = $this->kunde(['name' => 'Bernd Bestand', 'address_city' => 'Hamburg', 'address_zip' => '20095']);
        $b->forceFill(['created_at' => '2025-03-01 10:00:00'])->save();
        $this->vertrag($b, ['signing_date' => '2025-03-02', 'end_date' => '2026-12-05']);
        $this->vertrag($b, ['type' => 'haftpflicht', 'signing_date' => '2024-01-01', 'end_date' => '2026-10-01']);
        $this->vertrag($b, ['type' => 'rechtsschutz', 'signing_date' => '2024-01-01', 'cancellation_date' => '2026-11-03', 'end_date' => '2027-01-01']);

        $this->kunde(['name' => 'Carla Kontakt', 'address_zip' => null, 'address_city' => null]);
        $d = $this->kunde(['name' => 'Dora Antrag', 'customer_type' => 'firma', 'company_name' => 'Dora GmbH']);
        $this->vertrag($d, ['status' => 'pending', 'signing_date' => null, 'application_date' => '2026-11-20']);
        $this->vertrag($d, ['signing_date' => '2026-12-20']);
    }

    // ---------------------------------------------------------------
    // 1. Zahl = Liste, fuer JEDE Liste
    // ---------------------------------------------------------------

    public function test_jede_liste_zeigt_genau_die_zahl_der_kachel(): void
    {
        $this->bestand();
        $admin = $this->admin();
        $f = $this->filter();
        $dienst = new DashboardAnalyticsService(null);
        $daten = $dienst->auswerten($f);

        // Kacheln -> ihre Liste
        foreach ($daten['kpis'] as $kpi) {
            if (in_array($kpi['schluessel'], ['wert', 'abschlussquote'], true)) {
                continue; // Betrag bzw. Quote - die Liste dahinter zaehlt Vertraege/Kunden
            }
            $query = $f->listeQuery($kpi['liste'], [], $kpi['liste_zeitraum'] ?? null);
            $seite = $this->actingAs($admin)->get(route('admin.reports.liste', $query));
            $seite->assertOk();
            $this->assertSame((int) $kpi['wert'], $seite->viewData('gesamt'), 'Kachel '.$kpi['schluessel']);
            $this->assertSame((int) $kpi['wert'], $seite->viewData('seite')->total(), 'Kachel '.$kpi['schluessel']);
        }

        // Statusliste -> ihre Liste
        foreach ($daten['status']['zeilen'] as $zeile) {
            $this->assertSame($zeile['wert'], $dienst->liste($zeile['liste'], $f)->count(), 'Status '.$zeile['schluessel']);
        }
        // Kundenstruktur -> ihre Liste
        foreach ($daten['kunden']['gruppen'] as $g) {
            $gf = $this->filter($g['filter']);
            $this->assertSame($g['wert'], $dienst->liste($g['liste'], $gf)->count(), 'Kundengruppe '.$g['schluessel']);
        }
        // Jede registrierte Liste laedt (keine Liste ohne Seite).
        foreach (array_keys(DashboardAnalyticsService::LISTEN) as $liste) {
            $this->actingAs($admin)->get(route('admin.reports.liste', ['liste' => $liste]))->assertOk();
        }
    }

    public function test_unbekannte_liste_ist_404(): void
    {
        $this->actingAs($this->admin())->get(route('admin.reports.liste', ['liste' => 'gibtsnicht']))->assertNotFound();
    }

    // ---------------------------------------------------------------
    // 2. Kunden zaehlen einmal, Vertraege einzeln
    // ---------------------------------------------------------------

    public function test_kunde_mit_drei_abschluessen_zaehlt_einmal(): void
    {
        $k = $this->kunde(['name' => 'Anna Abschluss']);
        $this->vertrag($k);
        $this->vertrag($k, ['type' => 'strom']);
        $this->vertrag($k, ['type' => 'gas']);

        $daten = (new DashboardAnalyticsService(null))->auswerten($this->filter());
        $kpi = collect($daten['kpis'])->keyBy('schluessel');

        $this->assertSame(1, $kpi['kunden_abschluss']['wert']);
        $this->assertSame(3, $kpi['monat']['wert']);

        $seite = $this->actingAs($this->admin())->get(route('admin.reports.liste', ['liste' => 'kunden_abschluss']));
        $seite->assertOk()->assertSee('Anna Abschluss');
        $zeile = $seite->viewData('seite')->items()[0];
        $this->assertSame(3, $zeile['abschluesse']);
        $this->assertSame(1800.0, $zeile['wert']);
        $this->assertStringContainsString('KFZ', $zeile['sparten']);
    }

    public function test_abschlussquote_der_neukunden(): void
    {
        $mit = $this->kunde();
        $this->vertrag($mit);
        $this->kunde(['name' => 'Ohne Vertrag Eins']);
        $this->kunde(['name' => 'Ohne Vertrag Zwei']);

        $daten = (new DashboardAnalyticsService(null))->auswerten($this->filter());
        $quote = collect($daten['kpis'])->firstWhere('schluessel', 'abschlussquote');

        $this->assertSame(33.3, $quote['wert']);
        $this->assertSame('1 von 3 Neukunden mit Vertrag', $quote['trend_text']);

        $this->actingAs($this->admin())->get(route('admin.reports.liste', ['liste' => 'neukunden_ohne_vertrag']))
            ->assertOk()->assertSee('Ohne Vertrag Eins')->assertSee('Ohne Vertrag Zwei')->assertDontSee('Max Muster');
    }

    // ---------------------------------------------------------------
    // 3. Befunde vom 25.11.2026
    // ---------------------------------------------------------------

    /** F1: Anteile auf die Kunden AUF der Karte, Rest als eigene Liste. */
    public function test_karte_rechnet_anteile_ohne_kunden_ohne_plz(): void
    {
        $this->kunde(['address_zip' => '50667']);
        $this->kunde(['address_zip' => '50668']);
        $this->kunde(['address_zip' => null]);
        $this->kunde(['address_zip' => 'abc']);

        $f = $this->filter();
        $dienst = new DashboardAnalyticsService(null);
        $karte = $dienst->auswerten($f)['karte'];

        $this->assertSame(2, $karte['gesamt']);
        $this->assertSame(4, $karte['alle']);
        $this->assertSame(2, $karte['ohne_zuordnung']);
        $this->assertSame(100.0, $karte['laender']['NW']['anteil']);

        $ohne = $dienst->liste('kunden', $this->filter(['bundesland' => Bundesland::UNBEKANNT]))->count();
        $this->assertSame($karte['ohne_zuordnung'], $ohne);
    }

    /** F2: die ueberfaelligen Vertraege haben eine Liste. */
    public function test_ueberfaellige_vertraege_haben_eine_liste(): void
    {
        $k = $this->kunde(['name' => 'Otto Ueberfaellig']);
        $this->vertrag($k, ['end_date' => '2026-10-01']);

        $this->actingAs($this->admin())->get('/admin/reports')
            ->assertOk()->assertSee(route('admin.reports.liste', ['liste' => 'ueberfaellig']), false);

        $this->actingAs($this->admin())->get(route('admin.reports.liste', ['liste' => 'ueberfaellig']))
            ->assertOk()->assertSee('Otto Ueberfaellig');
    }

    /** F3: die Teile ergeben das Ganze, auch mit unbekanntem Status. */
    public function test_vorgaenge_mit_fremdem_status_fallen_nicht_heraus(): void
    {
        $k = $this->kunde();
        foreach (['open', 'open', 'wartet_auf_kunde'] as $status) {
            Ticket::create(['customer_id' => $k->id, 'type' => 'damage', 'status' => $status,
                'subject' => 'Test', 'description' => 'x', 'priority' => 'mittel']);
        }

        $antwort = $this->actingAs($this->admin())->get('/admin/reports')->assertOk();
        $tickets = $antwort->viewData('tickets');

        $this->assertSame(3, $tickets['gesamt']);
        $this->assertSame(3, array_sum(array_column($tickets['zeilen'], 'wert')));
        $antwort->assertSee('Anderer / kein Status');
    }

    /** F4: die Vergleichsbasis steht neben dem Prozentwert. */
    public function test_vergleichsbasis_steht_an_der_kachel(): void
    {
        $k = $this->kunde();
        $this->vertrag($k, ['signing_date' => '2025-05-01']);
        foreach (range(1, 4) as $i) {
            $this->vertrag($k, ['signing_date' => '2026-0'.$i.'-10']);
        }

        $this->actingAs($this->admin())->get('/admin/reports')
            ->assertOk()->assertSee('+300,0 %', false)->assertSee('vs. Vorjahr (1)', false);
    }

    /** Feste Jahreskachel: die Liste bekommt dieselbe Spanne (bis 31.12.). */
    public function test_jahreskachel_und_liste_meinen_dasselbe_jahr(): void
    {
        $k = $this->kunde();
        $this->vertrag($k, ['signing_date' => null, 'start_date' => '2026-12-20']);
        $this->vertrag($k, ['signing_date' => '2026-02-01']);

        $f = $this->filter();
        $jahr = collect((new DashboardAnalyticsService(null))->auswerten($f)['kpis'])->firstWhere('schluessel', 'jahr');
        $this->assertSame(2, $jahr['wert']);

        $seite = $this->actingAs($this->admin())
            ->get(route('admin.reports.liste', $f->listeQuery('vertraege_neu', [], $jahr['liste_zeitraum'])));
        $this->assertSame(2, $seite->viewData('gesamt'));
    }

    // ---------------------------------------------------------------
    // 4. Neue Filter und Ranglisten
    // ---------------------------------------------------------------

    public function test_stadt_filter_fasst_schreibweisen_zusammen_und_passt_zur_rangliste(): void
    {
        $this->vertrag($this->kunde(['address_city' => 'Kiel']));
        $this->vertrag($this->kunde(['address_city' => ' kiel ']));
        $this->vertrag($this->kunde(['address_city' => 'Lübeck', 'address_zip' => '23552']));

        $f = $this->filter();
        $dienst = new DashboardAnalyticsService(null);
        $staedte = $dienst->auswerten($f)['staedte'];

        $kiel = collect($staedte['zeilen'])->first(fn ($z) => mb_strtolower(trim($z['name'])) === 'kiel');
        $this->assertSame(2, $kiel['neue_vertraege']);
        $this->assertSame(2, $kiel['kunden']);
        $this->assertSame(2, $staedte['anzahl_orte']);

        $this->assertSame(2, $dienst->liste('vertraege_neu', $this->filter(['stadt' => $kiel['name']]))->count());
        $this->assertSame(1, $dienst->liste('vertraege_neu', $this->filter(['stadt' => 'Lübeck']))->count());
    }

    public function test_werber_rangliste_und_filter(): void
    {
        $willi = User::factory()->create(['role' => 'employee', 'name' => 'Willi Werber']);
        $k1 = $this->kunde(['acquired_by' => $willi->id]);
        $this->vertrag($k1);
        $this->vertrag($k1, ['type' => 'strom']);
        $this->vertrag($this->kunde());

        $f = $this->filter();
        $dienst = new DashboardAnalyticsService(null);
        $werber = collect($dienst->auswerten($f)['werber'])->keyBy('schluessel');

        $this->assertSame('Willi Werber', $werber['u:'.$willi->id]['name']);
        $this->assertSame(2, $werber['u:'.$willi->id]['neue_vertraege']);
        $this->assertSame(1, $werber['u:'.$willi->id]['kunden_abschluss']);
        $this->assertSame(1, $werber['keiner']['neue_vertraege']);

        $this->assertSame(2, $dienst->liste('vertraege_neu', $this->filter(['werber' => 'u:'.$willi->id]))->count());
        $this->assertSame(1, $dienst->liste('vertraege_neu', $this->filter(['werber' => 'keiner']))->count());

        $this->assertSame('Willi Werber', $this->filter(['werber' => 'u:'.$willi->id])->chips()[0]['wert']);
    }

    public function test_gesellschaft_und_plz_filter(): void
    {
        $this->vertrag($this->kunde(['address_zip' => '24103']), ['insurer' => 'Allianz']);
        $this->vertrag($this->kunde(['address_zip' => '24534']), ['insurer' => 'HUK']);
        $this->vertrag($this->kunde(['address_zip' => '20095']), ['insurer' => 'Allianz']);

        $dienst = new DashboardAnalyticsService(null);
        $this->assertSame(2, $dienst->liste('vertraege_neu', $this->filter(['gesellschaft' => 'Allianz']))->count());
        $this->assertSame(2, $dienst->liste('vertraege_neu', $this->filter(['plz' => '24']))->count());
        $this->assertSame(1, $dienst->liste('kunden', $this->filter(['plz' => '24', 'gesellschaft' => 'HUK']))->count());
        // Keine PLZ, sondern Text: verworfen, kein Filter.
        $this->assertNull($this->filter(['plz' => "24' or 1=1"])->plz);
    }

    public function test_neue_zeitraeume_und_monatswahl(): void
    {
        $nov = $this->filter(['monat' => '2026-10']);
        $this->assertSame('monatswahl', $nov->zeitraum);
        $this->assertSame('2026-10-01', $nov->von->toDateString());
        $this->assertSame('2026-10-31', $nov->bis->toDateString());

        $vm = $this->filter(['zeitraum' => 'letzter_monat']);
        $this->assertSame('2026-10-01', $vm->von->toDateString());

        $q = $this->filter(['zeitraum' => 'quartal']);
        $this->assertSame('2026-10-01', $q->von->toDateString());

        $lj = $this->filter(['zeitraum' => 'letztes_jahr']);
        $this->assertSame('2025-12-31', $lj->bis->toDateString());

        $this->assertSame('2026-11-24', $this->filter(['zeitraum' => 'gestern'])->von->toDateString());
        // Kaputter Monat -> Standard (dieser Monat), kein Fehler.
        $this->assertSame('monat', $this->filter(['zeitraum' => 'monatswahl', 'monat' => '2026-13'])->zeitraum);
    }

    // ---------------------------------------------------------------
    // 5. Export, Rechte, Portfolio
    // ---------------------------------------------------------------

    public function test_export_enthaelt_alle_zeilen_und_wird_protokolliert(): void
    {
        $this->bestand();
        $admin = $this->admin();
        $f = $this->filter();
        $anzahl = (new DashboardAnalyticsService(null))->liste('vertraege_neu', $f)->count();

        $csv = $this->actingAs($admin)->get(route('admin.reports.liste', ['liste' => 'vertraege_neu', 'format' => 'csv']));
        $csv->assertOk();
        $this->assertStringStartsWith('text/csv', $csv->headers->get('Content-Type'));
        $zeilen = array_values(array_filter(explode("\n", trim($csv->getContent()))));
        // 2 Kopfzeilen + Spaltenkopf + Datenzeilen (die Leerzeile faellt im Filter weg)
        $this->assertSame(4, $anzahl);
        $this->assertCount(3 + $anzahl, $zeilen);
        $this->assertStringContainsString('Anna Abschluss', $csv->getContent());
        $this->assertStringContainsString('Datumsquelle', $csv->getContent());

        $xlsx = $this->actingAs($admin)->get(route('admin.reports.liste', ['liste' => 'kunden_abschluss', 'format' => 'xlsx']));
        $xlsx->assertOk();

        $log = ActivityLog::where('action', 'report_list_export')->get();
        $this->assertCount(2, $log);
        $this->assertSame($anzahl, $log->first()->meta['zeilen']);
        // Protokoll ohne Namen (Datenminimierung).
        $this->assertStringNotContainsString('Anna', json_encode($log->first()->meta));
    }

    public function test_mitarbeiter_sieht_nur_sein_portfolio_und_darf_nicht_exportieren(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $meiner = $this->kunde(['name' => 'Mein Kunde']);
        $meiner->betreuer()->sync([$employee->id]);
        $this->vertrag($meiner);
        $this->vertrag($this->kunde(['name' => 'Fremder Kunde']));

        $seite = $this->actingAs($employee)->get(route('admin.reports.liste', ['liste' => 'vertraege_neu']));
        $seite->assertOk()->assertSee('Mein Kunde')->assertDontSee('Fremder Kunde')->assertDontSee('>Excel<', false);
        $this->assertSame(1, $seite->viewData('gesamt'));

        $this->actingAs($employee)->get(route('admin.reports.liste', ['liste' => 'vertraege_neu', 'format' => 'csv']))
            ->assertForbidden();
    }

    public function test_suche_schraenkt_nur_die_anzeige_ein(): void
    {
        $this->vertrag($this->kunde(['name' => 'Anna Abschluss']));
        $this->vertrag($this->kunde(['name' => 'Bruno Beispiel']));

        $seite = $this->actingAs($this->admin())->get(route('admin.reports.liste', ['liste' => 'vertraege_neu', 'q' => 'Bruno']));
        $seite->assertOk()->assertSee('Bruno Beispiel')->assertDontSee('Anna Abschluss')
            ->assertSee('1 von 2 Einträgen passen zur Suche', false);
    }

    public function test_uebersicht_zeigt_die_neuen_abschnitte_mit_daten(): void
    {
        $this->bestand();

        $this->actingAs($this->admin())->get('/admin/reports')->assertOk()
            ->assertSee('Kunden mit Abschluss')
            ->assertSee('Abschlussquote Neukunden')
            ->assertSee('Top Städte', false)
            ->assertSee('Abschlüsse nach Sparte', false)
            ->assertSee('Mitarbeiter &amp; Vermittler', false)
            ->assertSee('Gesellschaften')
            ->assertSee('Kunden auf der Karte')
            ->assertSee('Liste anzeigen');
    }
}
