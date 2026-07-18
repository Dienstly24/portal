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
 * $ocrText ist die kostenlose Tesseract-Vorstufe (leer, wenn OCR nicht
 * aktiv ist/nichts gelesen hat) - ein reiner Bild-/PDF-Anbieter wie
 * Claude kann sie ignorieren, ein textbasierter Anbieter ohne Vision
 * kann sich vollstaendig darauf stuetzen.
 */
interface DocumentAiProviderInterface
{
    public function isEnabled(): bool;

    public function model(): string;

    /**
     * @return array{type: string, confidence: int, summary: string, title: ?string, data: array}|null
     *         null = keine sichere Antwort (Ergebnis wird verworfen).
     * @throws \RuntimeException bei einem Dienstfehler (fuer Retry/Fehlerstatus im Job)
     */
    public function analyze(string $binary, string $mime, string $ocrText): ?array;
}
