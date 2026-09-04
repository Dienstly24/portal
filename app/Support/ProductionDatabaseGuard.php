<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\ConnectionEstablished;
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
 * Ein lautes Scheitern ist hier die milde Variante: die Ursache steht in
 * der Meldung, statt dass wochenlang niemand etwas merkt.
 *
 * WANN GEPRUEFT WIRD - und warum nicht beim Booten: die Pruefung haengt am
 * ERSTEN ECHTEN DATENBANK-ZUGRIFF, nicht am Start der Anwendung. Beim
 * Booten war sie zu frueh und hat einen ganz normalen Vorgang zerstoert:
 * `composer install` fuehrt `artisan package:discover` aus, und das
 * passiert BEVOR eine .env existiert. Ohne .env ist Laravels Vorgabe fuer
 * APP_ENV aber "production" - die Pruefung schlug also bei jeder frischen
 * Installation an, in CI wie auf dem Server, obwohl niemand eine Datenbank
 * anfassen wollte.
 *
 * Am Verbindungsaufbau ist die Regel dagegen genau richtig gesetzt: wer
 * keine Datenbank braucht (package:discover, config:cache, Assets bauen),
 * merkt nichts; wer eine Anfrage bedient oder migriert, faellt sofort auf.
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

    /**
     * Haengt die Pruefung an den ersten Verbindungsaufbau. Aufruf beim
     * Booten - geprueft wird aber erst, wenn wirklich eine Datenbank
     * geoeffnet wird.
     */
    public static function registrieren(Application $app): void
    {
        if (! $app->isProduction()) {
            return;
        }

        $app['events']->listen(
            ConnectionEstablished::class,
            static fn () => static::pruefen($app),
        );
    }

    public static function pruefen(Application $app): void
    {
        if (! $app->isProduction()) {
            return;
        }

        // NOCH GAR NICHT EINGERICHTET: ohne .env ist Laravels Vorgabe fuer
        // APP_ENV "production" - eine frische Arbeitskopie sieht also wie
        // Produktion aus, ist aber keine. Genau dort laeuft `composer
        // install` mit package:discover, und das darf nicht scheitern.
        //
        // Der Fall kostet hier auch nichts: fehlt die .env auf einem echten
        // Server, gibt es keinen APP_KEY und JEDE Anfrage bricht sofort ab -
        // das ist bereits laut. Der stille Fall, um den es dieser Pruefung
        // geht, ist die VORHANDENE .env mit fehlendem oder auskommentiertem
        // DB_CONNECTION - dann faellt Laravel unbemerkt auf SQLite zurueck.
        if (! file_exists($app->environmentFilePath())) {
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
