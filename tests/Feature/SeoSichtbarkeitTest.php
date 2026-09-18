<?php

namespace Tests\Feature;

use App\Models\ServicePage;
use App\Services\Seo\StructuredData;
use Database\Seeders\ServicePageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Waechter fuer die Auffindbarkeit der oeffentlichen Seiten
 * (SEO-Auftrag 02.10.2026).
 *
 * WARUM DIESE FEHLERKLASSE EINEN TEST BRAUCHT: keiner der hier geprueften
 * Maengel erzeugt einen Fehler. Kein 500er, keine Konsolenmeldung, kein
 * kaputtes Layout - die Seite sieht in jedem Fall normal aus. Ein
 * fehlendes og:image faellt erst auf, wenn ein Kunde den Link bei WhatsApp
 * teilt und ein grauer Kasten erscheint; ein Titel mit dem Markennamen
 * vorn faellt nie auf, er kostet nur ueber Monate Klicks. Genau wie bei
 * den strukturierten Daten (Audit 15.09.2026) ist das AUSGELIEFERTE HTML
 * der einzige verlaessliche Massstab.
 */
class SeoSichtbarkeitTest extends TestCase
{
    use RefreshDatabase;

    private function seiten(): array
    {
        $this->seed(ServicePageSeeder::class);

        $seiten = ['/leistungen'];
        foreach (ServicePage::active()->pluck('slug') as $slug) {
            $seiten[] = '/leistungen/'.$slug;
        }

        return $seiten;
    }

    /** Alle JSON-LD-Bloecke einer Antwort als Arrays. */
    private function schemata(string $html): array
    {
        preg_match_all(
            '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s',
            $html,
            $treffer
        );

        return array_map(
            fn (string $json) => json_decode(html_entity_decode($json, ENT_QUOTES), true),
            $treffer[1]
        );
    }

    public function test_titel_nennt_die_leistung_vor_der_marke(): void
    {
        $this->seed(ServicePageSeeder::class);
        $seite = ServicePage::active()->where('slug', 'kfz-versicherung')->firstOrFail();

        $html = $this->get('/leistungen/kfz-versicherung')->assertOk()->getContent();

        preg_match('#<title>(.*?)</title>#s', $html, $m);
        $titel = trim($m[1] ?? '');

        $this->assertSame($seite->title_de.' | Dienstly24', $titel);
        $this->assertStringStartsNotWith('Dienstly24', $titel,
            'Google kuerzt von rechts: der Markenname gehoert ans Ende, nicht an den Anfang.');
    }

    public function test_jede_leistungsseite_hat_eine_beschreibung_und_ein_teilbild(): void
    {
        foreach ($this->seiten() as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '#<meta name="description" content="[^"]{50,}"#', $html,
                "Ohne Beschreibung baut Google sich einen Auszug zusammen: $pfad"
            );
            $this->assertStringContainsString('property="og:image"', $html,
                "Ohne og:image zeigt jedes Teilen einen grauen Kasten: $pfad");
            $this->assertStringContainsString('name="twitter:card"', $html, $pfad);
        }
    }

    public function test_jede_leistungsseite_traegt_einen_brotkrumen_pfad(): void
    {
        foreach ($this->seiten() as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            $typen = array_column(array_filter($this->schemata($html)), '@type');
            $this->assertContains('BreadcrumbList', $typen,
                "BreadcrumbList fehlt (sichtbarer Pfad in der Trefferliste): $pfad");

            // Der Pfad muss auch SICHTBAR sein - Schema ohne sichtbares
            // Gegenstueck ist laut Google-Richtlinie unzulaessig.
            $this->assertStringContainsString('class="krumen"', $html, $pfad);
        }
    }

    public function test_brotkrumen_zeigen_auf_den_kanonischen_host(): void
    {
        $this->seed(ServicePageSeeder::class);
        $html = $this->get('/leistungen/kfz-versicherung')->assertOk()->getContent();

        $krumen = collect(array_filter($this->schemata($html)))
            ->firstWhere('@type', 'BreadcrumbList');

        $this->assertNotNull($krumen);
        $this->assertCount(3, $krumen['itemListElement']);
        $this->assertSame('https://www.dienstly24.de/', $krumen['itemListElement'][0]['item']);
        $this->assertSame('https://www.dienstly24.de/leistungen', $krumen['itemListElement'][1]['item']);
        $this->assertSame(
            'https://www.dienstly24.de/leistungen/kfz-versicherung',
            $krumen['itemListElement'][2]['item']
        );
    }

    /**
     * Eine Leistungsseite darf keine Sackgasse sein: ohne Ausgang erreicht
     * die Kfz-Versicherung die Kfz-Zulassung nie, obwohl beide zum selben
     * Anliegen gehoeren.
     */
    public function test_leistungsseiten_verlinken_verwandte_leistungen(): void
    {
        $this->seed(ServicePageSeeder::class);
        $html = $this->get('/leistungen/kfz-versicherung')->assertOk()->getContent();

        $this->assertStringContainsString('class="weiter"', $html);

        preg_match_all('#href="/leistungen/([a-z0-9-]+)"#', $html, $treffer);
        $ziele = array_unique(array_diff($treffer[1], ['kfz-versicherung']));

        $this->assertGreaterThanOrEqual(3, count($ziele),
            'Zu wenige interne Ausgaenge - die Seite bleibt eine Sackgasse.');
    }

    /**
     * Die arabische Fassung darf nicht auf die deutschen Adressen
     * verlinken: der Besucher verliesse damit seine Sprachversion, und
     * Google saehe eine arabische Seite, die nur deutsche Seiten verlinkt.
     */
    public function test_arabische_fassung_bleibt_in_ihrer_sprachversion(): void
    {
        $this->seed(ServicePageSeeder::class);

        foreach (['/ar/leistungen', '/ar/leistungen/kfz-versicherung'] as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            $this->assertStringContainsString('href="/ar/leistungen', $html, $pfad);
            $this->assertStringNotContainsString('href="/sprache/de"', $html,
                'Der Sprachwechsel muss auf die ECHTE Adresse der anderen Fassung fuehren.');
        }
    }

    /**
     * Im Browser gefunden, nicht im Test: die Brotkrumen standen auf der
     * arabischen Seite auf DEUTSCH ("Startseite | Leistungen | وسيط تأمين").
     * `lang/ar.json` kannte die beiden Woerter schlicht nicht, und `__()`
     * gibt dann den Schluessel zurueck - ohne Fehler, ohne Warnung. Dieselbe
     * Luecke wie bei `lang/ar/validation.php` (18.08.2026) und
     * `lang/de/validation.php` (10.09.2026).
     */
    public function test_brotkrumen_sind_in_der_arabischen_fassung_arabisch(): void
    {
        $this->seed(ServicePageSeeder::class);

        $html = $this->get('/ar/leistungen/kfz-versicherung')->assertOk()->getContent();

        preg_match('#<nav class="krumen".*?</nav>#s', $html, $m);
        $krumen = $m[0] ?? '';

        $this->assertNotSame('', $krumen);
        $this->assertStringNotContainsString('Startseite', $krumen);
        $this->assertStringNotContainsString('>Leistungen<', $krumen);
        $this->assertStringContainsString('الصفحة الرئيسية', $krumen);
        $this->assertStringContainsString('الخدمات', $krumen);
    }

    /** Die Pflichtangaben gehoeren auf jede oeffentliche Seite, nicht nur auf die Startseite. */
    public function test_pflichtangaben_stehen_auf_jeder_leistungsseite(): void
    {
        foreach ($this->seiten() as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            foreach (['/impressum', '/datenschutz', '/erstinformation', '/agb', '/widerruf'] as $pflicht) {
                $this->assertStringContainsString('href="'.url($pflicht).'"', $html,
                    "Pflichtangabe $pflicht fehlt auf $pfad");
            }
        }
    }

    /** Telefon und WhatsApp sind auf dem Telefon der kuerzeste Weg zur Anfrage. */
    public function test_leistungsseiten_bieten_telefon_und_whatsapp(): void
    {
        $this->seed(ServicePageSeeder::class);
        $html = $this->get('/leistungen/kfz-versicherung')->assertOk()->getContent();

        $this->assertStringContainsString('href="tel:'.config('website.phone_e164').'"', $html);
        $this->assertStringContainsString('https://wa.me/'.config('website.whatsapp'), $html);
    }

    /**
     * Ebenfalls erst im Browser sichtbar geworden: im arabischen
     * Fliesstext dreht die Zweirichtungs-Regel die Zifferngruppen um -
     * "+49 179 9673909" stand als "9673909 179 49+" auf der Seite. Das ist
     * keine Schoenheitsfrage: eine so abgetippte Nummer erreicht niemanden.
     */
    public function test_telefonnummer_steht_auch_arabisch_linkslaeufig(): void
    {
        $this->seed(ServicePageSeeder::class);

        $html = $this->get('/ar/leistungen/kfz-versicherung')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<span dir="ltr">'.config('website.phone_display').'</span>',
            $html
        );
    }

    /**
     * Die wichtigste kommerzielle Suchabsicht des Betriebs hatte bis zum
     * SEO-Auftrag keine eigene Seite.
     */
    public function test_versicherungsmakler_seite_existiert_und_bleibt_bei_der_wahrheit(): void
    {
        $this->seed(ServicePageSeeder::class);

        $html = $this->get('/leistungen/versicherungsmakler')->assertOk()->getContent();

        $this->assertStringContainsString('Versicherungsmakler', $html);

        // KERNPUNKT: Dienstly24 haelt die Erlaubnis nach Paragraph 34d NICHT
        // selbst (siehe /erstinformation) - die Seite darf das nie behaupten.
        $this->assertStringNotContainsString('Wir sind Ihr Versicherungsmakler mit Erlaubnis', $html);
        $this->assertStringContainsString('vertraglich gebundener Vermittler', $html);
        $this->assertStringContainsString(url('/erstinformation'), $html);
    }

    /** Die neue Seite muss auch in der Sitemap stehen, sonst findet Google sie spaet. */
    public function test_sitemap_enthaelt_die_neue_landingpage_in_beiden_sprachen(): void
    {
        $this->seed(ServicePageSeeder::class);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<loc>https://www.dienstly24.de/leistungen/versicherungsmakler</loc>', $xml);
        $this->assertStringContainsString(
            '<loc>https://www.dienstly24.de/ar/leistungen/versicherungsmakler</loc>', $xml);
    }

    /**
     * `sameAs` verbindet die Website mit den uebrigen Auftritten derselben
     * Marke. Ein erfundenes Profil waere eine falsche Unternehmensangabe -
     * deshalb darf die Liste nur enthalten, was konfiguriert ist.
     */
    public function test_keine_erfundenen_profile_in_den_strukturierten_daten(): void
    {
        config(['website.social' => ['https://www.facebook.com/Dienstly24']]);

        $this->assertSame(['https://www.facebook.com/Dienstly24'], StructuredData::sameAs());
        $this->assertSame(
            ['https://www.facebook.com/Dienstly24'],
            StructuredData::organization()['sameAs']
        );

        config(['website.social' => []]);
        $this->assertSame([], StructuredData::sameAs());
    }

    /** Ein Pfad mit nur einer Stufe ist kein Pfad - er gehoert nicht ausgezeichnet. */
    public function test_brotkrumen_mit_einer_stufe_ergeben_kein_schema(): void
    {
        $this->assertNull(StructuredData::breadcrumbList([['Startseite', '/']]));
        $this->assertNull(StructuredData::breadcrumbList([['Startseite', ''], ['', '/x']]));
        $this->assertNotNull(StructuredData::breadcrumbList([['Startseite', '/'], ['Leistungen', '/leistungen']]));
    }

    /** Die Startseite benennt sich als Website der Marke - genau einmal. */
    public function test_startseite_traegt_website_schema_die_unterseiten_nicht(): void
    {
        $this->seed(ServicePageSeeder::class);

        $start = array_column(array_filter($this->schemata(
            $this->get('/website')->assertOk()->getContent()
        )), '@type');
        $this->assertContains('WebSite', $start);
        $this->assertContains('InsuranceAgency', $start);

        $unterseite = array_column(array_filter($this->schemata(
            $this->get('/leistungen/kfz-versicherung')->assertOk()->getContent()
        )), '@type');
        $this->assertNotContains('WebSite', $unterseite);

        // Eine Unterseite ist auch kein zweiter Standort: sie traegt die
        // schlanke Organization, nie eine weitere InsuranceAgency mit
        // Anschrift und Oeffnungszeiten.
        $this->assertContains('Organization', $unterseite);
        $this->assertNotContains('InsuranceAgency', $unterseite);
    }
}
