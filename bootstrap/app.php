<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\ExtraBasicAuth;
use App\Http\Middleware\RedirectWebsiteHost;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetRequestLocale;
use App\Http\Middleware\TrackStaffActivity;
use App\Support\ErrorRecorder;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'forceLocale' => SetRequestLocale::class,
        ]);
        // Vertrauens-Proxy (Cloudflare + nginx vor PHP-FPM auf dem VPS): ohne
        // dies ignoriert $request->secure()/ip() die X-Forwarded-*-Header. Folge
        // waere (a) beim Cloudflare-Cutover ueber HTTP eine 301-Endlosschleife
        // im HTTPS-Redirect (RedirectWebsiteHost) -> gesamte Website down, und
        // (b) falsche IPs in ActivityLog/WorkSession und in ALLEN throttle-
        // Buckets (Login/Reset/Website-Formular) -> Rate-Limits kollabieren auf
        // wenige Proxy-IPs (Audit NET-1).
        //
        // Seit Audit SEC-2 ist die Liste EXPLIZIT statt '*'. Ein '*' glaubt
        // den Header auch dann, wenn die Anfrage direkt am Proxy vorbei auf
        // den Origin trifft - dann darf der Absender seine Client-IP frei
        // erfinden und bekommt fuer jeden Versuch einen frischen
        // Rate-Limit-Eimer, waehrend ActivityLog und die
        // DSGVO-Einwilligungsnachweise eine erfundene IP festhalten.
        // Standard: Cloudflare-Ranges + Loopback (config/trustedproxy.php),
        // ueberschreibbar per TRUSTED_PROXIES in der Server-.env.
        // Die Netzwerkseite steht in docs/SICHERHEIT_NETZWERK_ORIGIN.md -
        // Laravel allein kann den direkten Origin-Zugriff nicht schliessen.
        $middleware->trustProxies(at: TrustedProxies::resolve(), headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
        // abmelden/* (POST): RFC-8058-Ein-Klick-Abmeldung ist ein
        // Server-zu-Server-POST von Gmail/Yahoo ohne Session/CSRF-Token.
        $middleware->validateCsrfTokens(except: ['api/website-inquiry', 'api/website-contact', 'abmelden/*']);
        // Domain-Strategie der Website: Nicht-kanonische Hosts (ohne www,
        // .com, http) per 301 auf https://www.dienstly24.de umleiten.
        $middleware->prepend(RedirectWebsiteHost::class);
        // Defensive Sicherheitsheader auf jede Antwort.
        $middleware->append(SecurityHeaders::class);
        // Zusaetzliche Basic-Auth-Schichten: /admin (bis 2FA existiert)
        // und Staging-Hosts. No-Op ohne gesetzte Umgebungsvariablen.
        // Bewusst NACH SecurityHeaders angehaengt, damit auch die
        // 401-Challenge die Sicherheitsheader traegt.
        $middleware->append(ExtraBasicAuth::class);
        // Sprache (de/ar) je Kunde bzw. Session – nach StartSession.
        $middleware->appendToGroup('web', SetLocale::class);
        // Aktivitaetserfassung fuer Mitarbeiter: global in der Web-Gruppe,
        // damit sie serverseitig laeuft und nicht umgangen werden kann.
        $middleware->appendToGroup('web', TrackStaffActivity::class);
        // Sitzungen an den Passwort-Hash koppeln: aendert jemand sein
        // Passwort, sterben ALLE anderen offenen Sitzungen dieses Kontos
        // (gestohlenes Geraet, geteiltes Startpasswort, Magic-Link, der
        // weitergeleitet wurde). Ohne dies blieb eine fremde Sitzung nach
        // dem Passwortwechsel bis zum Session-Ablauf gueltig - der Wechsel
        // war also kein Rauswurf, sondern nur eine Umbenennung des
        // Schluessels. (Betreiber-Vorgabe 18.08.2026)
        $middleware->appendToGroup('web', AuthenticateSession::class);
        // Vom SYSTEM vergebene Passwoerter (Startpasswort = Geburtsdatum,
        // Admin-Reset, CLI) muessen beim naechsten Aufruf gegen ein
        // eigenes getauscht werden. NACH AuthenticateSession, damit die
        // Sitzungspruefung zuerst greift.
        $middleware->appendToGroup('web', EnsurePasswordChanged::class);
        // Zweiter Faktor fuer die Beraterwelt. NACH dem Passwortwechsel:
        // erst ein eigenes Passwort, dann die zweite Schicht - in der
        // umgekehrten Reihenfolge richtet jemand 2FA fuer ein Konto ein,
        // dessen Passwort noch das oeffentlich bekannte Geburtsdatum ist.
        $middleware->appendToGroup('web', EnsureTwoFactor::class);
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
        $exceptions->report(function (Throwable $e): void {
            ErrorRecorder::record($e);
        });
    })->create();
