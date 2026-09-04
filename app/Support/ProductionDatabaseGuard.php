<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * ARCH-2: SQLite darf in Produktion nicht starten.
 *
 * WARUM UEBERHAUPT: `config/database.php` faellt auf `sqlite` zurueck, wenn
 * `DB_CONNECTION` fehlt. Eine .env, die beim Deploy nicht ankommt oder eine
 * auskommentierte Zeile enthaelt, laesst das Portal deshalb NICHT scheitern -
 * es startet klaglos gegen eine leere SQLite-Datei. Der Betrieb sieht dann
 * ein funktionierendes Portal ohne einen einzigen Kunden, und im
 * schlimmsten Fall schreiben Mitarbeiter stundenlang in eine Datei, die
 * beim naechsten Deploy verschwindet.
 *
 * Ein lautes Scheitern beim Start ist hier die milde Variante: es trifft
 * niemanden ausser dem Deploy, und die Ursache steht in der Meldung.
 *
 * SQLite bleibt ueberall sonst voll unterstuetzt - lokale Entwicklung und
 * die gesamte Testsuite laufen bewusst weiter darauf (schnell, ohne
 * Serverdienst). Die Regel greift AUSSCHLIESSLICH bei APP_ENV=production.
 */
class ProductionDatabaseGuard
{
    /** Treiber, die in Produktion nicht in Frage kommen. */
    private const VERBOTEN = ['sqlite'];

    /**
     * Notausstieg fuer den Ausnahmefall, dass jemand SQLite in Produktion
     * WIRKLICH will (etwa eine abgeschottete Einzelplatz-Installation).
     * Bewusst eine ausdrueckliche Entscheidung und keine stille Voreinstellung.
     *
     * Gelesen wird ueber config(), NICHT ueber env(): mit gecachter
     * Konfiguration - in Produktion der Normalfall - liefert env() null.
     * Der Notausstieg haette also ausgerechnet dort nicht funktioniert, wo
     * er gebraucht wird.
     */
    private const AUSNAHME_CONFIG = 'database.allow_sqlite_in_production';

    public static function pruefen(Application $app): void
    {
        if (! $app->isProduction()) {
            return;
        }

        if (filter_var(config(self::AUSNAHME_CONFIG, false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $verbindung = (string) config('database.default');
        $treiber = (string) config("database.connections.{$verbindung}.driver", $verbindung);

        if (! in_array($treiber, self::VERBOTEN, true)) {
            return;
        }

        throw new RuntimeException(
            'Produktion laeuft mit dem Datenbanktreiber "'.$treiber.'". '
            .'Dieses Portal ist fuer MySQL ausgelegt; SQLite ist nur fuer lokale '
            .'Entwicklung und die Testsuite vorgesehen. '
            .'Bitte in der Server-.env setzen: DB_CONNECTION=mysql, DB_HOST, '
            .'DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD - danach '
            .'"php artisan config:clear" ausfuehren. '
            .'(Haeufigste Ursache: die .env wurde beim Deploy nicht mitgenommen '
            .'oder DB_CONNECTION ist auskommentiert - dann faellt Laravel still '
            .'auf SQLite zurueck.) '
            .'Nur wenn SQLite hier wirklich gewollt ist: '
            .'ALLOW_SQLITE_IN_PRODUCTION=true setzen.'
        );
    }
}
