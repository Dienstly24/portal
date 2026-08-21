<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setzt defensive HTTP-Sicherheitsheader auf jede Antwort:
 * - nosniff:        verhindert MIME-Sniffing
 * - SAMEORIGIN:     schützt vor Clickjacking (kein Einbetten in fremde Seiten)
 * - Referrer-Policy: keine vollständigen URLs an Drittseiten
 * - Permissions-Policy: schaltet nicht benötigte Browser-APIs ab
 * - HSTS:           nur über HTTPS – erzwingt künftige TLS-Verbindungen
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // Anwendungsbereiche (Beraterwelt, Login, Kunden-/Partnerportal) haben
        // in Suchmaschinen nichts verloren - zusaetzlich zur robots.txt auch
        // als Header, damit es fuer bereits bekannte URLs verbindlich ist.
        if ($request->is('admin', 'admin/*', 'login', 'register', 'portal', 'portal/*', 'partner', 'partner/*', 'password/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        // Content-Security-Policy (Audit SEC-1): moderate Defense-in-Depth-Schicht
        // gegen XSS/Clickjacking. Inline-Styles/-Handler sind derzeit noch noetig
        // (grosse Blade-Flaeche), daher 'unsafe-inline'; object/base/frame sind
        // hart eingeschraenkt. Nur auf HTML-Antworten setzen (nicht auf
        // PDF-/CSV-Downloads). Externe Hosts sind NICHT freigegeben.
        if (! $response->headers->has('Content-Security-Policy')) {
            $contentType = (string) $response->headers->get('Content-Type');
            $isHtml = $contentType === '' || str_contains($contentType, 'text/html');
            if ($isHtml) {
                $response->headers->set('Content-Security-Policy', implode('; ', [
                    "default-src 'self'",
                    "base-uri 'self'",
                    "object-src 'none'",
                    "frame-ancestors 'self'",
                    "form-action 'self'",
                    // blob: ist noetig, damit die Seite selbst erzeugte Bilder
                    // anzeigen und verarbeiten kann: der Dokumenten-Scanner im
                    // Kundenportal (Seiten-Vorschau + Verkleinern aufs JPEG),
                    // das Zaehlerfoto und die Banner-Vorschau nutzen alle
                    // URL.createObjectURL(). Ohne blob: bricht die Bild-
                    // verarbeitung still ab (Konsole: "Refused to load the
                    // image"). Sicherheitlich unbedenklich: blob:-URLs sind
                    // same-origin und koennen nur von bereits laufendem
                    // Seiten-Skript erzeugt werden - kein zusaetzlicher
                    // XSS-Weg, im Gegensatz zu einer fremden Host-Freigabe.
                    "img-src 'self' data: blob: https:",
                    // Kein externer Schrift-Host mehr: die Google-/Bunny-Fonts
                    // sind raus, alle Schriften liegen lokal in public/fonts.
                    // Ein Host, der nicht mehr gebraucht wird, gehoert auch
                    // nicht mehr in die Freigabe - jeder erlaubte Fremdhost
                    // ist ein moeglicher Weg fuer eingeschleusten Inhalt und
                    // (bei Schriften) ein DSGVO-Thema.
                    "font-src 'self' data:",
                    "style-src 'self' 'unsafe-inline'",
                    // 'unsafe-eval' ist noetig, weil Alpine.js v3 (Standard-Build)
                    // seine Direktiven (x-data/x-show/@click) per Function()
                    // auswertet. Ohne dies bricht Alpine still und Dropdowns/Menues
                    // (z. B. das ...-Aktionsmenue der Kundenliste) bleiben offen.
                    "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                    "connect-src 'self'",
                ]));
            }
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
