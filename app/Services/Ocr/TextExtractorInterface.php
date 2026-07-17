<?php
namespace App\Services\Ocr;

/**
 * Vertrag fuer die kostenlose Text-Extraktion (OCR-Basisebene des Smart
 * Document Upload). Austauschbar wie DocumentAiProviderInterface, falls
 * spaeter ein anderer OCR-Dienst als Tesseract eingesetzt werden soll.
 */
interface TextExtractorInterface
{
    /** Ob die Extraktion konfiguriert UND lauffaehig ist (Config-Flag + Systembinaries vorhanden). */
    public function isAvailable(): bool;

    /** Liefert den erkannten Text oder '', wenn nichts extrahiert werden konnte. */
    public function extract(string $binary, string $mime): string;
}
