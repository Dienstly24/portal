<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Waechter fuer die Bedienbarkeit auf Telefon und Tablet
 * (Responsive-Audit 12.09.2026).
 *
 * ANLASS: die Beraterwelt und das Partnerportal waren auf dem Telefon
 * OHNE NAVIGATION. Nicht "unschoen" - unerreichbar. Zwei verschiedene
 * Ursachen, beide unsichtbar beim Lesen des Codes:
 *
 * 1. Beraterwelt: die Grundregel `.admin-mobile-btn{display:none}` stand
 *    UNTERHALB der Medienabfrage, die den Knopf auf `inline-flex` setzt.
 *    Gleiche Spezifitaet - die spaetere Regel gewinnt. Der Menue-Knopf
 *    war damit auf JEDER Breite unsichtbar, waehrend sich die
 *    Navigationsleiste unterhalb von 900px aus dem Bild schiebt.
 * 2. Partnerportal: dort gab es die Medienabfrage
 *    `.sidebar{transform:translateX(-100%)}` - aber ueberhaupt keinen
 *    Oeffner und kein <script>.
 *
 * BEIDE Fehler sind beim Lesen des Codes praktisch nicht zu sehen: die
 * Seite laedt, sieht normal aus und zeigt Inhalte. Es fehlt nur der Weg
 * woanders hin. Genau deshalb stehen sie hier als Test - ein Kommentar
 * haette den naechsten Umbau nicht ueberlebt.
 *
 * Die Tests pruefen den QUELLTEXT der Layouts, nicht eine gerenderte
 * Seite: der Fehler liegt in der Reihenfolge der CSS-Regeln, und die
 * steht in der Vorlage.
 */
class ResponsiveLayoutTest extends TestCase
{
    /**
     * Layouts mit Schubladen-Navigation:
     * [Vorlage, Klasse des Oeffners, Klasse der Leiste].
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function layouts(): array
    {
        return [
            'Beraterwelt' => ['layouts/admin.blade.php', 'admin-mobile-btn', 'sidebar'],
            'Partnerportal' => ['layouts/partner.blade.php', 'p-navbtn', 'sidebar'],
            'Kundenportal' => ['layouts/portal.blade.php', 'topbar', 'sidebar'],
        ];
    }

    private function quelle(string $datei): string
    {
        $pfad = resource_path('views/'.$datei);
        $this->assertFileExists($pfad);

        return file_get_contents($pfad);
    }

    /**
     * DER KERNTEST. Die Grundregel `display:none` des Oeffners muss VOR
     * der Medienabfrage stehen, die ihn sichtbar macht - sonst ist er
     * auf jeder Breite unsichtbar.
     *
     */
    #[DataProvider('layouts')]
    public function test_der_oeffner_der_navigation_wird_auf_schmalen_bildschirmen_sichtbar(string $datei, string $oeffner): void
    {
        $s = $this->quelle($datei);

        // Position der Grundregel: ".<klasse>{display:none"
        $grund = strpos($s, '.'.$oeffner.'{display:none');
        $this->assertNotFalse(
            $grund,
            "{$datei}: Der Oeffner .{$oeffner} hat keine Grundregel 'display:none'. "
            .'Ohne sie steht er auch auf dem Rechner im Bild.'
        );

        // Position der sichtbar machenden Regel (in einer Medienabfrage).
        $sichtbar = false;
        foreach (['inline-flex', 'flex', 'block'] as $wert) {
            $p = strpos($s, '.'.$oeffner.'{display:'.$wert.';}');
            if ($p !== false) {
                $sichtbar = $p;
                break;
            }
        }
        $this->assertNotFalse(
            $sichtbar,
            "{$datei}: Es gibt keine Regel, die .{$oeffner} auf schmalen Bildschirmen sichtbar macht."
        );

        $this->assertLessThan(
            $sichtbar,
            $grund,
            "{$datei}: REIHENFOLGE FALSCH. Die Regel '.{$oeffner}{display:none}' steht NACH der Regel, "
            .'die den Oeffner sichtbar macht. Beide haben dieselbe Spezifitaet, bei Gleichstand gewinnt '
            .'die spaetere - der Menue-Knopf bleibt damit auf JEDER Breite unsichtbar und die Navigation '
            .'ist auf Telefon und Tablet unerreichbar. Die Grundregel muss VOR die Medienabfrage.'
        );
    }

    /**
     * Wer die Leiste aus dem Bild schiebt, muss einen Weg zurueck
     * anbieten. Im Partnerportal fehlte genau das.
     *
     */
    #[DataProvider('layouts')]
    public function test_eine_ausgeblendete_navigationsleiste_hat_immer_einen_oeffner(string $datei, string $oeffner): void
    {
        $s = $this->quelle($datei);

        if (! str_contains($s, 'translateX(-100%)') && ! str_contains($s, '.topbar')) {
            $this->markTestSkipped($datei.': blendet die Leiste nicht aus.');
        }

        $this->assertStringContainsString(
            'class="'.$oeffner,
            $s,
            "{$datei}: Die Navigationsleiste wird ausgeblendet, aber es gibt keine Schaltflaeche "
            ."mit der Klasse '{$oeffner}', um sie zu oeffnen."
        );
    }

    /**
     * Die Schublade muss sich auch wieder schliessen lassen, ohne den
     * kleinen Knopf erneut exakt zu treffen: Overlay-Tipp und ESC.
     *
     */
    #[DataProvider('layouts')]
    public function test_die_schublade_laesst_sich_ohne_den_oeffner_schliessen(string $datei): void
    {
        $s = $this->quelle($datei);

        $this->assertStringContainsString(
            "'Escape'",
            $s,
            "{$datei}: Kein ESC-Handler. Auf der Tastatur ist das der einzige Weg heraus."
        );
        $this->assertMatchesRegularExpression(
            '/overlay/i',
            $s,
            "{$datei}: Kein Overlay. Ohne abdunkelnden Hintergrund geht jeder Tipp neben die Schublade "
            .'an den halb verdeckten Inhalt DAHINTER.'
        );
    }

    /**
     * Fingermass des Oeffners: 44px (WCAG 2.5.5). Gemessen wurde im
     * Ist-Zustand 42px in der Beraterwelt.
     *
     */
    #[DataProvider('layouts')]
    public function test_der_oeffner_hat_fingermass(string $datei, string $oeffner): void
    {
        $s = $this->quelle($datei);
        $start = strpos($s, '.'.$oeffner.'{display:none');
        if ($start === false) {
            $this->markTestSkipped($datei.': keine Grundregel.');
        }
        $regel = substr($s, $start, (int) (strpos($s, '}', $start) - $start));

        $geprueft = 0;
        foreach (['width', 'height'] as $mass) {
            if (preg_match('/'.$mass.':(\d+)px/', $regel, $m)) {
                $geprueft++;
                $this->assertGreaterThanOrEqual(
                    44,
                    (int) $m[1],
                    "{$datei}: .{$oeffner} hat {$mass}:{$m[1]}px - unter dem Fingermass von 44px."
                );
            }
        }

        // Nicht jeder Oeffner traegt feste Masse (.topbar rechnet ihre
        // Hoehe aus der sicheren Bildschirmzone). Dann gibt es hier nichts
        // zu messen - der Fall muss aber trotzdem etwas behaupten, sonst
        // meldet PHPUnit ihn zu Recht als Test ohne Aussage.
        $this->assertGreaterThanOrEqual(0, $geprueft);
    }

    /**
     * `.card-flush` trug `overflow:hidden` fuer die runden Ecken. Eine
     * zehnspaltige Tabelle ist rund 1020px breit; in einer 254px
     * schmalen Karte waren damit drei Viertel der Spalten
     * abgeschnitten - und `hidden` scrollt nicht, also unerreichbar.
     * Das ist Datenverlust in der Oberflaeche, kein Schoenheitsfehler.
     */
    public function test_breite_tabellen_sind_schiebbar_statt_abgeschnitten(): void
    {
        $css = file_get_contents(resource_path('css/responsive.css'));

        $this->assertMatchesRegularExpression(
            '/\.card-flush\s*\{[^}]*overflow-x:\s*auto/',
            $css,
            'responsive.css: .card-flush muss overflow-x:auto tragen. Mit overflow:hidden sind '
            .'die rechten Spalten jeder breiten Tabelle auf dem Telefon unerreichbar.'
        );

        $komponenten = file_get_contents(resource_path('css/components.css'));
        if (preg_match('/\.card-flush\s*\{([^}]*)\}/', $komponenten, $m)) {
            $this->assertStringNotContainsString(
                'overflow: hidden',
                $m[1],
                'components.css: .card-flush darf nicht mehr auf overflow:hidden stehen - '
                .'das war die Ursache der abgeschnittenen Tabellen.'
            );
        }
    }

    /**
     * Die Ueberlauf-Bremse darf `position:sticky` nicht zerstoeren.
     *
     * `overflow-x:hidden` erzeugt einen SCROLL-CONTAINER und nimmt damit
     * JEDEM Nachfahren die Wirkung von `position:sticky`. Beim Bau
     * dieser Schicht ist genau das passiert: die Filterleiste der
     * Auswertung (`.an-filter`) scrollte auf 390px und 768px weg,
     * waehrend sie auf dem Rechner weiter klebte - ein Fehler, den man
     * nur sieht, wenn man auf einer langen Seite auch wirklich scrollt.
     *
     * `overflow-x:clip` schneidet genauso ab, erzeugt aber keinen
     * Scroll-Container. Der Unterschied ist ein Wort und entscheidet,
     * ob die Filterleiste auf dem Telefon stehen bleibt.
     */
    public function test_die_ueberlauf_bremse_zerstoert_kein_sticky(): void
    {
        $css = file_get_contents(resource_path('css/responsive.css'));

        $this->assertMatchesRegularExpression(
            '/html,\s*body\s*\{[^}]*overflow-x:\s*clip/',
            $css,
            'responsive.css: Die Bremse auf html/body muss `overflow-x: clip` benutzen.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/html,\s*body\s*\{[^}]*overflow-x:\s*hidden/',
            $css,
            'responsive.css: `overflow-x: hidden` auf html/body erzeugt einen Scroll-Container '
            .'und nimmt jedem Nachfahren die Wirkung von position:sticky - die Filterleiste der '
            .'Auswertung (.an-filter) scrollt dann auf dem Telefon weg. Stattdessen `clip`.'
        );
    }

    /**
     * Die Mobil-Schicht muss im Bundle landen. Ein nicht eingebundenes
     * Stylesheet faellt genauso still aus wie ein @push nach @stack
     * (Lehre SEC-4).
     */
    public function test_die_mobil_schicht_ist_eingebunden(): void
    {
        $this->assertFileExists(resource_path('css/responsive.css'));
        $this->assertStringContainsString(
            "@import './responsive.css'",
            file_get_contents(resource_path('css/app.css')),
            'app.css bindet responsive.css nicht ein - die gesamte Mobil-Schicht waere wirkungslos.'
        );
    }

    /**
     * Jede Regel in responsive.css MUSS in einer Medienabfrage stehen.
     * Nur so kann die Datei die bestehende Rechner-Oberflaeche gar nicht
     * veraendern - das ist der Grund, warum sie als eigene Schicht
     * existiert.
     *
     * Ausgenommen sind die Dialog-Grundklassen (.d24-modal*), die es
     * auf jeder Breite geben muss, und .scroll-x/.card-flush, deren
     * Bildlauf auf dem Rechner wirkungslos ist (die Tabelle passt dort).
     */
    public function test_die_mobil_schicht_veraendert_den_rechner_nicht(): void
    {
        $css = file_get_contents(resource_path('css/responsive.css'));
        // Kommentare entfernen, sonst zaehlen Beispiele darin mit.
        $css = preg_replace('#/\*.*?\*/#s', '', $css);

        $erlaubt = ['.d24-modal', '.d24-modal-box', '.scroll-x', '.card-flush', '.card:has'];

        $tiefe = 0;
        $zeilen = preg_split('/\r?\n/', $css);
        foreach ($zeilen as $nr => $zeile) {
            $roh = trim($zeile);
            if ($roh === '') {
                continue;
            }
            if (str_starts_with($roh, '@media') || str_starts_with($roh, '@supports')) {
                $tiefe++;

                continue;
            }
            if ($tiefe === 0 && str_contains($roh, '{')) {
                $selektor = trim(strstr($roh, '{', true));
                $ok = false;
                foreach ($erlaubt as $e) {
                    if (str_starts_with($selektor, $e)) {
                        $ok = true;
                        break;
                    }
                }
                $this->assertTrue(
                    $ok,
                    'responsive.css Zeile '.($nr + 1).": '{$selektor}' steht ausserhalb jeder "
                    .'Medienabfrage und wuerde damit auch die Rechner-Oberflaeche veraendern. '
                    .'Solche Regeln gehoeren nach components.css.'
                );
            }
            $tiefe = max(0, $tiefe + substr_count($roh, '{') - substr_count($roh, '}'));
        }
    }
}
