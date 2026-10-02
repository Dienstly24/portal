<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Waechter fuer das Issue-Register und das Changelog.
 *
 * WARUM: beide Dateien bekommen jeden Eintrag OBEN eingefuegt - jeder Zweig
 * schreibt also auf dieselbe Zeile. Damit daraus kein Dauer-Konflikt wird,
 * stehen sie in `.gitattributes` auf `merge=union`: Git behaelt beide Seiten,
 * statt zu scheitern.
 *
 * Genau deshalb braucht es diesen Test. "union" kann NICHT scheitern - es
 * wuerde auch eine doppelt vergebene KI-Nummer still durchwinken, und eine
 * Kennung, die zweimal existiert, macht das Register wertlos ("Kennungen
 * werden nie wiederverwendet"). Was mechanisch nicht mehr auffaellt, muss
 * gemessen werden.
 */
class WissensbasisRegisterTest extends TestCase
{
    private function register(): string
    {
        return (string) file_get_contents(base_path('docs/project-knowledge/KNOWN_ISSUES.md'));
    }

    public function test_keine_kennung_wird_zweimal_vergeben(): void
    {
        preg_match_all('/^### (KI-\d+)/m', $this->register(), $m);
        $doppelt = array_keys(array_filter(array_count_values($m[1]), fn ($n) => $n > 1));

        $this->assertSame([], $doppelt,
            'Diese Kennungen stehen mehrfach im Register: '.implode(', ', $doppelt)
            .'. Zwei Zweige haben dieselbe Nummer vergeben - eine davon muss auf die '
            .'naechste freie Nummer umgeschrieben werden.');
    }

    public function test_uebersicht_und_detail_beschreiben_dieselben_befunde(): void
    {
        $text = $this->register();
        preg_match_all('/^### (KI-\d+)/m', $text, $d);
        preg_match_all('/^\| (KI-\d+) \|/m', $text, $t);

        $nurDetail = array_values(array_diff($d[1], $t[1]));
        $nurTabelle = array_values(array_diff($t[1], $d[1]));

        $this->assertSame([], $nurDetail, 'Ohne Zeile in der Uebersicht: '.implode(', ', $nurDetail));
        $this->assertSame([], $nurTabelle, 'Ohne Detail-Abschnitt: '.implode(', ', $nurTabelle));
        $this->assertSame([], array_keys(array_filter(array_count_values($t[1]), fn ($n) => $n > 1)),
            'Eine Kennung steht mehrfach in der Uebersichtstabelle.');
    }

    public function test_jeder_befund_hat_genau_eine_kopfzeile(): void
    {
        // Haette "union" zwei Fassungen DESSELBEN Eintrags zusammengelegt
        // (z.B. zwei Zweige, die den Status aendern), stuende die Kopfzeile
        // doppelt da - mit zwei verschiedenen Staenden.
        $teile = preg_split('/^### (KI-\d+)/m', $this->register(), -1, PREG_SPLIT_DELIM_CAPTURE);
        $kaputt = [];
        for ($i = 1; $i < count($teile); $i += 2) {
            if (preg_match_all('/^- \*\*Category\*\*/m', $teile[$i + 1]) !== 1) {
                $kaputt[] = $teile[$i];
            }
        }

        $this->assertSame([], $kaputt,
            'Diese Befunde haben nicht genau eine "Category"-Zeile: '.implode(', ', $kaputt));
    }

    public function test_keine_konfliktmarker_in_der_wissensbasis(): void
    {
        $dateien = array_merge(
            glob(base_path('docs/project-knowledge/*.md')) ?: [],
            [base_path('CLAUDE.md')]
        );

        foreach ($dateien as $datei) {
            $inhalt = (string) file_get_contents($datei);
            foreach (['<<<<<<<', '>>>>>>>'] as $marker) {
                $this->assertStringNotContainsString(
                    "\n".$marker.' ',
                    "\n".$inhalt,
                    basename($datei).' enthaelt einen offenen Konfliktmarker.'
                );
            }
        }
    }

    public function test_die_protokolldateien_stehen_auf_union(): void
    {
        $attr = (string) file_get_contents(base_path('.gitattributes'));

        foreach (['docs/project-knowledge/CHANGELOG.md', 'docs/project-knowledge/KNOWN_ISSUES.md'] as $datei) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($datei, '/').'\s+merge=union$/m',
                $attr,
                $datei.' muss auf merge=union stehen - sonst kollidiert jeder parallele Zweig dort.'
            );
        }
        // CLAUDE.md wird MITTEN im Text geaendert: dort waere "union" gefaehrlich,
        // weil es zwei widersprechende Fassungen stillschweigend aneinanderhaengt.
        $this->assertDoesNotMatchRegularExpression('/^CLAUDE\.md\s+merge=union$/m', $attr);
    }
}
