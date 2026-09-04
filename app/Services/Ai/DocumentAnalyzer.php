<?php

namespace App\Services\Ai;

use App\Models\Document;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Support\Facades\Log;
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
        private readonly DocumentTemplateParser $templateParser,
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
     * @param bool $fresh   Bewusste NEU-Analyse (Mitarbeiter-Klick): das
     *                      Duplikat-Ergebnis nicht wiederverwenden, sondern
     *                      die Datei wirklich neu lesen - sonst kaeme nach
     *                      einer Parser-Verbesserung immer wieder das alte
     *                      Ergebnis zurueck.
     * @return array{type: string, confidence: int, summary: string, title: ?string, data: array, source: string}|null
     * @throws \RuntimeException bei nicht analysierbarer Datei oder KI-Dienstfehler
     */
    public function analyze(Document $document, bool $forceAi = false, bool $fresh = false): ?array
    {
        // Duplikat-Wiederverwendung (identischer Inhalts-Hash -> duplicate_of):
        // das fertige Ergebnis des Zwillings spart vor allem den zweiten
        // (kostenpflichtigen) KI-Aufruf. Sie greift aber erst NACH den
        // Vorlagen-Parsern - auf der Textebene UND auf dem OCR-Text - denn
        // die sind gratis und werden laufend verbessert; ein erneut
        // hochgeladenes Dokument (auch ein FOTO) soll das BESTE aktuelle
        // Ergebnis bekommen, nicht die Kopie eines veralteten Fehlversuchs
        // von vor der Verbesserung.
        // Nur ohne bewusste KI-Erzwingung (forceAi) und Neu-Analyse (fresh).
        $reuse = (! $forceAi && ! $fresh)
            ? fn () => $this->reuseFromDuplicate($document)
            : fn () => null;

        try {
            [$binary, $mime] = $this->readFile($document);
        } catch (\RuntimeException $e) {
            // Datei nicht lesbar (fehlt/zu gross/unbekannter Typ): fuer ein
            // Duplikat rettet das gespeicherte Zwillings-Ergebnis die Analyse.
            if (($reused = $reuse()) !== null) {
                return $reused;
            }
            throw $e;
        }

        // Erzwungene KI-Eskalation: kostenlose Stufe ueberspringen.
        if ($forceAi && $this->provider->isEnabled()) {
            return $this->runProvider($binary, $mime, '');
        }

        // Bekanntes Formular mit kaputt kodierter Textebene (defekter Font,
        // z.B. Novitas-Beitrittserklaerung)? Dann bekommen die Vorlagen-Parser
        // die ROHE Textebene ZUERST: die Beschriftungen sind zwar Mojibake, die
        // ausgefuellten WERTE aber sauber und exakt positioniert (Spalten aus
        // -layout). Ein layout-kundiger Parser liest sie gratis und praeziser
        // als OCR (das mehrteilige Namen verschmelzen wuerde).
        if ($mime === 'application/pdf' && $this->pdfText->isAvailable()) {
            $rawTextLayer = $this->pdfText->extractRaw($binary);
            if ($rawTextLayer !== '') {
                $parsed = $this->templateParser->parse($rawTextLayer);
                if ($parsed !== null) {
                    return $this->acceptTemplateOrEscalate($parsed, $binary, $mime, $rawTextLayer, true, $reuse);
                }
            }
        }

        // Kostenlose Stufe zuerst - in aufsteigender Kosten-Reihenfolge:
        // 1) PDF-Textebene (pdftotext, gratis, perfekter Text bei digitalen
        //    PDFs; kaputt kodierte Textebene wird hier verworfen), 2) sonst
        //    Tesseract-OCR (gratis, aber CPU + Fehler bei Scans). Der so
        //    gewonnene Text speist die Stichwort-/Regex-Heuristik.
        $freeText = '';
        $fromTextLayer = false;
        if ($mime === 'application/pdf' && $this->pdfText->isAvailable()) {
            $freeText = $this->pdfText->extract($binary);
            $fromTextLayer = $freeText !== '';
        }

        // HYBRID-Schutz: eine WINZIGE Textebene (nur Briefkopf/Fusszeile eines
        // ansonsten gescannten PDFs) wuerde sonst die gesamte Analyse blenden -
        // OCR liefe nie, und die KI bekaeme nur den Stummel als "Text". Ist OCR
        // verfuegbar, wird so eine Mini-Ebene verworfen und der Scan normal
        // gelesen; ohne OCR bleibt der Stummel (besser als nichts).
        if ($fromTextLayer && mb_strlen($freeText) < 200 && $this->ocr->isAvailable()) {
            $freeText = '';
            $fromTextLayer = false;
        }

        // Bekanntes, immer gleich aufgebautes Formular auf der sauberen
        // Textebene? Dann GRATIS per fester Regel lesen (kein KI-Aufruf).
        if ($freeText !== '') {
            $parsed = $this->templateParser->parse($freeText);
            if ($parsed !== null) {
                return $this->acceptTemplateOrEscalate($parsed, $binary, $mime, $freeText, true, $reuse);
            }
        }

        // Kein Vorlagen-Treffer auf der Textebene: bei Scans/Fotos zuerst
        // noch OCR + Vorlagen-Parser versuchen - Tesseract ist gratis (nur
        // CPU). Frueher griff hier die Duplikat-Wiederverwendung VOR dem OCR:
        // ein Foto, das einmal vor einer Parser-Verbesserung fehlschlug,
        // kopierte sein schlechtes Alt-Ergebnis bei jedem erneuten Upload -
        // der verbesserte Parser kam fuer Fotos nie zum Zug.
        if ($freeText === '' && $this->ocr->isAvailable()) {
            $freeText = $this->ocr->extract($binary, $mime);

            // Bekanntes Formular auf dem OCR-Text (z.B. die als Bild-PDF
            // hochgeladene KKH-Beitrittserklaerung)? Ebenfalls gratis lesen.
            if ($freeText !== '') {
                $parsed = $this->templateParser->parse($freeText);
                if ($parsed !== null) {
                    // OCR-Text eines Scans/Fotos: die Eskalation soll VISION
                    // nutzen (beste Qualitaet bei Scans), nie den fehler-
                    // anfaelligen OCR-Text als "verlaesslich" behandeln.
                    return $this->acceptTemplateOrEscalate($parsed, $binary, $mime, $freeText, false, $reuse);
                }
            }
        }

        // Erst jetzt darf das fertige Ergebnis des inhaltsgleichen Zwillings
        // uebernommen werden - es spart die Heuristik und vor allem die
        // (kostenpflichtige) KI-Eskalation.
        if (($reused = $reuse()) !== null) {
            return $reused;
        }

        // Saubere Textebene: bekannte Formulare auf die relevanten Seiten
        // reduzieren - weniger Rauschen/Tokens fuer Heuristik/KI.
        if ($fromTextLayer) {
            $freeText = $this->pageSelector->reduce($freeText);
        }

        $ocrResult = $freeText !== '' ? (new HeuristicDocumentClassifier)->classify($freeText) : null;

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
     * Vertrags-Dokumenttypen, deren Kern der VERSICHERER/ANBIETER ist: ohne
     * ihn (und ohne Vertragsnummer) laesst sich kein Vertrag anlegen - die
     * Review-UI zeigt die "Vertrag anlegen"-Box dann gar nicht erst an.
     * Bewusst OHNE die Kranken-Formulare (beitrittserklaerung,
     * familienversicherung): deren Kasse steht in "gesundheit", nicht in
     * "versicherung" - sie wuerden sonst grundlos KI kosten.
     */
    private const CONTRACT_CORE_TYPES = [
        'kfz_vertrag', 'escooter_vertrag', 'versicherungsvertrag',
        'beratungsprotokoll', 'energieauftrag', 'internetvertrag',
        // Auch eine POLICE lebt vom Versicherer/der Vertragsnummer - ein
        // Versicherungsschein-Foto, das die Heuristik nur an einer E-Mail
        // "erkannte", blieb sonst ohne Vertragskern liegen (Audit 10.08.2026).
        'versicherungspolice',
    ];

    /**
     * Sicherheitsnetz fuer Vorlagen-Treffer (Lehre 10.08.2026 - echtes
     * CHECK24-Protokoll): ein Vorlagen-Parser kann ein Vertrags-Dokument
     * erkennen, aber am Layout-Detail scheitern und OHNE Versicherer
     * zurueckkommen. Wuerde das Ergebnis einfach uebernommen, bietet die
     * Review-UI "Vertrag anlegen/verknuepfen" nicht an - genau der gemeldete
     * Ausfall. Deshalb: fehlt einem VERTRAGS-Dokument (CONTRACT_CORE_TYPES)
     * sowohl Versicherer als auch Vertragsnummer, wird zur KI eskaliert
     * (billiger Textweg). Liefert die KI nichts Brauchbares, bleibt das
     * kostenlose Vorlagen-Ergebnis erhalten (besser als nichts).
     *
     * @param array<string,mixed> $parsed validiertes Vorlagen-Ergebnis
     * @return array<string,mixed>
     */
    private function acceptTemplateOrEscalate(array $parsed, string $binary, string $mime, string $text, bool $fromTextLayer, ?callable $reuse = null): array
    {
        $template = [...$parsed, 'source' => 'template'];

        if (! in_array($parsed['type'] ?? '', self::CONTRACT_CORE_TYPES, true)) {
            return $template;
        }
        if ($this->hasContractCore($parsed)) {
            return $template;
        }
        if (! $this->provider->isEnabled()) {
            return $template;
        }

        // KOSTENDECKEL: fuer denselben Inhalt (Duplikat, identischer Hash)
        // wird die Eskalation nur EINMAL bezahlt. Hat der Zwilling den
        // Vertragskern bereits (typisch: seine eigene KI-Eskalation), wird
        // sein Ergebnis uebernommen; hat auch er keinen, bringt ein weiterer
        // bezahlter Versuch nichts - das Vorlagen-Ergebnis bleibt. Nur eine
        // bewusste Neu-Analyse (fresh) bzw. Erst-Uploads zahlen.
        if ($reuse !== null && ($reused = $reuse()) !== null) {
            return $this->hasContractCore($reused) ? $reused : $template;
        }

        // Textweg nur bei ZUVERLAESSIGEM Text: einer echten, nicht kaputt
        // kodierten PDF-Textebene. OCR-Text eines Scans/Fotos ist dafuer zu
        // fehleranfaellig - dort Vision (beste Qualitaet bei Scans), wie auf
        // dem normalen Eskalationsweg auch.
        $preferText = $fromTextLayer && $text !== '' && ! $this->pdfText->isLikelyGarbled($text);
        try {
            $ai = $this->runProvider($binary, $mime, $preferText ? $text : '', $preferText);
        } catch (\Throwable $e) {
            Log::warning(
                'KI-Eskalation nach Vorlagen-Treffer ohne Versicherer fehlgeschlagen: '.$e->getMessage()
            );
            return $template;
        }

        // Die KI-Antwort ersetzt das (praezise) Vorlagen-Ergebnis nur, wenn
        // sie WIRKLICH liefert, wozu eskaliert wurde: den Vertragskern.
        // Eine leere/duennere KI-Antwort (gueltiges JSON ohne Versicherer,
        // Typ 'sonstiges') wuerde sonst gelesene Felder wegwerfen und die
        // Kategorie verfaelschen - dann lieber das Vorlagen-Ergebnis behalten.
        return ($ai !== null && $this->hasContractCore($ai)) ? $ai : $template;
    }

    /** Traegt das Analyse-Ergebnis einen Vertragskern (Versicherer/Vertragsnummer)? */
    private function hasContractCore(array $result): bool
    {
        $ins = $result['data']['versicherung'] ?? [];
        return ! blank($ins['insurer'] ?? null) || ! blank($ins['contract_number'] ?? null);
    }

    /**
     * Uebernimmt das fertige Analyse-Ergebnis eines inhaltsgleichen, bereits
     * abgeschlossenen Dokuments (Duplikat). Der personenbezogene Match-
     * Vorschlag wird bewusst NICHT uebernommen - die Kundenzuordnung fuer das
     * neue Dokument rechnet der Job frisch (kostenlos, ohne KI).
     *
     * @return array{type: string, confidence: int, summary: ?string, title: ?string, data: array, source: string}|null
     */
    private function reuseFromDuplicate(Document $document): ?array
    {
        if (! $document->duplicate_of) {
            return null;
        }
        $twin = Document::whereKey($document->duplicate_of)
            ->where('ai_status', 'done')
            ->whereNotNull('ai_type')
            ->first();
        if ($twin === null) {
            return null;
        }

        $data = is_array($twin->ai_extracted) ? $twin->ai_extracted : [];
        unset($data['match']);

        return [
            'type' => $twin->ai_type,
            'confidence' => $twin->ai_confidence ?? 50,
            'summary' => $twin->ai_summary,
            'title' => null, // Dateiname des Duplikats bleibt unveraendert.
            'data' => $data,
            'source' => $twin->ai_source ?? 'ocr',
        ];
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
     * - AUSNAHME: Ein Dokument, das NEUES Geschaeft bedeutet (Auftrag/Antrag/
     *   Beratungsprotokoll/Police), ist ohne Vertragsdaten NICHT ausreichend.
     *   Die kostenlose Heuristik liest keinen Versicherer/keine Sparte - wuerde
     *   sie hier "reicht" melden (z.B. weil eine E-Mail erkannt wurde), bliebe
     *   der Vertrag ohne Versicherer und liesse sich NICHT anlegen. Solche
     *   Dokumente muessen zur KI eskalieren (die Vertragsdaten extrahiert).
     */
    private function ocrResultSufficient(array $result, string $text): bool
    {
        if (mb_strlen($text) > max(200, (int) config('services.ocr.heuristic_max_chars', 2500))) {
            return false;
        }
        $type = $result['type'] ?? 'sonstiges';
        if ($type === 'sonstiges') {
            return false;
        }
        // Vertrags-/Geschaefts-Dokument ohne Vertragsdaten -> immer eskalieren.
        // Gleiche Typliste wie das Vorlagen-Sicherheitsnetz (inkl.
        // versicherungspolice: ein Versicherungsschein-Foto wurde sonst allein
        // wegen einer erkannten Service-E-Mail als "ausreichend" akzeptiert
        // und blieb ohne Versicherer - kein "Vertrag anlegen" moeglich).
        if (in_array($type, self::CONTRACT_CORE_TYPES, true)
            && empty($result['data']['versicherung'])
            && empty($result['data']['energie'])
            && empty($result['data']['internet'])) {
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
        if (! Storage::disk($disk)->exists($document->file_path)) {
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
        if ($mime !== 'application/pdf' && ! in_array($mime, self::IMAGE_MEDIA_TYPES, true)) {
            $ext = strtolower(pathinfo($document->file_name, PATHINFO_EXTENSION));
            $mime = $ext === 'pdf' ? 'application/pdf' : (self::IMAGE_MEDIA_TYPES[$ext] ?? '');
        }

        if ($mime !== 'application/pdf' && ! in_array($mime, self::IMAGE_MEDIA_TYPES, true)) {
            throw new \RuntimeException('Dateityp wird von der Analyse nicht unterstuetzt ('.($mime ?: 'unbekannt').').');
        }

        return [$binary, $mime];
    }
}
