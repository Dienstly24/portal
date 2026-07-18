<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\RelevantPageSelector;
use Tests\TestCase;

/**
 * Reduktion mehrseitiger Formulare auf ihre relevanten Seiten
 * (RelevantPageSelector). Seiten sind per Form-Feed ("\f") getrennt.
 */
class RelevantPageSelectorTest extends TestCase
{
    private function pagesText(array $pages): string
    {
        return implode("\f", $pages);
    }

    public function test_reduces_known_profile_to_configured_pages(): void
    {
        config(['services.document_profiles' => [
            ['markers' => ['BERATUNGSPROTOKOLL'], 'pages' => [1, 2, 4]],
        ]]);

        $text = $this->pagesText([
            "Seite1 Vorlaeufiges Beratungsprotokoll",
            "Seite2 Kundendaten",
            "Seite3 Vergleichsergebnis IRRELEVANT",
            "Seite4 Fahrzeugdaten",
            "Seite5 Rechtstext IRRELEVANT",
        ]);

        $result = (new RelevantPageSelector())->reduce($text);

        $this->assertStringContainsString('Seite1', $result);
        $this->assertStringContainsString('Seite2', $result);
        $this->assertStringContainsString('Seite4', $result);
        $this->assertStringNotContainsString('Seite3', $result);
        $this->assertStringNotContainsString('Seite5', $result);
    }

    public function test_unknown_document_is_left_unchanged(): void
    {
        config(['services.document_profiles' => [
            ['markers' => ['BERATUNGSPROTOKOLL'], 'pages' => [1]],
        ]]);

        $text = $this->pagesText(['Seite1 Irgendein Vertrag', 'Seite2 mehr Text']);

        $this->assertSame($text, (new RelevantPageSelector())->reduce($text));
    }

    public function test_single_page_text_is_untouched(): void
    {
        $text = 'BERATUNGSPROTOKOLL aber nur eine Seite ohne Form-Feed';
        $this->assertSame($text, (new RelevantPageSelector())->reduce($text));
    }

    public function test_missing_pages_do_not_break_and_keep_full_text_if_nothing_kept(): void
    {
        config(['services.document_profiles' => [
            ['markers' => ['BERATUNGSPROTOKOLL'], 'pages' => [8, 9]],
        ]]);

        $text = $this->pagesText(['Seite1 BERATUNGSPROTOKOLL', 'Seite2']);

        // Gewuenschte Seiten existieren nicht -> voller Text statt leer.
        $this->assertSame($text, (new RelevantPageSelector())->reduce($text));
    }
}
