<?php

namespace Tests\Feature;

use App\Support\Erreichbarkeit;
use App\Support\GoogleBewertung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vertrauens-Karte zum Google-Unternehmensprofil (Betreiber-Wunsch
 * 19.09.2026: "die Kunden sollen den Eintrag sehen" - aber der Betrieb
 * arbeitet ausschliesslich online, niemand soll vorbeikommen).
 *
 * Die Tests halten die drei Zusagen fest, die diese Loesung ueberhaupt
 * erst vertretbar machen:
 *   1. KEIN Fremdzugriff aus dem Browser des Besuchers,
 *   2. keine erfundene Zahl,
 *   3. keine Einladung zum Besuch.
 */
class GoogleBewertungTest extends TestCase
{
    use RefreshDatabase;

    private const PROFIL = 'https://maps.google.com/?cid=123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(GoogleBewertung::CACHE_KEY);
        config(['website.google_business' => self::PROFIL]);
    }

    private function seiten(): array
    {
        return ['/', '/versicherungsmakler-hamburg', '/ar', '/ar/versicherungsmakler-hamburg'];
    }

    private function html(string $pfad): string
    {
        return $this->get('https://www.dienstly24.de'.$pfad)->assertOk()->getContent();
    }

    // ---------------------------------------------------------------
    // 1. Kein Fremdzugriff
    // ---------------------------------------------------------------

    /**
     * DER KERN DER GANZEN LOESUNG: kein eingebetteter Kartenrahmen und
     * ueberhaupt kein Google-Host, den der Browser des Besuchers laedt.
     * Ein iframe wuerde die IP jedes Besuchers an Google senden, bevor
     * er zugestimmt hat - dieselbe Klasse Fremdzugriff, wegen der die
     * Schriften lokal liegen.
     *
     * Der PROFIL-LINK ist ausdruecklich erlaubt: ein Link laedt nichts,
     * er wird erst durch einen Klick zum Aufruf.
     */
    public function test_keine_google_ressource_wird_vom_besucher_geladen(): void
    {
        Cache::put(GoogleBewertung::CACHE_KEY, [
            'rating' => 4.8, 'anzahl' => 37, 'stand' => now()->toDateTimeString(),
        ]);

        foreach ($this->seiten() as $pfad) {
            $html = $this->html($pfad);

            $this->assertStringNotContainsString('<iframe', $html, $pfad);
            $this->assertStringNotContainsString('maps.googleapis.com', $html, $pfad);
            $this->assertStringNotContainsString('maps.google.com/maps/embed', $html, $pfad);
            $this->assertStringNotContainsString('google.com/maps/embed', $html, $pfad);

            // Kein Google-Host in src=/href= AUSSER dem Profil-Link.
            preg_match_all('#(?:src|href)="(https?://[^"]*google[^"]*)"#i', $html, $treffer);
            foreach ($treffer[1] as $url) {
                $this->assertSame(self::PROFIL, $url, $pfad.' -> '.$url);
            }
        }
    }

    /** Die Inhaltsrichtlinie bleibt unangetastet - kein Google in frame-src. */
    public function test_die_inhaltsrichtlinie_oeffnet_sich_nicht_fuer_google(): void
    {
        $csp = (string) $this->get('https://www.dienstly24.de/')
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("frame-src 'self'", $csp);
        $this->assertStringNotContainsString('google.com', $csp);
    }

    // ---------------------------------------------------------------
    // 2. Nichts wird erfunden
    // ---------------------------------------------------------------

    /** Ohne hinterlegtes Profil erscheint die Karte gar nicht. */
    public function test_ohne_profil_keine_karte(): void
    {
        config(['website.google_business' => null]);

        foreach ($this->seiten() as $pfad) {
            $html = $this->html($pfad);
            $this->assertStringNotContainsString('class="gtrust"', $html, $pfad);
            $this->assertStringNotContainsString('data-cta="google-profil"', $html, $pfad);
        }
    }

    /** Profil ja, Zahlen nein: Karte ohne Bewertung - nie eine geratene. */
    public function test_ohne_abgerufene_zahlen_keine_bewertung(): void
    {
        $html = $this->html('/versicherungsmakler-hamburg');

        $this->assertStringContainsString('data-cta="google-profil"', $html);
        $this->assertStringNotContainsString('class="gtrust-sterne"', $html);
        $this->assertStringNotContainsString('Bewertungen von Google', $html);
    }

    /**
     * Ein Profil ohne eine einzige Bewertung zeigt KEINE Zahl:
     * "0,0 (0 Bewertungen)" liest sich wie ein schlechtes Ergebnis,
     * bedeutet aber nur, dass noch niemand bewertet hat.
     */
    public function test_null_bewertungen_werden_nicht_angezeigt(): void
    {
        Cache::put(GoogleBewertung::CACHE_KEY, [
            'rating' => 0.0, 'anzahl' => 0, 'stand' => now()->toDateTimeString(),
        ]);

        $this->assertNull(GoogleBewertung::stand());
        $this->assertStringNotContainsString('class="gtrust-sterne"', $this->html('/versicherungsmakler-hamburg'));
    }

    /** Mit Zahlen: Wert, Anzahl und Quellenangabe stehen auf der Seite. */
    public function test_mit_zahlen_zeigt_die_karte_bewertung_und_quelle(): void
    {
        Cache::put(GoogleBewertung::CACHE_KEY, [
            'rating' => 4.75, 'anzahl' => 12, 'stand' => now()->toDateTimeString(),
        ]);

        $html = $this->html('/versicherungsmakler-hamburg');

        $this->assertStringContainsString('4,8', $html);
        $this->assertStringContainsString('12 Bewertungen', $html);
        // Pflicht-Quellenangabe fuer Places-Daten ausserhalb einer Karte.
        $this->assertStringContainsString('Bewertungen von Google', $html);
    }

    public function test_eine_einzelne_bewertung_wird_im_singular_genannt(): void
    {
        Cache::put(GoogleBewertung::CACHE_KEY, [
            'rating' => 5.0, 'anzahl' => 1, 'stand' => now()->toDateTimeString(),
        ]);

        $html = $this->html('/versicherungsmakler-hamburg');

        $this->assertStringContainsString('(1 Bewertung)', $html);
        $this->assertStringNotContainsString('(1 Bewertungen)', $html);
    }

    /**
     * EIN ALTER WERT VERSCHWINDET VON SELBST. Die Ablaufzeit des
     * Cache-Eintrags IST die Regel - es gibt keine zweite Pruefung, die
     * jemand vergessen koennte. Sonst stuende eine Bewertung von vor
     * Monaten als aktuelle auf der Seite (UWG).
     */
    public function test_ein_veralteter_stand_faellt_weg(): void
    {
        Cache::put(GoogleBewertung::CACHE_KEY, [
            'rating' => 4.8, 'anzahl' => 37, 'stand' => now()->toDateTimeString(),
        ], now()->addDays(GoogleBewertung::MAX_ALTER_TAGE));

        $this->assertNotNull(GoogleBewertung::stand());

        $this->travel(GoogleBewertung::MAX_ALTER_TAGE + 1)->days();

        $this->assertNull(GoogleBewertung::stand());
        $this->assertStringNotContainsString('class="gtrust-sterne"', $this->html('/versicherungsmakler-hamburg'));
    }

    /** Sternreihe folgt rechnerisch der Zahl - keine zweite Quelle. */
    public function test_sterne_folgen_der_zahl(): void
    {
        $this->assertSame(
            ['voll', 'voll', 'voll', 'voll', 'voll'],
            GoogleBewertung::sterne(5.0)
        );
        $this->assertSame(
            ['voll', 'voll', 'voll', 'voll', 'halb'],
            GoogleBewertung::sterne(4.5)
        );
        $this->assertSame(
            ['voll', 'voll', 'voll', 'leer', 'leer'],
            GoogleBewertung::sterne(3.0)
        );
    }

    // ---------------------------------------------------------------
    // 3. Keine Einladung zum Besuch
    // ---------------------------------------------------------------

    /**
     * Die Karte macht aus der Sichtbarkeit keinen Besuchsbetrieb: sie
     * sagt ausdruecklich, dass alles online laeuft, und verspricht
     * keine Anfahrt.
     */
    public function test_die_karte_laedt_nicht_zum_besuch_ein(): void
    {
        Cache::put(GoogleBewertung::CACHE_KEY, [
            'rating' => 4.8, 'anzahl' => 37, 'stand' => now()->toDateTimeString(),
        ]);

        $html = $this->html('/versicherungsmakler-hamburg');

        $this->assertStringContainsString('Sie müssen nirgendwo hinkommen', $html);
        $this->assertStringNotContainsString('Anfahrt', $html);
        $this->assertStringNotContainsString('Route', $html);
        $this->assertStringNotContainsString('vorbeikommen', $html);
    }

    public function test_die_karte_steht_auch_auf_arabisch_richtig(): void
    {
        Cache::put(GoogleBewertung::CACHE_KEY, [
            'rating' => 4.8, 'anzahl' => 37, 'stand' => now()->toDateTimeString(),
        ]);

        $html = $this->html('/ar/versicherungsmakler-hamburg');

        $this->assertStringContainsString('أونلاين', $html);
        $this->assertStringContainsString('37', $html);
        // Die Zahl selbst bleibt LTR - eine gedrehte Bewertung waere
        // dieselbe Falle wie die gedrehte Telefonnummer (02.10.2026).
        $this->assertMatchesRegularExpression('#<span class="gtrust-zahl" dir="ltr">#', $html);
    }

    /** Die Karte erscheint dort, wo Vertrauen gebraucht wird. */
    public function test_die_karte_steht_auf_startseite_und_ortsseite(): void
    {
        foreach (['/' => 'startseite', '/versicherungsmakler-hamburg' => 'hamburg'] as $pfad => $seite) {
            $this->assertStringContainsString(
                'data-cta="google-profil" data-cta-seite="'.$seite.'"',
                $this->html($pfad),
                $pfad
            );
        }
    }

    // ---------------------------------------------------------------
    // Abruf
    // ---------------------------------------------------------------

    /** Der Abruf legt genau das ab, was die Seite liest. */
    public function test_der_abruf_speichert_den_stand(): void
    {
        config([
            'services.google_places.api_key' => 'test-key',
            'services.google_places.place_id' => 'ChIJtestplace',
        ]);

        Http::fake(['places.googleapis.com/*' => Http::response([
            'rating' => 4.7,
            'userRatingCount' => 21,
            'displayName' => ['text' => 'Dienstly24'],
        ])]);

        $this->artisan('google:bewertungen-holen')->assertSuccessful();

        $stand = GoogleBewertung::stand();
        $this->assertSame(4.7, $stand['rating']);
        $this->assertSame(21, $stand['anzahl']);
    }

    /**
     * EIN FEHLSCHLAG LOESCHT DEN BISHERIGEN STAND NICHT. Eine einzelne
     * Stoerung bei Google soll die Bewertung nicht sofort von der Seite
     * nehmen - dafuer gibt es den Ablauf nach MAX_ALTER_TAGE.
     */
    public function test_ein_fehlschlag_wirft_den_bisherigen_stand_nicht_weg(): void
    {
        config([
            'services.google_places.api_key' => 'test-key',
            'services.google_places.place_id' => 'ChIJtestplace',
        ]);
        Cache::put(GoogleBewertung::CACHE_KEY, [
            'rating' => 4.8, 'anzahl' => 37, 'stand' => now()->toDateTimeString(),
        ], now()->addDays(GoogleBewertung::MAX_ALTER_TAGE));

        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['message' => 'kaputt']], 500)]);

        $this->artisan('google:bewertungen-holen')->assertFailed();

        $this->assertSame(37, GoogleBewertung::stand()['anzahl']);
    }

    /** Ohne Einrichtung laeuft der Befehl still durch - kein Fehlalarm. */
    public function test_ohne_einrichtung_ist_der_abruf_kein_fehler(): void
    {
        config([
            'services.google_places.api_key' => null,
            'services.google_places.place_id' => null,
        ]);

        Http::fake();

        $this->artisan('google:bewertungen-holen')->assertSuccessful();

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // Zeiten: die dritte Kopie
    // ---------------------------------------------------------------

    /**
     * Die Zeiten standen nach dem 19.09.2026 immer noch DREIMAL im
     * Bestand: Hamburg-Seite, Schema - und fest verdrahtet im
     * Kontaktblock der Startseite. Eine gemeinsame Quelle fuer den WERT
     * genuegt nicht, solange jede Vorlage die Zeile selbst formatiert.
     *
     * Der Test aendert die EINE Quelle und verlangt, dass BEIDE Seiten
     * mitgehen. Mit der alten festen Zeile auf der Startseite ist er rot.
     */
    public function test_die_zeiten_stehen_wirklich_nur_an_einer_stelle(): void
    {
        config(['website.opening_hours' => [
            'tage' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'von' => '08:15',
            'bis' => '16:45',
        ]]);

        foreach (['/', '/versicherungsmakler-hamburg'] as $pfad) {
            $html = $this->html($pfad);

            $this->assertStringContainsString('08:15', $html, $pfad);
            $this->assertStringContainsString('16:45', $html, $pfad);
            $this->assertStringNotContainsString('9:00–18:00', $html, $pfad);
        }
    }

    public function test_die_kurzform_der_zeiten_ist_lesbar(): void
    {
        config(['website.opening_hours' => [
            'tage' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'von' => '09:00',
            'bis' => '18:00',
        ]]);

        $this->assertSame('Mo-Fr, 09:00 – 18:00 Uhr', Erreichbarkeit::kurz(false));
        $this->assertSame('Montag bis Freitag, 09:00 – 18:00 Uhr', Erreichbarkeit::zeile(false));
        $this->assertStringContainsString('الاثنين', Erreichbarkeit::zeile(true));
    }

    /** Unvollstaendige Angaben ergeben keinen halben Satz. */
    public function test_ohne_zeiten_entsteht_keine_zeile(): void
    {
        config(['website.opening_hours' => ['tage' => [], 'von' => '', 'bis' => '']]);

        $this->assertSame('', Erreichbarkeit::zeile(false));
        $this->assertSame('', Erreichbarkeit::kurz(false));
    }

    /**
     * UNSICHTBARER KNOPF (im Browser gefunden, von keinem Test
     * gemeldet): `.btn-ghost` faerbt seinen Text mit `--dark-text`
     * (#F5F3EC) - er ist fuer die DUNKLEN Abschnitte gedacht (Hero,
     * Kundenstimmen). Die Hamburg-Seite und der Kontaktblock sind
     * HELL (#F8F6F0 / #F1EEE5): dort stand naht-weisse Schrift auf
     * naht-weissem Grund, der Knopf war praktisch unlesbar.
     *
     * Fuer helle Flaechen gibt es `.btn-ghost-light` - es fehlte nur
     * das "-light". Ein Test am Markup, weil genau diese Klasse beim
     * naechsten neuen Knopf wieder falsch abgeschrieben wird.
     */
    public function test_helle_abschnitte_nutzen_den_hellen_ghost_knopf(): void
    {
        foreach (['/versicherungsmakler-hamburg', '/ar/versicherungsmakler-hamburg'] as $pfad) {
            $html = $this->html($pfad);

            $this->assertStringNotContainsString('class="btn btn-ghost"', $html, $pfad);
            $this->assertStringContainsString('btn-ghost-light', $html, $pfad);
        }
    }
}
