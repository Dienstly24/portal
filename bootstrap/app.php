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
        // wenige Proxy-IPs (Audit NET-1). Die App ist nur ueber den Proxy
        // erreichbar; ist der Origin direkt erreichbar, auf CF-Ranges einengen.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
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
        // Sitzungen an den Passwort-Hash koppeln: aendert jemand sein
        // Passwort, sterben ALLE anderen offenen Sitzungen dieses Kontos
        // (gestohlenes Geraet, geteiltes Startpasswort, Magic-Link, der
        // weitergeleitet wurde). Ohne dies blieb eine fremde Sitzung nach
        // dem Passwortwechsel bis zum Session-Ablauf gueltig - der Wechsel
        // war also kein Rauswurf, sondern nur eine Umbenennung des
        // Schluessels. (Betreiber-Vorgabe 18.08.2026)
        $middleware->appendToGroup('web', \Illuminate\Session\Middleware\AuthenticateSession::class);
        // Vom SYSTEM vergebene Passwoerter (Startpasswort = Geburtsdatum,
        // Admin-Reset, CLI) muessen beim naechsten Aufruf gegen ein
        // eigenes getauscht werden. NACH AuthenticateSession, damit die
        // Sitzungspruefung zuerst greift.
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsurePasswordChanged::class);
        // Zweiter Faktor fuer die Beraterwelt. NACH dem Passwortwechsel:
        // erst ein eigenes Passwort, dann die zweite Schicht - in der
        // umgekehrten Reihenfolge richtet jemand 2FA fuer ein Konto ein,
        // dessen Passwort noch das oeffentlich bekannte Geburtsdatum ist.
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureTwoFactor::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        // Jeden unerwarteten Fehler zusaetzlich in error_events festhalten,
        // damit er auf /admin/systemzustand sichtbar wird. Die Logdatei
        // bleibt die ausfuehrliche Quelle (Stacktrace) - sie oeffnet im
        // Alltag nur niemand, und genau deshalb blieb bisher jeder 500er
        // unbemerkt, bis sich ein Kunde beschwert hat.
        // Der Recorder schluckt eigene Fehler; die normale Behandlung
        // (Logdatei, Fehlerseite) laeuft danach unveraendert weiter.
        $exceptions->report(function (\Throwable $e): void {
            \App\Support\ErrorRecorder::record($e);
        });
    })->create();
