<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Waechter fuer KI-007 (Wissensbasis, 23.09.2026): jeder Text, den ein
 * KUNDE oder Website-Besucher sieht und der ueber __() laeuft, braucht
 * einen Eintrag in lang/ar.json.
 *
 * Fehlt er, gibt __() stillschweigend den deutschen Schluessel zurueck -
 * kein Fehler, keine Logzeile, nur ein deutscher Satz mitten in der
 * arabischen Oberflaeche. Gefunden wurden so 21 Texte, darunter die
 * KOMPLETTE Seite "Bitte bestaetigen Sie Ihre E-Mail-Adresse" nach der
 * Registrierung. Ohne diesen Test waere die naechste neue Zeile wieder
 * unuebersetzt.
 *
 * Geprueft werden die Bereiche, die Kunden und Besucher sehen. Die
 * Beraterwelt ist bewusst ausgenommen - sie ist deutschsprachig.
 */
class ArabischeUebersetzungVollstaendigTest extends TestCase
{
    private const BEREICHE = [
        'resources/views/portal',
        'resources/views/auth',
        'resources/views/website',
        'resources/views/services',
        'resources/views/support',
        'resources/views/partials',
        'resources/views/layouts/portal.blade.php',
    ];

    public function test_jeder_kundentext_hat_eine_arabische_uebersetzung(): void
    {
        $ar = json_decode((string) file_get_contents(base_path('lang/ar.json')), true);
        $this->assertIsArray($ar, 'lang/ar.json ist kein gueltiges JSON.');

        $fehlend = [];
        foreach ($this->dateien() as $datei) {
            $inhalt = (string) file_get_contents($datei);
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $inhalt, $treffer);
            foreach ($treffer[1] as $schluessel) {
                $schluessel = stripslashes($schluessel);
                // Gruppen-Schluessel wie "signing.titel" stehen in lang/ar/*.php
                if (preg_match('/^[a-z_]+\.[a-z_.]+$/', $schluessel)) {
                    continue;
                }
                if (! array_key_exists($schluessel, $ar)) {
                    $fehlend[] = $schluessel.'  ['.str_replace(base_path().'/', '', $datei).']';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($fehlend)),
            "Ohne arabische Uebersetzung (in lang/ar.json ergaenzen):\n".implode("\n", array_unique($fehlend)));
    }

    public function test_keine_uebersetzung_ist_leer(): void
    {
        $ar = json_decode((string) file_get_contents(base_path('lang/ar.json')), true);
        // Bewusst leer: im Arabischen steht hinter "14:30" kein Wort fuer "Uhr".
        $gewollt = ['Uhr'];
        $leer = array_values(array_diff(
            array_keys(array_filter($ar, fn ($wert) => ! is_string($wert) || trim($wert) === '')),
            $gewollt,
        ));

        $this->assertSame([], $leer, 'Leere Uebersetzungen: '.implode(', ', $leer));
    }

    /**
     * Im Browser gefunden (23.09.2026): auf der arabischen Seite "Bitte
     * bestaetigen Sie Ihre E-Mail-Adresse" wanderte das Haekchen nach rechts,
     * der Freiraum blieb links - das Zeichen lag auf dem ersten Wort. Der
     * Abstand folgt jetzt der Leserichtung (padding-inline-start).
     */
    public function test_haekchen_der_registrierungsseite_folgen_der_leserichtung(): void
    {
        $vorlage = (string) file_get_contents(resource_path('views/auth/register-pending.blade.php'));

        $this->assertStringContainsString('padding-inline-start:30px', $vorlage);
        $this->assertStringNotContainsString('padding:9px 0 9px 30px', $vorlage);
    }

    /** @return list<string> */
    private function dateien(): array
    {
        $liste = [];
        foreach (self::BEREICHE as $bereich) {
            $pfad = base_path($bereich);
            if (is_file($pfad)) {
                $liste[] = $pfad;

                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pfad));
            foreach ($iterator as $datei) {
                if ($datei->isFile() && str_ends_with($datei->getFilename(), '.blade.php')) {
                    $liste[] = $datei->getPathname();
                }
            }
        }

        return $liste;
    }
}
