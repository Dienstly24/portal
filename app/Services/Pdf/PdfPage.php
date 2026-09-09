<?php

namespace App\Services\Pdf;

/**
 * Eine Seite, so wie die Signatur-Oberflaeche sie braucht: Nummer des
 * Seitenobjekts (zum Ergaenzen), Groesse und Drehung.
 *
 * ANZEIGE-Groesse (displayWidth/-Height) ist NICHT dieselbe wie die
 * MediaBox: bei /Rotate 90 zeigt der Betrachter die Seite quer. Die
 * Feldkoordinaten der Oberflaeche beziehen sich immer auf das, was der
 * Mensch gesehen hat - alles andere waere ein Feld, das im fertigen
 * Dokument woanders steht als im Editor.
 */
final class PdfPage
{
    public function __construct(
        public readonly int $index,
        public readonly int $objectNumber,
        /** @var array{0: float, 1: float, 2: float, 3: float} */
        public readonly array $mediaBox,
        public readonly int $rotate,
    ) {
    }

    public function width(): float
    {
        return abs($this->mediaBox[2] - $this->mediaBox[0]);
    }

    public function height(): float
    {
        return abs($this->mediaBox[3] - $this->mediaBox[1]);
    }

    public function isQuarterTurned(): bool
    {
        return $this->rotate === 90 || $this->rotate === 270;
    }

    public function displayWidth(): float
    {
        return $this->isQuarterTurned() ? $this->height() : $this->width();
    }

    public function displayHeight(): float
    {
        return $this->isQuarterTurned() ? $this->width() : $this->height();
    }
}
