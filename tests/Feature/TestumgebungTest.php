<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Die Testumgebung selbst (Audit-Nachlauf 16.09.2026).
 *
 * ZWEI BEFUNDE haben diese Datei ausgeloest, beide derselbe Fehlertyp:
 * ein Test, der sich unter einer Bedingung SELBST UEBERSPRINGT, steht
 * gruen in der Liste und prueft nichts.
 *
 *  - `ClientIpIntegrityTest` las die IP im Feld `meta` statt in der
 *    SPALTE `ip`, fand dort nie etwas und uebersprang sich - der
 *    gesamte Schutz gegen gefaelschte Client-IPs (SEC-2) war damit
 *    ungeprueft.
 *  - `ContentSecurityPolicyTest` haengte an `Statuscode === 200`, waehrend
 *    die Systemzustand-Ansicht regelgerecht 503 liefert, sobald die Ampel
 *    rot ist (in der Testumgebung der Normalfall).
 *
 * Beide liefen MONATE als "bestanden". Deshalb hier die Regel: ein
 * SICHERHEITSTEST darf sich nicht selbst ueberspringen. Fehlt ihm etwas,
 * soll er scheitern - ein roter Test wird repariert, ein uebersprungener
 * nicht einmal bemerkt.
 */
class TestumgebungTest extends TestCase
{
    /**
     * Sicherheitstests duerfen sich nicht selbst ueberspringen.
     *
     * Bewusst NUR fuer `tests/Feature/Security/`: anderswo ist ein Skip
     * berechtigt (ein Systemwerkzeug wie tesseract muss nicht auf jedem
     * Rechner liegen). Bei einer Sicherheitszusicherung ist er es nie.
     */
    public function test_kein_sicherheitstest_ueberspringt_sich_selbst(): void
    {
        $treffer = [];

        foreach ($this->dateien(base_path('tests/Feature/Security')) as $datei) {
            $inhalt = (string) file_get_contents($datei);
            foreach (explode("\n", $inhalt) as $nr => $zeile) {
                // Eine Erlaeuterung darf das Wort nennen, ein Aufruf nicht.
                if (preg_match('/^\s*(\*|\/\/)/', $zeile)) {
                    continue;
                }
                if (str_contains($zeile, 'markTestSkipped')) {
                    $treffer[] = basename($datei).':'.($nr + 1);
                }
            }
        }

        $this->assertSame([], $treffer,
            "Sicherheitstests mit markTestSkipped:\n".implode("\n", $treffer)
            ."\nEin uebersprungener Sicherheitstest prueft nichts und faellt niemandem auf.");
    }

    /**
     * Die Voraussetzungen, ohne die Teile der Suite stumm bleiben.
     *
     * Der Test SCHEITERT NICHT daran - er BENENNT sie. Ein Entwickler
     * ohne tesseract soll arbeiten koennen; er soll nur wissen, dass
     * fuenf OCR-Faelle dann nicht laufen. Die Liste ist damit die eine
     * Quelle fuer docs/TESTUMGEBUNG.md und scripts/testumgebung-pruefen.sh.
     */
    public function test_die_voraussetzungen_sind_benannt(): void
    {
        $erwartet = [
            'PHP-Erweiterung gd' => extension_loaded('gd'),
            'PHP-Erweiterung zip' => class_exists(\ZipArchive::class),
            'PHP-Erweiterung sqlite3' => extension_loaded('pdo_sqlite'),
            'PHP-Erweiterung mbstring' => extension_loaded('mbstring'),
            'PHP-Erweiterung intl' => extension_loaded('intl'),
            'PHP-Erweiterung exif' => extension_loaded('exif'),
        ];

        $fehlend = array_keys(array_filter($erwartet, fn ($da) => ! $da));

        $this->assertSame([], $fehlend,
            "Diese PHP-Erweiterungen fehlen und sind PFLICHT (siehe docs/TESTUMGEBUNG.md):\n"
            .implode("\n", $fehlend));
    }

    /** @return list<string> */
    private function dateien(string $verzeichnis): array
    {
        if (! is_dir($verzeichnis)) {
            return [];
        }

        $dateien = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($verzeichnis)) as $datei) {
            if ($datei->isFile() && str_ends_with($datei->getFilename(), '.php')) {
                $dateien[] = $datei->getPathname();
            }
        }
        sort($dateien);

        return $dateien;
    }
}
