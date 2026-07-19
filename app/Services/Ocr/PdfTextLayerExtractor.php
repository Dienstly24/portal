<?php
namespace App\Services\Ocr;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Kostenlose "Textebene zuerst"-Stufe des Smart Document Upload.
 *
 * Sehr viele Dokumente, die der Betrieb hochlaedt (CHECK24-Beratungs-
 * protokolle, Antraege/Policen aus Versicherer-Portalen, alles, was aus einer
 * Software erzeugt wurde), sind DIGITALE PDFs mit einer eingebetteten,
 * perfekten Textebene. Diese laesst sich per `pdftotext` in Millisekunden und
 * zu NULL Kosten auslesen - besser als OCR (fehlerfrei) und ohne die teure
 * KI-Vision-Eskalation (bei vielseitigen PDFs schnell 20+ Cent pro Dokument).
 *
 * Diese Stufe laeuft daher VOR Tesseract-OCR und vor dem KI-Anbieter. Ist
 * keine Textebene vorhanden (echter Scan als Bild) oder schlaegt der Aufruf
 * fehl, wird '' zurueckgegeben und die Analyse faellt sauber auf OCR/Vision
 * zurueck. Der Rohtext wird - wie bei OCR - NICHT gespeichert
 * (Datenminimierung); nur das validierte Extraktionsergebnis bleibt.
 */
class PdfTextLayerExtractor
{
    /** Unter dieser Zeichenzahl gilt eine PDF praktisch als bildbasiert (Scan). */
    private const MIN_USEFUL_CHARS = 40;

    public function isAvailable(): bool
    {
        if (!config('services.ocr.text_layer', true)) {
            return false;
        }
        return $this->binaryWorks();
    }

    /**
     * Liest die Textebene eines PDF (erste N Seiten). Liefert '' bei fehlender
     * Textebene, Fehler, zu wenig verwertbarem Text ODER kaputt kodierter
     * Textebene (Mojibake). Fuer Heuristik/KI verwenden.
     */
    public function extract(string $binary): string
    {
        $text = $this->extractRaw($binary);

        // Manche digitalen PDFs (z.B. enviaM-Auftraege, Novitas-Formulare)
        // haben eine kaputt kodierte Textebene (Font-Encoding ohne gueltiges
        // ToUnicode) - pdftotext liefert dann Kauderwelsch ("$XIWUDJ" statt
        // "Auftrag"). Solchen Text NICHT an Heuristik/KI weiterreichen, damit
        // die Analyse sauber auf OCR/Vision zurueckfaellt.
        return ($text === '' || $this->isLikelyGarbled($text)) ? '' : $text;
    }

    /**
     * Rohe Textebene OHNE Mojibake-Filter. Fuer Vorlagen-Parser: bei
     * Formularen mit defektem Font-Encoding sind zwar die BESCHRIFTUNGEN
     * kaputt, die AUSGEFUELLTEN Werte (Name, Datum, Ort ...) aber sauber und
     * exakt positioniert. Ein Formular-Parser, der das Layout kennt (z.B.
     * Novitas-Beitrittserklaerung), liest diese Werte gratis und praezise -
     * besser als OCR, das mehrteilige Namen verschmilzt.
     */
    public function extractRaw(string $binary): string
    {
        if (!$this->isAvailable()) {
            return '';
        }

        $dir = sys_get_temp_dir() . '/dienstly_pdftext_' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }

        $pdfPath = $dir . '/source.pdf';

        try {
            file_put_contents($pdfPath, $binary);

            $maxPages = (string) max(1, (int) config('services.ocr.text_layer_max_pages', 15));

            // -layout bewahrt die Spalten-/Zeilenstruktur (wichtig fuer die
            // zeilenweise Heuristik-Extraktion, z.B. IBAN in eigener Zeile).
            $process = new Process([
                $this->binary(), '-layout', '-f', '1', '-l', $maxPages, '-enc', 'UTF-8', $pdfPath, '-',
            ]);
            $process->setTimeout(30);
            $process->run();

            if (!$process->isSuccessful()) {
                return '';
            }

            $text = trim($process->getOutput());
            if (mb_strlen($text) < self::MIN_USEFUL_CHARS) {
                return '';
            }

            return $text;
        } catch (\Throwable $e) {
            Log::warning('PDF-Textebene-Extraktion fehlgeschlagen: ' . $e->getMessage());
            return '';
        } finally {
            @unlink($pdfPath);
            @rmdir($dir);
        }
    }

    /** Haeufige deutsche Allerweltswoerter - eine echte Textebene trifft mehrere. */
    private const GERMAN_MARKERS = [
        'und', 'der', 'die', 'für', 'fuer', 'straße', 'strasse', 'gmbh', 'euro',
        'datum', 'vertrag', 'kunde', 'nummer', 'betrag', 'monat', 'jahr', 'name', 'preis',
    ];

    /**
     * Grobe Erkennung einer kaputt kodierten Textebene. Zwei Signale:
     *
     * 1) Steuer-/C1-Zeichen (0x00-0x1F ohne Whitespace, U+0080-U+009F) kommen
     *    in echtem Text nie vor. Ein Font ohne gueltiges ToUnicode bildet
     *    Glyphen auf solche Codepoints ab (z.B. Novitas-Formulare) - schon ein
     *    paar davon sind ein sicheres Zeichen fuer Mojibake.
     * 2) Ein laengerer deutscher Text enthaelt zwangslaeufig mehrere
     *    Allerweltswoerter (der/die/und ...); fehlen sie fast alle, ist die
     *    Kodierung verschoben (z.B. Caesar-artige enviaM-Auftraege).
     *
     * Kurze Texte werden bewusst nicht bewertet (zu wenig Signal).
     */
    public function isLikelyGarbled(string $text): bool
    {
        if (mb_strlen($text) < 400) {
            return false;
        }
        // 1) Steuer-/C1-Zeichen -> sicheres Mojibake-Signal.
        if (preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{0080}-\x{009F}]/u', $text) >= 5) {
            return true;
        }
        // 2) Zu wenige deutsche Allerweltswoerter.
        $lower = mb_strtolower($text);
        $hits = 0;
        foreach (self::GERMAN_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                $hits++;
            }
        }
        return $hits < 3;
    }

    private function binary(): string
    {
        return (string) config('services.ocr.pdftotext_binary', 'pdftotext');
    }

    private function binaryWorks(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $process = new Process([$this->binary(), '-v']);
            $process->setTimeout(5);
            $process->run();
            // pdftotext -v schreibt die Version nach STDERR und liefert je nach
            // Version einen Nicht-Null-Exitcode; das Vorhandensein zaehlt.
            return $cache = ($process->isSuccessful()
                || str_contains($process->getErrorOutput(), 'pdftotext'));
        } catch (\Throwable) {
            return $cache = false;
        }
    }
}
