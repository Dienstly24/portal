<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zweite Schicht vor der Beraterwelt: ohne zweiten Faktor kein /admin.
 *
 * Drei Zustaende, drei Wege:
 *  1. Konto hat noch keinen zweiten Faktor  -> Einrichtung (kein Aussperren:
 *     jeder kann sie selbst abschliessen).
 *  2. Eingerichtet, in dieser Sitzung aber noch nicht erbracht -> Abfrage.
 *  3. Erbracht -> durchlassen.
 *
 * Erreichbar bleiben in jedem Zustand: Abmelden, Sprachwechsel, die
 * 2FA-Seiten selbst und der erzwungene Passwortwechsel. Sonst sitzt
 * jemand in einer Sackgasse, die er nicht einmal verlassen kann.
 *
 * Kundenkonten sind bewusst ausgenommen (User::requiresTwoFactor()).
 */
class EnsureTwoFactor
{
    private const ALLOWED_ROUTES = [
        'two_factor.setup',
        'two_factor.setup.store',
        'two_factor.challenge',
        'two_factor.challenge.store',
        'two_factor.recovery_codes',
        'logout',
        'locale.switch',
        'legal',
        // Der Passwortwechsel ist die andere Pflicht-Station; die beiden
        // duerfen sich nicht gegenseitig blockieren.
        'password.forced',
        'password.forced.store',
    ];

    public function __construct(private TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->requiresTwoFactor() || ! self::enabled()) {
            return $next($request);
        }

        $route = $request->route()?->getName();
        if ($route !== null && in_array($route, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if (! $user->hasTwoFactor()) {
            return $this->stop($request, route('two_factor.setup'));
        }

        if (! $this->twoFactor->isVerified($request, $user)) {
            return $this->stop($request, route('two_factor.challenge'));
        }

        return $next($request);
    }

    /**
     * Notbremse fuer den Betreiber. Voreinstellung: AN. Anders als bei
     * anderen Schaltern ist AUS hier die Ausnahme - eine Sicherheitsschicht,
     * die man erst einschalten muss, ist meistens aus.
     */
    public static function enabled(): bool
    {
        return (string) \App\Models\SystemSetting::get('two_factor_required', '1') === '1';
    }

    private function stop(Request $request, string $target): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => __('Bitte bestätigen Sie zuerst die Zwei-Faktor-Anmeldung.'),
            ], 409);
        }

        return redirect()->to($target);
    }
}
