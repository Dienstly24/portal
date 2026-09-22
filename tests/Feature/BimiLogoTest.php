<?php

namespace Tests\Feature;

use App\Support\BimiLogo;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Waechter fuer das BIMI-Markenlogo (Auftrag 22.09.2026).
 *
 * Warum ein Test und kein Merkblatt: das Zertifikat einer
 * Zertifizierungsstelle wird auf GENAU DIESE Datei ausgestellt. Wer sie
 * spaeter "nur kurz" austauscht oder mit einem beliebigen Export aus einem
 * Grafikprogramm ueberschreibt, macht das bezahlte Zertifikat ungueltig -
 * und zwar lautlos: es gibt keinen Fehler, keinen Bounce, keine Logzeile.
 * In Gmail steht dann wieder das "D", und niemand weiss, seit wann.
 */
class BimiLogoTest extends TestCase
{
    public function test_das_ausgelieferte_logo_erfuellt_svg_tiny_ps(): void
    {
        $ergebnis = BimiLogo::pruefeDatei();

        $this->assertSame([], $ergebnis['fehler'],
            'Das BIMI-Logo entspricht nicht mehr SVG Tiny PS: '.implode(' | ', $ergebnis['fehler']));
        $this->assertTrue($ergebnis['ok']);
    }

    public function test_das_logo_bleibt_unter_der_groessengrenze(): void
    {
        $this->assertLessThanOrEqual(BimiLogo::MAX_BYTES, filesize(BimiLogo::pfad()),
            'BIMI erlaubt hoechstens 32 KB.');
    }

    public function test_das_logo_traegt_den_firmennamen_und_ist_quadratisch(): void
    {
        $svg = (string) file_get_contents(BimiLogo::pfad());

        $this->assertMatchesRegularExpression('/<title>\s*Dienstly24\s*<\/title>/', $svg);
        $this->assertMatchesRegularExpression('/viewBox="0 0 (\d+) \1"/', $svg,
            'Die viewBox muss quadratisch sein - BIMI verlangt 1:1.');
    }

    /**
     * Die Gegenprobe: genau die Fassung, die bis zum 22.09.2026 im
     * public-Ordner lag (potrace-Export, DOCTYPE, kein tiny-ps, Masse in pt),
     * muss durchfallen. Ohne diesen Fall koennte der Pruefer alles
     * durchwinken und der Test waere gruen, ohne etwas zu sichern.
     */
    public function test_ein_gewoehnlicher_svg_export_faellt_durch(): void
    {
        $alt = <<<'SVG'
        <?xml version="1.0" standalone="no"?>
        <!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 20010904//EN"
         "http://www.w3.org/TR/2001/REC-SVG-20010904/DTD/svg10.dtd">
        <svg version="1.0" xmlns="http://www.w3.org/2000/svg"
         width="1254.000000pt" height="1254.000000pt" viewBox="0 0 1254.000000 1254.000000">
        <g><path d="M0 0"/></g>
        </svg>
        SVG;

        $ergebnis = BimiLogo::pruefe($alt);

        $this->assertFalse($ergebnis['ok']);
        $this->assertStringContainsString('baseProfile', implode(' ', $ergebnis['fehler']));
        $this->assertStringContainsString('DOCTYPE', implode(' ', $ergebnis['fehler']));
        $this->assertStringContainsString('title', implode(' ', $ergebnis['fehler']));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unzulaessigeFassungen(): array
    {
        $kopf = '<svg xmlns="http://www.w3.org/2000/svg" version="1.2" baseProfile="tiny-ps" '
            .'viewBox="0 0 100 100" width="100" height="100"><title>Dienstly24</title>';

        return [
            'Skript' => [$kopf.'<script>x</script></svg>', 'script'],
            'Animation' => [$kopf.'<animate dur="1s"/></svg>', 'animate'],
            'Rasterbild' => [$kopf.'<image href="a.png"/></svg>', 'image'],
            'Verlauf' => [$kopf.'<linearGradient id="a"/></svg>', 'linearGradient'],
            'Verweis nach draussen' => [$kopf.'<a href="https://example.com"><rect fill="#fff"/></a></svg>', 'href'],
            'Ereignis-Attribut' => [$kopf.'<rect onclick="x()" fill="#fff"/></svg>', 'on'],
            'eingebettetes Bild' => [$kopf.'<rect fill="url(data:image/png;base64,AAA)"/></svg>', 'base64'],
            'nicht quadratisch' => [
                '<svg xmlns="http://www.w3.org/2000/svg" version="1.2" baseProfile="tiny-ps" '
                .'viewBox="0 0 200 100" width="200" height="100"><title>Dienstly24</title></svg>',
                'quadratisch',
            ],
            'Masse in pt' => [
                '<svg xmlns="http://www.w3.org/2000/svg" version="1.2" baseProfile="tiny-ps" '
                .'viewBox="0 0 100 100" width="100pt" height="100pt"><title>Dienstly24</title></svg>',
                'absolute Pixelangabe',
            ],
        ];
    }

    #[DataProvider('unzulaessigeFassungen')]
    public function test_verbotene_bestandteile_werden_erkannt(string $svg, string $erwartet): void
    {
        $ergebnis = BimiLogo::pruefe($svg);

        $this->assertFalse($ergebnis['ok'], 'Diese Fassung haette abgelehnt werden muessen.');
        $this->assertStringContainsString($erwartet, implode(' ', $ergebnis['fehler']));
    }

    public function test_zu_grosse_dateien_werden_abgelehnt(): void
    {
        $gross = '<svg xmlns="http://www.w3.org/2000/svg" version="1.2" baseProfile="tiny-ps" '
            .'viewBox="0 0 10 10" width="10" height="10"><title>Dienstly24</title>'
            .'<rect fill="#fff" width="10" height="10"/><!--'.str_repeat('x', 33000).'--></svg>';

        $ergebnis = BimiLogo::pruefe($gross);

        $this->assertFalse($ergebnis['ok']);
        $this->assertStringContainsString('32 KB', implode(' ', $ergebnis['fehler']));
    }

    public function test_der_pruefbefehl_ist_registriert(): void
    {
        $this->assertArrayHasKey('bimi:pruefen', Artisan::all());
    }
}
