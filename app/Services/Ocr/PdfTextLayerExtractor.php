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
     * Textebene, Fehler oder zu wenig verwertbarem Text.
     */
    public function extract(string $binary): string
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
            return mb_strlen($text) >= self::MIN_USEFUL_CHARS ? $text : '';
        } catch (\Throwable $e) {
            Log::warning('PDF-Textebene-Extraktion fehlgeschlagen: ' . $e->getMessage());
            return '';
        } finally {
            @unlink($pdfPath);
            @rmdir($dir);
        }
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
