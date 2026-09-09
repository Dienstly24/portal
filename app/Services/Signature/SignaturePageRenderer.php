<?php

namespace App\Services\Signature;

use App\Models\SignatureRequest;
use App\Services\Pdf\PdfDocument;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Seitenbilder fuer die Anzeige - im Editor des Mitarbeiters wie auf dem
 * Telefon des Unterzeichners.
 *
 * WARUM BILDER UND KEIN PDF-BETRACHTER IM BROWSER: die Inhaltsrichtlinie
 * dieses Portals erlaubt seit SEC-4 nur Skripte von der eigenen Domain
 * (`script-src 'self'` + Nonce). Eine PDF-Bibliothek aus einem fremden CDN
 * ist damit ausgeschlossen, und 1,5 MB mitgeliefertes JavaScript waeren
 * ausgerechnet auf dem Telefon der schlechteste Ort dafuer. Gerendert wird
 * mit `pdftoppm` - poppler-utils laeuft auf dem Server ohnehin (OCR, seit
 * 18.07.2026), es kommt also keine neue Abhaengigkeit dazu.
 *
 * EHRLICH BLEIBEN: der Unterzeichner sieht ein BILD des Dokuments. Deshalb
 * steht auf der Unterschriftsseite immer auch der Weg zum echten PDF, und
 * das Protokoll haelt den SHA-256 der Datei fest, die gerendert wurde -
 * "was du gesehen hast" ist damit belegbar dieselbe Datei.
 *
 * Fehlt poppler, bleibt die Seite leer statt kaputt: der Editor zeigt eine
 * masshaltige leere Flaeche (die Groesse kennt der PDF-Leser auch ohne
 * poppler), die Unterschriftsseite den Hinweis samt PDF-Link. Ein
 * Fehlalarm "Dokument nicht verfuegbar" waere schlimmer als ein Hinweis.
 */
class SignaturePageRenderer
{
    /** Breite der Vorschau in Pixeln - lesbar auf dem Telefon, klein genug fuers Netz. */
    private const WIDTH = 1400;

    public function __construct(private readonly SignatureStorage $storage)
    {
    }

    /**
     * PNG einer Seite (1-basiert). Wird beim ersten Aufruf erzeugt und
     * danach aus dem Speicher geliefert - dieselbe Seite wird von jedem
     * Aufruf des Editors erneut gebraucht.
     */
    public function page(SignatureRequest $request, int $page): ?string
    {
        $path = $this->storage->pagePreviewPath($request, $page);
        $cached = $this->storage->read($path);
        if ($cached !== null) {
            return $cached;
        }

        $pdf = $this->storage->read($request->original_path);
        if ($pdf === null) {
            return null;
        }

        $png = $this->render($pdf, $page);
        if ($png === null) {
            return null;
        }
        $this->storage->disk()->put($path, $png);

        return $png;
    }

    public function available(): bool
    {
        try {
            $process = new Process([$this->binary(), '-v']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful() || str_contains($process->getErrorOutput(), 'pdftoppm');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Seitengeometrie fuer den Editor - ohne poppler und ohne Bild. Der
     * PDF-Leser kennt sie aus der MediaBox, und der Editor braucht sie
     * zwingend: ein Feld wird ANTEILIG gespeichert, das Seitenverhaeltnis
     * entscheidet also, wo es im fertigen Dokument landet.
     *
     * @return list<array{page: int, width: float, height: float}>
     */
    public function geometry(SignatureRequest $request): array
    {
        $pdf = $this->storage->read($request->original_path);
        if ($pdf === null) {
            return [];
        }
        try {
            $doc = PdfDocument::open($pdf);
        } catch (\Throwable $e) {
            Log::warning('Signatur: PDF-Geometrie nicht lesbar: '.$e->getMessage());

            return [];
        }

        $out = [];
        foreach ($doc->pages() as $page) {
            $out[] = [
                'page' => $page->index + 1,
                'width' => round($page->displayWidth(), 2),
                'height' => round($page->displayHeight(), 2),
            ];
        }

        return $out;
    }

    private function render(string $pdf, int $page): ?string
    {
        if (! $this->available()) {
            return null;
        }
        $dir = sys_get_temp_dir().'/signatur-'.bin2hex(random_bytes(8));
        if (! @mkdir($dir, 0700) && ! is_dir($dir)) {
            return null;
        }
        try {
            $source = $dir.'/quelle.pdf';
            file_put_contents($source, $pdf);
            $process = new Process([
                $this->binary(), '-png', '-scale-to-x', (string) self::WIDTH, '-scale-to-y', '-1',
                '-f', (string) $page, '-l', (string) $page, '-singlefile', $source, $dir.'/seite',
            ]);
            $process->setTimeout(60);
            $process->run();
            if (! $process->isSuccessful()) {
                Log::warning('Signatur: pdftoppm fehlgeschlagen: '.$process->getErrorOutput());

                return null;
            }
            $file = $dir.'/seite.png';

            return is_file($file) ? (file_get_contents($file) ?: null) : null;
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private function binary(): string
    {
        return (string) config('services.ocr.pdftoppm_binary', 'pdftoppm');
    }
}
