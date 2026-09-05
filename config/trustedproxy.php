<?php

/*
|--------------------------------------------------------------------------
| Vertrauenswuerdige Proxys (Audit SEC-2)
|--------------------------------------------------------------------------
|
| Wem glauben wir den X-Forwarded-For-Header?
|
| Bis 18.08.2026 stand hier faktisch "jedem" (trustProxies(at: '*')). Das
| ist genau so lange harmlos, wie der Origin ausschliesslich ueber den
| Proxy erreichbar ist. Ist er es NICHT - und das laesst sich im Repository
| nicht beweisen, siehe docs/SICHERHEIT_NETZWERK_ORIGIN.md -, dann darf
| jeder, der die Server-IP kennt, seine eigene Client-IP frei erfinden:
|
|   curl -H 'X-Forwarded-For: 1.2.3.4' https://<origin-ip>/login
|
| Damit bekommt er (a) fuer jeden Versuch einen frischen Rate-Limit-Eimer
| (Login-, Reset- und Registrierungs-Bremse sind wirkungslos) und (b)
| schreibt eine frei gewaehlte IP in ActivityLog und in die
| DSGVO-Einwilligungsnachweise (CustomerConsent.ip_address) - also
| ausgerechnet in die Datensaetze, die im Streitfall etwas belegen sollen.
|
| Deshalb: eine EXPLIZITE Liste. Standard sind die veroeffentlichten
| Cloudflare-Ranges plus Loopback fuer den nginx auf derselben Maschine.
|
| NACHTRAG 05.09.2026 - die Cloudflare-Annahme ist gemessen und WIDERLEGT:
| die Antwort von www.dienstly24.de traegt "server: hcdn" und keinen
| cf-ray-Header, der Edge ist also das CDN des Hosters. Die Liste bleibt
| trotzdem stehen: sie ist zu KLEIN, nicht zu gross - eine zu kleine Liste
| glaubt zu wenigen Absendern und ist damit nie unsicher. Falsch werden
| kann nur die FACHLICHE Seite: reicht der Vorschalt-Dienst von einer
| externen, nicht gelisteten Adresse weiter, sehen alle Besucher dieselbe
| IP (ein gemeinsamer Rate-Limit-Eimer, CDN-Adresse im
| Einwilligungsnachweis). Ob das der Fall ist, sagt
|   php artisan netz:client-ip-pruefen
| auf dem Server; die dort genannte Adresse gehoert dann in
| TRUSTED_PROXIES. Adressbereiche eines CDN werden NIE geraten.
|
| Abweichende Infrastruktur wird ueber TRUSTED_PROXIES in der Server-.env
| gesetzt (kommagetrennte IPs/CIDRs). Der Wert "*" ist weiterhin moeglich,
| aber eine bewusste, dokumentierte Entscheidung - kein Standard mehr.
|
*/

return [

    /*
    | Kommagetrennte Liste aus der .env. Leer = die Standardliste unten.
    | Sonderwert "*" = allen Proxys vertrauen (nur mit Firewall davor!).
    */
    'proxies' => env('TRUSTED_PROXIES', ''),

    /*
    | Cloudflare IPv4/IPv6-Ranges, Stand 2026-09.
    | Quelle: https://www.cloudflare.com/ips/
    | Pflege: scripts/update-cloudflare-ips.sh haelt diese Liste aktuell.
    */
    'cloudflare' => [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],

    /*
    | Der Reverse-Proxy auf derselben Maschine (nginx -> php-fpm).
    | Ohne ihn verliert ein Aufbau ohne Cloudflare die echte Client-IP.
    */
    'local' => [
        '127.0.0.1',
        '::1',
    ],

];
