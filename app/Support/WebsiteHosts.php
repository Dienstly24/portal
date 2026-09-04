<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Eine Quelle fuer die Host-Logik der Marketing-Website:
 * - Welche Hosts zeigen die Website (kanonisch + Staging)?
 * - Welche Hosts werden per 301 auf den kanonischen Host umgeleitet?
 * - Absolute URLs fuer canonical/hreflang/Sitemap (immer kanonischer Host).
 */
class WebsiteHosts
{
    /** Kanonischer Website-Host (www.dienstly24.de). */
    public static function canonical(): string
    {
        return (string) config('website.canonical_host');
    }

    /** Hosts, auf denen '/' die Marketing-Startseite rendert. */
    public static function serving(): array
    {
        return array_merge([self::canonical()], (array) config('website.extra_hosts'));
    }

    /** Zeigt dieser Request die Website (statt Portal/Beraterwelt)? */
    public static function isWebsiteRequest(Request $request): bool
    {
        return in_array(strtolower($request->getHost()), array_map('strtolower', self::serving()), true);
    }

    /** Muss dieser Host per 301 auf den kanonischen Host umgeleitet werden? */
    public static function needsRedirect(Request $request): bool
    {
        return in_array(strtolower($request->getHost()), array_map('strtolower', (array) config('website.redirect_hosts')), true);
    }

    /**
     * Absolute URL auf dem kanonischen Website-Host.
     * $path mit fuehrendem '/' ("/leistungen/kfz-versicherung", '/' = Start).
     */
    public static function url(string $path = '/'): string
    {
        $path = '/'.ltrim($path, '/');
        return 'https://'.self::canonical().($path === '/' ? '/' : $path);
    }

    /** Arabische Variante eines Pfads: '/' -> '/ar', '/x' -> '/ar/x'. */
    public static function arPath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        return $path === '/' ? '/ar' : '/ar'.$path;
    }
}
