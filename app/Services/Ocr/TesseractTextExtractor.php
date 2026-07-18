<?php
namespace App\Services\Ocr;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Kostenlose OCR-Basisebene (Tesseract) fuer den Smart Document Upload.
 *
 * Bewusst nur mit ausdruecklicher Konfiguration aktiv (OCR_ENABLED=true),
 * NICHT allein anhand vorhandener Systembinaries: ein zufaellig auf dem
 * Server/CI-Runner installiertes Tesseract soll nicht unbemerkt das
 * Analyse-Verhalten aendern (z.B. in Tests). Der Betreiber schaltet die
 * Stufe bewusst per .env frei, nachdem `tesseract-ocr`, `tesseract-ocr-deu`
 * und (fuer PDFs) `poppler-utils` auf dem Server installiert sind.
 *
 * Faellt jede Extraktion aus irgendeinem Grund aus (Binary fehlt, Timeout,
 * kaputtes Bild), wird '' zurueckgegeben statt eine Exception zu werfen -
 * die OCR-Stufe darf den Upload/die restliche Analyse nie blockieren.
 */
class TesseractTextExtractor implements TextExtractorInterface
{
    /** Harte Obergrenze fuer PDF-Seiten, die rasterisiert/OCR-gelesen werden. */
    private const MAX_PDF_PAGES = 20;

    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
    ];

    public function isAvailable(): bool
    {
        if (!config('services.ocr.enabled')) {
            return false;
        }
        return $this->binaryWorks($this->tesseractBinary(), ['--version']);
    }

    public function extract(string $binary, string $mime): string
    {
        if (!$this->isAvailable()) {
            return '';
        }

        $dir = sys_get_temp_dir() . '/dienstly_ocr_' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }

        try {
            $images = $mime === 'application/pdf'
                ? $this->rasterizePdf($binary, $dir)
                : $this->writeSingleImage($binary, $mime, $dir);

            // Hartes Gesamt-Zeitbudget: OCR darf niemals das Job-Timeout
            // sprengen (sonst wird der Worker mitten im Lauf gekillt und das
            // Dokument bleibt in 'processing' haengen). Ist das Budget
            // erschoepft, wird mit dem bisher Gelesenen weitergemacht -
            // reicht das nicht, eskaliert DocumentAnalyzer ohnehin zur KI.
            $deadline = microtime(true) + max(5, (int) config('services.ocr.max_seconds', 60));

            $text = '';
            foreach ($images as $image) {
                if (microtime(true) >= $deadline) {
                    Log::warning('OCR-Zeitbudget erschoepft - Teilergebnis nach ' . mb_strlen($text) . ' Zeichen.');
                    break;
                }
                $text .= $this->ocrImage($image) . "\n";
            }
            return trim($text);
        } catch (\Throwable $e) {
            Log::warning('OCR-Extraktion fehlgeschlagen: ' . $e->getMessage());
            return '';
        } finally {
            $this->cleanup($dir);
        }
    }

    /** @return list<string> Pfade der rasterisierten Seiten (leer, wenn poppler-utils fehlt). */
    private function rasterizePdf(string $binary, string $dir): array
    {
        if (!$this->binaryWorks($this->pdftoppmBinary(), ['-v'])) {
            return [];
        }

        $pdfPath = $dir . '/source.pdf';
        file_put_contents($pdfPath, $binary);

        // Niedrigere DPI (Default 150 statt 200) reicht fuer OCR voellig aus,
        // halbiert aber Pixelzahl und Rechenzeit; die Seitenzahl fuer OCR ist
        // bewusst begrenzt (Default 10), da fuer Typ-Erkennung + Basisfelder
        // die ersten Seiten genuegen - so bleibt der Lauf im Zeitbudget.
        $dpi = (string) max(72, (int) config('services.ocr.dpi', 150));
        $maxPages = (string) max(1, (int) config('services.ocr.max_pages', 10));
        $prefix = $dir . '/page';
        $process = new Process([
            $this->pdftoppmBinary(), '-png', '-r', $dpi, '-f', '1', '-l', $maxPages, $pdfPath, $prefix,
        ]);
        $process->setTimeout(60);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }

        $files = glob($prefix . '*.png') ?: [];
        sort($files, SORT_NATURAL);
        return $files;
    }

    /** @return list<string> */
    private function writeSingleImage(string $binary, string $mime, string $dir): array
    {
        $ext = self::IMAGE_EXTENSIONS[$mime] ?? null;
        if ($ext === null) {
            return [];
        }
        $path = $dir . '/input.' . $ext;
        file_put_contents($path, $binary);
        return [$path];
    }

    private function ocrImage(string $path): string
    {
        $process = new Process([
            $this->tesseractBinary(), $path, 'stdout', '-l', (string) config('services.ocr.languages', 'deu+eng'),
        ]);
        $process->setTimeout(max(5, (int) config('services.ocr.page_timeout', 20)));
        $process->run();
        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    private function binaryWorks(string $binary, array $args): bool
    {
        static $cache = [];
        $key = $binary . ' ' . implode(' ', $args);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $process = new Process([$binary, ...$args]);
            $process->setTimeout(5);
            $process->run();
            return $cache[$key] = $process->isSuccessful();
        } catch (\Throwable) {
            return $cache[$key] = false;
        }
    }

    private function tesseractBinary(): string
    {
        return (string) config('services.ocr.tesseract_binary', 'tesseract');
    }

    private function pdftoppmBinary(): string
    {
        return (string) config('services.ocr.pdftoppm_binary', 'pdftoppm');
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
