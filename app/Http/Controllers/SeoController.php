<?php

namespace App\Http\Controllers;

use App\Models\ServicePage;
use App\Support\WebsiteHosts;
use Illuminate\Http\Request;

/**
 * Dynamische robots.txt + sitemap.xml (Arbeitsauftrag P1-6).
 *
 * robots.txt haengt vom Host ab: Auf dem Website-Host ist die Seite fuer
 * Suchmaschinen offen (Anwendungsbereiche ausgenommen); auf portal./admin.
 * ist ALLES gesperrt - Marketing-Inhalte leben nur noch auf dem
 * Haupt-Domain, das Portal ist reiner Login-Bereich.
 *
 * Die Sitemap wird aus den echten Inhalten erzeugt (Startseite DE/AR,
 * Leistungsseiten aus der Datenbank mit echtem lastmod, Rechtsseiten) -
 * nie von Hand gepflegt.
 */
class SeoController extends Controller
{
    public function robots(Request $request)
    {
        // NUR der kanonische Host ist fuer Suchmaschinen offen - Staging-/
        // Vorschau-Hosts (WEBSITE_EXTRA_HOSTS) zeigen zwar die Website,
        // duerfen aber nie indexiert werden (Duplicate Content).
        if (strtolower($request->getHost()) === strtolower(WebsiteHosts::canonical())) {
            $lines = [
                'User-agent: *',
                'Disallow: /admin',
                'Disallow: /login',
                'Disallow: /register',
                'Disallow: /portal',
                'Disallow: /partner',
                '',
                'Sitemap: '.WebsiteHosts::url('/sitemap.xml'),
            ];
        } else {
            $lines = ['User-agent: *', 'Disallow: /'];
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap()
    {
        $urls = [];
        $add = function (string $path, ?string $lastmod, bool $withAr = true) use (&$urls) {
            $urls[] = [
                'loc' => WebsiteHosts::url($path),
                'lastmod' => $lastmod,
                'alternates' => $withAr ? [
                    'de' => WebsiteHosts::url($path),
                    'ar' => WebsiteHosts::url(WebsiteHosts::arPath($path)),
                    'x-default' => WebsiteHosts::url($path),
                ] : [],
            ];
            if ($withAr) {
                $urls[] = [
                    'loc' => WebsiteHosts::url(WebsiteHosts::arPath($path)),
                    'lastmod' => $lastmod,
                    'alternates' => [
                        'de' => WebsiteHosts::url($path),
                        'ar' => WebsiteHosts::url(WebsiteHosts::arPath($path)),
                        'x-default' => WebsiteHosts::url($path),
                    ],
                ];
            }
        };

        // Startseite: lastmod = echte letzte Aenderung des Templates.
        $homeMtime = @filemtime(resource_path('views/website/home.blade.php')) ?: null;
        $add('/', $homeMtime ? date('Y-m-d', $homeMtime) : null);

        // Leistungen: Uebersicht + aktive Seiten mit echtem lastmod aus der DB.
        $pages = ServicePage::active()->ordered()->get();
        $newest = $pages->max('updated_at');
        $add('/leistungen', $newest?->format('Y-m-d'));
        foreach ($pages as $page) {
            $add('/leistungen/'.$page->slug, $page->updated_at?->format('Y-m-d'));
        }

        // Rechtsseiten (nur Deutsch).
        foreach (array_keys(WebsiteController::LEGAL_PAGES) as $slug) {
            $view = resource_path('views/website/legal/'.str_replace('-', '_', $slug).'.blade.php');
            $mtime = @filemtime($view) ?: null;
            $add('/'.$slug, $mtime ? date('Y-m-d', $mtime) : null, withAr: false);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
        foreach ($urls as $u) {
            $xml .= "  <url>\n    <loc>".e($u['loc'])."</loc>\n";
            foreach ($u['alternates'] as $lang => $href) {
                $xml .= '    <xhtml:link rel="alternate" hreflang="'.$lang.'" href="'.e($href)."\"/>\n";
            }
            if ($u['lastmod']) {
                $xml .= '    <lastmod>'.$u['lastmod']."</lastmod>\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
