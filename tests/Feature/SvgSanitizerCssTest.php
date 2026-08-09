<?php

namespace Tests\Feature;

use App\Services\Media\SvgSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Audit SEC-4: Der SVG-Sanitizer entfernt Skripte/Handler bereits, liess aber
 * externe Ressourcen in <style>/@import und style=""-url(...) durch. Website-
 * Seiten duerfen NIE externe Ressourcen laden (DSGVO/Abmahnung). Interne CSS-
 * Klassen und #id-Referenzen (Logo-SVGs) muessen erhalten bleiben.
 */
class SvgSanitizerCssTest extends TestCase
{
    public function test_style_import_and_external_url_are_stripped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://evil.example/x.css); .a{fill:red}</style><rect class="a" width="10" height="10"/></svg>';
        $out = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($out);
        $this->assertStringNotContainsString('@import', $out);
        $this->assertStringNotContainsString('evil.example', $out);
        // Interne CSS-Klasse bleibt erhalten (Logo-Darstellung).
        $this->assertStringContainsString('fill:red', $out);
    }

    public function test_external_url_in_style_attribute_is_neutralized(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill:url(https://evil.example/p.png)" width="10" height="10"/></svg>';
        $out = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($out);
        $this->assertStringNotContainsString('evil.example', $out);
    }

    public function test_internal_url_reference_is_preserved(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"/></defs><rect fill="url(#g)" width="10" height="10"/></svg>';
        $out = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($out);
        $this->assertStringContainsString('url(#g)', $out);
    }

    public function test_script_is_still_removed(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>';
        $out = SvgSanitizer::sanitize($svg);

        $this->assertNotNull($out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }
}
