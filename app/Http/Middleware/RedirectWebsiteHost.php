<?php

namespace App\Http\Middleware;

use App\Support\WebsiteHosts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain-Strategie der Website (Arbeitsauftrag 30.07.2026):
 * dienstly24.de (ohne www), dienstly24.com und www.dienstly24.com werden
 * per 301 auf https://www.dienstly24.de umgeleitet - Pfad und Query bleiben
 * erhalten. http->https auf dem kanonischen Host uebernimmt zusaetzlich
 * der Webserver/Cloudflare; hier ist die App-seitige Absicherung.
 */
class RedirectWebsiteHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (WebsiteHosts::needsRedirect($request)) {
            return redirect()->to(
                'https://' . WebsiteHosts::canonical() . $request->getRequestUri(),
                301
            );
        }

        if (! $request->secure()
            && strtolower($request->getHost()) === strtolower(WebsiteHosts::canonical())) {
            return redirect()->to(
                'https://' . WebsiteHosts::canonical() . $request->getRequestUri(),
                301
            );
        }

        return $next($request);
    }
}
