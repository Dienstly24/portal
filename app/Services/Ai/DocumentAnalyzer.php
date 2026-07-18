<?php
namespace App\Services\Ai;

use App\Models\Document;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestriert die Dokumentanalyse (Smart Document Upload) - "kostenlos
 * zuerst" (Betreiber-Entscheidung):
 *
 * 1) Die kostenlose OCR-Basisebene (Tesseract) liest den Text und eine
 *    einfache Stichwort-/Regex-Heuristik bestimmt Typ + Basisfelder.
 * 2) Reicht dieses Ergebnis (Typ erkannt UND mindestens ein nutzbares
 *    Feld), wird es OHNE KI-Aufruf uebernommen (ai_source = 'ocr').
 * 3) Sonst wird - falls konfiguriert - der KI-Anbieter (Standard: Claude,
 *    Vision) hinzugezogen (ai_source = 'ai'). So kostet die KI nur dann
 *    etwas, wenn die kostenlose Stufe nicht ausreicht.
 * 4) Ist kein KI-Anbieter konfiguriert, bleibt es beim (ggf. schwachen)
 *    OCR-Ergebnis; ist auch OCR nicht verfuegbar, laeuft der Upload wie
 *    frueher ohne Analyse.
 *
 * Mitarbeiter koennen die KI ueber die Review-UI bewusst erzwingen
 * (forceAi) - z.B. wenn das OCR-Ergebnis zwar formal "reicht", die
 * Kundenzuordnung aber die bessere Vision-Extraktion braucht.
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
        private readonly PdfTextLayerExtractor $pdfText,
        private readonly RelevantPageSelector $pageSelector,
        private readonly \App\Services\Ai\Contracts\DocumentTemplateParser $templateParser,
    ) {
    }

    /** Analyse moeglich? (KI-Anbieter ODER kostenlose Text-/OCR-Basisebene) */
    public function isEnabled(): bool
    {
        return $this->provider->isEnabled() || $this->ocr->isAvailable() || $this->pdfText->isAvailable();
    }

    /** Steht die kostenpflichtige KI-Stufe zur Verfuegung? (fuer den "Mit KI"-Button) */
    public function providerEnabled(): bool
    {
        return $this->provider->isEnabled();
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
     * @param bool $forceAi KI-Anbieter direkt nutzen (Mitarbeiter-Eskalation),
     *                      die kostenlose Vorstufe ueberspringen.
     * @return array{type: string, confidence: int, summary: string, title: ?string, data: array, source: string}|null
     * @throws \RuntimeException bei nicht analysierbarer Datei oder KI-Dienstfehler
     */
    public function analyze(Document $document, bool $forceAi = false): ?array
    {
        [$binary, $mime] = $this->readFile($document);

        // Erzwungene KI-Eskalation: kostenlose Stufe ueberspringen.
        if ($forceAi && $this->provider->isEnabled()) {
            return $this->runProvider($binary, $mime, '');
        }

        // Kostenlose Stufe zuerst - in aufsteigender Kosten-Reihenfolge:
        // 1) PDF-Textebene (pdftotext, gratis, perfekter Text bei digitalen
        //    PDFs), 2) sonst Tesseract-OCR (gratis, aber CPU + Fehler bei
        //    Scans). Der so gewonnene Text speist die Stichwort-/Regex-
        //    Heuristik.
        $freeText = '';
        $fromTextLayer = false;
        if ($mime === 'application/pdf' && $this->pdfText->isAvailable()) {
            $freeText = $this->pdfText->extract($binary);
            $fromTextLayer = $freeText !== '';
            if ($fromTextLayer) {
                // Bekanntes, immer gleich aufgebautes Formular? Dann GRATIS per
                // fester Regel aus der Textebene lesen (kein KI-Aufruf) - z.B.
                // das CHECK24-Kfz-Beratungsprotokoll.
                $parsed = $this->templateParser->parse($freeText);
                if ($parsed !== null) {
                    return [...$parsed, 'source' => 'template'];
                }
                // Sonst: bekannte Formulare auf die relevanten Seiten
                // reduzieren - weniger Rauschen/Tokens fuer Heuristik/KI.
                $freeText = $this->pageSelector->reduce($freeText);
            }
        }
        if ($freeText === '' && $this->ocr->isAvailable()) {
            $freeText = $this->ocr->extract($binary, $mime);
        }
        $ocrResult = $freeText !== '' ? (new HeuristicDocumentClassifier())->classify($freeText) : null;

        // Reicht das kostenlose Ergebnis, KI gar nicht erst bemuehen.
        if ($ocrResult !== null && $this->ocrResultSufficient($ocrResult, $freeText)) {
            return [...$ocrResult, 'source' => 'ocr'];
        }

        // Sonst zur KI eskalieren (falls konfiguriert). Bei sauberer
        // Textebene bekommt die KI den TEXT (billig) statt der Bild-/PDF-
        // Seiten - gleiche Genauigkeit, ein Bruchteil der Kosten.
        if ($this->provider->isEnabled()) {
            return $this->runProvider($binary, $mime, $freeText, $fromTextLayer);
        }

        // Kein KI-Anbieter: bestmoegliches OCR-Ergebnis (auch schwach) oder nichts.
        return $ocrResult !== null ? [...$ocrResult, 'source' => 'ocr'] : null;
    }

    /** @return array{...}|null */
    private function runProvider(string $binary, string $mime, string $ocrText, bool $preferText = false): ?array
    {
        $result = $this->provider->analyze($binary, $mime, $ocrText, $preferText);
        return $result !== null ? [...$result, 'source' => 'ai'] : null;
    }

    /**
     * Ist das kostenlose Ergebnis gut genug, um die KI zu sparen?
     * Kriterien:
     * - Der Text ist kurz genug fuer die einfache Heuristik. Lange,
     *   mehrseitige Dokumente (Protokolle, Vertraege) haben zu viele
     *   Abschnitte -> die Regex-Heuristik produziert Falschtreffer
     *   (fremde E-Mail, maskierte IBAN, 17-Buchstaben-Wort als FIN). Solche
     *   werden zur genauen KI-Analyse eskaliert (auf dem billigen Textweg).
     * - Der Dokumenttyp wurde erkannt (nicht 'sonstiges') UND mindestens ein
     *   strukturiertes Feld (IBAN, FIN, Kennzeichen, E-Mail ...) extrahiert.
     */
    private function ocrResultSufficient(array $result, string $text): bool
    {
        if (mb_strlen($text) > max(200, (int) config('services.ocr.heuristic_max_chars', 2500))) {
            return false;
        }
        if (($result['type'] ?? 'sonstiges') === 'sonstiges') {
            return false;
        }
        foreach ($result['data'] ?? [] as $group) {
            if (is_array($group) && $group !== []) {
                return true;
            }
        }
        return false;
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
