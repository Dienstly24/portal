<?php

namespace Tests\Unit;

use App\Services\Media\SvgSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * SVG-Upload: externe Verweise in CSS (System-Audit 28.09.2026, KI-037).
 * Skripte entfernte der Sanitizer schon; CSS konnte aber beim Betrachten
 * eine fremde Adresse nachladen (Zaehlpixel).
 */
class SvgSanitizerExterneVerweiseTest extends TestCase
{
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg">'
        .'<style>@import url(https://x.test/a.css); .a{fill:url(#g)} .b{background:url("https://x.test/p.png")}</style>'
        .'<rect class="a" fill="url(\'#g\')" style="background:url(https://x.test/q)"/></svg>';

    public function test_externe_verweise_verschwinden(): void
    {
        $aus = (string) SvgSanitizer::sanitize(self::SVG);

        $this->assertStringNotContainsString('x.test', $aus);
        $this->assertStringNotContainsString('@import', $aus);
    }

    public function test_interne_verweise_und_farben_bleiben(): void
    {
        $aus = (string) SvgSanitizer::sanitize(self::SVG);

        $this->assertStringContainsString('.a{fill:url(#g)}', $aus);
        $this->assertStringContainsString('fill="url(\'#g\')"', $aus);
    }
}
