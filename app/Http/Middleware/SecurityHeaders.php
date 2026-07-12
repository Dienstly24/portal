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

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
