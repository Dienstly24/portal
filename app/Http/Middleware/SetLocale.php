<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setzt die App-Sprache pro Request:
 * 1. eingeloggter Kunde -> bevorzugte Sprache aus der Kundenakte
 * 2. sonst -> Session-Wahl (Sprachumschalter auf Login/Registrierung)
 * 3. Fallback Deutsch.
 * Unterstützt: de, ar (Arabisch inkl. RTL im Portal-Layout).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        $user = $request->user();
        if ($user && $user->role === 'customer') {
            $locale = $user->customer?->preferred_lang;
        }

        $locale = $locale ?: $request->session()->get('locale');

        // Fallback Deutsch: ohne aktive de/ar-Wahl blieb die App bisher auf
        // APP_LOCALE=en stehen - oeffentliche deutsche Seiten (Login, Website,
        // Leistungsseiten) lieferten dann <html lang="en"> und ein Google
        // widersprechendes hreflang-Signal (Audit I18N-1). Jetzt immer eine
        // der unterstuetzten Sprachen setzen.
        app()->setLocale(in_array($locale, ['de', 'ar'], true) ? $locale : 'de');

        return $next($request);
    }
}
