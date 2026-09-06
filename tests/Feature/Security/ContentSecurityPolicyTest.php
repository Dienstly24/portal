<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sicherheits-Regressionstests der Content-Security-Policy (Audit SEC-4).
 *
 * Abnahmekriterium: die Richtlinie enthaelt in `script-src` weder
 * 'unsafe-inline' noch 'unsafe-eval'. Beides zurueckzubauen ist leicht
 * (ein Skript laeuft nicht, man setzt es wieder rein) - deshalb steht es
 * hier als Test und nicht nur als Kommentar.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_script_src_has_no_unsafe_inline(): void
    {
        $this->assertStringNotContainsString(
            "'unsafe-inline'",
            $this->scriptSrc(),
            "script-src enthaelt wieder 'unsafe-inline' - damit laeuft jedes eingeschleuste <script>."
        );
    }

    public function test_script_src_has_no_unsafe_eval(): void
    {
        $this->assertStringNotContainsString(
            "'unsafe-eval'",
            $this->scriptSrc(),
            "script-src enthaelt wieder 'unsafe-eval' - damit laesst sich jede Zeichenkette als Code ausfuehren."
        );
    }

    /** Ohne Nonce koennte kein einziges eingebettetes Skript laufen. */
    public function test_script_src_carries_a_nonce(): void
    {
        $this->assertMatchesRegularExpression(
            "/'nonce-[A-Za-z0-9+\/_-]{16,}'/",
            $this->scriptSrc()
        );
    }

    /** Attribut-Handler (onclick="…") sind ausdruecklich verboten. */
    public function test_script_src_attr_is_none(): void
    {
        $this->assertStringContainsString("script-src-attr 'none'", $this->policy());
    }

    /** Die harten Grundregeln bleiben stehen. */
    public function test_base_directives_stay_restrictive(): void
    {
        $policy = $this->policy();

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
    }

    /**
     * Der einzige erlaubte fremde Skript-Host ist Turnstile
     * (Bot-Schutz der Registrierung, Audit SEC-1). Kommt ein weiterer
     * dazu, soll das auffallen.
     */
    public function test_only_turnstile_is_an_external_script_host(): void
    {
        $hosts = array_values(array_filter(
            explode(' ', $this->scriptSrc()),
            fn (string $t): bool => str_starts_with($t, 'http')
        ));

        $this->assertSame(['https://challenges.cloudflare.com'], $hosts);
    }

    /** Der Nonce im Header muss zu dem im HTML passen. */
    public function test_nonce_in_header_matches_the_html(): void
    {
        $response = $this->get('/login');
        $response->assertOk();

        preg_match("/'nonce-([A-Za-z0-9+\/_-]+)'/", $this->header($response), $kopf);
        $this->assertNotEmpty($kopf, 'Kein Nonce im Header.');

        $this->assertStringContainsString(
            'nonce="'.$kopf[1].'"',
            $response->getContent(),
            'Der Nonce im HTML weicht vom Header ab - dann blockiert der Browser jedes eingebettete Skript.'
        );
    }

    /** Jede Antwort bekommt einen EIGENEN Nonce. */
    public function test_each_response_gets_a_fresh_nonce(): void
    {
        preg_match("/'nonce-([^']+)'/", $this->header($this->get('/login')), $a);
        preg_match("/'nonce-([^']+)'/", $this->header($this->get('/login')), $b);

        $this->assertNotEmpty($a);
        $this->assertNotEmpty($b);
        $this->assertNotSame($a[1], $b[1], 'Zwei Antworten teilen sich einen Nonce - ein wiederverwendeter Nonce schuetzt nicht.');
    }

    /** Ein Download bekommt keine HTML-Richtlinie aufgedrueckt. */
    public function test_non_html_responses_get_no_policy(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get('/admin/systemzustand.json');

        if ($response->getStatusCode() === 200) {
            $this->assertFalse(
                $response->headers->has('Content-Security-Policy'),
                'JSON-Antworten brauchen keine CSP.'
            );
        } else {
            $this->markTestSkipped('Systemzustand nicht erreichbar.');
        }
    }

    /**
     * KEINE Vorlage darf wieder einen Attribut-Handler bekommen.
     *
     * Der eigentliche Waechter: die Richtlinie oben verbietet sie, aber
     * ein neuer onclick faellt sonst erst auf, wenn ein Mitarbeiter
     * meldet "der Knopf tut nichts". Hier faellt er beim Test auf.
     */
    public function test_no_view_uses_an_inline_event_handler(): void
    {
        $treffer = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        $attribut = '/\son(click|change|input|submit|load|error|focus|blur|keyup|keydown|keypress'
            .'|mouseover|mouseout|mouseenter|mouseleave|dblclick|contextmenu|paste|drop'
            .'|dragover|dragleave|dragenter|toggle|wheel|scroll|reset|select|search)\s*=\s*["\']/i';

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $inhalt = file_get_contents($file->getPathname());
            // Blade-Kommentare erklaeren die Umstellung und nennen dabei
            // "onclick" - sie sind kein Verstoss.
            $inhalt = preg_replace('/\{\{--.*?--\}\}/s', '', $inhalt);

            if (preg_match($attribut, $inhalt)) {
                $treffer[] = str_replace(resource_path('views').'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $treffer,
            'Diese Vorlagen benutzen wieder Inline-Handler (die CSP blockiert sie): '
            .implode(', ', $treffer));
    }

    /** Jedes eingebettete Skript traegt einen Nonce. */
    public function test_every_inline_script_carries_a_nonce(): void
    {
        $treffer = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $inhalt = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file->getPathname()));

            preg_match_all('/<script\b([^>]*)>/i', (string) $inhalt, $treffer_roh);
            foreach ($treffer_roh[1] as $attribute) {
                if (str_contains($attribute, 'src=')) {
                    continue;   // externes Skript, braucht keinen Nonce
                }
                if (! str_contains($attribute, '@cspNonce') && ! str_contains($attribute, 'nonce=')) {
                    $treffer[] = str_replace(resource_path('views').'/', '', $file->getPathname());
                    break;
                }
            }
        }

        $this->assertSame([], $treffer,
            'Diese Vorlagen haben ein eingebettetes Skript ohne @cspNonce - es wuerde nicht ausgefuehrt: '
            .implode(', ', $treffer));
    }

    /**
     * Das hidden-Attribut muss gegen Klassenregeln gewinnen.
     *
     * Ohne diese CSS-Regel blieb die Sammelauswahl der Ticketliste
     * dauerhaft sichtbar: `.bulk-bar{display:flex}` schlaegt den
     * Standard-Browserstil `[hidden]{display:none}`. Aufgefallen ist es
     * erst im Browser, nicht im Test - deshalb steht es jetzt als Test.
     */
    public function test_hidden_attribute_beats_class_rules(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\[hidden\]\s*\{[^}]*display:\s*none\s*!important/i',
            $css,
            'Die Regel [hidden]{display:none !important} fehlt - Elemente mit '
            .'eigener display-Klasse liessen sich dann nicht mehr verbergen.'
        );
    }

    /** Alpine.js ist raus - es war der Grund fuer 'unsafe-eval'. */
    public function test_alpine_is_gone(): void
    {
        $package = json_decode(file_get_contents(base_path('package.json')), true);

        $this->assertArrayNotHasKey('alpinejs', $package['dependencies'] ?? []);
        $this->assertArrayNotHasKey('alpinejs', $package['devDependencies'] ?? []);
    }

    /**
     * Jedes verdrahtete Bedienelement bekommt seinen Handler MITGELIEFERT.
     *
     * Seit SEC-4 traegt ein Knopf nur noch data-h-<ereignis>="schluessel";
     * die Funktion dahinter steht in einem @pushOnce('cspScripts')-Block,
     * den @stack ausgibt. Faellt dieser Block aus dem HTML, ist das kein
     * Fehler und keine leere Seite - das Element tut nur nichts mehr.
     * Genau so waren die Kopfzeilen-Suche, die Glocke und die
     * Passwort-Augen der Anmeldung wochenlang tot: die Bloecke standen in
     * ihrer Vorlage NACH der Stack-Zeile, und @stack gibt nur aus, was bis
     * dahin gestapelt wurde. Deshalb wird hier das AUSGELIEFERTE HTML
     * geprueft, nicht die Vorlage.
     */
    public function test_every_wired_control_gets_its_handler_in_the_html(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Gast-Seite und Beraterwelt-Layout - die beiden Vorlagen, die
        // ihre Handler selbst stapeln (untergeordnete Views werden vor dem
        // Layout gerendert und sind von der Reihenfolge nicht betroffen).
        $seiten = [
            '/login' => null,
            '/admin/tickets/statistik' => $admin,
        ];

        foreach ($seiten as $pfad => $als) {
            $antwort = $als === null ? $this->get($pfad) : $this->actingAs($als)->get($pfad);
            $antwort->assertOk();
            $html = (string) $antwort->getContent();

            preg_match_all('/data-h-[a-z]+="([^"]+)"/', $html, $benutzt);
            $this->assertNotEmpty(
                $benutzt[1],
                "Auf $pfad steht kein einziges data-h-… - dann prueft dieser Test nichts mehr."
            );

            foreach (array_unique($benutzt[1]) as $schluessel) {
                $this->assertStringContainsString(
                    'window.__h["'.$schluessel.'"]',
                    $html,
                    "Auf $pfad traegt ein Bedienelement data-h-…=\"$schluessel\", die "
                    .'Registrierung fehlt aber im ausgelieferten HTML - das Element tut nichts.'
                );
            }
        }
    }

    /**
     * Keine Vorlage stapelt in einen Stack, den sie schon ausgegeben hat.
     *
     * Der guenstige Waechter zum Test darueber: er sieht ALLE Vorlagen,
     * auch die, die kein Test je aufruft. @stack gibt aus, was BIS DAHIN
     * gestapelt wurde - ein @push danach verschwindet spurlos.
     */
    public function test_no_view_pushes_into_an_already_yielded_stack(): void
    {
        $treffer = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Blade-Kommentare erklaeren genau diese Regel und enthalten
            // dabei die Woerter @stack und @push - sie sind kein Verstoss.
            $inhalt = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file->getPathname()));

            preg_match_all('/@stack\(\s*[\'"]([\w-]+)[\'"]\s*\)/', (string) $inhalt, $stacks, PREG_OFFSET_CAPTURE);

            foreach ($stacks[1] as $i => $stack) {
                $name = $stack[0];
                $rest = substr((string) $inhalt, $stacks[0][$i][1] + strlen($stacks[0][$i][0]));

                if (preg_match('/@(push|pushOnce|prepend)\(\s*[\'"]'.preg_quote($name, '/').'[\'"]/', $rest)) {
                    $treffer[] = str_replace(resource_path('views').'/', '', $file->getPathname()).' ('.$name.')';
                }
            }
        }

        $this->assertSame([], $treffer,
            'Diese Vorlagen stapeln NACH ihrer eigenen @stack-Zeile - der Block wird nie ausgegeben: '
            .implode(', ', $treffer));
    }

    // ------------------------------------------------------------------

    private function policy(): string
    {
        return app(SecurityHeaders::class)->policy();
    }

    private function scriptSrc(): string
    {
        foreach (explode(';', $this->policy()) as $teil) {
            $teil = trim($teil);
            if (str_starts_with($teil, 'script-src ')) {
                return $teil;
            }
        }

        $this->fail('Keine script-src-Direktive in der CSP.');
    }

    private function header($response): string
    {
        return (string) $response->headers->get('Content-Security-Policy');
    }
}
