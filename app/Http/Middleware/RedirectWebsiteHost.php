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
                'https://'.WebsiteHosts::canonical().$request->getRequestUri(),
                301
            );
        }

        if (! $request->secure()
            && strtolower($request->getHost()) === strtolower(WebsiteHosts::canonical())) {
            return redirect()->to(
                'https://'.WebsiteHosts::canonical().$request->getRequestUri(),
                301
            );
        }

        /*
         * Alt-URLs der Marketing-Seiten, die frueher unter
         * portal.dienstly24.de/leistungen/... erreichbar waren, auf den
         * kanonischen Host umleiten (Auftrag P1-4). Erst nach dem
         * DNS-Umzug aktiv (WEBSITE_MARKETING_REDIRECT), sonst zeigte die
         * Umleitung auf die statische Site, die diese Seiten nicht hat.
         * Der Login-/Portalbereich bleibt unberuehrt.
         */
        if (config('website.marketing_redirect')
            && ! WebsiteHosts::isWebsiteRequest($request)
            && $request->isMethod('GET')
            && $request->is(...(array) config('website.marketing_paths'))) {
            return redirect()->to(
                'https://'.WebsiteHosts::canonical().$request->getRequestUri(),
                301
            );
        }

        return $next($request);
    }
}
