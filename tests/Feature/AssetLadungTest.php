<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Was die Beraterwelt laedt - und was nicht (Audit 15.09.2026).
 *
 * BEFUND: `chart.umd.min.js` (200 kB) stand unbedingt im Layout und kam
 * damit auf JEDER Seite mit - bei rund 50 Seiten, von denen FUENF ein
 * Diagramm zeichnen. Das ist mehr als das Vierzigfache des gesamten
 * eigenen JavaScript-Bundles (4,4 kB), ohne jede Wirkung ausser
 * Ladezeit. Dazu ein 124-kB-Logo, dargestellt auf 30 px Hoehe.
 *
 * Gemessen (Chromium, eingeloggt): eine Seite ohne Diagramm ging von
 * 452 kB auf 152 kB zurueck.
 */
class AssetLadungTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        SystemSetting::set('two_factor_required', '0');

        return User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    /** Die fuenf Seiten, die wirklich ein Diagramm zeichnen. */
    public static function diagrammSeiten(): array
    {
        return [['/admin'], ['/admin/reports'], ['/admin/tickets/statistik'],
            ['/admin/banners/statistik'], ['/admin/aktivitaet']];
    }

    /** Seiten ohne jedes Diagramm. */
    public static function seitenOhneDiagramm(): array
    {
        return [['/admin/customers'], ['/admin/contracts'], ['/admin/tickets'],
            ['/admin/settings'], ['/admin/employees'], ['/admin/signaturen']];
    }

    #[DataProvider('diagrammSeiten')]
    public function test_diagrammseite_laedt_chartjs(string $pfad): void
    {
        $this->actingAs($this->admin())->get($pfad)->assertOk()
            ->assertSee('/js/chart.umd.min.js', false);
    }

    #[DataProvider('seitenOhneDiagramm')]
    public function test_seite_ohne_diagramm_laedt_kein_chartjs(string $pfad): void
    {
        $this->actingAs($this->admin())->get($pfad)->assertOk()
            ->assertDontSee('/js/chart.umd.min.js', false);
    }

    /**
     * brand.js bleibt ueberall - es ist winzig und wird auch ohne
     * Diagramm gebraucht (der Signatur-Editor faerbt seine Felder damit).
     */
    public function test_brand_js_bleibt_auf_jeder_seite(): void
    {
        foreach (['/admin', '/admin/customers', '/admin/signaturen'] as $pfad) {
            $this->actingAs($this->admin())->get($pfad)->assertOk()
                ->assertSee('/js/brand.js', false);
        }

        $this->assertLessThan(5 * 1024, File::size(public_path('js/brand.js')),
            'brand.js darf klein bleiben - sonst gehoert auch sie je Seite geladen.');
    }

    /**
     * Wer ein Diagramm ergaenzt, muss Chart.js anfordern. Dieser Test
     * findet die Vorlage, die es vergisst - sonst faellt es erst auf,
     * wenn die Seite beim Betreiber leer bleibt.
     */
    public function test_jede_vorlage_mit_diagramm_fordert_chartjs_an(): void
    {
        $fehlend = [];

        foreach (File::allFiles(resource_path('views')) as $datei) {
            if (! str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }
            $inhalt = File::get($datei->getPathname());

            if (! str_contains($inhalt, 'new Chart(')) {
                continue;
            }
            if (! str_contains($inhalt, "@push('charts')")
                && ! str_contains($inhalt, 'chart.umd.min.js')) {
                $fehlend[] = $datei->getRelativePathname();
            }
        }

        $this->assertSame([], $fehlend,
            "Diese Vorlagen zeichnen ein Diagramm, fordern Chart.js aber nicht an:\n"
            .implode("\n", $fehlend));
    }

    /**
     * Umgekehrt: keine Vorlage soll Chart.js anfordern, ohne es zu
     * brauchen - sonst waechst die Ladezeit wieder zurueck.
     */
    public function test_keine_vorlage_laedt_chartjs_ohne_diagramm(): void
    {
        $ueberfluessig = [];

        foreach (File::allFiles(resource_path('views')) as $datei) {
            if (! str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }
            $inhalt = File::get($datei->getPathname());

            if (str_contains($inhalt, 'chart.umd.min.js') && ! str_contains($inhalt, 'new Chart(')) {
                $ueberfluessig[] = $datei->getRelativePathname();
            }
        }

        $this->assertSame([], $ueberfluessig,
            "Diese Vorlagen laden Chart.js, ohne ein Diagramm zu zeichnen:\n"
            .implode("\n", $ueberfluessig));
    }

    /** Das Kopfzeilen-Logo hat Anzeigegroesse, nicht Druckgroesse. */
    public function test_kopfzeilen_logo_ist_nicht_uebergross(): void
    {
        $datei = public_path('images/logo-header.png');

        $this->assertFileExists($datei);
        $this->assertLessThan(40 * 1024, File::size($datei),
            'Ein Logo, das auf 30 px Hoehe erscheint, braucht keine 124 kB.');

        [$breite, $hoehe] = getimagesize($datei);
        $this->assertLessThanOrEqual(120, $hoehe,
            'Dreifache Anzeigehoehe genuegt auch fuer dichte Bildschirme.');

        $this->actingAs($this->admin())->get('/admin')->assertOk()
            ->assertSee('/images/logo-header.png', false);
    }
}
