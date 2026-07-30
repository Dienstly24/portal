<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Erzwingt die Sprache fuer URL-basierte Sprachversionen der Website
 * (z. B. alle Routen unter /ar/...). Laeuft als Routen-Middleware NACH
 * SetLocale und gewinnt daher gegen Session-/Kundenwahl - die URL ist
 * fuer Suchmaschinen und geteilte Links die verbindliche Quelle.
 */
class SetRequestLocale
{
    public function handle(Request $request, Closure $next, string $locale): Response
    {
        if (in_array($locale, ['de', 'ar'], true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
