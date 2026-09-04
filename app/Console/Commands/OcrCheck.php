<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Diagnose der kostenlosen Analyse-Basisebene (Textebene + OCR) des Smart
 * Document Upload. Prueft die Systembinaries UND - der eigentliche Zweck -
 * ob die konfigurierten OCR-Sprachen (OCR_LANGUAGES, z.B. deu+eng+ara)
 * wirklich installiert sind. Fehlt eine Sprachdatei, steigt tesseract sonst
 * auf JEDER Seite still mit Nicht-Null aus und alles eskaliert unbemerkt zum
 * kostenpflichtigen KI-Vision - genau das macht dieser Befehl sichtbar.
 *
 * Bewusst unabhaengig von OCR_ENABLED: der Betreiber kann VOR dem
 * Freischalten pruefen, ob der Server bereit ist.
 */
class OcrCheck extends Command
{
    protected $signature = 'ocr:check';
    protected $description = 'Textebene/OCR-Binaries und die konfigurierten OCR-Sprachen pruefen';

    public function handle(): int
    {
        $this->line('Konfiguration:');
        $this->line('  OCR_ENABLED     = '.var_export((bool) config('services.ocr.enabled'), true));
        $this->line('  OCR_TEXT_LAYER  = '.var_export((bool) config('services.ocr.text_layer'), true));
        $languages = (string) config('services.ocr.languages', 'deu+eng');
        $this->line('  OCR_LANGUAGES   = '.$languages);
        $this->newLine();

        $ok = true;

        // 1) Binaries.
        $pdftotext = (string) config('services.ocr.pdftotext_binary', 'pdftotext');
        $tesseract = (string) config('services.ocr.tesseract_binary', 'tesseract');
        $pdftoppm = (string) config('services.ocr.pdftoppm_binary', 'pdftoppm');

        $textLayerOk = $this->probe($pdftotext, ['-v'], 'pdftotext (PDF-Textebene)');
        $tessOk = $this->probe($tesseract, ['--version'], 'tesseract (OCR)');
        $this->probe($pdftoppm, ['-v'], 'pdftoppm (PDF -> Bild fuer OCR)');

        // Textebene reicht allein fuer digitale PDFs; ohne sie und ohne
        // tesseract laeuft gar keine kostenlose Stufe.
        if (! $textLayerOk && ! $tessOk) {
            $ok = false;
        }

        // 2) Sprachen gegen `tesseract --list-langs` pruefen (nur wenn tesseract da).
        if ($tessOk) {
            $installed = $this->installedLanguages($tesseract);
            $this->newLine();
            $this->line('Installierte OCR-Sprachen: '.($installed === [] ? '(keine gefunden)' : implode(', ', $installed)));
            foreach (array_filter(explode('+', $languages)) as $lang) {
                $lang = trim($lang);
                if ($lang === '') {
                    continue;
                }
                if (in_array($lang, $installed, true)) {
                    $this->info('  ✓ Sprache "'.$lang.'" installiert');
                } else {
                    $this->error('  ✗ Sprache "'.$lang.'" FEHLT - bitte tesseract-ocr-'.$lang.' installieren');
                    $ok = false;
                }
            }
        }

        $this->newLine();
        if ($ok) {
            $this->info('OCR/Textebene bereit.');
            return self::SUCCESS;
        }
        $this->error('OCR/Textebene NICHT vollstaendig einsatzbereit - siehe Meldungen oben.');
        return self::FAILURE;
    }

    /** Ein Binary aufrufen und das Ergebnis melden. */
    private function probe(string $binary, array $args, string $label): bool
    {
        try {
            $process = new Process([$binary, ...$args]);
            $process->setTimeout(10);
            $process->run();
            $out = trim($process->getOutput().' '.$process->getErrorOutput());
            $lower = mb_strtolower($out);
            // Fehlendes Binary sicher erkennen: Exitcode 127 bzw. eine
            // "not found"/"no such file"-Meldung - sonst wuerde der
            // Fehlertext selbst faelschlich als "vorhanden" zaehlen.
            $notFound = $process->getExitCode() === 127
                || str_contains($lower, 'not found')
                || str_contains($lower, 'no such file');
            // Vorhanden: sauberer Lauf ODER eine erkennbare Versionsangabe
            // (manche Tools wie pdftotext -v schreiben sie nach STDERR und
            // liefern dabei einen Nicht-Null-Exitcode).
            $found = ! $notFound && ($process->isSuccessful() || preg_match('/\d+\.\d+/', $out) === 1);
            if ($found) {
                $this->info('  ✓ '.$label.' vorhanden');
                return true;
            }
            $this->error('  ✗ '.$label.' NICHT gefunden ('.$binary.')');
            return false;
        } catch (\Throwable $e) {
            $this->error('  ✗ '.$label.' NICHT gefunden ('.$binary.'): '.$e->getMessage());
            return false;
        }
    }

    /** @return list<string> Sprachcodes aus `tesseract --list-langs`. */
    private function installedLanguages(string $tesseract): array
    {
        try {
            $process = new Process([$tesseract, '--list-langs']);
            $process->setTimeout(10);
            $process->run();
            $lines = preg_split('/\R/', trim($process->getOutput()."\n".$process->getErrorOutput())) ?: [];
            $langs = [];
            foreach ($lines as $line) {
                $line = trim($line);
                // Kopfzeile ("List of available languages ...") ueberspringen.
                if ($line === '' || str_contains(mb_strtolower($line), 'list of available')) {
                    continue;
                }
                if (preg_match('/^[a-z]{2,}(?:_[a-z]+)?$/i', $line)) {
                    $langs[] = $line;
                }
            }
            return array_values(array_unique($langs));
        } catch (\Throwable) {
            return [];
        }
    }
}
