<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zwei zusaetzliche Schutzschichten (Betreiber-Bedingung 30.07.2026):
 *
 * 1. /admin hinter zusaetzlicher HTTP-Basic-Auth (ADMIN_BASIC_AUTH):
 *    Die Medienverwaltung laedt Dateien auf den Server - ein einzelnes
 *    geleaktes Login-Passwort darf dafuer nicht reichen. Diese Schicht
 *    ist die Uebergangsloesung, bis echtes 2FA umgesetzt ist; sie
 *    ergaenzt das normale Login, ersetzt es nicht.
 *
 * 2. Staging-Hosts komplett hinter Basic-Auth (STAGING_HOSTS +
 *    STAGING_BASIC_AUTH) inkl. noindex-Header - damit der Betreiber die
 *    neue Website VOR dem DNS-Umzug auf einer Vorschau-Domain
 *    durchklicken kann, ohne dass sie oeffentlich oder indexierbar ist.
 *
 * Ohne gesetzte Umgebungsvariablen ist die Middleware ein No-Op.
 * Hinweis Apache/FPM: Authorization-Header muss durchgereicht werden
 * (siehe docs/WEBSITE_MERGE_UMSETZUNG.md).
 */
class ExtraBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $stagingHosts = array_map('strtolower', (array) config('website.staging_hosts'));
        $isStagingHost = in_array($host, $stagingHosts, true);

        if ($isStagingHost && ($cred = (string) config('website.staging_basic_auth')) !== '') {
            if (! $this->authorized($request, $cred)) {
                return $this->challenge('Dienstly24 Staging');
            }
        }

        if (($cred = (string) config('website.admin_basic_auth')) !== ''
            && $request->is('admin', 'admin/*')) {
            if (! $this->authorized($request, $cred)) {
                return $this->challenge('Dienstly24 Adminbereich');
            }
        }

        $response = $next($request);

        // Staging darf nie im Index landen - unabhaengig vom Seitentyp.
        if ($isStagingHost) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /** Vergleicht "benutzer:passwort" zeitkonstant mit den Request-Credentials. */
    private function authorized(Request $request, string $cred): bool
    {
        [$user, $pass] = array_pad(explode(':', $cred, 2), 2, '');

        return hash_equals($user, (string) $request->getUser())
            && hash_equals($pass, (string) $request->getPassword());
    }

    private function challenge(string $realm): Response
    {
        return response('Zugriff geschuetzt - Anmeldung erforderlich.', 401, [
            'WWW-Authenticate' => 'Basic realm="'.$realm.'", charset="UTF-8"',
        ]);
    }
}
