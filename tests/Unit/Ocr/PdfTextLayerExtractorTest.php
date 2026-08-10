<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\PdfTextLayerExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Mojibake-Erkennung der PDF-Textebene. Kernpunkt (Lehre 10.08.2026, echtes
 * 19-seitiges CHECK24-Protokoll): Form-Feeds (\f) sind die SEITENTRENNER von
 * pdftotext und duerfen ein sauberes mehrseitiges Dokument NIE als "kaputt
 * kodiert" erscheinen lassen - sonst verliert es seine gesamte Textebene und
 * eskaliert unnoetig zur teuren Bild-KI.
 */
class PdfTextLayerExtractorTest extends TestCase
{
    private function cleanGermanPage(int $n): string
    {
        return "Seite $n\n"
            . "Der Kunde und die Versicherung haben einen Vertrag über den Preis "
            . "von 100 Euro pro Monat geschlossen. Datum und Nummer stehen im "
            . "Dokument. Die Straße und der Name des Kunden sind angegeben.\n"
            . str_repeat("Weiterer deutscher Fließtext für die Länge dieser Seite. ", 10);
    }

    public function test_multipage_clean_text_with_form_feeds_is_not_garbled(): void
    {
        // 19 Seiten -> 18 Form-Feeds (deutlich ueber der alten 5er-Schwelle).
        $pages = [];
        for ($i = 1; $i <= 19; $i++) {
            $pages[] = $this->cleanGermanPage($i);
        }
        $text = implode("\f", $pages);

        $this->assertFalse(
            (new PdfTextLayerExtractor())->isLikelyGarbled($text),
            'Seitentrenner (\f) duerfen ein sauberes mehrseitiges PDF nicht als Mojibake werten.'
        );
    }

    public function test_real_control_characters_still_count_as_garbled(): void
    {
        // Kaputtes Font-Encoding: C1-/Steuerzeichen im Text (kein \f).
        $text = $this->cleanGermanPage(1) . "\x01\x02\x03\x1A\x1B\u{0085}\u{0090}";

        $this->assertTrue((new PdfTextLayerExtractor())->isLikelyGarbled($text));
    }

    public function test_shifted_encoding_without_german_words_is_garbled(): void
    {
        // Caesar-artig verschobener Text (lang, aber ohne deutsche Woerter).
        $text = str_repeat('Xqavwxq zrvw kxqw abcqvw hxqwv mqvwt pxqzv. ', 30);
        $this->assertGreaterThan(400, mb_strlen($text));

        $this->assertTrue((new PdfTextLayerExtractor())->isLikelyGarbled($text));
    }
}
