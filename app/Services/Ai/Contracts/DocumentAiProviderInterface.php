<?php
namespace App\Services\Ai\Contracts;

/**
 * Vertrag fuer austauschbare KI-Anbieter der Dokumentanalyse (Smart
 * Document Upload). Ein weiterer Anbieter (z.B. ein anderer LLM-
 * Dienst) muss nur dieses Interface implementieren und in
 * AppServiceProvider registriert werden - DocumentAnalyzer (der
 * Orchestrator), der Analyse-Job, das Matching und die Review-UI
 * bleiben dabei unveraendert.
 *
 * $ocrText ist die kostenlose Vorstufe (PDF-Textebene oder Tesseract-OCR;
 * leer, wenn nichts gelesen wurde). $preferText signalisiert, dass dieser
 * Text zuverlaessig ist (echte PDF-Textebene) und die KI ihn STATT der
 * teuren Bild-/PDF-Seiten nutzen soll - gleiche Genauigkeit, ein Bruchteil
 * der Kosten. Ein textloser Anbieter kann sich immer auf $ocrText stuetzen.
 */
interface DocumentAiProviderInterface
{
    public function isEnabled(): bool;

    public function model(): string;

    /**
     * @param bool $preferText Text (falls vorhanden) der Bild-/PDF-Analyse
     *                         vorziehen (guenstiger bei digitalen PDFs).
     * @return array{type: string, confidence: int, summary: string, title: ?string, data: array}|null
     *         null = keine sichere Antwort (Ergebnis wird verworfen).
     * @throws \RuntimeException bei einem Dienstfehler (fuer Retry/Fehlerstatus im Job)
     */
    public function analyze(string $binary, string $mime, string $ocrText, bool $preferText = false): ?array;
}
