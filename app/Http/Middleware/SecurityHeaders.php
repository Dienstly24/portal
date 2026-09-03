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
        // Ein frischer Nonce JE ANFRAGE (Audit SEC-4). Muss vor dem
        // Rendern der Antwort geschehen, damit Header und HTML denselben
        // Wert tragen - stimmen sie nicht ueberein, blockiert der Browser
        // jedes eingebettete Skript und die Seite bleibt stumm.
        // Ohne das Zuruecksetzen truege in einem langlebigen Prozess
        // (Tests, Octane) jede weitere Antwort den Nonce der ersten - ein
        // wiederverwendeter Nonce ist kein Schutz mehr.
        \App\Support\CspNonce::reset();
        \Illuminate\Support\Facades\Vite::useCspNonce(\App\Support\CspNonce::get());

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

        // Content-Security-Policy (Audit SEC-1, verschaerft mit SEC-4).
        //
        // Bis SEC-4 stand hier `script-src 'self' 'unsafe-inline' 'unsafe-eval'`.
        // Damit war die Richtlinie gegen XSS praktisch wirkungslos:
        // 'unsafe-inline' erlaubt jedes eingeschleuste <script>, und
        // 'unsafe-eval' zusaetzlich Function()/eval().
        //
        // Jetzt: NONCE statt 'unsafe-inline'. Nur Skripte, die den
        // Zufallswert dieser Antwort tragen, laufen. Und kein
        // 'unsafe-eval' mehr - Alpine.js, das es brauchte, ist durch
        // eigenes JavaScript ersetzt (resources/js/ui.js).
        //
        // Nur auf HTML-Antworten setzen (nicht auf PDF-/CSV-Downloads).
        if (! $response->headers->has('Content-Security-Policy')
            && ! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $contentType = (string) $response->headers->get('Content-Type');
            $isHtml = $contentType === '' || str_contains($contentType, 'text/html');
            if ($isHtml) {
                $header = config('security.csp_report_only', false)
                    ? 'Content-Security-Policy-Report-Only'
                    : 'Content-Security-Policy';

                $response->headers->set($header, $this->policy());
            }
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Die Richtlinie als Zeichenkette.
     *
     * Getrennte Methode, damit der Sicherheitstest sie pruefen kann,
     * ohne eine Anfrage zu stellen.
     */
    public function policy(): string
    {
        $nonce = "'nonce-" . \App\Support\CspNonce::get() . "'";

        // Turnstile (Bot-Schutz der Registrierung, Audit SEC-1) ist der
        // EINZIGE fremde Skript-Host. Er steht hier ausdruecklich und
        // nicht als Platzhalter: jeder erlaubte Fremdhost ist ein
        // moeglicher Weg fuer eingeschleusten Inhalt.
        $turnstile = 'https://challenges.cloudflare.com';

        return implode('; ', array_filter([
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            // Turnstile rendert sein Widget in einem iframe.
            "frame-src 'self' " . $turnstile,
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
            // Kein externer Schrift-Host: alle Schriften liegen lokal in
            // public/fonts. Ein Host, der nicht gebraucht wird, gehoert
            // nicht in die Freigabe - er waere ein moeglicher Weg fuer
            // eingeschleusten Inhalt und (bei Schriften) ein
            // DSGVO-Thema.
            "font-src 'self' data:",
            // style-src behaelt 'unsafe-inline' - bewusst und
            // dokumentiert: die Anwendung traegt mehrere tausend
            // style="..."-Attribute. Ein Nonce hilft dort nicht (ein
            // Attribut kann keinen tragen), die Alternative waere eine
            // vollstaendige Neufassung der Oberflaeche. Das Risiko ist
            // deutlich kleiner als bei Skripten: aus einem Inline-Style
            // laesst sich kein Code ausfuehren, hoechstens die
            // Darstellung veraendern. Der Weg dahin steht in
            // docs/SICHERHEIT_SEC_1_BIS_5.md.
            "style-src 'self' 'unsafe-inline'",
            // Kein 'unsafe-inline', kein 'unsafe-eval'.
            "script-src 'self' " . $nonce . ' ' . $turnstile,
            // Attribut-Handler (onclick="...") sind damit ausgeschlossen
            // und bleiben es auch: sie koennen keinen Nonce tragen.
            "script-src-attr 'none'",
            "connect-src 'self'",
            config('security.csp_report_uri')
                ? 'report-uri ' . config('security.csp_report_uri')
                : null,
        ]));
    }

}
