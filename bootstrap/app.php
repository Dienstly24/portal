<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserRole::class,
            'forceLocale' => \App\Http\Middleware\SetRequestLocale::class,
        ]);
        // Vertrauens-Proxy (Cloudflare + nginx vor PHP-FPM auf dem VPS): ohne
        // dies ignoriert $request->secure()/ip() die X-Forwarded-*-Header. Folge
        // waere (a) beim Cloudflare-Cutover ueber HTTP eine 301-Endlosschleife
        // im HTTPS-Redirect (RedirectWebsiteHost) -> gesamte Website down, und
        // (b) falsche IPs in ActivityLog/WorkSession und in ALLEN throttle-
        // Buckets (Login/Reset/Website-Formular) -> Rate-Limits kollabieren auf
        // wenige Proxy-IPs (Audit NET-1).
        //
        // SICHERHEIT (Audit NET-2): Default '*' vertraut dem unmittelbaren Peer
        // und liest X-Forwarded-For. Ist der Origin DIREKT erreichbar (VPS-IP
        // ohne Origin-Firewall), kann ein Angreifer die Client-IP faelschen ->
        // Rate-Limit-Umgehung + gefaelschte Consent-/Log-IPs. In Produktion
        // daher TRUSTED_PROXIES auf die Cloudflare-Ranges (bzw. die echten
        // Proxy-IPs) setzen; nur der Origin muss dann noch auf CF eingeschraenkt
        // werden. Leer/'*' = bisheriges Verhalten (nur hinter echtem Proxy sicher).
        $trustedProxies = env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(
            at: in_array($trustedProxies, ['*', '**'], true)
                ? $trustedProxies
                : array_values(array_filter(array_map('trim', explode(',', (string) $trustedProxies)))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );
        // abmelden/* (POST): RFC-8058-Ein-Klick-Abmeldung ist ein
        // Server-zu-Server-POST von Gmail/Yahoo ohne Session/CSRF-Token.
        $middleware->validateCsrfTokens(except: ['api/website-inquiry', 'api/website-contact', 'abmelden/*']);
        // Domain-Strategie der Website: Nicht-kanonische Hosts (ohne www,
        // .com, http) per 301 auf https://www.dienstly24.de umleiten.
        $middleware->prepend(\App\Http\Middleware\RedirectWebsiteHost::class);
        // Defensive Sicherheitsheader auf jede Antwort.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Zusaetzliche Basic-Auth-Schichten: /admin (bis 2FA existiert)
        // und Staging-Hosts. No-Op ohne gesetzte Umgebungsvariablen.
        // Bewusst NACH SecurityHeaders angehaengt, damit auch die
        // 401-Challenge die Sicherheitsheader traegt.
        $middleware->append(\App\Http\Middleware\ExtraBasicAuth::class);
        // Sprache (de/ar) je Kunde bzw. Session – nach StartSession.
        $middleware->appendToGroup('web', \App\Http\Middleware\SetLocale::class);
        // Aktivitaetserfassung fuer Mitarbeiter: global in der Web-Gruppe,
        // damit sie serverseitig laeuft und nicht umgangen werden kann.
        $middleware->appendToGroup('web', \App\Http\Middleware\TrackStaffActivity::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
