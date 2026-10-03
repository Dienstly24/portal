<?php

namespace Tests\Unit;

use App\Support\Bildfreistellung;
use App\Support\FeldGeometrie;
use PHPUnit\Framework\TestCase;

/**
 * Freistellen des weissen Hintergrunds eines Firmenbildes (KI-055).
 *
 * Zwei Grenzen sind die eigentliche Regel: weiss INNERHALB des Logos
 * bleibt (sonst bekommt ein Buchstabe Loecher), und ein Bild, das schon
 * Transparenz benutzt, wird nie angefasst.
 */
class BildfreistellungTest extends TestCase
{
    private function logo(bool $transparenterRand = false): \GdImage
    {
        $b = imagecreatetruecolor(100, 60);
        imagealphablending($b, false);
        imagesavealpha($b, true);
        imagefill($b, 0, 0, $transparenterRand
            ? imagecolorallocatealpha($b, 255, 255, 255, 127)
            : imagecolorallocate($b, 255, 255, 255));
        imagefilledrectangle($b, 20, 10, 80, 50, imagecolorallocate($b, 200, 20, 20));
        imagefilledrectangle($b, 40, 25, 60, 35, imagecolorallocate($b, 255, 255, 255)); // weisser Innenteil

        return $b;
    }

    private function alpha(\GdImage $b, int $x, int $y): int
    {
        return (imagecolorat($b, $x, $y) >> 24) & 0x7F;
    }

    public function test_der_weisse_rand_wird_durchsichtig_das_zeichen_bleibt(): void
    {
        $b = $this->logo();
        $anzahl = Bildfreistellung::weissenRandFreistellen($b);

        $this->assertGreaterThan(0, $anzahl);
        $this->assertSame(127, $this->alpha($b, 2, 2), 'Ecke: Hintergrund');
        $this->assertSame(0, $this->alpha($b, 25, 15), 'Rotes Zeichen bleibt deckend');
    }

    public function test_weiss_innerhalb_des_logos_bleibt_erhalten(): void
    {
        $b = $this->logo();
        Bildfreistellung::weissenRandFreistellen($b);

        $this->assertSame(0, $this->alpha($b, 50, 30), 'Der weisse Innenteil ist nicht mit dem Rand verbunden.');
    }

    public function test_ein_bereits_freigestelltes_bild_wird_nicht_angefasst(): void
    {
        $b = $this->logo(transparenterRand: true);
        $this->assertSame(0, Bildfreistellung::weissenRandFreistellen($b));
        $this->assertSame(0, $this->alpha($b, 50, 30));
    }

    public function test_verkleinern_auf_die_druckaufloesung_des_feldes(): void
    {
        $b = imagecreatetruecolor(1600, 960);
        // Feld 131 x 23.6 pt -> bei 300 dpi rund 546 x 99 px; das Bild passt sich ein.
        $klein = Bildfreistellung::verkleinern($b, 131, 23.6);

        $this->assertLessThanOrEqual(546, imagesx($klein));
        $this->assertLessThanOrEqual(99, imagesy($klein));
        $this->assertGreaterThan(90, imagesy($klein), 'Nicht kleiner als noetig.');

        $winzig = imagecreatetruecolor(40, 20);
        $this->assertSame(40, imagesx(Bildfreistellung::verkleinern($winzig, 131, 23.6)), 'Vergroessert wird nie.');
    }

    public function test_feldgeometrie_ist_vom_zoom_unabhaengig(): void
    {
        // Gespeichert sind Anteile. Der Editor rechnet sie bei 75 %, 100 %
        // und 150 % aus denselben Verhaeltnissen - die Punkte im PDF sind
        // damit fuer jeden Zoom gleich.
        foreach ([0.75, 1.0, 1.5] as $zoom) {
            $breitePx = 1400 * $zoom;
            $hoehePx = 1981 * $zoom;
            $anteil = [365.7 / 595.28 * $breitePx / $breitePx, 212.6 / 841.89 * $hoehePx / $hoehePx, 0.28, 0.055];
            $punkte = FeldGeometrie::ausAnteilen($anteil[0], $anteil[1], $anteil[2], $anteil[3], 595.28, 841.89);
            $this->assertEqualsWithDelta(365.7, $punkte[0], 0.01, 'Zoom '.$zoom);
            $this->assertEqualsWithDelta(212.6, $punkte[1], 0.01, 'Zoom '.$zoom);
        }
    }
}
