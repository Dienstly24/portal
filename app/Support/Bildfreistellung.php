<?php

namespace App\Support;

/**
 * Bildaufbereitung fuer das Stempeln in ein PDF.
 *
 * ZWEI AUFGABEN:
 *
 * 1. VERKLEINERN auf die Aufloesung, die das Feld im Druck braucht
 *    (300 dpi). Hochgeladene Stempel kommen mit 1600 px, gesetzt werden sie
 *    in ein Feld von rund 130 Punkt - der Rest waere Ballast im Dokument,
 *    und jedes Pixel kostet beim Einbetten Rechenzeit.
 *
 * 2. WEISSEN HINTERGRUND FREISTELLEN - nur fuer Firmenbilder und nur, wenn
 *    das Bild VOLLSTAENDIG deckend ist. Firmenlogos und eingescannte
 *    Stempel kommen fast immer als JPG oder als PNG mit weissem Grund
 *    (gemessen auf dem Server 02.10.2026: beide Firmenbilder 100 %
 *    deckend). Mit echten Farben eingebettet stuende dort ein weisser
 *    Kasten UEBER der Formularlinie "Stempel / Unterschrift" - genau die
 *    Stelle, an der der Stempel sitzen soll. Freigestellt wird per
 *    Flutfuellung VOM RAND aus: weiss, was mit dem Rand verbunden ist, wird
 *    durchsichtig; ein weisses Element INNERHALB des Logos (Buchstabe,
 *    Innenflaeche) bleibt erhalten. Ein Bild, das schon einen Alphakanal
 *    benutzt, wird NIE angefasst - dort hat jemand bewusst freigestellt.
 */
final class Bildfreistellung
{
    /** Ziel-Aufloesung im Druck. */
    public const DPI = 300;

    /** Ab dieser Helligkeit (je Kanal) gilt ein Randpixel als Hintergrund. */
    public const WEISS_SCHWELLE = 235;

    /**
     * Verkleinert auf die Druckaufloesung des Feldes; vergroessert nie.
     * Der Alphakanal bleibt erhalten.
     */
    public static function verkleinern(\GdImage $bild, float $breitePt, float $hoehePt): \GdImage
    {
        $w = imagesx($bild);
        $h = imagesy($bild);
        $zielW = max(16, (int) ceil($breitePt / 72 * self::DPI));
        $zielH = max(16, (int) ceil($hoehePt / 72 * self::DPI));
        $faktor = min($zielW / $w, $zielH / $h);
        if ($faktor >= 1.0) {
            return $bild;
        }
        imagealphablending($bild, false);
        imagesavealpha($bild, true);
        $klein = @imagescale($bild, max(1, (int) round($w * $faktor)), max(1, (int) round($h * $faktor)), IMG_BICUBIC);
        if ($klein === false) {
            return $bild;
        }
        imagedestroy($bild);
        imagealphablending($klein, false);
        imagesavealpha($klein, true);

        return $klein;
    }

    /** Hat das Bild IRGENDWO Transparenz? Dann wurde bewusst freigestellt. */
    public static function hatTransparenz(\GdImage $bild): bool
    {
        $w = imagesx($bild);
        $h = imagesy($bild);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($bild, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Macht den mit dem Rand verbundenen weissen Hintergrund durchsichtig.
     *
     * @return int Anzahl freigestellter Pixel (0 = nichts geaendert)
     */
    public static function weissenRandFreistellen(\GdImage $bild): int
    {
        if (! imageistruecolor($bild)) {
            imagepalettetotruecolor($bild);
        }
        if (self::hatTransparenz($bild)) {
            return 0;
        }
        $w = imagesx($bild);
        $h = imagesy($bild);
        imagealphablending($bild, false);
        imagesavealpha($bild, true);
        $klar = imagecolorallocatealpha($bild, 255, 255, 255, 127);

        $besucht = [];
        $stapel = [];
        for ($x = 0; $x < $w; $x++) {
            $stapel[] = $x;
            $stapel[] = ($h - 1) * $w + $x;
        }
        for ($y = 0; $y < $h; $y++) {
            $stapel[] = $y * $w;
            $stapel[] = $y * $w + $w - 1;
        }

        $anzahl = 0;
        while ($stapel !== []) {
            $i = array_pop($stapel);
            if (isset($besucht[$i])) {
                continue;
            }
            $besucht[$i] = true;
            $x = $i % $w;
            $y = intdiv($i, $w);
            $c = imagecolorat($bild, $x, $y);
            if ((($c >> 16) & 0xFF) < self::WEISS_SCHWELLE
                || (($c >> 8) & 0xFF) < self::WEISS_SCHWELLE
                || ($c & 0xFF) < self::WEISS_SCHWELLE) {
                continue;
            }
            imagesetpixel($bild, $x, $y, $klar);
            $anzahl++;
            if ($x > 0) {
                $stapel[] = $i - 1;
            }
            if ($x < $w - 1) {
                $stapel[] = $i + 1;
            }
            if ($y > 0) {
                $stapel[] = $i - $w;
            }
            if ($y < $h - 1) {
                $stapel[] = $i + $w;
            }
        }

        return $anzahl;
    }
}
