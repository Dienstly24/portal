<?php

namespace App\Support;

/**
 * EXIF-Ausrichtung eines Handyfotos (Betreiber-Meldung 23.11.2026, KI-132).
 *
 * Telefone speichern ein Foto oft so, wie der Sensor liegt, und vermerken die
 * Drehung nur im EXIF-Feld "Orientation". Browser und Bildbetrachter werten
 * das aus - Tesseract NICHT: es las den hochkant gespeicherten Fahrzeugschein
 * seitlich und lieferte praktisch keinen Text (3 Zeilen statt ~40). Weil die
 * Vorschau das Bild richtig herum zeigt, sieht niemand den Grund.
 *
 * Die EINE Stelle fuer diese Drehregel (OCR und Bild->PDF).
 */
final class ExifAusrichtung
{
    /** Erst spiegeln, dann drehen (Grad gegen den Uhrzeigersinn, wie imagerotate). */
    private const SPIEGELN = [2 => IMG_FLIP_HORIZONTAL, 4 => IMG_FLIP_VERTICAL, 5 => IMG_FLIP_VERTICAL, 7 => IMG_FLIP_HORIZONTAL];

    private const DREHEN = [3 => 180, 5 => -90, 6 => -90, 7 => -90, 8 => 90];

    /** EXIF-Orientation 1..8 einer Datei; 1 = unveraendert (auch ohne exif-Erweiterung). */
    public static function lesen(string $pfad): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($pfad, null, false);
        $wert = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $wert >= 1 && $wert <= 8 ? $wert : 1;
    }

    /** Dreht/spiegelt das Bild gerade; bei 1 unveraendert. */
    public static function anwenden(\GdImage $bild, int $orientierung): \GdImage
    {
        if (isset(self::SPIEGELN[$orientierung])) {
            imageflip($bild, self::SPIEGELN[$orientierung]);
        }
        if (isset(self::DREHEN[$orientierung])) {
            $bild = imagerotate($bild, self::DREHEN[$orientierung], 0) ?: $bild;
        }

        return $bild;
    }
}
