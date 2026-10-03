<?php

namespace App\Support;

use App\Models\SignatureField;
use App\Services\Pdf\PdfPage;

/**
 * Die EINE Umrechnung eines Signaturfeldes in Punkte auf der Seite
 * (Betreiber-Entscheidung 02.10.2026: Anteile bleiben, eine Funktion fuer
 * Stempler, Selbsttest und Diagnose).
 *
 * WAS GESPEICHERT IST (`signature_fields.pos_x/pos_y/width/height`):
 * ANTEILE von 0 bis 1 der ANZEIGE-Seite - also der Seite NACH Anwendung
 * von /Rotate, bezogen auf die MediaBox (so rendert auch pdftoppm ohne
 * -cropbox die Vorschau, auf der das Feld gesetzt wurde), Ursprung OBEN
 * LINKS. Damit sind die Werte unabhaengig vom Zoom des Editors und von
 * der Bildschirmaufloesung: ein Feld bei 75 %, 100 % oder 150 % ergibt
 * denselben Anteil.
 *
 * WAS HERAUSKOMMT: [x, y, breite, hoehe] in PUNKTEN der Anzeige-Seite,
 * Ursprung oben links. Die Umrechnung in den PDF-Benutzerraum (Ursprung
 * unten links, Drehung, MediaBox-Versatz) macht ausschliesslich der
 * PdfStamper - an EINER Stelle.
 */
final class FeldGeometrie
{
    /** @return array{0: float, 1: float, 2: float, 3: float} */
    public static function punkte(SignatureField $field, PdfPage $page): array
    {
        return self::ausAnteilen(
            (float) $field->pos_x,
            (float) $field->pos_y,
            (float) $field->width,
            (float) $field->height,
            $page->displayWidth(),
            $page->displayHeight(),
        );
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    public static function ausAnteilen(float $x, float $y, float $w, float $h, float $seitenBreite, float $seitenHoehe): array
    {
        return [$x * $seitenBreite, $y * $seitenHoehe, $w * $seitenBreite, $h * $seitenHoehe];
    }
}
