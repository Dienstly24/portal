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

    /**
     * Anteil der Pixel, die auf weissem Papier SICHTBAR waeren: deckend
     * (Alpha hoechstens halb) und deutlich dunkler als Weiss. Dieselbe
     * Schwelle wie der Bildvergleich des Qualitaetsgates (Summe der
     * Kanalabstaende > 60) - ein Bild, das hier fast nichts liefert, wuerde
     * dort als "unsichtbar" scheitern, und zwar erst NACH dem
     * Unterschreiben. Gemessen wird nach dem Freistellen: was der Stempler
     * spaeter entfernt, zaehlt nicht.
     */
    public static function sichtbarerAnteil(\GdImage $bild): float
    {
        $w = imagesx($bild);
        $h = imagesy($bild);
        $schritt = max(1, (int) floor(max($w, $h) / 300));
        $gesamt = 0;
        $sichtbar = 0;
        for ($y = 0; $y < $h; $y += $schritt) {
            for ($x = 0; $x < $w; $x += $schritt) {
                $c = imagecolorat($bild, $x, $y);
                $gesamt++;
                if ((($c >> 24) & 0x7F) > 63) {
                    continue;
                }
                $abstand = (255 - (($c >> 16) & 0xFF)) + (255 - (($c >> 8) & 0xFF)) + (255 - ($c & 0xFF));
                if ($abstand > 60) {
                    $sichtbar++;
                }
            }
        }

        return $sichtbar / $gesamt;
    }

    /**
     * Stellt eine FOTOGRAFIERTE oder gescannte Unterschrift frei
     * ("Meine Unterschrift" hochladen, Teil B 07.10.2026).
     *
     * Anders als bei einem Firmenlogo ist der Hintergrund hier kein reines
     * Weiss: Papier auf einem Handyfoto ist grau, ungleichmaessig
     * ausgeleuchtet und verrauscht - `weissenRandFreistellen` mit seiner
     * festen Schwelle (235) liesse davon eine graue Flaeche stehen, die im
     * Vertrag als Kasten erscheint. Hier wird die Papierhelligkeit GEMESSEN
     * (90. Perzentil der Helligkeit) und alles, was nicht deutlich dunkler
     * ist, durchsichtig. Die Tinte behaelt ihre Farbe; ihre Deckkraft folgt
     * der Dunkelheit, damit Kanten weich bleiben.
     *
     * Ein Bild, das schon Transparenz benutzt, wird NICHT angefasst.
     *
     * @return array{bild: \GdImage, kontrast: int} Kontrast = Papier minus Tinte (0-255)
     */
    public static function tinteFreistellen(\GdImage $bild): array
    {
        imagealphablending($bild, false);
        imagesavealpha($bild, true);
        $w = imagesx($bild);
        $h = imagesy($bild);
        if (self::hatTransparenz($bild)) {
            return ['bild' => $bild, 'kontrast' => 255];
        }

        $histogramm = array_fill(0, 256, 0);
        $schritt = max(1, (int) floor(max($w, $h) / 400));
        $anzahl = 0;
        for ($y = 0; $y < $h; $y += $schritt) {
            for ($x = 0; $x < $w; $x += $schritt) {
                $histogramm[self::helligkeit(imagecolorat($bild, $x, $y))]++;
                $anzahl++;
            }
        }
        $papier = self::perzentil($histogramm, $anzahl, 0.90);
        $tinte = self::perzentil($histogramm, $anzahl, 0.02);
        $kontrast = max(0, $papier - $tinte);

        // Ab hier gilt ein Pixel als Papier. Der Abstand ist relativ zum
        // Kontrast: bei einem blassen Kugelschreiber muss die Schwelle naeher
        // am Papier liegen als bei einem schwarzen Filzstift.
        $schwelle = $papier - max(18, (int) round($kontrast * 0.35));
        $breite = max(12, (int) round($kontrast * 0.45));
        $durchsichtig = imagecolorallocatealpha($bild, 255, 255, 255, 127);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($bild, $x, $y);
                $l = self::helligkeit($c);
                if ($l >= $schwelle) {
                    imagesetpixel($bild, $x, $y, $durchsichtig);

                    continue;
                }
                $deckkraft = min(1.0, ($schwelle - $l) / $breite);
                $alpha = (int) round(127 * (1 - $deckkraft));
                imagesetpixel($bild, $x, $y, imagecolorallocatealpha($bild, ($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, $alpha));
            }
        }

        return ['bild' => $bild, 'kontrast' => $kontrast];
    }

    private static function helligkeit(int $c): int
    {
        return (int) round(((($c >> 16) & 0xFF) * 299 + (($c >> 8) & 0xFF) * 587 + ($c & 0xFF) * 114) / 1000);
    }

    /** @param  array<int, int>  $histogramm */
    private static function perzentil(array $histogramm, int $anzahl, float $anteil): int
    {
        $ziel = $anzahl * $anteil;
        $summe = 0;
        foreach ($histogramm as $wert => $haeufigkeit) {
            $summe += $haeufigkeit;
            if ($summe >= $ziel) {
                return $wert;
            }
        }

        return 255;
    }
}
