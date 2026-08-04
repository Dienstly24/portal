<?php
namespace App\Services\Social;

use App\Models\BannerSocialChannel;
use Illuminate\Support\Facades\Cache;

/**
 * Kennzahlen aus der Meta-API (Phase 3), damit der Betreiber Meta nicht
 * oeffnen muss:
 * - je veroeffentlichtem Beitrag: Likes, Kommentare, Shares, Reichweite
 *   (gespeichert als channels.insights, Zeitstempel insights_refreshed_at)
 * - Seiten-Ueberblick: Follower, "Gefaellt mir", Seitenaufrufe 28 Tage
 *   (im Cache, wird vom Statistik-Dashboard NUR gelesen - kein API-Aufruf
 *   beim Seitenaufbau)
 * Aktualisierung: Command social:refresh-insights (alle 6 Stunden) oder
 * Button "Zahlen aktualisieren" auf der Social-Seite.
 */
class MetaInsightsService
{
    public const PAGE_CACHE_KEY = 'meta_page_insights';

    public function __construct(private MetaGraphClient $graph)
    {
    }

    /** Kennzahlen EINES veroeffentlichten Kanals holen und speichern. */
    public function refreshChannel(BannerSocialChannel $channel): void
    {
        if (!$channel->external_post_id || !MetaPublisher::configuredFor($channel->platform)) {
            return;
        }

        $insights = $channel->platform === 'facebook'
            ? $this->facebookPost($channel->external_post_id)
            : $this->instagramMedia($channel->external_post_id);

        $channel->forceFill([
            'insights' => $insights,
            'insights_refreshed_at' => now(),
        ])->save();
    }

    /** Seiten-Ueberblick holen und cachen (fuer das Statistik-Dashboard). */
    public function refreshPageOverview(): void
    {
        if (!MetaPublisher::configuredFor('facebook')) {
            return;
        }
        $pageId = (string) config('services.meta.page_id');

        $page = $this->graph->get($pageId, ['fields' => 'followers_count,fan_count,name']);

        $views = 0;
        try {
            $resp = $this->graph->get($pageId . '/insights', [
                'metric' => 'page_views_total',
                'period' => 'days_28',
            ]);
            $values = $resp['data'][0]['values'] ?? [];
            $views = (int) (end($values)['value'] ?? 0);
        } catch (\Throwable $e) {
            // Seitenaufrufe sind Komfort - Follower/Fans reichen als Karte.
        }

        Cache::put(self::PAGE_CACHE_KEY, [
            'name' => $page['name'] ?? '',
            'followers' => (int) ($page['followers_count'] ?? 0),
            'fans' => (int) ($page['fan_count'] ?? 0),
            'page_views_28d' => $views,
            'refreshed_at' => now()->toDateTimeString(),
        ], now()->addDays(3));
    }

    /** @return array{likes:int,comments:int,shares:int,reach:int} */
    private function facebookPost(string $postId): array
    {
        $post = $this->graph->get($postId, [
            'fields' => 'reactions.summary(true).limit(0),comments.summary(true).limit(0),shares',
        ]);

        $reach = 0;
        try {
            $resp = $this->graph->get($postId . '/insights', ['metric' => 'post_impressions_unique']);
            $reach = (int) ($resp['data'][0]['values'][0]['value'] ?? 0);
        } catch (\Throwable $e) {
        }

        return [
            'likes' => (int) ($post['reactions']['summary']['total_count'] ?? 0),
            'comments' => (int) ($post['comments']['summary']['total_count'] ?? 0),
            'shares' => (int) ($post['shares']['count'] ?? 0),
            'reach' => $reach,
        ];
    }

    /** @return array{likes:int,comments:int,shares:int,reach:int} */
    private function instagramMedia(string $mediaId): array
    {
        $media = $this->graph->get($mediaId, ['fields' => 'like_count,comments_count']);

        $reach = 0;
        try {
            $resp = $this->graph->get($mediaId . '/insights', ['metric' => 'reach']);
            $reach = (int) ($resp['data'][0]['values'][0]['value'] ?? 0);
        } catch (\Throwable $e) {
        }

        return [
            'likes' => (int) ($media['like_count'] ?? 0),
            'comments' => (int) ($media['comments_count'] ?? 0),
            'shares' => 0, // Instagram liefert keine Share-Zahl
            'reach' => $reach,
        ];
    }
}
