<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * UX-1/UX-2: Waechter fuer das Farb- und Baustein-System.
 *
 * Ohne diese Tests waere die Aufraeumung eine Momentaufnahme. Der Bestand
 * ist genau deshalb entstanden, weil jede neue Seite ihren eigenen
 * Farbsatz mitbrachte - der naechste Copy-Paste-Block wuerde ihn erneut
 * einschleppen, und niemand faellt es auf, bevor irgendwo eine gruene
 * "Gold"-Linie steht.
 */
class DesignSystemTest extends TestCase
{
    /** Seiten OHNE Vite-Bundle - dort MUESSEN Farben als Hex stehen. */
    private const OHNE_BUNDLE = [
        'errors/404.blade.php',
        'errors/500.blade.php',
        'legal/page.blade.php',
        'admin/provision_report_print.blade.php',
        // Ein HTML-Attribut kann kein var() aufloesen (theme-color, Favicon).
        'partials/favicon.blade.php',
        'layouts/portal.blade.php',
        // Erscheint auch auf Website-Hosts ohne Bundle.
        'partials/cookie_consent.blade.php',
    ];

    /** @return array<int,string> Blade-Dateien der Anwendung (ohne E-Mail/Website). */
    private function views(): array
    {
        $dateien = [];
        $basis = resource_path('views');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basis));
        foreach ($it as $datei) {
            if (! $datei->isFile() || ! str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }
            $rel = str_replace($basis.DIRECTORY_SEPARATOR, '', $datei->getPathname());
            // E-Mail-Vorlagen bleiben bewusst tabellenbasiert mit Inline-Styles
            // (Gmail/Outlook entfernen <style>) - eigenes Thema, siehe CLAUDE.md.
            // Die statische Website hat ihren eigenen, korrekten Tokensatz.
            if (str_starts_with($rel, 'emails/') || str_starts_with($rel, 'website/')) {
                continue;
            }
            $dateien[$rel] = $datei->getPathname();
        }

        return $dateien;
    }

    public function test_keine_markenfarbe_mehr_als_hex_in_den_views(): void
    {
        $muster = '/#(17A65B|19B463|128A4B|3DDC8E|D9F4E6|B8A16B|D1C18F|131A17|0F1512|0B1310)/i';
        $treffer = [];

        foreach ($this->views() as $rel => $pfad) {
            if (in_array(str_replace('\\', '/', $rel), self::OHNE_BUNDLE, true)) {
                continue;
            }
            if (preg_match($muster, (string) file_get_contents($pfad))) {
                $treffer[] = $rel;
            }
        }

        $this->assertSame([], $treffer,
            'Markenfarbe als Hex statt als Token aus brand.css: '.implode(', ', $treffer));
    }

    public function test_die_alten_irrefuehrenden_tokennamen_sind_verschwunden(): void
    {
        // --gold trug in Beraterwelt/Portal das GRUEN, --akzent das Gold;
        // --petrol ist ein Name, den die Hoheitsregel ausdruecklich ausschliesst.
        $verboten = ['var(--petrol', 'var(--akzent', 'var(--gold-hell)', 'var(--green)', 'var(--mint)'];
        $treffer = [];

        foreach ($this->views() as $rel => $pfad) {
            $inhalt = (string) file_get_contents($pfad);
            foreach ($verboten as $name) {
                if (str_contains($inhalt, $name)) {
                    $treffer[] = "$rel: $name";
                }
            }
        }

        $this->assertSame([], $treffer, implode(' | ', $treffer));
    }

    public function test_jede_markenfarbe_hat_genau_eine_definition(): void
    {
        $brand = (string) file_get_contents(resource_path('css/brand.css'));

        foreach (['--emerald', '--gold', '--graphite', '--canvas', '--ink'] as $token) {
            $this->assertSame(1, preg_match_all('/^\s*'.preg_quote($token, '/').':\s/m', $brand),
                "Token $token ist in brand.css nicht genau einmal definiert.");
        }

        // Und der gefaehrlichste Fall ausdruecklich: --gold ist GOLD.
        $this->assertMatchesRegularExpression('/--gold:\s*#B8A16B/i', $brand);
        $this->assertMatchesRegularExpression('/--emerald:\s*#17A65B/i', $brand);
    }

    public function test_bausteine_sind_nicht_mehr_je_layout_neu_definiert(): void
    {
        // .card/.btn/.badge/.field standen dreimal - je einmal im <style>
        // der drei Layouts. Ein Layout darf ihre MASSE ueber Tokens
        // anpassen, aber die Klasse nicht erneut definieren.
        $bausteine = ['.card', '.btn', '.badge', '.field', '.page-title', '.item-row'];
        $treffer = [];

        foreach (['admin', 'portal', 'partner'] as $layout) {
            $inhalt = (string) file_get_contents(resource_path("views/layouts/$layout.blade.php"));
            foreach ($bausteine as $b) {
                // Am ZEILENANFANG: `form .field{...}` ist eine absteigende
                // Regel des Portals fuer seine eigenen Formulare und keine
                // zweite Definition des Bausteins.
                if (preg_match('/^'.preg_quote($b, '/').'\{/m', $inhalt)) {
                    $treffer[] = "layouts/$layout: $b";
                }
            }
        }

        $this->assertSame([], $treffer,
            'Baustein erneut im Layout definiert statt in components.css: '.implode(', ', $treffer));
    }
}
