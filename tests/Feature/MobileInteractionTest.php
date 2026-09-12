<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Waechter fuer die BEDIENUNG auf Telefon und Tablet
 * (Verification & Hardening 12.09.2026).
 *
 * ResponsiveLayoutTest sichert die Geometrie (Navigation, Tabellen,
 * Ueberlauf). Hier geht es um das Verhalten der interaktiven Teile -
 * Karussell, Dokument-Vorschau, Fingermasse, Raster. Jeder Fall unten
 * ist ein Fehler, der im Browser GEMESSEN wurde; ohne Test faellt er
 * beim naechsten Umbau lautlos zurueck.
 *
 * Gemessen wird der QUELLTEXT, nicht eine gerenderte Seite: die Fehler
 * lagen in den Vorlagen und in responsive.css, und eine Blade-Vorlage
 * laesst sich ohne Browser pruefen. Was NUR im Browser pruefbar ist
 * (echtes Wischen, echtes iOS Safari) steht ausdruecklich im Bericht
 * und nicht hier - ein Test, der etwas anderes prueft als er behauptet,
 * ist schlimmer als kein Test.
 */
class MobileInteractionTest extends TestCase
{
    private function view(string $datei): string
    {
        $pfad = resource_path('views/'.$datei);
        $this->assertFileExists($pfad);

        return file_get_contents($pfad);
    }

    private function css(string $datei): string
    {
        $pfad = resource_path('css/'.$datei);
        $this->assertFileExists($pfad);

        return file_get_contents($pfad);
    }

    // ================= KARUSSELL =================

    /**
     * DER DEFEKT: die Folienliste wurde EINMAL geholt und in einer
     * Konstanten gehalten. Eine solche NodeList ist statisch und kennt
     * das spaetere `slide.remove()` des Ausblendens nicht. Traf die
     * Selbstschaltung auf den entfernten Eintrag, war das Karussell
     * dauerhaft LEER - nachgestellt: nach rund 18 Sekunden.
     */
    public function test_das_karussell_liest_seine_folien_bei_jedem_zugriff_neu(): void
    {
        $s = $this->view('portal/dashboard.blade.php');

        $this->assertStringContainsString(
            'function slides()',
            $s,
            'portal/dashboard: Die Folien muessen ueber eine Funktion frisch gelesen werden.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/const\s+slides\s*=\s*document\.querySelectorAll/',
            $s,
            'portal/dashboard: Eine festgehaltene NodeList kennt entfernte Folien nicht - '
            .'genau daran wurde das Karussell nach dem Ausblenden eines Banners dauerhaft leer.'
        );
    }

    /** Ohne Wischen war auf dem Telefon nur der 9px-Punkt uebrig. */
    public function test_das_karussell_laesst_sich_wischen(): void
    {
        $s = $this->view('portal/dashboard.blade.php');

        foreach (['touchstart', 'touchend'] as $ereignis) {
            $this->assertStringContainsString(
                $ereignis,
                $s,
                "portal/dashboard: Kein {$ereignis} - auf dem Telefon ist Wischen die erwartete Bedienung."
            );
        }
        $this->assertStringContainsString(
            'touch-action:pan-y',
            $s,
            'portal/dashboard: Ohne touch-action:pan-y kollidiert die Wischgeste mit dem Seitenlauf.'
        );
    }

    /**
     * Die Punkte waren 9x9-px-<span>: unter dem Finger, ohne
     * Tastaturfokus, ohne Rolle fuer Screenreader.
     */
    public function test_die_bannerpunkte_sind_schaltflaechen_mit_fingermass(): void
    {
        $s = $this->view('portal/dashboard.blade.php');

        $this->assertMatchesRegularExpression(
            '/<button[^>]*class="banner-dot/',
            $s,
            'portal/dashboard: Die Punkte muessen <button> sein (Fokus, Rolle, Tastatur).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<span[^>]*class="banner-dot/',
            $s,
            'portal/dashboard: Ein <span> ist weder fokussierbar noch bedienbar.'
        );
        $this->assertMatchesRegularExpression(
            '/\.banner-dot\s*\{[^}]*width:\s*44px[^}]*height:\s*44px/s',
            $s,
            'portal/dashboard: Die Trefferflaeche der Punkte muss 44x44 sein (WCAG 2.5.5). '
            .'Der SICHTBARE Punkt bleibt 9px - das ist der Sinn des ::before.'
        );
    }

    // ================= DOKUMENT-VORSCHAU / IFRAME =================

    /**
     * Die Schnellvorschau haengt an `mouseover`. Ein Telefon hat kein
     * Hover, simuliert es aber beim Antippen: gemessen auf 390px ging
     * nach einem Fingertipp ein 179px schmales Fenster mit
     * eingebettetem PDF auf, das niemand angefordert hat.
     */
    public function test_die_schnellvorschau_erscheint_nur_auf_zeigegeraeten(): void
    {
        foreach (['partials/doc_preview.blade.php', 'admin/documents_inbox.blade.php'] as $datei) {
            $s = $this->view($datei);
            $this->assertStringContainsString(
                '(hover: hover) and (pointer: fine)',
                $s,
                "{$datei}: Die Schnellvorschau muss an ein echtes Zeigegeraet gebunden sein. "
                .'Die Frage gilt dem GERAET, nicht der Breite - ein schmales Fenster auf dem '
                .'Rechner behaelt sie, ein breites Tablet bekommt sie nicht.'
            );
        }
    }

    /**
     * `vh` rechnet auf dem Telefon mit eingefahrener Adressleiste. Ein
     * Vorschau-Fenster mit `height:92vh` ragt unten aus dem Bild -
     * genau dort, wo Schliessen und Herunterladen sitzen.
     */
    public function test_die_dokumentvorschau_rechnet_mit_der_sichtbaren_hoehe(): void
    {
        $s = $this->view('partials/doc_preview.blade.php');

        $this->assertStringContainsString(
            'dvh',
            $s,
            'partials/doc_preview: Das Vorschau-Fenster braucht dvh - vh rechnet mit '
            .'eingefahrener Adressleiste und schiebt die Schaltflaechen aus dem Bild.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/height:\s*92vh\s*;/',
            $s,
            'partials/doc_preview: Nacktes 92vh ohne dvh-Deckel.'
        );
    }

    /**
     * EHRLICH BLEIBEN: Android Chrome zeigt eingebettete PDFs gar nicht
     * an, iOS Safari nur die erste Seite. Der Rahmen bleibt (auf
     * iPadOS funktioniert er), aber auf Beruehrgeraeten steht IMMER ein
     * sichtbarer Weg zum echten PDF darueber. Ein leerer Rahmen ohne
     * Ausweg sieht aus wie ein kaputtes Portal.
     */
    public function test_die_pdf_vorschau_bietet_auf_beruehrgeraeten_einen_ausweg(): void
    {
        $s = $this->view('partials/doc_preview.blade.php');

        $this->assertStringContainsString(
            'PDF öffnen',
            $s,
            'partials/doc_preview: Es fehlt der sichtbare Weg zum echten PDF fuer den Fall, '
            .'dass der Browser den eingebetteten Rahmen leer laesst.'
        );
        $this->assertStringContainsString(
            '!echterZeiger.matches',
            $s,
            'partials/doc_preview: Der Ausweg gehoert auf Beruehrgeraete - auf dem Rechner '
            .'funktioniert der Rahmen und ein zusaetzlicher Balken waere nur Laerm.'
        );
    }

    // ================= FINGERMASSE =================

    /**
     * Fingermasse haengen am FINGER, nicht am Bildschirm: ein Tablet im
     * Querformat ist 1024px breit und wird trotzdem beruehrt. Wer sie
     * an `max-width` haengt, trifft diesen Fall falsch.
     */
    public function test_fingermasse_haengen_an_der_eingabeart_nicht_an_der_breite(): void
    {
        $css = $this->css('responsive.css');

        $this->assertStringContainsString(
            '@media (pointer: coarse)',
            $css,
            'responsive.css: Fingermasse muessen in (pointer: coarse) stehen.'
        );

        // Die Bedienelemente, die im Ist-Zustand unter 44px gemessen wurden.
        foreach (['.nav-item', '.tab-row .tab', '.btn', '.icon-btn', '.modal-close', '.logout-btn'] as $sel) {
            $this->assertStringContainsString(
                $sel,
                $css,
                "responsive.css: {$sel} wurde unter 44px gemessen und fehlt in der Fingermass-Regel."
            );
        }
    }

    /**
     * Unter 16px zoomt iOS Safari beim Hineintippen die ganze Seite -
     * danach ist das Formular verschoben und muss von Hand
     * zurueckgezoomt werden. Das ist kein Schoenheitsfehler.
     */
    public function test_eingabefelder_loesen_auf_ios_keinen_zoom_aus(): void
    {
        $css = $this->css('responsive.css');

        $this->assertMatchesRegularExpression(
            '/input[^{]*\{[^}]*font-size:\s*16px/s',
            $css,
            'responsive.css: Eingabefelder brauchen auf Beruehrgeraeten 16px, sonst zoomt '
            .'iOS Safari beim Hineintippen und verschiebt die Seite.'
        );
    }

    // ================= RASTER =================

    /**
     * Rund 35 Seiten schreiben ihr Raster direkt als style="..." ins
     * Markup; ein Attribut schlaegt jede Klassenregel. Gemessen bei
     * 320px: 28px breite Eingabefelder (signatures/create), 97px je
     * Geldbetrag (provisions), 88px je Spalte (provision_report).
     */
    public function test_starre_raster_brechen_auf_schmalen_bildschirmen_um(): void
    {
        $css = $this->css('responsive.css');

        foreach (['repeat(3,', 'repeat(4,', 'repeat(5,', '1fr 1fr 1fr', '340px 1fr'] as $muster) {
            $this->assertStringContainsString(
                '[style*="'.$muster.'"]',
                $css,
                "responsive.css: Das gemessene Muster {$muster} bricht auf dem Telefon nicht um."
            );
        }
    }

    /**
     * GEGENPROBE - das ist der wichtigere Teil: die Umbruchregel darf
     * genau zwei Dinge NICHT treffen.
     *
     * `auto-fit`/`auto-fill` (59 Vorkommen) brechen von sich aus um und
     * sind bereits richtig. `auto 1fr` ist Symbol + Text (Avatar,
     * Betreuerzeile) - ein Umbruch wuerde das Symbol UEBER den Namen
     * setzen. Beide sind im Browser als Kontrollfall geprueft worden.
     */
    public function test_die_umbruchregel_trifft_keine_bereits_richtigen_raster(): void
    {
        $css = $this->css('responsive.css');

        $this->assertStringNotContainsString(
            '[style*="auto-fit"]',
            $css,
            'responsive.css: auto-fit-Raster brechen von selbst um und duerfen nicht angefasst werden.'
        );
        $this->assertStringNotContainsString(
            '[style*="auto-fill"]',
            $css,
            'responsive.css: auto-fill-Raster brechen von selbst um.'
        );
        $this->assertStringNotContainsString(
            '[style*="auto 1fr"]',
            $css,
            'responsive.css: "auto 1fr" ist Symbol + Text - ein Umbruch setzt das Symbol ueber den Namen.'
        );
    }
}
