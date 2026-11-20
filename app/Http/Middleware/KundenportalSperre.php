<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Kundenportal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serverseitige Sperre des Kundenportals (Betreiber-Vorgabe 20.11.2026,
 * keine Erlaubnis nach Paragraph 34d GewO). Schalter:
 * `CUSTOMER_PORTAL_ENABLED` (config/portal.php). Ist er AN, tut diese
 * Middleware NICHTS.
 *
 * Bewusst SERVERSEITIG und nicht durch ausgeblendete Knoepfe: ein Link aus
 * einer alten Mail, ein Lesezeichen oder eine noch offene Sitzung kaemen
 * sonst weiterhin hinein.
 *
 * Drei Stufen:
 *  1. Eine bestehende KUNDENSITZUNG wird beendet (abmelden, Sitzung
 *     verwerfen, "angemeldet bleiben" vergessen) - nichts wird geloescht.
 *  2. Kunden-Wege werden abgewiesen (Seiten -> Wartungsseite 503,
 *     JSON -> 503). Anmeldung und "Passwort vergessen" bleiben auf dem
 *     Host der Beraterwelt fuer das Personal erreichbar; nur auf dem
 *     Portal-Host sind sie gesperrt. Passwort-Wege mit Kundenbezug werden
 *     am KONTO erkannt, nicht am Host.
 *  3. NACHLAUF: hat sich waehrend DIESER Anfrage doch ein Kunde
 *     angemeldet (Anmeldeformular auf dem Admin-Host, Passwort setzen,
 *     ein kuenftiger Weg, den hier niemand kennt), wird er sofort wieder
 *     abgemeldet. Das ist die Rueckfallebene fuer jeden Weg, den Stufe 2
 *     nicht namentlich kennt.
 *
 * Protokoll: Kanal `portal_sperre`, NUR Grund, Methode, Pfad ohne
 * Query-String, Routenname und ggf. die Konto-ID - keine IP, keine
 * Formularfelder (Datenminimierung).
 */
class KundenportalSperre
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Kundenportal::aktiv()) {
            return $next($request);
        }

        $user = $request->user();
        if ($user !== null && $user->role === 'customer') {
            $this->abmelden($request);
            $this->protokoll($request, 'kundensitzung_beendet', $user->id);
        }

        if (($grund = $this->gesperrterWeg($request)) !== null) {
            $this->protokoll($request, $grund);

            return $this->gesperrt($request);
        }

        $response = $next($request);

        $nachher = Auth::guard('web')->user();
        if ($nachher !== null && $nachher->role === 'customer') {
            $this->abmelden($request);
            $this->protokoll($request, 'kundenanmeldung_verhindert', $nachher->id);

            return $this->gesperrt($request);
        }

        return $response;
    }

    /** Liefert den Sperrgrund oder null, wenn der Weg offen bleibt. */
    private function gesperrterWeg(Request $request): ?string
    {
        if ($request->is('portal', 'portal/*')) {
            return 'portal';
        }
        if ($request->is('magic-login/*')) {
            return 'magic_link';
        }
        if ($request->is('register', 'register/*')) {
            return 'registrierung';
        }

        if ($this->istPortalHost($request)
            && ($request->is('/') || $request->is('login') || $request->is('forgot-password', 'forgot-password/*'))) {
            return 'anmeldung_portal_host';
        }

        // Passwort-Wege: am KONTO erkannt. Das Personal braucht sie weiter.
        if ($request->is('reset-password', 'reset-password/*')) {
            $email = (string) ($request->input('email') ?? $request->query('email', ''));
            if ($email !== '' && $this->istKundenkonto(User::where('email', $email)->first())) {
                return 'passwort_reset';
            }
        }
        if ($request->is('zugang/passwort-festlegen/*')) {
            $id = $request->route('user');
            if ($id !== null && $this->istKundenkonto(User::find($id))) {
                return 'einladungslink';
            }
        }

        return null;
    }

    private function istKundenkonto(?User $user): bool
    {
        return $user !== null && $user->role === 'customer';
    }

    private function istPortalHost(Request $request): bool
    {
        $portalHost = parse_url((string) SystemSetting::get('portal_url', 'https://portal.dienstly24.de'), PHP_URL_HOST);

        return is_string($portalHost) && strcasecmp($portalHost, $request->getHost()) === 0;
    }

    private function abmelden(Request $request): void
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    private function gesperrt(Request $request): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'ok' => false,
                'fehler' => 'kundenportal_nicht_verfuegbar',
                'nachricht' => 'Unser Kundenportal ist vorübergehend nicht verfügbar.',
                'chat' => url('/chat'),
                'whatsapp' => Kundenportal::whatsappLink(),
            ], 503, ['Retry-After' => '3600', 'Cache-Control' => 'no-store']);
        }

        return response()
            ->view('errors.portal-gesperrt', [], 503)
            ->header('Retry-After', '3600')
            ->header('Cache-Control', 'no-store, private');
    }

    private function protokoll(Request $request, string $grund, int|string|null $userId = null): void
    {
        try {
            Log::channel('portal_sperre')->info('Kundenportal-Zugriff blockiert', [
                'grund' => $grund,
                'methode' => $request->method(),
                'pfad' => '/'.ltrim($request->path(), '/'),
                'route' => $request->route()?->getName(),
                'konto_id' => $userId,
            ]);
        } catch (\Throwable) {
            // Das Protokollieren darf die Sperre nie aufhalten.
        }
    }
}
