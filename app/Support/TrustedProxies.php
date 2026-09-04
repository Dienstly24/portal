<?php

namespace App\Support;

use Illuminate\Container\Container;

/**
 * Loest die Liste der vertrauenswuerdigen Proxys auf (Audit SEC-2).
 *
 * Eine Stelle, damit bootstrap/app.php, die Systemzustand-Seite und die
 * Tests dieselbe Antwort bekommen. Wer den X-Forwarded-For-Header setzen
 * darf, entscheidet ueber die Echtheit JEDER Client-IP in dieser App:
 * Rate-Limit-Eimer, ActivityLog und die DSGVO-Einwilligungsnachweise
 * haengen alle an $request->ip().
 */
class TrustedProxies
{
    /**
     * Die konfigurierte Liste.
     *
     * WICHTIG: Diese Methode laeuft in bootstrap/app.php, also BEVOR der
     * Container den `config`-Dienst kennt. Ein Aufruf von config() dort
     * endet in "Target class [config] does not exist" - und zwar bei
     * JEDEM artisan-Aufruf, also auch beim Deploy. Deshalb wird die
     * Konfiguration hier notfalls direkt aus der Datei gelesen.
     *
     * @return array<int,string>|string Liste von IPs/CIDRs oder '*'
     */
    public static function resolve(): array|string
    {
        $configured = trim((string) self::setting('proxies', ''));

        if ($configured === '*') {
            // Ausdrueckliche Betreiber-Entscheidung. Nur vertretbar, wenn
            // die Firewall den Origin auf die Proxy-Ranges einschraenkt.
            return '*';
        }

        if ($configured !== '') {
            $list = array_values(array_filter(array_map(
                'trim',
                explode(',', $configured)
            ), fn (string $v): bool => $v !== ''));

            if ($list !== []) {
                return $list;
            }
        }

        return self::defaults();
    }

    /**
     * Standard: Cloudflare-Ranges + Loopback. Deckt den dokumentierten
     * Aufbau (Client -> Cloudflare -> nginx -> php-fpm) ab.
     *
     * @return array<int,string>
     */
    public static function defaults(): array
    {
        return array_values(array_unique(array_merge(
            (array) self::setting('cloudflare', []),
            (array) self::setting('local', []),
        )));
    }

    /**
     * Ein Wert aus config/trustedproxy.php - ueber den Container, wenn er
     * schon steht, sonst direkt aus der Datei.
     */
    private static function setting(string $key, mixed $default): mixed
    {
        $app = Container::getInstance();

        if ($app !== null && $app->bound('config')) {
            return $app->make('config')->get('trustedproxy.'.$key, $default);
        }

        return self::fromFile()[$key] ?? $default;
    }

    /** @var array<string,mixed>|null */
    private static ?array $file = null;

    /** @return array<string,mixed> */
    private static function fromFile(): array
    {
        if (self::$file !== null) {
            return self::$file;
        }

        $path = dirname(__DIR__, 2).'/config/trustedproxy.php';
        $config = is_file($path) ? require $path : [];

        return self::$file = is_array($config) ? $config : [];
    }

    /**
     * Vertraut die Anwendung derzeit JEDEM Proxy? Die Systemzustand-Seite
     * macht daraus eine sichtbare Warnung - eine stillschweigend offene
     * Header-Annahme faellt sonst niemandem auf.
     */
    public static function trustsEveryone(): bool
    {
        return self::resolve() === '*';
    }
}
