<?php

namespace Tests\Feature;

use App\Models\ServicePage;
use App\Services\Seo\StructuredData;
use Database\Seeders\ServicePageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\View\Compilers\BladeCompiler;
use Tests\TestCase;

/**
 * Waechter fuer die strukturierten Daten (Audit 15.09.2026).
 *
 * VORGESCHICHTE: Die JSON-LD-Bloecke standen als `json_encode([...])` in
 * der Blade-Vorlage. Blade uebersetzte den Array-Schluessel '@context'
 * als eigene DIREKTIVE - im ausgelieferten HTML stand deshalb PHP-
 * Quelltext statt des Schluessels, auf der Startseite UND auf allen 21
 * Leistungsseiten. Google konnte weder InsuranceAgency noch FAQPage
 * lesen; die Rich Results fielen vollstaendig aus.
 *
 * Es gab dabei KEINEN Fehler: kein 500er, keine Konsolenmeldung, die
 * Seite sah normal aus. Genau deshalb braucht es einen Test, der das
 * AUSGELIEFERTE HTML prueft - nicht den Quelltext der Vorlage.
 */
class StructuredDataTest extends TestCase
{
    use RefreshDatabase;

    /** Die oeffentlichen Seiten, die strukturierte Daten tragen. */
    private function oeffentlicheSeiten(): array
    {
        $seiten = ['/website', '/leistungen'];

        foreach (ServicePage::active()->pluck('slug') as $slug) {
            $seiten[] = '/leistungen/'.$slug;
        }

        return $seiten;
    }

    private function seiten(): array
    {
        $this->seed(ServicePageSeeder::class);

        return $this->oeffentlicheSeiten();
    }

    /**
     * DER KERNTEST: in einer HTML-Antwort darf NIE PHP-Quelltext stehen.
     * Das faengt die gesamte Fehlerklasse ab, nicht nur '@context'.
     */
    public function test_keine_php_quelle_im_ausgelieferten_html(): void
    {
        $treffer = [];

        foreach ($this->seiten() as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            foreach (['<?php', '<?=', '$__env', '$__contextArgs', 'endcontext'] as $spur) {
                if (str_contains($html, $spur)) {
                    $treffer[] = $pfad.' enthaelt "'.$spur.'"';
                }
            }
        }

        $this->assertSame([], $treffer,
            "PHP-Quelltext im ausgelieferten HTML:\n".implode("\n", $treffer));
    }

    /**
     * Jeder JSON-LD-Block muss gueltiges JSON sein UND einen echten
     * @ context-Schluessel auf schema.org tragen.
     */
    public function test_jeder_json_ld_block_ist_gueltig(): void
    {
        $fehler = [];
        $bloecke = 0;

        foreach ($this->seiten() as $pfad) {
            $html = $this->get($pfad)->assertOk()->getContent();

            preg_match_all(
                '#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s',
                $html,
                $treffer
            );

            foreach ($treffer[1] as $roh) {
                $bloecke++;
                $daten = json_decode(trim($roh), true);

                if (! is_array($daten)) {
                    $fehler[] = $pfad.': kein gueltiges JSON ('.json_last_error_msg().')';

                    continue;
                }
                if (($daten['@'.'context'] ?? null) !== 'https://schema.org') {
                    $fehler[] = $pfad.': Kontext fehlt oder ist falsch - Schluessel: '
                        .implode(',', array_slice(array_keys($daten), 0, 3));
                }
                if (! isset($daten['@'.'type'])) {
                    $fehler[] = $pfad.': Typ fehlt';
                }
            }
        }

        $this->assertSame([], $fehler, "Ungueltige JSON-LD-Bloecke:\n".implode("\n", $fehler));
        $this->assertGreaterThanOrEqual(10, $bloecke,
            'Es wurden verdaechtig wenige JSON-LD-Bloecke gefunden - stehen sie noch in den Vorlagen?');
    }

    /** Die Startseite fuehrt InsuranceAgency UND FAQPage. */
    public function test_startseite_traegt_insurance_agency_und_faq(): void
    {
        $typen = $this->typenVon('/website');

        $this->assertContains('InsuranceAgency', $typen);
        $this->assertContains('FAQPage', $typen);
    }

    /** Eine Leistungsseite fuehrt Service (und FAQPage, wenn Fragen da sind). */
    public function test_leistungsseite_traegt_service_schema(): void
    {
        $this->seed(ServicePageSeeder::class);
        $seite = ServicePage::active()->firstOrFail();

        $typen = $this->typenVon('/leistungen/'.$seite->slug);

        $this->assertContains('Service', $typen);
    }

    /**
     * KEINE Vorlage darf einen Blade-Direktivnamen als Zeichenkette
     * fuehren (z. B. '@context', '@class', '@props'). Das ist die
     * Ursache, nicht das Symptom - dieser Test faengt sie im Quelltext,
     * bevor eine Seite ueberhaupt ausgeliefert wird.
     */
    public function test_keine_vorlage_fuehrt_eine_direktive_als_zeichenkette(): void
    {
        $direktiven = $this->bekannteDirektiven();
        $muster = '#[\'"]@('.implode('|', $direktiven).')\b#';
        $treffer = [];

        foreach (File::allFiles(resource_path('views')) as $datei) {
            if (! str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }
            $inhalt = File::get($datei->getPathname());

            foreach (preg_split('/\R/', $inhalt) as $nr => $zeile) {
                // @yield/@include in einem HTML-Attribut ist gewollt und
                // harmlos: dort steht die Direktive absichtlich, sie
                // erzeugt Ausgabe statt eines Array-Schluessels.
                if (preg_match('/(content|href|src|value|alt|title)\s*=\s*"@/', $zeile)) {
                    continue;
                }
                if (preg_match($muster, $zeile)) {
                    $treffer[] = $datei->getRelativePathname().':'.($nr + 1).' '.trim($zeile);
                }
            }
        }

        $this->assertSame([], $treffer,
            'Blade-Direktive als Zeichenkette in einer Vorlage - sie wird beim '
            ."Kompilieren durch PHP-Quelltext ersetzt:\n".implode("\n", $treffer));
    }

    /**
     * KEIN HTML-Attribut darf INNERHALB eines Blade-Ausdrucks stehen.
     *
     * Das kam bei genau dieser Aufraeumaktion zweimal vor: ein Ausdruck
     * wie `value="{{ $x->y }}"` oder eine Direktive wie
     * `@disabled(!$i->isDraft())` enthaelt ein ">", und wer ein Tag mit
     * "<input[^>]*>" sucht, haelt dieses ">" fuer das Tag-Ende. Das
     * eingefuegte Attribut landet dann mitten im PHP-Ausdruck:
     *
     *     <select @disabled(!$import- aria-label="...">isDraft())>
     *
     * Der BladeCompileTest faengt den Syntaxfehler zwar - aber nur,
     * wenn dabei ungueltiges PHP entsteht. Dieser Test prueft die FORM
     * und meldet auch den Fall, der zufaellig noch kompiliert.
     */
    public function test_kein_attribut_steht_in_einem_blade_ausdruck(): void
    {
        $treffer = [];

        foreach (File::allFiles(resource_path('views')) as $datei) {
            if (! str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }

            foreach (preg_split('/\R/', File::get($datei->getPathname())) as $nr => $zeile) {
                // Ein Objektpfeil, der durch ein Attribut zerrissen wurde
                // ("$import- aria-label=..."), oder ein Attribut zwischen
                // {{ und }}.
                if (preg_match('/\$[A-Za-z_][A-Za-z0-9_]*-\s+[a-z-]+=/', $zeile)
                    || preg_match('/\{\{[^}]*\s[a-z-]+="[^"]*"[^}]*\}\}\s*>/', $zeile)) {
                    $treffer[] = $datei->getRelativePathname().':'.($nr + 1).' '.trim($zeile);
                }
            }
        }

        $this->assertSame([], $treffer,
            "Ein HTML-Attribut steht innerhalb eines Blade-Ausdrucks:\n".implode("\n", $treffer));
    }

    /** Der Dienst selbst: leere Fragen erzeugen kein FAQPage. */
    public function test_faq_ohne_inhalt_erzeugt_kein_schema(): void
    {
        $this->assertNull(StructuredData::faqPage([]));
        $this->assertNull(StructuredData::faqPage([['', ''], [null, 'Antwort ohne Frage']]));
        $this->assertSame('', (string) StructuredData::script(null));

        $mit = StructuredData::faqPage([['Frage?', 'Antwort.'], ['', 'faellt weg']]);
        $this->assertNotNull($mit);
        $this->assertCount(1, $mit['mainEntity']);
    }

    /** @return array<int, string> */
    private function typenVon(string $pfad): array
    {
        $this->seed(ServicePageSeeder::class);
        $html = $this->get($pfad)->assertOk()->getContent();

        preg_match_all(
            '#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s',
            $html,
            $treffer
        );

        return array_values(array_filter(array_map(
            fn (string $roh) => json_decode(trim($roh), true)['@'.'type'] ?? null,
            $treffer[1]
        )));
    }

    /** @return array<int, string> */
    private function bekannteDirektiven(): array
    {
        $klasse = new \ReflectionClass(BladeCompiler::class);
        $namen = [];

        foreach ($klasse->getMethods() as $methode) {
            if (preg_match('/^compile([A-Z][A-Za-z]*)$/', $methode->name, $t)) {
                $namen[] = lcfirst($t[1]);
            }
        }
        foreach ($klasse->getTraits() as $trait) {
            foreach ($trait->getMethods() as $methode) {
                if (preg_match('/^compile([A-Z][A-Za-z]*)$/', $methode->name, $t)) {
                    $namen[] = lcfirst($t[1]);
                }
            }
        }

        return array_values(array_unique($namen));
    }
}
