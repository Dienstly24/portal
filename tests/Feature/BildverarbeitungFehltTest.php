<?php

namespace Tests\Feature;

use App\Support\Bildverarbeitung;
use Tests\TestCase;

/**
 * EINE FEHLENDE PHP-ERWEITERUNG DARF KEIN HTTP 500 SEIN.
 *
 * Gemeldet 10.09.2026: beim Unterschreiben kam ein HTTP 500, im Protokoll
 * stand "Unterschrift begonnen" und danach nichts. Ursache war NICHT der
 * Code, sondern das fehlende Paket `php8.3-gd` auf dem Server:
 * `imagecreatefromstring()` existiert ohne GD gar nicht, und ein fehlender
 * FUNKTIONSNAME ist ein fataler Error - kein `@` und kein `try` um den
 * Aufruf herum faengt ihn.
 *
 * Die Lehre ist nicht "GD installieren" (das ist erledigt), sondern: die
 * Voraussetzung wird FRUEH geprueft, sie steht auf der Systemzustand-Seite,
 * und der Weg zum Unterschreiben hat eine Schleuse. Diese Tests halten das
 * fest - sonst faellt es beim naechsten Server wieder auf denselben Fehler
 * zurueck, und wieder erst beim Kunden.
 */
class BildverarbeitungFehltTest extends TestCase
{
    public function test_die_pruefung_kennt_die_funktion_die_tatsaechlich_gebrochen_ist(): void
    {
        // Das war die Zeile, die auf dem Server fatal endete.
        $this->assertContains('imagecreatefromstring', Bildverarbeitung::BENOETIGT);

        // Und das war die Zeile DAVOR, die durchlief - sie gehoert zum
        // PHP-Kern und beweist gar nichts ueber GD. Stuende sie in der
        // Liste, meldete die Pruefung "alles da", waehrend GD fehlt.
        $this->assertNotContains('getimagesizefromstring', Bildverarbeitung::BENOETIGT);
    }

    public function test_es_wird_die_aufrufbarkeit_geprueft_nicht_nur_die_erweiterung(): void
    {
        // extension_loaded() genuegt nicht: einzelne Funktionen koennen per
        // disable_functions gesperrt sein, obwohl die Erweiterung geladen
        // ist. Geprueft werden muss, was wirklich aufgerufen wird.
        $quelle = (string) file_get_contents(app_path('Support/Bildverarbeitung.php'));
        $this->assertStringContainsString('function_exists', $quelle);
    }

    public function test_die_meldung_an_den_mitarbeiter_nennt_keine_technischen_namen(): void
    {
        $meldung = Bildverarbeitung::meldungFuerOberflaeche();

        // Ein Mitarbeiter kann mit einem Funktionsnamen oder einem
        // Dateipfad nichts anfangen - genau das war die alte Fehlerseite.
        $this->assertStringNotContainsString('imagecreatefromstring', $meldung);
        $this->assertStringNotContainsString('/var/www', $meldung);
        $this->assertStringNotContainsString('500', $meldung);
        // Stattdessen: was los ist und was jetzt zu tun ist.
        $this->assertStringContainsString('Systemverwaltung', $meldung);
    }

    public function test_der_betriebshinweis_nennt_den_befehl(): void
    {
        // Auf der Systemzustand-Seite liest ein Mensch, der den Server
        // bedienen kann. Ein "GD fehlt" ohne Befehl kostet ihn eine
        // Recherche - und die Seite soll handlungsleitend sein.
        $this->assertStringContainsString('php8.3-gd', Bildverarbeitung::hinweisFuerBetrieb());
        $this->assertStringContainsString('php -v', Bildverarbeitung::hinweisFuerBetrieb());
    }

    public function test_der_unterschreiben_pfad_hat_eine_schleuse(): void
    {
        $quelle = (string) file_get_contents(app_path('Http/Controllers/SignatureSigningController.php'));

        // Ohne diese Schleuse schlug jede Stoerung hinter der Pruefung
        // ungefiltert bis zum Unterzeichner durch.
        $this->assertStringContainsString('catch (\Throwable', $quelle);
        $this->assertStringContainsString('signing_failed', $quelle);
    }

    public function test_geprueft_wird_beim_hochladen_nicht_beim_unterschreiben(): void
    {
        $quelle = (string) file_get_contents(app_path('Http/Controllers/Admin/SignatureController.php'));

        // Dieselbe Regel wie beim defekten PDF: ein Fehlschlag NACH der
        // Unterschrift ist dem Unterzeichner nicht zu erklaeren.
        $this->assertStringContainsString('Bildverarbeitung::verfuegbar()', $quelle);
    }

    public function test_der_deploy_uebereignet_die_caches_dem_webserver(): void
    {
        // Der zweite Befund desselben Tages: der Deploy baute die Caches als
        // root, danach quittierte JEDE Seite mit HTTP 500 - und der Deploy
        // meldete Erfolg.
        $skript = (string) file_get_contents(base_path('scripts/deploy.sh'));

        $this->assertStringContainsString('chown -R', $skript);
        $this->assertStringContainsString('bootstrap/cache', $skript);

        // Die Uebereignung muss NACH dem Bauen der Caches stehen - davor
        // waere sie wirkungslos, denn view:cache legt die Dateien erst an.
        $this->assertGreaterThan(
            strpos($skript, 'php artisan view:cache'),
            strpos($skript, 'chown -R'),
            'chown muss nach view:cache stehen, sonst gehoeren die neuen Dateien wieder root.'
        );
    }
}
