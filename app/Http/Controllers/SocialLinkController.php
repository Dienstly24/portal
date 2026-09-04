<?php

namespace App\Http\Controllers;

use App\Models\BannerSocialChannel;

/**
 * Oeffentlicher Kurzlink-Redirect fuer Social-Media-Beitraege (/s/{code}):
 * zaehlt den Klick auf dem Plattform-Kanal und leitet zum oeffentlichen
 * Ziel weiter. Bewusst OHNE Banner::current()-Scope - ein einmal
 * veroeffentlichter Beitrag bleibt auf der Plattform dauerhaft klickbar,
 * auch wenn der Banner im Portal laengst beendet ist.
 */
class SocialLinkController extends Controller
{
    public function redirect(string $code)
    {
        $channel = BannerSocialChannel::with('post.banner')
            ->where('short_code', $code)
            ->firstOrFail();
        $channel->recordClick();

        $target = $channel->post?->target_url;
        if (! $target) {
            // Fallback: absolutes Banner-Ziel; Portal-interne Pfade sind fuer
            // Social-Besucher hinter der Login-Wand -> stattdessen Startseite.
            $bannerUrl = $channel->post?->banner?->link_url;
            $target = ($bannerUrl && preg_match('#^https?://#i', $bannerUrl))
                ? $bannerUrl
                : url('/');
        }

        return redirect()->away($this->withUtm($target, $channel));
    }

    /**
     * UTM-Parameter fuer die Auswertung ergaenzen (utm_source = Plattform).
     * Vorhandene utm_-Parameter werden respektiert, ein #Fragment bleibt
     * korrekt am Ende der URL.
     */
    private function withUtm(string $url, BannerSocialChannel $channel): string
    {
        if (stripos($url, 'utm_') !== false) {
            return $url;
        }
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $sep = str_contains($base, '?') ? '&' : '?';
        $query = http_build_query([
            'utm_source' => $channel->platform,
            'utm_medium' => 'social',
            'utm_campaign' => 'banner-'.($channel->post?->banner_id ?? 0),
        ]);

        return $base.$sep.$query.($fragment !== null ? '#'.$fragment : '');
    }
}
