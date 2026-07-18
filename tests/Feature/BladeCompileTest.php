<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Sicherheitsnetz gegen Blade-Kompilierfallen: `view:cache` prueft NUR,
 * ob Blade zu PHP uebersetzt werden kann - nicht, ob das erzeugte PHP
 * gueltig ist. Kaputte Konstrukte (z. B. @json mit Options-Parameter,
 * dessen Argumente an Kommas zerlegt werden) fallen dadurch erst zur
 * Laufzeit in Produktion als 500 auf. Dieser Test lintet das kompilierte
 * PHP ALLER Views und faengt solche Fehler bereits in der CI ab.
 */
class BladeCompileTest extends TestCase
{
    public function test_alle_blade_templates_erzeugen_gueltiges_php(): void
    {
        $compiler = app('blade.compiler');
        $errors = [];
        $checked = 0;

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $compiled = $compiler->compileString(File::get($file->getPathname()));
            try {
                // TOKEN_PARSE wirft ParseError bei syntaktisch ungueltigem PHP
                token_get_all('<?php ?>' . $compiled, TOKEN_PARSE);
                $checked++;
            } catch (\ParseError $e) {
                $errors[] = $file->getRelativePathname() . ': ' . $e->getMessage();
            }
        }

        $this->assertSame([], $errors, "Blade-Views mit ungueltigem PHP:\n" . implode("\n", $errors));
        $this->assertGreaterThan(50, $checked, 'Es wurden verdaechtig wenige Views geprueft.');
    }
}
