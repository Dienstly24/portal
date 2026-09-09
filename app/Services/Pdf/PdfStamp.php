<?php

namespace App\Services\Pdf;

/**
 * EIN sichtbarer Eintrag auf einer Seite - eine Unterschrift, ein Name, ein
 * Datum, ein Kreuz.
 *
 * Die Koordinaten sind ANZEIGE-Koordinaten: Ursprung oben links, so wie der
 * Mitarbeiter das Feld im Editor gesetzt und der Unterzeichner es gesehen
 * hat. Die Umrechnung in die PDF-Welt (Ursprung unten links, dazu die
 * Seitendrehung) macht ausschliesslich der PdfStamper - an EINER Stelle,
 * sonst steht das Feld im fertigen Dokument woanders als im Editor.
 */
final class PdfStamp
{
    public const TYPE_IMAGE = 'bild';

    public const TYPE_TEXT = 'text';

    public const TYPE_BOX = 'rahmen';

    private function __construct(
        public readonly int $pageIndex,
        public readonly string $type,
        public readonly float $x,
        public readonly float $y,
        public readonly float $width,
        public readonly float $height,
        public readonly ?string $png = null,
        public readonly ?string $text = null,
        public readonly float $fontSize = 10.0,
    ) {
    }

    public static function image(int $pageIndex, string $png, float $x, float $y, float $width, float $height): self
    {
        return new self($pageIndex, self::TYPE_IMAGE, $x, $y, $width, $height, png: $png);
    }

    public static function text(int $pageIndex, string $text, float $x, float $y, float $width, float $height, float $fontSize = 10.0): self
    {
        return new self($pageIndex, self::TYPE_TEXT, $x, $y, $width, $height, text: $text, fontSize: $fontSize);
    }

    public static function box(int $pageIndex, float $x, float $y, float $width, float $height): self
    {
        return new self($pageIndex, self::TYPE_BOX, $x, $y, $width, $height);
    }
}
