<?php

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\User;

/**
 * Links in Mails, die ein NICHT angemeldeter Besucher ausloesen kann
 * (Passwort vergessen, Registrierung bestaetigen), duerfen nie dem
 * Host-Header der Anfrage folgen.
 *
 * WARUM (Audit 12.11.2026): `route()` baut absolute Adressen aus dem Host
 * der laufenden Anfrage. Nimmt der Webserver beliebige Host-Header an
 * (Standard-vHost), genuegt `POST /forgot-password` mit
 * `Host: angreifer.example` und der Kennung des Opfers: die ECHTE Mail
 * von Dienstly24 traegt dann einen Link auf den fremden Host - samt
 * gueltigem Reset-Token. Ein Klick, und das Token liegt beim Angreifer.
 *
 * Regel: ist der Host der Anfrage einer der EIGENEN (APP_URL, Portal-/
 * Admin-Adresse aus den Einstellungen, Website-Hosts), bleibt alles wie
 * bisher. Sonst wird der Link auf die fest hinterlegte Adresse gebaut.
 * Bewusst KEINE harte Ablehnung fremder Hosts (trustHosts): ein
 * vergessener eigener Host waere dann ein Totalausfall der Seite.
 */
final class VertrauterHost
{
    /** Absolute Adresse einer benannten Route - nie auf einem fremden Host. */
    public static function route(string $name, array $parameter = [], ?User $fuer = null): string
    {
        if (self::anfrageIstVertraut()) {
            return route($name, $parameter);
        }

        return self::basis($fuer).route($name, $parameter, false);
    }

    /** Laeuft die Anfrage auf einem der eigenen Hosts? Ohne Anfrage (Konsole, Queue): ja - dort gilt APP_URL. */
    public static function anfrageIstVertraut(): bool
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return true;
        }

        $host = strtolower((string) request()->getHost());

        return $host === '' || in_array($host, self::eigeneHosts(), true);
    }

    /** @return list<string> */
    public static function eigeneHosts(): array
    {
        $hosts = array_merge(
            [parse_url((string) config('app.url'), PHP_URL_HOST)],
            [parse_url(self::portalAdresse(), PHP_URL_HOST), parse_url(self::adminAdresse(), PHP_URL_HOST)],
            WebsiteHosts::serving(),
            (array) config('website.redirect_hosts'),
            (array) config('website.staging_hosts'),
        );

        return array_values(array_unique(array_map(
            'strtolower',
            array_filter($hosts, fn ($h) => is_string($h) && $h !== '')
        )));
    }

    /** Feste Basisadresse: Kunden ins Portal, alle anderen in die Beraterwelt. */
    private static function basis(?User $fuer): string
    {
        $adresse = $fuer !== null && $fuer->role !== 'customer' ? self::adminAdresse() : self::portalAdresse();

        return rtrim($adresse, '/');
    }

    private static function portalAdresse(): string
    {
        return (string) SystemSetting::get('portal_url', 'https://portal.dienstly24.de');
    }

    private static function adminAdresse(): string
    {
        return (string) SystemSetting::get('admin_url', 'https://admin.dienstly24.de');
    }
}
