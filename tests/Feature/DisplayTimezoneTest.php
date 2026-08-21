<?php

namespace Tests\Feature;

use App\Support\LocalTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gespeichert wird UTC, GEZEIGT wird deutsche Ortszeit.
 *
 * Der Betrieb sitzt in Deutschland, die Datenbank rechnet in UTC. Ein
 * Zeitstempel, der 14:30 anzeigt, obwohl der Vorgang um 16:30 stattfand, ist
 * schlicht falsch - beim DSGVO-Einwilligungszeitpunkt sogar rechtlich heikel.
 *
 * Diese Tests sichern BEIDE Haelften: dass die Umrechnung stimmt, und dass
 * die Speicherung dabei unberuehrt bleibt.
 */
class DisplayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------ Speicherung bleibt UTC

    public function test_gespeichert_wird_weiterhin_utc(): void
    {
        // Das ist die eigentliche Zusicherung an den Altbestand: wuerde die
        // Anwendungs-Zeitzone auf Berlin wechseln, laegen alte Zeilen in UTC
        // und neue in Ortszeit - ohne dass man einer Zeile ansieht, welche
        // von beiden sie ist. Ein solcher Mischbestand ist nicht mehr
        // sauber zu reparieren.
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_die_anzeige_zeitzone_ist_deutsch(): void
    {
        $this->assertSame('Europe/Berlin', LocalTime::zone());
    }

    // -------------------------------------------------------- Die Umrechnung

    public function test_lokal_rechnet_einen_gespeicherten_zeitpunkt_um(): void
    {
        // Sommerzeit: UTC+2.
        $gespeichert = Carbon::parse('2026-08-21 14:30:00', 'UTC');

        $this->assertSame('16:30', $gespeichert->lokal()->format('H:i'));
        // Der Zeitpunkt selbst aendert sich nicht - nur seine Darstellung.
        $this->assertSame($gespeichert->getTimestamp(), $gespeichert->lokal()->getTimestamp());
    }

    public function test_lokal_beachtet_die_winterzeit(): void
    {
        // Winterzeit: UTC+1. Ein fester Stundenversatz waere zweimal im Jahr
        // falsch - deshalb echte Zeitzone statt Offset.
        $this->assertSame('15:30', Carbon::parse('2026-01-21 14:30:00', 'UTC')->lokal()->format('H:i'));
    }

    public function test_lokal_veraendert_das_original_nicht(): void
    {
        $gespeichert = Carbon::parse('2026-08-21 14:30:00', 'UTC');
        $gespeichert->lokal();

        // Sonst wuerde ein einmaliges Anzeigen den Wert fuer alle folgenden
        // Berechnungen im selben Request verschieben.
        $this->assertSame('14:30', $gespeichert->format('H:i'));
    }

    public function test_kurz_vor_mitternacht_verschiebt_sich_der_TAG(): void
    {
        // Genau der Fall, der eine reine Datums-Anzeige falsch machte: um
        // 23:30 UTC ist es in Deutschland schon der naechste Tag.
        $gespeichert = Carbon::parse('2026-08-21 23:30:00', 'UTC');

        $this->assertSame('22.08.2026', $gespeichert->lokal()->format('d.m.Y'));
    }

    public function test_localtime_kommt_mit_leeren_werten_klar(): void
    {
        // Eine Anzeige darf nie daran scheitern, dass ein Feld leer ist.
        $this->assertNull(LocalTime::for(null));
        $this->assertNull(LocalTime::for(''));
        $this->assertNull(LocalTime::for('kein datum'));
        $this->assertNotNull(LocalTime::for('2026-08-21 14:30:00'));
    }

    // ------------------------------------------------- Waechter fuer die Views

    /**
     * Verhindert den Rueckfall: eine neue View, die eine Uhrzeit ohne
     * ->lokal() ausgibt, zeigt wieder UTC - und niemand merkt es, weil
     * zwei Stunden Abweichung plausibel aussehen.
     */
    public function test_keine_view_zeigt_eine_uhrzeit_ohne_umrechnung(): void
    {
        $treffer = [];

        foreach ($this->bladeDateien() as $datei) {
            foreach (file($datei) as $nr => $zeile) {
                // Formate mit Stunden-Platzhalter (H oder G).
                if (! preg_match("/->format\('[^']*[HG][^']*'\)/", $zeile)) {
                    continue;
                }
                // datetime-local-Formularwerte rechnen bewusst selbst um
                // (sie gehen zurueck an den Server).
                if (str_contains($zeile, '\\TH:i')) {
                    continue;
                }
                if (str_contains($zeile, 'lokal()')) {
                    continue;
                }
                $treffer[] = str_replace(base_path() . '/', '', $datei) . ':' . ($nr + 1);
            }
        }

        $this->assertSame([], $treffer,
            "Diese Stellen zeigen eine Uhrzeit ohne ->lokal() (also in UTC):\n" . implode("\n", $treffer));
    }

    /**
     * Zeitpunkt-Spalten (Laravel-Konvention "..._at") sind Momente. Auch wenn
     * nur das Datum gezeigt wird, muessen sie umgerechnet werden - sonst
     * steht bei einem Vorgang um 01:30 der falsche TAG da.
     */
    public function test_zeitpunkt_spalten_werden_auch_als_datum_umgerechnet(): void
    {
        $treffer = [];

        foreach ($this->bladeDateien() as $datei) {
            foreach (file($datei) as $nr => $zeile) {
                if (! preg_match('/\w*_at\??->format\(/', $zeile)) {
                    continue;
                }
                if (str_contains($zeile, 'lokal()')) {
                    continue;
                }
                $treffer[] = str_replace(base_path() . '/', '', $datei) . ':' . ($nr + 1);
            }
        }

        $this->assertSame([], $treffer,
            "Zeitpunkt-Spalten ohne ->lokal():\n" . implode("\n", $treffer));
    }

    /** @return list<string> */
    private function bladeDateien(): array
    {
        $dateien = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );
        foreach ($iterator as $datei) {
            if ($datei->isFile() && str_ends_with($datei->getFilename(), '.blade.php')) {
                $dateien[] = $datei->getPathname();
            }
        }
        sort($dateien);

        return $dateien;
    }
}
