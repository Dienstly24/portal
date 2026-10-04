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
        /** @var array{0: float, 1: float, 2: float, 3: float}|null auf die MediaBox beschnitten; null = wie MediaBox */
        public readonly ?array $cropBox = null,
    ) {
    }

    /** Weicht der sichtbare Bereich (CropBox) von der MediaBox ab? */
    public function hatAbweichendeCropBox(): bool
    {
        if ($this->cropBox === null) {
            return false;
        }
        foreach ([0, 1, 2, 3] as $i) {
            if (abs($this->cropBox[$i] - $this->mediaBox[$i]) > 0.5) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dieselbe Seite, aber mit dem SICHTBAREN Bereich als Bezugsflaeche.
     *
     * Ein Betrachter (Acrobat, Browser, Vorschau) zeigt die CropBox, nicht
     * die MediaBox. Wird ein Feld auf die MediaBox bezogen, kann es in
     * einem Rand liegen, den der Kunde nie zu sehen bekommt. Mit dieser
     * Fassung rechnen Stempler, Selbsttest und Diagnose fuer Anfragen mit
     * Feldbezug "cropbox" - Drehung und Versatz laufen unveraendert ueber
     * dieselben Formeln, nur die Flaeche ist eine andere.
     */
    public function alsSichtbereich(): self
    {
        if ($this->cropBox === null) {
            return $this;
        }

        return new self($this->index, $this->objectNumber, $this->cropBox, $this->rotate, null);
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
