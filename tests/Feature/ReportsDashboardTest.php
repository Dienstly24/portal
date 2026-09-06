<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Services\Reporting\AnalyticsFilters;
use App\Services\Reporting\DashboardAnalyticsService;
use App\Support\Bundesland;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * BERICHTE & ANALYSEN - das Auswertungs-Dashboard (Betreiber-Auftrag
 * 06.09.2026).
 *
 * WAS DIESE TESTS FESTHALTEN, ist nicht das Aussehen der Seite, sondern
 * die vier Aussagen, auf denen sie steht - denn eine Kennzahlenseite ist
 * nur so viel wert wie die Definition hinter der Zahl:
 *
 *   1. Ein Vertrag zaehlt zum Zeitpunkt seines ABSCHLUSSES, nicht zum Tag
 *      des Imports (sonst waere jeder Altbestands-Import ein Rekordmonat).
 *   2. "Aktiv" heisst Contract::currentlyActive() - nie status === 'active'.
 *   3. Das Bundesland wird aus der PLZ ABGELEITET und nie geraten.
 *   4. Der Portfolio-Scope gilt auch hier: ein Mitarbeiter sieht in der
 *      Auswertung nie mehr Kunden als in seiner Kundenliste.
 */
class ReportsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $plz = '50667', array $overrides = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5($user->id.microtime()), 0, 8)),
            'address_zip' => $plz,
            'customer_type' => 'privat',
        ], $overrides));
    }

    private function vertrag(Customer $kunde, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $kunde->id,
            'type' => 'kfz',
            'insurer' => 'WGV',
            'status' => 'active',
        ], $overrides));
    }

    private function filter(array $query = []): AnalyticsFilters
    {
        return AnalyticsFilters::ausRequest(Request::create('/admin/reports', 'GET', $query));
    }

    private function auswerten(array $query = [], ?array $ids = null): array
    {
        return (new DashboardAnalyticsService($ids))->auswerten($this->filter($query));
    }

    private function kpi(array $daten, string $schluessel): array
    {
        return collect($daten['kpis'])->firstWhere('schluessel', $schluessel);
    }

    // ---------------------------------------------------------------
    // Fall 1: Die Seite laedt und traegt die geforderten Abschnitte
    // ---------------------------------------------------------------

    /**
     * MIT DATEN geladen, nicht leer. Eine leere Datenbank rendert jede
     * Liste ueber ihren "keine Daten"-Zweig - genau die Zweige, in denen
     * kein Feld gelesen wird. Ein Schluesselfehler in einem Baustein faellt
     * so erst dem Betreiber auf.
     */
    public function test_seite_zeigt_alle_abschnitte(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde('50667');
        $this->vertrag($kunde, ['type' => 'kfz', 'signing_date' => now()->toDateString(),
            'premium_amount' => 60, 'premium_interval' => 'monthly']);
        $this->vertrag($kunde, ['type' => 'hausrat', 'end_date' => now()->addDays(10)->toDateString()]);

        $antwort = $this->actingAs($admin)->get('/admin/reports');

        $antwort->assertOk();
        foreach ([
            'Berichte &amp; Analysen',
            'Leistungskennzahlen, Vertragsentwicklung und Kundenanalyse',
            'Verträge diesen Monat', 'Verträge dieses Jahr', 'Neue Kunden',
            'Vertragswert', 'Verlängerungen', 'Auslaufende Verträge',
            'Vertragsentwicklung', 'Verträge nach Sparte',
            'Kundenverteilung in Deutschland', 'Top Regionen',
            'Kundenstruktur', 'Vertragsstatus',
        ] as $text) {
            $antwort->assertSee($text, false);
        }
    }

    /**
     * "Filter zurücksetzen" erscheint nur, wenn es etwas zurueckzusetzen
     * gibt. Ein Knopf, der im Standardzustand nichts tut, trainiert dem
     * Nutzer an, dass Knoepfe nichts tun.
     */
    public function test_zuruecksetzen_erscheint_nur_bei_gesetztem_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/reports')
            ->assertDontSee('Filter zurücksetzen', false);

        $this->actingAs($admin)->get('/admin/reports?sparte=kfz')
            ->assertSee('Filter zurücksetzen', false)
            ->assertSee('KFZ', false);
    }

    // ---------------------------------------------------------------
    // Fall 2: Abschluss-Zeitpunkt, nicht Anlagedatum
    // ---------------------------------------------------------------

    /**
     * Ein im September importierter Vertrag vom Maerz ist ein
     * Maerz-Abschluss. Wuerde die Seite created_at zaehlen, meldete jeder
     * Altbestands-Import einen Rekordmonat.
     */
    public function test_vertrag_zaehlt_zum_abschluss_nicht_zum_import(): void
    {
        $kunde = $this->kunde();
        $this->vertrag($kunde, ['signing_date' => now()->subMonths(6)->toDateString()]);
        $this->vertrag($kunde, ['signing_date' => now()->toDateString()]);

        $daten = $this->auswerten();

        $this->assertSame(1, $this->kpi($daten, 'monat')['wert']);
    }

    /** Ohne Unterschrift entscheidet Antrag, dann Beginn, dann Anlage. */
    public function test_abschluss_faellt_auf_antrag_und_beginn_zurueck(): void
    {
        $kunde = $this->kunde();
        $this->vertrag($kunde, ['application_date' => now()->startOfMonth()->toDateString()]);
        $this->vertrag($kunde, ['start_date' => now()->startOfMonth()->toDateString()]);
        $this->vertrag($kunde, ['start_date' => now()->subYear()->toDateString()]);

        $this->assertSame(2, $this->kpi($this->auswerten(), 'monat')['wert']);
    }

    // ---------------------------------------------------------------
    // Fall 3: Kein erfundener Trend ohne Vergleichsbasis
    // ---------------------------------------------------------------

    /**
     * Ohne Vorzeitraum gibt es KEINEN Prozentwert. "+100 %" waere fuer
     * einen Vertrag dasselbe wie fuer tausend - eine Zahl ohne Aussage.
     */
    public function test_ohne_vergleichsbasis_kein_prozentwert(): void
    {
        $this->vertrag($this->kunde(), ['signing_date' => now()->toDateString()]);

        $trend = $this->kpi($this->auswerten(), 'monat')['trend'];

        $this->assertSame('neu', $trend['richtung']);
        $this->assertNull($trend['prozent']);
    }

    public function test_trend_rechnet_gegen_den_vormonat(): void
    {
        $kunde = $this->kunde();
        $vormonat = now()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateString();
        $this->vertrag($kunde, ['signing_date' => $vormonat]);
        $this->vertrag($kunde, ['signing_date' => $vormonat]);
        $this->vertrag($kunde, ['signing_date' => now()->toDateString()]);
        $this->vertrag($kunde, ['signing_date' => now()->toDateString()]);
        $this->vertrag($kunde, ['signing_date' => now()->toDateString()]);

        $trend = $this->kpi($this->auswerten(), 'monat')['trend'];

        $this->assertSame('auf', $trend['richtung']);
        $this->assertSame(50.0, $trend['prozent']);
    }

    // ---------------------------------------------------------------
    // Fall 4: "Aktiv" folgt der EINEN Definition
    // ---------------------------------------------------------------

    /**
     * Ein zum Ablauf gekuendigter Vertrag mit erreichtem Ende ist Historie -
     * auch wenn sein gespeicherter Status noch 'active' lautet (der
     * Nachtlauf zieht ihn erst spaeter nach).
     */
    public function test_beendete_deckung_zaehlt_nicht_zum_aktiven_bestand(): void
    {
        $kunde = $this->kunde();
        $this->vertrag($kunde, ['type' => 'kfz']);
        $this->vertrag($kunde, [
            'type' => 'kfz',
            'cancellation_date' => now()->subMonths(2)->toDateString(),
            'end_date' => now()->subMonth()->toDateString(),
        ]);

        $daten = $this->auswerten();
        $aktiv = collect($daten['status']['zeilen'])->firstWhere('schluessel', 'aktiv')['wert'];

        $this->assertSame(1, $aktiv);
        $this->assertSame(1, collect($daten['sparten'])->firstWhere('schluessel', 'kfz')['anzahl']);
    }

    /**
     * Verlaengerung ist eine ABLEITUNG: Ablauf im Zeitraum, Vertrag laeuft
     * weiter. Derselbe Ablauf MIT Kuendigung ist keine Verlaengerung.
     */
    public function test_verlaengerung_ist_ablauf_im_zeitraum_ohne_kuendigung(): void
    {
        $kunde = $this->kunde();
        $imZeitraum = now()->startOfMonth()->addDays(2)->toDateString();
        $this->vertrag($kunde, ['end_date' => $imZeitraum]);
        $this->vertrag($kunde, ['end_date' => $imZeitraum, 'cancellation_date' => $imZeitraum, 'status' => 'cancelled']);

        $kpi = $this->kpi($this->auswerten(), 'verlaengerungen');

        $this->assertSame(1, $kpi['wert']);
        $this->assertStringContainsString('50,0 % Verlängerungsquote', $kpi['trend_text']);
    }

    // ---------------------------------------------------------------
    // Fall 5: Bundesland aus der PLZ - abgeleitet, nie geraten
    // ---------------------------------------------------------------

    public function test_plz_wird_dem_richtigen_bundesland_zugeordnet(): void
    {
        $this->assertSame('NW', Bundesland::ausPlz('50667'));   // Köln
        $this->assertSame('BY', Bundesland::ausPlz('80331'));   // München
        $this->assertSame('BE', Bundesland::ausPlz('10115'));   // Berlin
        $this->assertSame('HB', Bundesland::ausPlz('28195'));   // Bremen
        // Feinzuordnung: die Leitregion reicht ueber die Landesgrenze.
        $this->assertSame('HE', Bundesland::ausPlz('63065'));   // Offenbach
        $this->assertSame('BY', Bundesland::ausPlz('63739'));   // Aschaffenburg
        $this->assertSame('BW', Bundesland::ausPlz('89073'));   // Ulm
        $this->assertSame('BY', Bundesland::ausPlz('89231'));   // Neu-Ulm
        $this->assertSame('BW', Bundesland::ausPlz('89518'));   // Heidenheim
    }

    /** Was nicht eindeutig ist, wird NICHT auf ein Land geraten. */
    public function test_unklare_plz_bleibt_unbekannt(): void
    {
        foreach ([null, '', '123', 'ABCDE', '1010'] as $eingabe) {
            $this->assertSame(Bundesland::UNBEKANNT, Bundesland::ausPlz($eingabe));
        }
    }

    /**
     * Kunden ohne auswertbare PLZ werden GETRENNT ausgewiesen, nie still
     * auf die Laender verteilt - sonst sieht die Karte vollstaendiger aus
     * als der Datenbestand.
     */
    public function test_karte_weist_kunden_ohne_plz_getrennt_aus(): void
    {
        $this->kunde('50667');
        $this->kunde('80331');
        $this->kunde('', ['address_zip' => null]);

        $karte = $this->auswerten()['karte'];

        $this->assertSame(1, $karte['laender']['NW']['kunden']);
        $this->assertSame(1, $karte['laender']['BY']['kunden']);
        $this->assertSame(1, $karte['ohne_zuordnung']);
        $this->assertSame(3, $karte['gesamt']);
    }

    public function test_top_regionen_sind_nach_kunden_sortiert(): void
    {
        $this->kunde('50667');
        $this->kunde('50668');
        $this->kunde('80331');

        $top = $this->auswerten()['karte']['top'];

        $this->assertSame('Nordrhein-Westfalen', $top[0]['name']);
        $this->assertSame(2, $top[0]['kunden']);
        $this->assertSame('Bayern', $top[1]['name']);
    }

    // ---------------------------------------------------------------
    // Fall 6: Filter wirken auf ALLE Auswertungen
    // ---------------------------------------------------------------

    public function test_bundeslandfilter_schraenkt_karte_und_sparten_ein(): void
    {
        $koeln = $this->kunde('50667');
        $muenchen = $this->kunde('80331');
        $this->vertrag($koeln, ['type' => 'kfz', 'signing_date' => now()->toDateString()]);
        $this->vertrag($muenchen, ['type' => 'hausrat', 'signing_date' => now()->toDateString()]);

        $daten = $this->auswerten(['bundesland' => 'NW']);

        $this->assertSame(1, $this->kpi($daten, 'monat')['wert']);
        $this->assertSame(1, $daten['kunden']['gesamt']);
        $this->assertSame(['kfz'], array_column($daten['sparten'], 'schluessel'));
    }

    public function test_spartenfilter_wirkt_auf_kennzahlen_und_kundenstruktur(): void
    {
        $a = $this->kunde('50667');
        $b = $this->kunde('80331');
        $this->vertrag($a, ['type' => 'kfz', 'signing_date' => now()->toDateString()]);
        $this->vertrag($b, ['type' => 'hausrat', 'signing_date' => now()->toDateString()]);

        $daten = $this->auswerten(['sparte' => 'kfz']);

        $this->assertSame(1, $this->kpi($daten, 'monat')['wert']);
        $this->assertSame(1, $daten['kunden']['gesamt']);
    }

    /** Ein unbekannter Filterwert wird verworfen, nicht durchgereicht. */
    public function test_unbekannte_filterwerte_werden_verworfen(): void
    {
        $f = $this->filter([
            'sparte' => 'gibt-es-nicht',
            'bundesland' => 'XX',
            'kundentyp' => 'behoerde',
            'status' => 'irgendwas',
            'takt' => 'stunde',
            'zeitraum' => 'jahrzehnt',
        ]);

        $this->assertNull($f->sparte);
        $this->assertNull($f->bundesland);
        $this->assertNull($f->kundentyp);
        $this->assertNull($f->vertragsstatus);
        $this->assertSame('monat', $f->granularitaet);
        $this->assertSame('monat', $f->zeitraum);
    }

    public function test_zurueckgesetzte_filter_sind_der_standard(): void
    {
        $this->assertTrue($this->filter()->istStandard());
        $this->assertFalse($this->filter(['sparte' => 'kfz'])->istStandard());
    }

    /**
     * Neue Kunden zaehlen im gewaehlten Zeitraum - ein vor Jahren
     * angelegter Kunde ist kein Neukunde, nur weil er noch existiert.
     */
    public function test_neue_kunden_folgen_dem_zeitraum(): void
    {
        $alt = $this->kunde('50667');
        $alt->forceFill(['created_at' => now()->subYear()])->saveQuietly();
        $this->kunde('80331');

        $daten = $this->auswerten();

        $this->assertSame(1, $this->kpi($daten, 'neukunden')['wert']);
        $this->assertSame(2, $daten['kunden']['gesamt']);
        $bestand = collect($daten['kunden']['gruppen'])->firstWhere('schluessel', 'bestand');
        $this->assertSame(1, $bestand['wert']);
    }

    // ---------------------------------------------------------------
    // Fall 7: Zeitreihe
    // ---------------------------------------------------------------

    public function test_verlauf_liefert_zwoelf_monate_und_alle_reihen(): void
    {
        $daten = $this->auswerten();

        $this->assertCount(12, $daten['verlauf']['labels']);
        $this->assertSame(
            ['neu', 'verlaengert', 'gekuendigt'],
            array_column($daten['verlauf']['reihen'], 'schluessel')
        );
        $this->assertCount(12, $daten['verlauf']['vergleich']['werte']);
    }

    public function test_verlauf_kennt_quartal_und_jahr(): void
    {
        $this->assertCount(8, $this->auswerten(['takt' => 'quartal'])['verlauf']['labels']);
        $this->assertCount(5, $this->auswerten(['takt' => 'jahr'])['verlauf']['labels']);
    }

    // ---------------------------------------------------------------
    // Fall 8: Vertragswert
    // ---------------------------------------------------------------

    /**
     * Der Vertragswert ist die Summe der JAHRESBEITRAEGE - der
     * SQL-Ausdruck muss dasselbe liefern wie Contract::yearlyPremium().
     * Einmalbeitraege gehen bewusst nicht ein (kein Jahreswert).
     */
    public function test_vertragswert_entspricht_dem_jahresbeitrag(): void
    {
        $kunde = $this->kunde();
        $heute = now()->toDateString();
        $this->vertrag($kunde, ['signing_date' => $heute, 'premium_amount' => 100, 'premium_interval' => 'monthly']);
        $this->vertrag($kunde, ['signing_date' => $heute, 'premium_amount' => 240, 'premium_interval' => 'yearly']);
        $this->vertrag($kunde, ['signing_date' => $heute, 'premium_amount' => 500, 'premium_interval' => 'einmalig']);

        $this->assertSame(1440.0, $this->kpi($this->auswerten(), 'wert')['wert']);
    }

    // ---------------------------------------------------------------
    // Fall 9: Portfolio-Scope
    // ---------------------------------------------------------------

    /**
     * Die Auswertung darf nie mehr zeigen als die Kundenliste. Ohne diesen
     * Schutz waere die Kennzahlenseite ein Weg, den Gesamtbestand zu
     * lesen, ohne einen einzigen Kunden oeffnen zu duerfen.
     */
    public function test_auswertung_folgt_dem_sichtbaren_portfolio(): void
    {
        $meiner = $this->kunde('50667');
        $fremder = $this->kunde('80331');
        $this->vertrag($meiner, ['signing_date' => now()->toDateString()]);
        $this->vertrag($fremder, ['signing_date' => now()->toDateString()]);

        $daten = $this->auswerten([], [$meiner->id]);

        $this->assertSame(1, $this->kpi($daten, 'monat')['wert']);
        $this->assertSame(1, $daten['kunden']['gesamt']);
        $this->assertSame(0, $daten['karte']['laender']['BY']['kunden']);
    }

    // ---------------------------------------------------------------
    // Fall 10: Insights sind nachzaehlbar - und entstehen nicht aus nichts
    // ---------------------------------------------------------------

    public function test_ohne_daten_keine_insights(): void
    {
        $this->assertSame([], $this->auswerten()['insights']);
    }

    public function test_insights_nennen_die_staerkste_region(): void
    {
        $this->kunde('50667');
        $this->kunde('50668');

        $texte = implode(' ', array_column($this->auswerten()['insights'], 'text'));

        $this->assertStringContainsString('Nordrhein-Westfalen', $texte);
        $this->assertStringContainsString('höchsten Kundenanteil', $texte);
    }

    // ---------------------------------------------------------------
    // Fall 11: Vergleichszeitraum
    // ---------------------------------------------------------------

    public function test_vergleichszeitraum_liegt_unmittelbar_davor(): void
    {
        $f = $this->filter(['zeitraum' => 'benutzerdefiniert', 'von' => '2026-03-01', 'bis' => '2026-03-31']);

        $this->assertSame('2026-03-01', $f->von->format('Y-m-d'));
        $this->assertSame('2026-03-31', $f->bis->format('Y-m-d'));
        // Gleich lange Spanne unmittelbar davor: 31 Tage vor dem 1. Maerz.
        $this->assertSame('2026-01-29', $f->vergleichVon()->format('Y-m-d'));
        $this->assertSame('2026-02-28', $f->vergleichBis()->format('Y-m-d'));
    }

    /** Verdrehte Eingabe wird gedreht, nicht abgelehnt. */
    public function test_verdrehter_zeitraum_wird_gedreht(): void
    {
        $f = $this->filter(['zeitraum' => 'benutzerdefiniert', 'von' => '2026-03-31', 'bis' => '2026-03-01']);

        $this->assertSame('2026-03-01', $f->von->format('Y-m-d'));
        $this->assertSame('2026-03-31', $f->bis->format('Y-m-d'));
    }
}
