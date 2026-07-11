<?php
namespace App\Services\Mailbox;

use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/**
 * Text aus PDF-Anhängen ziehen (Phase 2: PDF-Anhang-Analyse).
 * Defensives Scheitern: beschädigte/verschlüsselte/gescannte PDFs
 * liefern null bzw. leeren Text - nie eine Exception in die Pipeline
 * (Prüfbericht-Prinzip "defensiv statt still falsch").
 * Grenze: reine Bild-Scans ohne Textebene brauchen OCR - bewusst nicht
 * Teil dieser Stufe.
 */
class PdfTextExtractor
{
    private const MAX_CHARS = 20000;

    public function extractFromStorage(string $disk, string $path): ?string
    {
        if (!Storage::disk($disk)->exists($path)) {
            return null;
        }

        try {
            $document = (new Parser())->parseFile(Storage::disk($disk)->path($path));
            $text = trim($this->textWithLines($document) ?? $document->getText());
            return $text === '' ? null : mb_substr($text, 0, self::MAX_CHARS);
        } catch (\Throwable $e) {
            \Log::info('PDF-Textextraktion fehlgeschlagen: ' . $path . ' (' . $e->getMessage() . ')');
            return null;
        }
    }

    /**
     * Zeilen anhand der Textpositionen rekonstruieren: getText() klebt
     * Textblöcke teils ohne Umbruch zusammen - die Label-Parser
     * (Fonds Finanz, Provisionen) sind aber zeilenbasiert. Chunks mit
     * gleicher Y-Position bilden eine Zeile (links-nach-rechts),
     * absteigendes Y = neue Zeile.
     */
    private function textWithLines(\Smalot\PdfParser\Document $document): ?string
    {
        $lines = [];

        foreach ($document->getPages() as $pageIndex => $page) {
            foreach ($page->getDataTm() as $item) {
                [$tm, $text] = [$item[0] ?? null, $item[1] ?? ''];
                if (!is_array($tm) || $text === '') {
                    continue;
                }
                $x = (float) ($tm[4] ?? 0);
                $y = (int) round((float) ($tm[5] ?? 0));
                $lines[$pageIndex][$y][] = ['x' => $x, 'text' => $text];
            }
        }

        if (empty($lines)) {
            return null;
        }

        $output = [];
        ksort($lines);
        foreach ($lines as $pageLines) {
            krsort($pageLines); // PDF-Y wächst nach oben -> oberste Zeile zuerst
            foreach ($pageLines as $chunks) {
                usort($chunks, fn ($a, $b) => $a['x'] <=> $b['x']);
                $output[] = implode(' ', array_column($chunks, 'text'));
            }
        }

        return implode("\n", $output);
    }
}
