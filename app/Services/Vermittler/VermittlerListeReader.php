<?php
namespace App\Services\Vermittler;

use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;

/**
 * Macht aus einer hochgeladenen Vorgangsliste auswertbare Zeilen - egal in
 * welcher Form sie kommt.
 *
 * ZWEI WEGE, und sie sind bewusst nicht gleichwertig:
 *
 *  1. CSV/Text: die Spalten stehen fest, jede Zeile traegt ihre Referenz-Nr.
 *     selbst. Das ist der GENAUE Weg - hier kann bei der Paarung nichts
 *     verrutschen. Wenn das Portal einen Export anbietet, ist er immer
 *     vorzuziehen.
 *  2. PDF/Bild: erst Textebene (gratis, exakt), sonst Texterkennung. Danach
 *     muss der Zeilen-Parser die Tabelle rekonstruieren - und er sagt
 *     ausdruecklich, wenn ihm das nicht sicher gelingt.
 */
class VermittlerListeReader
{
    public function __construct(
        private TextExtractorInterface $ocr,
        private PdfTextLayerExtractor $pdfText,
        private VermittlerCsvReader $csv,
        private VermittlerVorgangslisteParser $parser,
    ) {}

    /**
     * @return array{rows: array<int,array<string,?string>>, ambiguous: bool, notes: array<int,string>, source: string}
     * @throws \RuntimeException wenn sich die Datei nicht lesen laesst.
     */
    public function rows(string $path, string $mime, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt'], true) || str_contains($mime, 'csv')) {
            return $this->fromCsv($path);
        }

        return $this->fromText($this->text($path, $mime, $extension)) + ['source' => 'ocr'];
    }

    /** Ist die Texterkennung fuer Bilder ueberhaupt verfuegbar? */
    public function ocrAvailable(): bool
    {
        return $this->ocr->isAvailable();
    }

    /**
     * CSV: die Spalten sind beschriftet, also wird nichts rekonstruiert.
     * Deshalb kann diese Quelle auch nie "mehrdeutig" sein.
     */
    private function fromCsv(string $path): array
    {
        $parsed = $this->csv->read($path);

        $rows = [];
        foreach ($parsed['rows'] as $row) {
            $id = VermittlerReference::display($row['vermittler_id'] ?? null);
            if ($id === null) {
                continue;
            }
            $rows[] = [
                'vermittler_id' => $id,
                'reference_number' => VermittlerReference::display($row['reference_number'] ?? null),
                'produkt' => mb_substr(trim((string) ($row['produkt'] ?? '')), 0, 190) ?: null,
                'datum' => VermittlerCsvReader::date($row['datum'] ?? null)?->toDateString(),
                'status' => trim((string) ($row['status'] ?? '')) ?: null,
            ];
        }

        return ['rows' => $rows, 'ambiguous' => false, 'notes' => [], 'source' => 'csv'];
    }

    /** @return array{rows: array<int,array<string,?string>>, ambiguous: bool, notes: array<int,string>} */
    private function fromText(string $text): array
    {
        return $this->parser->parse($text);
    }

    private function text(string $path, string $mime, string $extension): string
    {
        $binary = (string) file_get_contents($path);

        if ($extension === 'pdf' || str_contains($mime, 'pdf')) {
            $text = $this->pdfText->isAvailable() ? $this->pdfText->extract($binary) : '';
            if (trim($text) !== '') {
                return $text;
            }
        }

        if (!$this->ocr->isAvailable()) {
            throw new \RuntimeException(
                'Für Bilder und gescannte PDF wird die Texterkennung (OCR) benötigt, sie ist auf diesem Server aber nicht aktiv. '
                . 'Bitte die Liste als CSV exportieren – das ist ohnehin der genauere Weg.'
            );
        }

        $text = $this->ocr->extract($binary, $mime ?: 'image/png');
        if (trim($text) === '') {
            throw new \RuntimeException('Aus der Datei liess sich kein Text lesen. Bitte einen schärferen Screenshot oder einen CSV-Export verwenden.');
        }

        return $text;
    }
}
