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
        if (! config('services.ocr.enabled')) {
            return false;
        }
        return $this->binaryWorks($this->tesseractBinary(), ['--version']);
    }

    public function extract(string $binary, string $mime): string
    {
        if (! $this->isAvailable()) {
            return '';
        }

        $dir = sys_get_temp_dir().'/dienstly_ocr_'.bin2hex(random_bytes(8));
        if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
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

            // Seiten wie bei pdftotext mit Form-Feed ("\f") trennen: nur so
            // koennen die Vorlagen-Parser auch bei einem SCAN die Regel
            // "Auftrag = nur die Formularseite" anwenden (ReadsDocumentPages)
            // - sonst zaehlt der Rechtstext der Folgeseiten wieder mit und
            // verstummt den Parser (z.B. "Beratungsprotokollen" im
            // Datenschutzhinweis).
            $pages = [];
            foreach ($images as $image) {
                if (microtime(true) >= $deadline) {
                    Log::warning('OCR-Zeitbudget erschoepft - Teilergebnis nach '.count($pages).' Seiten.');
                    break;
                }
                $pages[] = trim($this->ocrImage($image));
            }

            if ($mime !== 'application/pdf' && count($pages) === 1 && microtime(true) < $deadline) {
                $pages[0] = $this->mrzImOriginalNachlesen($pages[0], $images[0], $dir, $mime);
            }

            return trim(implode("\f", $pages), " \t\n\r\0\x0B\f");
        } catch (\Throwable $e) {
            Log::warning('OCR-Extraktion fehlgeschlagen: '.$e->getMessage());
            return '';
        } finally {
            $this->cleanup($dir);
        }
    }

    /**
     * Ausweiskarten: MRZ im ORIGINAL nachlesen, wenn die Vergroesserung sie
     * verschluckt hat (Betreiber-Meldung 30.09.2026, am eingesandten Foto
     * einer Personalausweis-Rueckseite gemessen): in Originalgroesse liest
     * Tesseract die drei MRZ-Zeilen, nach dem Verdoppeln - egal ob 1,5x, 2x
     * oder 3x - stuft es den Block vor dem Hologramm als Bild ein und gibt
     * NICHTS davon aus. Ohne MRZ fehlt der Rueckseite aber genau das, woran
     * sie erkannt wird.
     *
     * Die Stichwoerter sind WORTANFAENGE: am zweiten Foto (30.09.2026) kam
     * die vergroesserte Fassung als "Anschrif/Addrenn", "Heighr/Tanlike"
     * an - mit ganzen Woertern loeste das Nachlesen nie aus.
     *
     * Bewusst eng: nur wenn das Bild vergroessert wurde, der Text nach einer
     * Karten-Rueckseite aussieht und KEINE "<<"-Zeile enthaelt. Dann kostet
     * es einen zweiten Tesseract-Lauf, und uebernommen werden NUR die
     * MRZ-artigen Zeilen - der uebrige Text steht nicht doppelt da (sonst
     * saehe das Foto nach zwei Karten aus).
     */
    private function mrzImOriginalNachlesen(string $text, string $gelesen, string $dir, string $mime): string
    {
        $original = $dir.'/input.'.(self::IMAGE_EXTENSIONS[$mime] ?? '');
        if ($gelesen === $original || ! is_file($original) || str_contains($text, '<<')
            || ! preg_match('/ANSCHRI|ADRES|ADDRE|AUGENFARB|EYE COLOU|GR(?:[OÖ]|OE)(?:SS|ß)E|HEIGHT|TAILLE|GEBURTSORT|BEH(?:[OÖ]|OE)RDE|AUTHOR|B(?:Ü|UE)RGERMEIST|ANMERKUNG|K[UÜ]NSTLER|AUFENTHALT|PERSONALAUSW/iu', $text)) {
            return $text;
        }

        $mrz = [];
        foreach (preg_split('/\R/u', $this->ocrImage($original)) ?: [] as $zeile) {
            $kompakt = (string) preg_replace('/\s+/u', '', $zeile);
            if (str_contains($kompakt, '<<') && strlen($kompakt) >= 24) {
                $mrz[] = trim($zeile);
            }
        }

        return $mrz === [] ? $text : $text."\n".implode("\n", $mrz);
    }

    /** @return list<string> Pfade der rasterisierten Seiten (leer, wenn poppler-utils fehlt). */
    private function rasterizePdf(string $binary, string $dir): array
    {
        if (! $this->binaryWorks($this->pdftoppmBinary(), ['-v'])) {
            return [];
        }

        $pdfPath = $dir.'/source.pdf';
        file_put_contents($pdfPath, $binary);

        // Niedrigere DPI (Default 150 statt 200) reicht fuer OCR voellig aus,
        // halbiert aber Pixelzahl und Rechenzeit; die Seitenzahl fuer OCR ist
        // bewusst begrenzt (Default 10), da fuer Typ-Erkennung + Basisfelder
        // die ersten Seiten genuegen - so bleibt der Lauf im Zeitbudget.
        $dpi = (string) max(72, (int) config('services.ocr.dpi', 150));
        $maxPages = (string) max(1, (int) config('services.ocr.max_pages', 10));
        $prefix = $dir.'/page';
        $process = new Process([
            $this->pdftoppmBinary(), '-png', '-r', $dpi, '-f', '1', '-l', $maxPages, $pdfPath, $prefix,
        ]);
        $process->setTimeout(60);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }

        $files = glob($prefix.'*.png') ?: [];
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
        $path = $dir.'/input.'.$ext;
        file_put_contents($path, $binary);

        return [$this->upscaleIfSmall($path, $dir)];
    }

    /**
     * KLEINE Bilder vor der OCR vergroessern (Lehre 16.08.2026, mit
     * Chromium+Tesseract nachgestellt): Bildschirmfotos kommen mit ~150 dpi
     * und feiner Schrift; Tesseract verwechselt darin regelmaessig aehnliche
     * Zeichen ("NOLADE21RDB" -> "NOLADE2IRDB", "Tariftyp" -> "Tarityp"). Eine
     * einfache Verdopplung (bikubisch) behebt genau diese Verwechslungen -
     * gratis, ohne KI. Groessere Aufnahmen (Handyfotos) bleiben unveraendert,
     * denn dort bringt Skalieren nichts und kostet nur Rechenzeit.
     *
     * Faellt irgendetwas aus (kein GD, kaputtes Bild, Speicher), wird das
     * Original weiterverwendet - die OCR-Stufe darf nie blockieren.
     */
    private function upscaleIfSmall(string $path, string $dir): string
    {
        $grenze = max(0, (int) config('services.ocr.upscale_below_px', 2600));
        if ($grenze === 0 || ! extension_loaded('gd')) {
            return $path;
        }

        try {
            $info = @getimagesize($path);
            if (! is_array($info) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
                return $path;
            }
            [$breite, $hoehe] = $info;
            if (max($breite, $hoehe) >= $grenze) {
                return $path;
            }

            $quelle = @imagecreatefromstring((string) file_get_contents($path));
            if ($quelle === false) {
                return $path;
            }
            $ziel = @imagecreatetruecolor($breite * 2, $hoehe * 2);
            if ($ziel === false) {
                imagedestroy($quelle);
                return $path;
            }
            $ok = imagecopyresampled($ziel, $quelle, 0, 0, 0, 0, $breite * 2, $hoehe * 2, $breite, $hoehe);
            $gross = $dir.'/input-2x.png';
            $ok = $ok && imagepng($ziel, $gross);
            imagedestroy($quelle);
            imagedestroy($ziel);

            return ($ok && is_file($gross)) ? $gross : $path;
        } catch (\Throwable $e) {
            Log::warning('OCR: Vergroessern des Bildes fehlgeschlagen: '.$e->getMessage());
            return $path;
        }
    }

    private function ocrImage(string $path): string
    {
        $process = new Process([
            $this->tesseractBinary(), $path, 'stdout', '-l', (string) config('services.ocr.languages', 'deu+eng'),
        ]);
        $process->setTimeout(max(5, (int) config('services.ocr.page_timeout', 20)));
        $process->run();
        if ($process->isSuccessful()) {
            return $process->getOutput();
        }
        // Frueher verstummte OCR hier komplett: eine fehlende Sprachdatei
        // (z.B. tesseract-ocr-ara bei OCR_LANGUAGES=deu+eng+ara) laesst
        // tesseract auf JEDER Seite mit Nicht-Null aussteigen, das Ergebnis
        // war '' und alles eskalierte still zum bezahlten KI-Vision. Jetzt
        // wird der Grund protokolliert (mit `ocr:check` gezielt pruefbar).
        Log::warning('OCR (tesseract) Seite fehlgeschlagen (Sprachen '
            .config('services.ocr.languages', 'deu+eng').'): '
            .trim($process->getErrorOutput()));
        return '';
    }

    /**
     * Binary-Probe mit Cache - aber nur ERFOLGE werden gecacht. Ein
     * transienter Fehler (z.B. 5s-Timeout unter Last) darf die Stufe nicht
     * fuer die gesamte Worker-Lebensdauer (queue:work laeuft stundenlang)
     * lahmlegen und alles still zum bezahlten KI-Vision umleiten - er wird
     * beim naechsten Dokument erneut geprueft.
     */
    private function binaryWorks(string $binary, array $args): bool
    {
        static $cache = [];
        $key = $binary.' '.implode(' ', $args);
        if (! empty($cache[$key])) {
            return true;
        }
        try {
            $process = new Process([$binary, ...$args]);
            $process->setTimeout(5);
            $process->run();
            if ($process->isSuccessful()) {
                return $cache[$key] = true;
            }
        } catch (\Throwable) {
            // Nicht cachen - beim naechsten Aufruf erneut versuchen.
        }
        return false;
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
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
