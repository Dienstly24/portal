<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * Die Sprachen, die diese Anwendung kennt - an EINER Stelle.
 *
 * Warum es das braucht (KI-014, 23.09.2026): `APP_LOCALE` stand in der
 * Vorlage auf `en`. Web-Anfragen merkten davon nichts, weil `SetLocale`
 * dort immer de oder ar setzt. Konsole und Warteschlange laufen aber ohne
 * Middleware - geplante Laeufe, Importe und gequeuete Mails arbeiteten auf
 * Englisch. Eine .env aus der alten Vorlage bleibt auf dem Server liegen;
 * deshalb wird die Sprache beim Start geprueft, statt sich auf die .env zu
 * verlassen.
 */
final class Sprache
{
    public const UNTERSTUETZT = ['de', 'ar'];

    public const STANDARD = 'de';

    public static function istUnterstuetzt(?string $sprache): bool
    {
        return in_array($sprache, self::UNTERSTUETZT, true);
    }

    /** Stellt eine unbekannte Sprache (z.B. "en") auf Deutsch. */
    public static function erzwingen(Application $app): void
    {
        $sprache = (string) $app['config']->get('app.locale');
        if (self::istUnterstuetzt($sprache)) {
            return;
        }

        $app['config']->set('app.locale', self::STANDARD);
        $app->setLocale(self::STANDARD);
    }
}
