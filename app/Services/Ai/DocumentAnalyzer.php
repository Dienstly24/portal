<?php
namespace App\Services\Ai;

use App\Models\Document;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestriert die Dokumentanalyse (Smart Document Upload):
 *
 * 1) Die kostenlose OCR-Basisebene (Tesseract, nur wenn per Konfiguration
 *    aktiviert) liest den Text der Datei.
 * 2) Ist ein KI-Anbieter konfiguriert (Standard: Claude/Anthropic), liefert
 *    er Typ-Erkennung und strukturierte Datenextraktion - er liest Bilder/
 *    PDFs direkt (Vision) und braucht den OCR-Text i.d.R. nicht.
 * 3) Ohne KI-Anbieter liefert eine einfache Stichwort-/Regex-Heuristik auf
 *    dem OCR-Text ein Ergebnis niedriger Konfidenz.
 * 4) Ist weder ein Anbieter konfiguriert noch OCR aktiv/erfolgreich,
 *    bleibt der Upload ohne Analyse (ai_status = 'none', wie zuvor).
 *
 * Der KI-Anbieter ist ueber DocumentAiProviderInterface austauschbar
 * (siehe AppServiceProvider): ein weiterer Anbieter braucht keinen Umbau
 * dieser Klasse, des Analyse-Jobs oder der Review-UI.
 */
class DocumentAnalyzer
{
    public const SKILL = 'analyze_document';

    /** Anthropic-Limit fuer PDF-Requests liegt bei 32 MB; wir bleiben darunter. */
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

    private const IMAGE_MEDIA_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif',
    ];

    public function __construct(
        private readonly DocumentAiProviderInterface $provider,
        private readonly TextExtractorInterface $ocr,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->provider->isEnabled() || $this->ocr->isAvailable();
    }

    public function model(): string
    {
        return $this->provider->isEnabled() ? $this->provider->model() : 'tesseract-ocr';
    }

    /**
     * Analysiert die Dokumentdatei (PDF oder Bild) und liefert das
     * validierte Ergebnis (inkl. "source": 'ai'|'ocr') - oder null, wenn
     * keine brauchbare/sichere Antwort vorliegt.
     *
     * @return array{type: string, confidence: int, summary: string, title: ?string, data: array, source: string}|null
     * @throws \RuntimeException bei nicht analysierbarer Datei oder KI-Dienstfehler
     */
    public function analyze(Document $document): ?array
    {
        [$binary, $mime] = $this->readFile($document);

        $ocrText = $this->ocr->isAvailable() ? $this->ocr->extract($binary, $mime) : '';

        if ($this->provider->isEnabled()) {
            $result = $this->provider->analyze($binary, $mime, $ocrText);
            return $result !== null ? [...$result, 'source' => 'ai'] : null;
        }

        if ($ocrText !== '') {
            $result = (new HeuristicDocumentClassifier())->classify($ocrText);
            return $result !== null ? [...$result, 'source' => 'ocr'] : null;
        }

        return null;
    }

    /** @return array{0: string, 1: string} [$binary, $mime] */
    private function readFile(Document $document): array
    {
        $disk = $document->disk ?: 'public';
        if (!Storage::disk($disk)->exists($document->file_path)) {
            throw new \RuntimeException('Datei nicht gefunden.');
        }

        $binary = Storage::disk($disk)->get($document->file_path);
        if (strlen($binary) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Datei zu gross fuer die Analyse (max. 20 MB).');
        }

        // Medientyp aus dem ECHTEN Inhalt bestimmen (Client-Dateinamen sind
        // nicht verlaesslich); liefert der Inhalt keinen bekannten Typ,
        // faellt die Erkennung auf die Endung des Anzeigenamens zurueck.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: '';
        if ($mime !== 'application/pdf' && !in_array($mime, self::IMAGE_MEDIA_TYPES, true)) {
            $ext = strtolower(pathinfo($document->file_name, PATHINFO_EXTENSION));
            $mime = $ext === 'pdf' ? 'application/pdf' : (self::IMAGE_MEDIA_TYPES[$ext] ?? '');
        }

        if ($mime !== 'application/pdf' && !in_array($mime, self::IMAGE_MEDIA_TYPES, true)) {
            throw new \RuntimeException('Dateityp wird von der Analyse nicht unterstuetzt (' . ($mime ?: 'unbekannt') . ').');
        }

        return [$binary, $mime];
    }
}
