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

        // Content-Security-Policy (Audit SEC-1): moderate Defense-in-Depth-Schicht
        // gegen XSS/Clickjacking. Inline-Styles/-Handler sind derzeit noch noetig
        // (grosse Blade-Flaeche), daher 'unsafe-inline'; object/base/frame sind
        // hart eingeschraenkt. Nur auf HTML-Antworten setzen (nicht auf
        // PDF-/CSV-Downloads). Fonts von bunny.net sind explizit erlaubt.
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
                    "img-src 'self' data: https:",
                    "font-src 'self' data: https://fonts.bunny.net",
                    // form.partner-versicherung.de: eingebetteter Tarifcheck-
                    // Vergleichsrechner (Zwei-Klick-Einwilligung auf den
                    // Leistungsseiten, config/vergleichsrechner.php).
                    "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://form.partner-versicherung.de",
                    // 'unsafe-eval' ist noetig, weil Alpine.js v3 (Standard-Build)
                    // seine Direktiven (x-data/x-show/@click) per Function()
                    // auswertet. Ohne dies bricht Alpine still und Dropdowns/Menues
                    // (z. B. das ...-Aktionsmenue der Kundenliste) bleiben offen.
                    "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://form.partner-versicherung.de",
                    "connect-src 'self' https://form.partner-versicherung.de",
                    "frame-src 'self' https://form.partner-versicherung.de https://www.tarifcheck.de",
                ]));
            }
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
