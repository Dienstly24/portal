<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Knoepfe mit leichter Tiefe in der ganzen Anwendung (Betreiber-Wunsch
 * 19.11.2026). Die Optik steht EINMAL in components.css - und nur
 * zusammen mit `.btn`: die Anmeldeseiten benutzen `btn-ghost` ohne den
 * Baustein auf dunklem Glas, ein weisser Verlauf machte dort den Text
 * unsichtbar.
 */
class Knoepfe3dTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/components.css'));
    }

    public function test_der_baustein_btn_hat_tiefe_und_reagiert_auf_druecken(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\.btn\s*\{[^}]*box-shadow:/s', $css);
        $this->assertMatchesRegularExpression('/\.btn:hover\s*\{[^}]*translateY\(-1px\)/', $css);
        $this->assertMatchesRegularExpression('/\.btn:active\s*\{[^}]*translateY\(1px\)/', $css);
    }

    public function test_verlauf_nur_zusammen_mit_btn(): void
    {
        $css = $this->css();

        foreach (['primary', 'emerald', 'ghost', 'danger'] as $art) {
            $this->assertMatchesRegularExpression('/\.btn\.btn-'.$art.'\s*\{[^}]*linear-gradient/s', $css, "btn-$art ohne 3D-Fassung");
            $this->assertDoesNotMatchRegularExpression('/(^|\})\s*\.btn-'.$art.'\s*\{[^}]*linear-gradient/s', $css,
                "btn-$art traegt den Verlauf auch ohne .btn - die dunklen Anmeldeseiten wuerden weiss");
        }
    }

    public function test_das_kundenportal_ueberschreibt_den_hover_nicht_mehr_flach(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/portal.blade.php'));

        $this->assertStringNotContainsString('.btn-primary:hover{background:var(--graphite-deep);}', $layout);
    }
}
