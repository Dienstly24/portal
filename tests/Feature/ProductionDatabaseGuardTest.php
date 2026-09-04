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

    /**
     * REGRESSION (04.09.2026): die Pruefung hat die CI komplett lahmgelegt -
     * alle vier Jobs scheiterten binnen Sekunden am `composer install`.
     *
     * Ursache: `composer install` fuehrt `artisan package:discover` aus, und
     * das passiert BEVOR eine .env existiert. Ohne .env ist Laravels Vorgabe
     * fuer APP_ENV aber "production" - eine frische Arbeitskopie sah damit
     * wie Produktion aus. Die Pruefung schlug also bei jeder frischen
     * Installation an, obwohl niemand eine Datenbank anfassen wollte.
     *
     * Der Fall kostet nichts: fehlt die .env auf einem echten Server, gibt
     * es keinen APP_KEY und jede Anfrage bricht ohnehin sofort ab. Der
     * STILLE Fall, um den es geht, ist die vorhandene .env mit fehlendem
     * DB_CONNECTION - und den prueft der Test darunter weiterhin.
     */
    public function test_ohne_env_datei_greift_die_pruefung_nicht(): void
    {
        $this->app['env'] = 'production';
        $this->app->useEnvironmentPath('/pfad/den/es/nicht/gibt');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.driver' => 'sqlite',
        ]);

        ProductionDatabaseGuard::pruefen($this->app);

        $this->assertTrue(true, 'Eine frische Installation ohne .env darf nicht scheitern.');
    }

    /**
     * Die Gegenprobe dazu: mit VORHANDENER .env bleibt die Regel scharf -
     * genau das ist der Fall, den sie abfangen soll.
     */
    public function test_mit_vorhandener_env_datei_bleibt_die_pruefung_scharf(): void
    {
        $this->app['env'] = 'production';
        $this->app->useEnvironmentPath(base_path());
        $this->app->loadEnvironmentFrom('.env.example');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.driver' => 'sqlite',
        ]);

        $this->expectException(RuntimeException::class);
        ProductionDatabaseGuard::pruefen($this->app);
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
