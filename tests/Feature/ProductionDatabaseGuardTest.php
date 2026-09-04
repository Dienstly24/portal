<?php

namespace Tests\Feature;

use App\Support\ProductionDatabaseGuard;
use RuntimeException;
use Tests\TestCase;

/**
 * ARCH-2: die Produktion ist auf MySQL ausgelegt.
 *
 * Der Wert dieser Tests liegt nicht im Fehlerfall, sondern in den beiden
 * Gegenproben: die Regel darf lokale Entwicklung und die Testsuite NIE
 * treffen. Ein Schutz, der die eigene Entwicklung blockiert, wird
 * abgeschaltet - und dann schuetzt er gar nichts mehr.
 */
class ProductionDatabaseGuardTest extends TestCase
{
    private function pruefeMitUmgebung(string $env, string $verbindung): void
    {
        $this->app['env'] = $env;
        config([
            'database.default' => $verbindung,
            "database.connections.{$verbindung}.driver" => $verbindung,
        ]);

        ProductionDatabaseGuard::pruefen($this->app);
    }

    public function test_produktion_mit_sqlite_startet_nicht(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/MySQL/');

        $this->pruefeMitUmgebung('production', 'sqlite');
    }

    public function test_die_meldung_nennt_die_haeufigste_ursache_und_den_naechsten_schritt(): void
    {
        try {
            $this->pruefeMitUmgebung('production', 'sqlite');
            $this->fail('Die Pruefung haette anschlagen muessen.');
        } catch (RuntimeException $e) {
            // Eine Fehlermeldung, die nur "falsche Datenbank" sagt, laesst den
            // Betrieb raten. Sie muss sagen, WAS zu setzen ist.
            $this->assertStringContainsString('DB_CONNECTION=mysql', $e->getMessage());
            $this->assertStringContainsString('.env', $e->getMessage());
        }
    }

    public function test_produktion_mit_mysql_startet(): void
    {
        $this->pruefeMitUmgebung('production', 'mysql');
        $this->assertTrue(true);
    }

    public function test_lokale_entwicklung_mit_sqlite_bleibt_erlaubt(): void
    {
        $this->pruefeMitUmgebung('local', 'sqlite');
        $this->assertTrue(true);
    }

    public function test_die_testumgebung_mit_sqlite_bleibt_erlaubt(): void
    {
        $this->pruefeMitUmgebung('testing', 'sqlite');
        $this->assertTrue(true);
    }

    public function test_der_notausstieg_laesst_sqlite_in_produktion_ausdruecklich_zu(): void
    {
        config(['database.allow_sqlite_in_production' => true]);

        $this->pruefeMitUmgebung('production', 'sqlite');
        $this->assertTrue(true);
    }

    /**
     * Der Notausstieg wird ueber config() gelesen, nicht ueber env(): mit
     * gecachter Konfiguration - in Produktion der Normalfall - liefert env()
     * null. Er haette also ausgerechnet dort nicht gegriffen, wo er
     * gebraucht wird. Geprueft wird der CODE (Tokens), nicht der Text -
     * in den Kommentaren darf "env()" selbstverstaendlich vorkommen.
     */
    public function test_der_notausstieg_ruft_kein_env_auf(): void
    {
        $tokens = token_get_all(file_get_contents(app_path('Support/ProductionDatabaseGuard.php')));

        $aufrufe = [];
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            // Nur echte Aufrufe zaehlen: dem Namen folgt eine Klammer.
            $naechstes = $tokens[$i + 1] ?? null;
            if ($naechstes === '(') {
                $aufrufe[] = $token[1];
            }
        }

        $this->assertNotContains('env', $aufrufe, 'Der Guard darf env() nicht aufrufen - mit gecachter Konfiguration liefert es null.');
        $this->assertContains('config', $aufrufe);
    }

    /**
     * Der Schluessel muss in der Konfiguration existieren - sonst greift der
     * Notausstieg nie, egal was in der .env steht.
     */
    public function test_der_konfigurationsschluessel_existiert_und_ist_standardmaessig_aus(): void
    {
        $frisch = require config_path('database.php');

        $this->assertArrayHasKey('allow_sqlite_in_production', $frisch);
        $this->assertFalse((bool) $frisch['allow_sqlite_in_production']);
    }

    /**
     * Die gesamte Suite laeuft auf SQLite - wenn dieser Test etwas anderes
     * meldet, ist die Guard-Regel in die Testumgebung durchgeschlagen.
     */
    public function test_die_suite_selbst_laeuft_unbehelligt_auf_sqlite(): void
    {
        $this->assertSame('testing', $this->app->environment());
        $this->assertFalse($this->app->isProduction());
    }
}
