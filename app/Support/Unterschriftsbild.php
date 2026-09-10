<?php

namespace App\Support;

/**
 * Aufbereitung der gezeichneten Handschrift.
 *
 * ZWEI AUFGABEN, beide aus der Betreiber-Vorgabe (Punkte 11 und 12):
 *
 * 1. RAND ABSCHNEIDEN. Die Zeichenflaeche ist gross (Finger brauchen
 *    Platz), die Unterschrift steht meist in der Mitte. Bliebe der leere
 *    Rand erhalten, waere die Handschrift im Vertrag winzig - das Feld
 *    zeigt dann zu 80 % Nichts. Zugeschnitten wird auf die tatsaechlich
 *    bemalten Pixel, mit einem kleinen Sicherheitsrand, damit der
 *    Federstrich nicht am Bildrand klebt.
 *
 * 2. SEITENVERHAELTNIS. Bisher wurde das Bild auf die Feldgroesse
 *    GEZOGEN. Sind die Felder verschieden gross - und das sind sie, ein
 *    Kuerzel-Kasten auf Seite 7 ist nicht der Unterschriftsbalken auf
 *    Seite 1 -, stand dieselbe Unterschrift einmal gestaucht und einmal
 *    gestreckt im Dokument. Eine verzerrte Unterschrift ist keine
 *    Unterschrift mehr. `einpassen()` rechnet deshalb den groesstmoeglichen
 *    unverzerrten Platz aus und zentriert ihn im Feld.
 *
 * Ohne GD faellt alles auf "unveraendert durchreichen" zurueck: lieber ein
 * Bild mit Rand als gar keine Unterschrift. Ob GD ueberhaupt da ist, prueft
 * `App\Support\Bildverarbeitung` frueher im Ablauf.
 */
final class Unterschriftsbild
{
    /** Sicherheitsrand um die bemalten Pixel, anteilig zur laengeren Kante. */
    private const RAND = 0.02;

    /**
     * Schneidet den leeren Rand ab. Gibt bei jedem Zweifel das ORIGINAL
     * zurueck - ein nicht zugeschnittenes Bild ist ein Schoenheitsfehler,
     * ein falsch zugeschnittenes waere eine halbe Unterschrift.
     */
    public static function zuschneiden(string $png): string
    {
        if (! Bildverarbeitung::verfuegbar()) {
            return $png;
        }

        $bild = @imagecreatefromstring($png);
        if ($bild === false) {
            return $png;
        }

        try {
            $breite = imagesx($bild);
            $hoehe = imagesy($bild);
            $grenzen = self::bemalterBereich($bild, $breite, $hoehe);
            if ($grenzen === null) {
                return $png; // Nichts gefunden - nicht raten.
            }

            [$links, $oben, $rechts, $unten] = $grenzen;
            $rand = (int) round(max($breite, $hoehe) * self::RAND);
            $links = max(0, $links - $rand);
            $oben = max(0, $oben - $rand);
            $rechts = min($breite - 1, $rechts + $rand);
            $unten = min($hoehe - 1, $unten + $rand);

            $neuBreite = $rechts - $links + 1;
            $neuHoehe = $unten - $oben + 1;
            if ($neuBreite < 8 || $neuHoehe < 8) {
                return $png;
            }
            // Kein Gewinn - dann auch kein zweiter Bilddurchlauf.
            if ($neuBreite >= $breite && $neuHoehe >= $hoehe) {
                return $png;
            }

            $ziel = imagecreatetruecolor($neuBreite, $neuHoehe);
            imagealphablending($ziel, false);
            imagesavealpha($ziel, true);
            $transparent = imagecolorallocatealpha($ziel, 0, 0, 0, 127);
            imagefill($ziel, 0, 0, $transparent);
            imagecopy($ziel, $bild, 0, 0, $links, $oben, $neuBreite, $neuHoehe);

            ob_start();
            imagepng($ziel, null, 8);
            $ergebnis = (string) ob_get_clean();
            imagedestroy($ziel);

            return $ergebnis === '' ? $png : $ergebnis;
        } catch (\Throwable) {
            return $png;
        } finally {
            imagedestroy($bild);
        }
    }

    /**
     * Groesstes unverzerrtes Rechteck im Feld, mittig ausgerichtet.
     *
     * Liefert [x, y, breite, hoehe] in denselben Einheiten wie das Feld.
     * Ist das Bild nicht lesbar, wird das Feld unveraendert genutzt -
     * schlimmstenfalls sieht es aus wie vorher.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function einpassen(string $png, float $x, float $y, float $breite, float $hoehe): array
    {
        $masse = self::masse($png);
        if ($masse === null || $breite <= 0 || $hoehe <= 0) {
            return [$x, $y, $breite, $hoehe];
        }

        [$bildBreite, $bildHoehe] = $masse;
        $faktor = min($breite / $bildBreite, $hoehe / $bildHoehe);
        $neuBreite = $bildBreite * $faktor;
        $neuHoehe = $bildHoehe * $faktor;

        return [
            $x + ($breite - $neuBreite) / 2,
            $y + ($hoehe - $neuHoehe) / 2,
            $neuBreite,
            $neuHoehe,
        ];
    }

    /**
     * Masse eines PNG - ohne GD, denn `getimagesizefromstring` gehoert zum
     * PHP-Kern. Das Einpassen funktioniert damit selbst dann, wenn die
     * Bildbibliothek fehlt.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function masse(string $png): ?array
    {
        $info = @getimagesizefromstring($png);
        if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return null;
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * Der bemalte Bereich: alles, was nicht vollstaendig durchsichtig ist.
     *
     * Gemessen wird ueber den ALPHAKANAL, nicht ueber die Farbe. Die
     * Zeichenflaeche liefert eine durchsichtige PNG; "weiss" gibt es darin
     * gar nicht, und ein Helligkeitsvergleich wuerde bei einer hellen
     * Tinte die Unterschrift selbst wegschneiden.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    private static function bemalterBereich(\GdImage $bild, int $breite, int $hoehe): ?array
    {
        $links = $breite;
        $oben = $hoehe;
        $rechts = -1;
        $unten = -1;

        for ($py = 0; $py < $hoehe; $py++) {
            for ($px = 0; $px < $breite; $px++) {
                $farbe = imagecolorat($bild, $px, $py);
                // Bit 24..30 tragen den Alphawert: 127 = ganz durchsichtig.
                $alpha = ($farbe >> 24) & 0x7F;
                if ($alpha >= 120) {
                    continue;
                }
                if ($px < $links) {
                    $links = $px;
                }
                if ($px > $rechts) {
                    $rechts = $px;
                }
                if ($py < $oben) {
                    $oben = $py;
                }
                if ($py > $unten) {
                    $unten = $py;
                }
            }
        }

        return $rechts < 0 ? null : [$links, $oben, $rechts, $unten];
    }
}
