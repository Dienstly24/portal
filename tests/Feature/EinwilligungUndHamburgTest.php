<?php

namespace Tests\Feature;

use App\Services\Seo\StructuredData;
use App\Support\Consent;
use Database\Seeders\ServicePageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Waechter fuer den Einwilligungs-Banner und die lokale Hamburg-Seite
 * (Betreiber-Auftrag 19.09.2026).
 *
 * DER WICHTIGSTE FALL STEHT GANZ UNTEN: ohne Einwilligung darf die
 * Messung nicht starten. Das ist keine Schoenheitsfrage - eine Messung,
 * ueber die der Besucher nie entschieden hat, ist genau der Zustand, den
 * Paragraph 25 TTDSG verbietet. Und sie erzeugt keine Fehlermeldung:
 * die Seite sieht in beiden Faellen gleich aus.
 */
class EinwilligungUndHamburgTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Rechtsseiten rendern LOKAL nur auf dem Website-Host - sonst
     * leiten sie regelgerecht auf die offizielle Website um (302).
     */
    private const RECHTSSEITEN = [
        'https://www.dienstly24.de/cookie-richtlinie',
        'https://www.dienstly24.de/datenschutz',
    ];

    /** Eine Anfrage mit gesetztem Cookie (tap() proxyt nur Methoden). */
    private function mitCookie(string $name, string $wert)
    {
        $request = request();
        $request->cookies->set($name, $wert);

        return $request;
    }

    /** Eine Anfrage mit gesetztem Host. */
    private function mitHost(string $host)
    {
        $request = request();
        $request->headers->set('HOST', $host);

        return $request;
    }

    private function matomoEinrichten(): void
    {
        config([
            'analytics.matomo.url' => 'https://statistik.dienstly24.de',
            'analytics.matomo.site_id' => '1',
        ]);
    }

    /** Die oeffentlichen Seiten, die den Banner tragen muessen. */
    private function oeffentlicheSeiten(): array
    {
        $this->seed(ServicePageSeeder::class);

        return ['/website', '/leistungen', '/leistungen/kfz-versicherung', '/versicherungsmakler-hamburg'];
    }

    // ---------------------------------------------------------------
    // Einwilligung: Lesart
    // ---------------------------------------------------------------

    public function test_ohne_cookie_hat_der_besucher_nicht_entschieden(): void
    {
        $this->assertNull(Consent::erteilt(request()));
        $this->assertFalse(Consent::entschieden(request()));
    }

    public function test_notwendig_ist_immer_erlaubt_und_steht_nie_zur_wahl(): void
    {
        $this->assertTrue(Consent::erlaubt(request(), Consent::NOTWENDIG));
        $this->assertNotContains(Consent::NOTWENDIG, Consent::OPTIONAL);
    }

    public function test_die_wahl_wird_aus_dem_cookie_gelesen(): void
    {
        $mit = fn (string $wert) => $this->mitCookie(Consent::COOKIE, $wert);

        $this->assertSame(['statistik'], Consent::erteilt($mit('v1:statistik')));
        $this->assertSame([], Consent::erteilt($mit('v1:')));
        // Unbekannte Kategorie im Cookie wird verworfen, nicht uebernommen.
        $this->assertSame([], Consent::erteilt($mit('v1:werbung')));
    }

    /**
     * Steigt die Fassung, gilt eine alte Wahl nicht mehr: sie bezog sich
     * auf einen anderen Satz Kategorien, und ueber eine neue hat niemand
     * entschieden.
     */
    public function test_eine_andere_fassung_gilt_als_nicht_entschieden(): void
    {
        $request = $this->mitCookie(Consent::COOKIE, 'v9:statistik');

        $this->assertNull(Consent::erteilt($request));
    }

    /**
     * Der alte Zwei-Knopf-Banner schrieb `cookie_consent=all|essential`,
     * und sein Text nannte "Statistik" ausdruecklich als das, was
     * "Alle akzeptieren" umfasst. Diese Wahl gilt weiter - alle erneut
     * zu fragen waere nicht sicherer, nur laestiger.
     */
    public function test_der_altbestand_wird_geerbt(): void
    {
        $alt = fn (string $wert) => $this->mitCookie(Consent::COOKIE_ALT, $wert);

        $this->assertSame(['statistik'], Consent::erteilt($alt('all')));
        $this->assertSame([], Consent::erteilt($alt('essential')));
        $this->assertNull(Consent::erteilt($alt('irgendwas')));
    }

    public function test_die_neue_wahl_schlaegt_den_altbestand(): void
    {
        $request = request();
        $request->cookies->set(Consent::COOKIE_ALT, 'all');
        $request->cookies->set(Consent::COOKIE, 'v1:');

        $this->assertSame([], Consent::erteilt($request));
    }

    // ---------------------------------------------------------------
    // Einwilligung: gemeinsame Domain fuer Website UND Portal
    // ---------------------------------------------------------------

    public function test_die_wahl_gilt_auf_website_und_portal(): void
    {
        config(['website.canonical_host' => 'www.dienstly24.de']);

        $this->assertSame('dienstly24.de', Consent::basisDomain());

        foreach (['www.dienstly24.de', 'portal.dienstly24.de', 'dienstly24.de'] as $host) {
            $request = $this->mitHost($host);
            $this->assertSame('.dienstly24.de', Consent::domain($request), $host);
        }
    }

    /**
     * Auf einem fremden Host darf KEINE Domain gesetzt werden - der
     * Browser verwirft ein solches Cookie, und der Banner erschiene dann
     * bei jedem Aufruf erneut, ohne dass irgendwo ein Fehler steht.
     */
    public function test_auf_fremden_hosts_bleibt_das_cookie_host_eigen(): void
    {
        config(['website.canonical_host' => 'www.dienstly24.de']);

        foreach (['localhost', '127.0.0.1', 'beispiel.test', 'nicht-dienstly24.de'] as $host) {
            $request = $this->mitHost($host);
            $this->assertNull(Consent::domain($request), $host);
        }
    }

    public function test_eine_basis_ohne_punkt_ergibt_keine_domain(): void
    {
        config(['website.canonical_host' => 'localhost']);

        $this->assertSame('', Consent::basisDomain());
        $this->assertNull(Consent::domain(request()));
    }

    // ---------------------------------------------------------------
    // Der Banner in der Oberflaeche
    // ---------------------------------------------------------------

    public function test_der_banner_steht_auf_jeder_oeffentlichen_seite(): void
    {
        $this->matomoEinrichten();

        foreach ($this->oeffentlicheSeiten() as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            $this->assertStringContainsString('id="d24-consent"', $html, $pfad);
            $this->assertStringContainsString('Alle akzeptieren', $html, $pfad);
            $this->assertStringContainsString('Ablehnen', $html, $pfad);
            $this->assertStringContainsString('Einstellungen', $html, $pfad);
        }
    }

    /** Der Widerruf muss so leicht erreichbar sein wie die Zustimmung. */
    public function test_cookie_einstellungen_stehen_im_fuss(): void
    {
        $this->matomoEinrichten();

        foreach ($this->oeffentlicheSeiten() as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            $this->assertStringContainsString('data-consent-oeffnen', $html, $pfad);
        }
    }

    /**
     * Die Auswahl darf nur nennen, was es WIRKLICH gibt. Eine Kategorie
     * "Marketing" oder "Externe Medien" waere eine Behauptung ueber den
     * Betrieb - und zwar eine falsche.
     */
    public function test_nur_kategorien_die_es_wirklich_gibt(): void
    {
        $this->matomoEinrichten();
        $this->seed(ServicePageSeeder::class);
        $html = $this->get('/website')->assertOk()->getContent();

        $this->assertStringContainsString('Notwendige Cookies', $html);
        $this->assertStringContainsString('Analyse / Statistik', $html);
        $this->assertStringNotContainsString('Marketing-Cookies', $html);
        $this->assertStringNotContainsString('Externe Medien', $html);
        $this->assertSame([Consent::STATISTIK], Consent::OPTIONAL);
    }

    /**
     * Vom Test gefunden: der Banner beschrieb "Analyse / Statistik" mit
     * "Matomo auf unserem eigenen Server" - auch dort, wo Matomo gar
     * nicht eingerichtet ist. Solange es nichts zu entscheiden gibt,
     * erscheint deshalb kein Banner; er kommt von selbst, sobald
     * MATOMO_URL gesetzt ist.
     */
    public function test_ohne_einwilligungspflichtige_technik_kein_banner(): void
    {
        config(['analytics.matomo.url' => null, 'analytics.matomo.site_id' => null]);
        $this->seed(ServicePageSeeder::class);

        $this->assertFalse(Consent::optionaleDiensteVorhanden());

        foreach ($this->oeffentlicheSeiten() as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();
            $this->assertStringNotContainsString('id="d24-consent"', $html, $pfad);
            $this->assertStringNotContainsString('Matomo', $html, $pfad);
        }

        $this->matomoEinrichten();
        $this->assertTrue(Consent::optionaleDiensteVorhanden());
        $this->assertStringContainsString(
            'id="d24-consent"',
            $this->get('/website')->assertOk()->getContent()
        );
    }

    /** Ein Einwilligungswerkzeug, das selbst einen Dritten einbindet, ist ein Widerspruch. */
    public function test_der_banner_laedt_keinen_fremden_dienst(): void
    {
        $quelle = (string) file_get_contents(resource_path('views/partials/cookie_consent.blade.php'));

        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://#i', $quelle);
    }

    // ---------------------------------------------------------------
    // DER KERNFALL: keine Messung ohne Einwilligung
    // ---------------------------------------------------------------

    public function test_die_messung_startet_erst_nach_der_einwilligung(): void
    {
        $this->matomoEinrichten();
        $this->seed(ServicePageSeeder::class);

        foreach ($this->oeffentlicheSeiten() as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            // Der Tracker haengt im Rueckruf - er laeuft NICHT beim Laden.
            $this->assertStringContainsString("d24Consent.beiFreigabe('statistik', starten)", $html, $pfad);
            // Und er wird nicht zusaetzlich unbedingt gestartet.
            $this->assertStringNotContainsString("\n    starten();", $html, $pfad);
        }
    }

    /**
     * Fehlt der Banner auf einer Seite, wird NICHT gemessen. Die sichere
     * Richtung ist "nicht messen" - ein Einbindungsfehler darf nie dazu
     * fuehren, dass ungefragt gemessen wird.
     */
    public function test_ohne_banner_wird_nicht_gemessen(): void
    {
        $this->matomoEinrichten();

        $html = $this->get('/website')->assertOk()->getContent();

        $this->assertStringContainsString('if (! window.d24Consent) { return; }', $html);
    }

    /**
     * Der Banner muss VOR dem Matomo-Partial stehen: er definiert
     * `window.d24Consent`, das Matomo abfragt. Umgekehrt wuerde nie
     * gemessen - ohne Fehlermeldung.
     */
    public function test_der_banner_steht_vor_der_messung(): void
    {
        $vorlagen = [
            'website/layout', 'website/legal-layout', 'services/show', 'services/index',
        ];

        foreach ($vorlagen as $vorlage) {
            $inhalt = (string) file_get_contents(resource_path('views/'.$vorlage.'.blade.php'));

            $banner = strpos($inhalt, 'partials.cookie_consent');
            $matomo = strpos($inhalt, 'partials.matomo');

            $this->assertNotFalse($banner, $vorlage.': Banner fehlt');
            $this->assertNotFalse($matomo, $vorlage.': Messung fehlt');
            $this->assertLessThan($matomo, $banner, $vorlage.': Banner steht HINTER der Messung');
        }
    }

    /**
     * Ob die Statistik ueberhaupt eine Einwilligung braucht, ist eine
     * RECHTSFRAGE. Der Code entscheidet sie nicht - er stellt den
     * strengeren Fall als Standard ein und laesst den Betreiber
     * umstellen.
     */
    public function test_die_strengere_auslegung_ist_der_standard(): void
    {
        $this->assertTrue(config('analytics.requires_consent'));
        $this->assertTrue(Consent::statistikBrauchtEinwilligung());
        $this->assertFalse(Consent::statistikErlaubt(request()));

        config(['analytics.requires_consent' => false]);
        $this->assertTrue(Consent::statistikErlaubt(request()));
    }

    public function test_nach_zustimmung_ist_die_statistik_erlaubt(): void
    {
        $request = $this->mitCookie(Consent::COOKIE, 'v1:statistik');

        $this->assertTrue(Consent::statistikErlaubt($request));
    }

    // ---------------------------------------------------------------
    // Hamburg
    // ---------------------------------------------------------------

    public function test_die_hamburg_seite_gibt_es_in_beiden_sprachen(): void
    {
        foreach (['/versicherungsmakler-hamburg', '/ar/versicherungsmakler-hamburg'] as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<h1[^>]*>.*Hamburg|هامبورغ#u', $html, $pfad);
            $this->assertStringContainsString(config('website.address')['street'], $html, $pfad);
            $this->assertStringContainsString('class="krumen-w"', $html, $pfad);
        }
    }

    /**
     * KEINE DOORWAY PAGE: die Ortsseite beantwortet, was nur hier gilt,
     * und verweist fuer den Fachtext auf die Leistungsseiten - statt ihn
     * mit eingesetztem Stadtnamen zu wiederholen.
     */
    public function test_die_hamburg_seite_ist_keine_kopie_der_leistungsseiten(): void
    {
        $this->seed(ServicePageSeeder::class);

        $hamburg = $this->get('/versicherungsmakler-hamburg')->assertOk()->getContent();
        $makler = $this->get('/leistungen/versicherungsmakler')->assertOk()->getContent();

        // Der ausfuehrliche Fachtext der nationalen Seite steht NICHT hier.
        $this->assertStringContainsString('Makler, Vertreter oder Vergleichsportal', $makler);
        $this->assertStringNotContainsString('Makler, Vertreter oder Vergleichsportal', $hamburg);

        // Stattdessen Verweise auf die Leistungsseiten.
        foreach (['kfz-versicherung', 'kfz-zulassung', 'krankenversicherung', 'strom-gas', 'versicherungsmakler'] as $slug) {
            $this->assertStringContainsString('/leistungen/'.$slug, $hamburg, $slug);
        }
    }

    /**
     * Dieselbe Strenge wie auf der nationalen Seite: Dienstly24 haelt die
     * Erlaubnis nach Paragraph 34d GewO NICHT selbst. Eine Ortsseite, die
     * das verschweigt, waere die bequemere - und eine falsche Angabe
     * ueber das eigene Unternehmen.
     */
    public function test_die_hamburg_seite_bleibt_bei_der_wahrheit(): void
    {
        $html = $this->get('/versicherungsmakler-hamburg')->assertOk()->getContent();

        $this->assertStringContainsString('vertraglich gebundener Vermittler', $html);
        $this->assertStringContainsString(url('/erstinformation'), $html);
        $this->assertStringNotContainsString('Wir sind Ihr Versicherungsmakler mit Erlaubnis', $html);
    }

    /** Der Standort steht genau einmal - die Seite ist keine zweite Filiale. */
    public function test_die_hamburg_seite_traegt_den_standort_und_den_pfad(): void
    {
        $html = $this->get('/versicherungsmakler-hamburg')->assertOk()->getContent();

        preg_match_all(
            '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s',
            $html,
            $treffer
        );
        $typen = array_map(
            fn (string $json) => json_decode(html_entity_decode($json, ENT_QUOTES), true)['@type'] ?? null,
            $treffer[1]
        );

        $this->assertContains('InsuranceAgency', $typen);
        $this->assertContains('BreadcrumbList', $typen);
        $this->assertSame(1, count(array_filter($typen, fn ($t) => $t === 'InsuranceAgency')));
    }

    public function test_die_hamburg_seite_steht_in_der_sitemap(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<loc>https://www.dienstly24.de/versicherungsmakler-hamburg</loc>', $xml);
        $this->assertStringContainsString(
            '<loc>https://www.dienstly24.de/ar/versicherungsmakler-hamburg</loc>', $xml);
    }

    /** Eine Seite, die von nirgends verlinkt ist, findet auch Google spaet. */
    public function test_die_hamburg_seite_ist_von_der_startseite_erreichbar(): void
    {
        $html = $this->get('/website')->assertOk()->getContent();

        $this->assertStringContainsString('/versicherungsmakler-hamburg', $html);
    }

    /** Auch die Kontaktwege der Ortsseite werden gezaehlt. */
    public function test_die_hamburg_seite_kennzeichnet_ihre_kontaktwege(): void
    {
        $html = $this->get('/versicherungsmakler-hamburg')->assertOk()->getContent();

        $this->assertStringContainsString('data-cta="telefon" data-cta-seite="hamburg"', $html);
        $this->assertStringContainsString('data-cta="whatsapp" data-cta-seite="hamburg"', $html);
        // Im arabischen Fliesstext dreht die Zweirichtungs-Regel die
        // Zifferngruppen - dieselbe Lehre wie auf den Leistungsseiten.
        $this->assertStringContainsString('<span dir="ltr">'.config('website.phone_display').'</span>', $html);
    }

    // ---------------------------------------------------------------
    // Rechtsseiten beschreiben, was WIRKLICH laeuft
    // ---------------------------------------------------------------

    public function test_die_rechtsseiten_nennen_matomo_nur_wenn_es_eingerichtet_ist(): void
    {
        config(['analytics.matomo.url' => null, 'analytics.matomo.site_id' => null]);

        foreach (self::RECHTSSEITEN as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Matomo', $html, $url);
        }

        $this->matomoEinrichten();

        foreach (self::RECHTSSEITEN as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('Matomo', $html, $url);
            $this->assertStringContainsString('gekürzt', $html, $url);
        }
    }

    /**
     * Bis zum 19.09.2026 stand in der Cookie-Richtlinie "ein
     * Cookie-Banner ist nicht erforderlich". Der Satz war richtig,
     * solange es keine Messung gab - und waere am Tag der Einrichtung
     * still falsch geworden.
     */
    public function test_der_ueberholte_satz_steht_nicht_mehr_da(): void
    {
        $html = $this->get(self::RECHTSSEITEN[0])->assertOk()->getContent();

        $this->assertStringNotContainsString('kein Cookie-Banner erforderlich', $html);
        $this->assertStringNotContainsString('Cookie-Banner ist daher nicht erforderlich', $html);
    }

    /**
     * DER BANNER DARF NICHTS VERDECKEN (Betreiber-Vorgabe: weder eine
     * Ablehnung noch eine unbeantwortete Frage darf jemanden am
     * Anmelden hindern).
     *
     * Am 19.09.2026 im Browser gemessen und genau so vorgefunden: auf
     * dem iPhone lag die Leiste ueber dem GESAMTEN Anmeldeformular samt
     * Knopf, und zwar unerreichbar - die Anmeldeseite war 808 px hoch,
     * das Fenster 664 px, also 144 px Bildlauf moeglich; der Knopf bei
     * 522 px blieb danach immer noch unter der Leiste. Anmelden war
     * ohne Beantworten der Cookie-Frage schlicht unmoeglich.
     *
     * Eine Layout-Ueberdeckung laesst sich hier nicht messen - dieser
     * Test haelt deshalb die zwei Eigenschaften fest, aus denen die
     * Erreichbarkeit folgt, und die eine kuenftige Aenderung still
     * entfernen koennte.
     */
    public function test_der_banner_reserviert_seinen_platz(): void
    {
        $this->matomoEinrichten();

        $html = $this->get('/login')->assertOk()->getContent();

        // Er misst seine Hoehe und haengt sie unten an den Seiteninhalt.
        $this->assertStringContainsString('raumReservieren', $html);
        $this->assertStringContainsString('banner.offsetHeight', $html);
        $this->assertStringContainsString("document.body.style.paddingBottom = 'calc(", $html);
        // Und gibt sie wieder frei, sobald entschieden ist - sonst
        // bliebe am Seitenende dauerhaft ein leerer Streifen stehen.
        $this->assertStringContainsString('raumFreigeben', $html);
    }

    /**
     * Die drei Knoepfe muessen in jeder Lage sichtbar bleiben. Lag der
     * ganze Kasten im Bildlauf, rutschte auf dem Telefon ausgerechnet
     * "Ablehnen" unter die Kante - und eine Ablehnung, die schwerer zu
     * erreichen ist als eine Zustimmung, ist keine freiwillige
     * Einwilligung (Art. 4 Nr. 11, Art. 7 Abs. 3 DSGVO).
     */
    public function test_nur_der_text_scrollt_nie_die_knoepfe(): void
    {
        $this->matomoEinrichten();

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('.d24-consent-text{max-height:', $html);
        $this->assertStringContainsString('overflow-y:auto', $html);

        // Der Knopfblock liegt AUSSERHALB des scrollenden Textblocks.
        $text = strpos($html, '<div class="d24-consent-text">');
        $knoepfe = strpos($html, '<div class="d24-consent-knoepfe">');
        $ende = strpos($html, '</div>', $text);
        $this->assertNotFalse($text);
        $this->assertNotFalse($knoepfe);
        $this->assertGreaterThan($ende, $knoepfe,
            'Die Knoepfe stehen im scrollenden Textblock - "Ablehnen" kann dadurch unter die Bildschirmkante rutschen.');
    }

    /**
     * Die Anmeldung ist kein Ort fuer eine Abfrage ueber einen Dienst,
     * der dort gar nicht laeuft: im Portal wird NIE gemessen. Der
     * Banner steht dort trotzdem, weil die Entscheidung fuer beide
     * Adressen gilt - aber ein Messskript darf die Anmeldeseite nie
     * erreichen.
     */
    public function test_auf_der_anmeldeseite_wird_nie_gemessen(): void
    {
        $this->matomoEinrichten();

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('d24-consent', $html);
        $this->assertStringNotContainsString('_paq', $html);
        $this->assertStringNotContainsString('matomo.js', $html);
    }

    /**
     * OEFFNUNGSZEITEN HABEN EINE QUELLE.
     *
     * Bis 19.09.2026 standen sie doppelt: als Text auf der Seite und
     * fest verdrahtet im `InsuranceAgency`-Schema. Beide sahen fuer sich
     * richtig aus - wer eine davon aendert, erzeugt genau den
     * Widerspruch, den Google beim Abgleich mit dem Unternehmensprofil
     * bemaengelt, und niemand sieht ihn.
     *
     * Der Test aendert die EINE Quelle und verlangt, dass BEIDE Stellen
     * mitgehen. Mit den alten festen Werten scheitert er.
     */
    public function test_oeffnungszeiten_stehen_nur_an_einer_stelle(): void
    {
        config(['website.opening_hours' => [
            'tage' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'von' => '08:30',
            'bis' => '17:15',
        ]]);

        $html = $this->get('https://www.dienstly24.de/versicherungsmakler-hamburg')
            ->assertOk()->getContent();

        // Die Seite zeigt die neuen Zeiten ...
        $this->assertStringContainsString('08:30', $html);
        $this->assertStringContainsString('17:15', $html);
        $this->assertStringNotContainsString('09:00', $html);

        // ... und das Schema nennt dieselben.
        $daten = StructuredData::insuranceAgency();
        $this->assertSame('08:30', $daten['openingHoursSpecification'][0]['opens']);
        $this->assertSame('17:15', $daten['openingHoursSpecification'][0]['closes']);
    }

    /**
     * DER ZWEI-WEGE-LINK ENTSTEHT NICHT AUS DEM NICHTS: ohne
     * hinterlegtes Unternehmensprofil erscheint kein Knopf. Ein Link auf
     * ein Profil, das es nicht gibt, waere eine falsche Angabe ueber das
     * eigene Unternehmen - dieselbe Regel wie bei `sameAs`.
     */
    public function test_ohne_unternehmensprofil_kein_link_darauf(): void
    {
        config(['website.google_business' => null]);

        $html = $this->get('https://www.dienstly24.de/versicherungsmakler-hamburg')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('data-cta="google-profil"', $html);
        $this->assertStringNotContainsString('Profil bei Google', $html);
    }

    public function test_mit_unternehmensprofil_verlinkt_die_seite_es_sichtbar(): void
    {
        $profil = 'https://maps.app.goo.gl/beispiel';
        config([
            'website.google_business' => $profil,
            'website.social' => ['https://www.facebook.com/Dienstly24', $profil],
        ]);

        foreach (['/versicherungsmakler-hamburg', '/ar/versicherungsmakler-hamburg'] as $pfad) {
            $html = $this->get('https://www.dienstly24.de'.$pfad)->assertOk()->getContent();

            // Sichtbar auf der Seite ...
            $this->assertStringContainsString($profil, $html, $pfad);
            $this->assertStringContainsString('data-cta="google-profil"', $html, $pfad);
            // ... und als Entity-Signal in den strukturierten Daten.
            $this->assertStringContainsString('sameAs', $html, $pfad);
        }
    }
}
