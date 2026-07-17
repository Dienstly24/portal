<?php
namespace App\Services\Pdf;

/**
 * Baut aus fotografierten/ausgewaehlten Seiten (JPEG/PNG/WebP/GIF) ein
 * einzelnes mehrseitiges PDF - ohne externe Abhaengigkeit (kein Imagick,
 * kein FPDF). JPEG wird unveraendert als DCTDecode-Stream eingebettet,
 * andere Formate werden vorher per GD nach JPEG konvertiert.
 *
 * Die Seiten werden auf A4-Breite (595.28 pt) skaliert; die Hoehe folgt
 * dem Seitenverhaeltnis. So bleiben die Scans druckbar und in jedem
 * Viewer lesbar.
 */
class ImagesToPdfService
{
    private const A4_WIDTH_PT = 595.28;
    private const JPEG_QUALITY = 82;

    /**
     * @param list<string> $imageBinaries Rohdaten der Seiten in Anzeige-Reihenfolge
     * @return string fertige PDF-Datei (Bytes)
     * @throws \RuntimeException wenn eine Seite nicht lesbar/konvertierbar ist
     */
    public function build(array $imageBinaries): string
    {
        if ($imageBinaries === []) {
            throw new \RuntimeException('Keine Seiten uebergeben.');
        }

        $pages = [];
        foreach (array_values($imageBinaries) as $i => $binary) {
            $pages[] = $this->preparePage($binary, $i + 1);
        }

        return $this->assemble($pages);
    }

    /** Dekompressionsbomben-Schutz: mehr Pixel dekodiert GD nicht. */
    private const MAX_MEGAPIXELS = 50_000_000;

    /** @return array{jpeg: string, width: int, height: int, channels: int} */
    private function preparePage(string $binary, int $pageNo): array
    {
        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new \RuntimeException("Seite $pageNo ist kein lesbares Bild.");
        }
        if ((int) $info[0] * (int) $info[1] > self::MAX_MEGAPIXELS) {
            throw new \RuntimeException("Seite $pageNo hat zu viele Pixel (max. 50 Megapixel).");
        }

        $mime = $info['mime'] ?? '';
        if ($mime !== 'image/jpeg') {
            $binary = $this->convertToJpeg($binary, $pageNo);
            $info = @getimagesizefromstring($binary);
            if ($info === false) {
                throw new \RuntimeException("Seite $pageNo konnte nicht konvertiert werden.");
            }
        }

        return [
            'jpeg' => $binary,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            // 1 = Graustufen, 3 = RGB, 4 = Adobe-CMYK (invertiert)
            'channels' => (int) ($info['channels'] ?? 3),
        ];
    }

    private function convertToJpeg(string $binary, int $pageNo): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('GD-Erweiterung fehlt - Bildkonvertierung nicht moeglich.');
        }
        $img = @imagecreatefromstring($binary);
        if ($img === false) {
            throw new \RuntimeException("Seite $pageNo hat ein nicht unterstuetztes Bildformat.");
        }
        // Transparenz auf weiss legen (PNG/WebP), sonst wird sie schwarz.
        $w = imagesx($img);
        $h = imagesy($img);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

        ob_start();
        imagejpeg($flat, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();
        imagedestroy($flat);

        if ($jpeg === '') {
            throw new \RuntimeException("Seite $pageNo konnte nicht als JPEG gespeichert werden.");
        }
        return $jpeg;
    }

    /** @param list<array{jpeg: string, width: int, height: int, channels: int}> $pages */
    private function assemble(array $pages): string
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        // Objektnummern: 1 = Catalog, 2 = Pages, danach je Seite
        // (Page, Bild-XObject, Content-Stream) in fester Reihenfolge.
        $kids = [];
        foreach (array_keys($pages) as $i) {
            $kids[] = (3 + $i * 3) . ' 0 R';
        }

        $writeObject = function (int $num, string $body) use (&$pdf, &$offsets): void {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        };

        $writeObject(1, '<< /Type /Catalog /Pages 2 0 R >>');
        $writeObject(2, '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>');

        foreach (array_values($pages) as $i => $page) {
            $pageObj = 3 + $i * 3;
            $imageObj = $pageObj + 1;
            $contentObj = $pageObj + 2;

            $pageW = self::A4_WIDTH_PT;
            $pageH = $page['width'] > 0
                ? round($page['height'] * $pageW / $page['width'], 2)
                : $pageW;
            // PDF erlaubt maximal 14400 pt Kantenlaenge - extreme
            // Seitenverhaeltnisse werden proportional eingedampft.
            if ($pageH > 14400) {
                $pageW = round($pageW * 14400 / $pageH, 2);
                $pageH = 14400.0;
            }

            $writeObject($pageObj, sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] '
                . '/Resources << /XObject << /Im%d %d 0 R >> /ProcSet [/PDF /ImageC] >> /Contents %d 0 R >>',
                $pageW, $pageH, $i, $imageObj, $contentObj
            ));

            $colorSpace = match ($page['channels']) {
                4 => '/ColorSpace /DeviceCMYK /Decode [1 0 1 0 1 0 1 0]',
                1 => '/ColorSpace /DeviceGray',
                default => '/ColorSpace /DeviceRGB',
            };
            $writeObject($imageObj, sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d %s "
                . "/BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $page['width'], $page['height'], $colorSpace, strlen($page['jpeg']), $page['jpeg']
            ));

            $content = sprintf("q %.2F 0 0 %.2F 0 0 cm /Im%d Do Q", $pageW, $pageH, $i);
            $writeObject($contentObj, sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content), $content
            ));
        }

        $objectCount = 2 + count($pages) * 3;
        $xrefOffset = strlen($pdf);
        $pdf .= 'xref' . "\n" . '0 ' . ($objectCount + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($num = 1; $num <= $objectCount; $num++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$num]);
        }
        $pdf .= "trailer\n<< /Size " . ($objectCount + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }
}
