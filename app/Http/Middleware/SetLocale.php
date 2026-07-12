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

        if (in_array($locale, ['de', 'ar'], true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
