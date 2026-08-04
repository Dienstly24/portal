<?php
namespace App\Services\Social;

use App\Models\BannerSocialChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Direktes Veroeffentlichen ueber die Meta Graph API (Phase 2):
 * - Facebook-Seite: Foto-Beitrag (Bild "quadrat" + Text inkl. Tracking-
 *   Link); ohne Bild (Video-Banner) ein Link-Beitrag.
 * - Instagram-Business: Container-Flow (media -> media_publish) mit dem
 *   1:1-Bild; Instagram AKZEPTIERT nur oeffentliche Bild-URLs - die
 *   erzeugten Formate liegen dafuer auf der public disk (APP_URL muss die
 *   echte Domain sein, lokal kann Meta nichts abrufen).
 * - TikTok bewusst NICHT: deren Content-API erfordert ein App-Audit.
 *
 * Konfiguration: config/services.php 'meta' (System-User-Token aus dem
 * Business Manager, laeuft nicht ab; NUR Server-.env). Fehler werden als
 * verstaendliche RuntimeException gemeldet und am Kanal gespeichert -
 * es wird NIE still erneut gepostet (Doppel-Post-Schutz).
 */
class MetaPublisher
{
    /** Plattformen, die per API bedient werden koennen. */
    public const AUTO_PLATFORMS = ['facebook', 'instagram'];

    /** Instagram-Limit fuer Bildunterschriften (Zeichen). */
    private const IG_CAPTION_MAX = 2200;

    public static function configuredFor(string $platform): bool
    {
        $cfg = config('services.meta', []);
        if (empty($cfg['token'])) {
            return false;
        }

        return match ($platform) {
            'facebook' => !empty($cfg['page_id']),
            'instagram' => !empty($cfg['ig_user_id']),
            default => false,
        };
    }

    /**
     * Veroeffentlicht den Kanal-Beitrag. Idempotent: ein bereits erstellter
     * Beitrag (external_post_id) wird nie erneut gepostet.
     * $actorId = ausloesender Mitarbeiter, null = geplanter Auto-Versand.
     */
    public function publish(BannerSocialChannel $channel, ?int $actorId = null): void
    {
        if ($channel->external_post_id) {
            return;
        }
        if (!in_array($channel->platform, self::AUTO_PLATFORMS, true)) {
            throw new \RuntimeException('Diese Plattform unterstuetzt kein API-Posten (nur manuell).');
        }
        if (!self::configuredFor($channel->platform)) {
            throw new \RuntimeException('Meta-API nicht konfiguriert (META_... in der Server-.env, Anleitung: docs/ANLEITUNG_META_API_AR.md).');
        }

        $post = $channel->post()->with('banner')->first();
        $banner = $post?->banner;
        if (!$banner) {
            throw new \RuntimeException('Zugehoeriger Banner nicht gefunden.');
        }

        $caption = $this->composeCaption($post, $channel);
        $imageUrl = $this->publicImageUrl($banner);

        [$externalId, $externalUrl] = $channel->platform === 'facebook'
            ? $this->publishFacebook($caption, $imageUrl, $channel->shortUrl())
            : $this->publishInstagram($caption, $imageUrl);

        $channel->forceFill([
            'external_post_id' => $externalId,
            'external_url' => $externalUrl,
            'publish_error' => null,
            'published_at' => now(),
            'published_by' => $actorId,
        ])->save();
    }

    /** Beitragstext: Deutsch + Arabisch + Tracking-Link (was da ist). */
    private function composeCaption($post, BannerSocialChannel $channel): string
    {
        $teile = array_filter([
            trim((string) $post->caption_de),
            trim((string) $post->caption_ar),
            $channel->shortUrl(),
        ]);

        return implode("\n\n", $teile);
    }

    /** Oeffentliche URL des 1:1-Formats (null, wenn keines existiert). */
    private function publicImageUrl($banner): ?string
    {
        $path = SocialFormatGenerator::path($banner, 'quadrat');

        return Storage::disk('public')->exists($path) ? asset('storage/' . $path) : null;
    }

    /** @return array{0:string,1:?string} [external_post_id, external_url] */
    private function publishFacebook(string $caption, ?string $imageUrl, string $fallbackLink): array
    {
        $pageId = config('services.meta.page_id');

        if ($imageUrl) {
            $resp = $this->call('post', $pageId . '/photos', [
                'url' => $imageUrl,
                'message' => $caption,
            ]);
        } else {
            // Video-Banner ohne Bildformate: Link-Beitrag (Text + Kurzlink).
            $resp = $this->call('post', $pageId . '/feed', [
                'message' => $caption,
                'link' => $fallbackLink,
            ]);
        }

        $externalId = (string) ($resp['post_id'] ?? $resp['id'] ?? '');
        if ($externalId === '') {
            throw new \RuntimeException('Meta-API: Antwort ohne Beitrags-ID.');
        }

        return [$externalId, 'https://www.facebook.com/' . $externalId];
    }

    /** @return array{0:string,1:?string} [external_post_id, external_url] */
    private function publishInstagram(string $caption, ?string $imageUrl): array
    {
        if (!$imageUrl) {
            throw new \RuntimeException('Instagram benoetigt ein Bild - dieses Banner hat keine erzeugten Bildformate (Video?).');
        }
        if (mb_strlen($caption) > self::IG_CAPTION_MAX) {
            // Nie still kuerzen - der Betreiber entscheidet, was wegfaellt.
            throw new \RuntimeException('Beitragstext fuer Instagram zu lang (max. ' . self::IG_CAPTION_MAX . ' Zeichen inkl. Link) - bitte kuerzen.');
        }

        $igUserId = config('services.meta.ig_user_id');

        $container = $this->call('post', $igUserId . '/media', [
            'image_url' => $imageUrl,
            'caption' => $caption,
        ]);
        $creationId = (string) ($container['id'] ?? '');
        if ($creationId === '') {
            throw new \RuntimeException('Meta-API: Instagram-Container ohne ID.');
        }

        $published = $this->call('post', $igUserId . '/media_publish', [
            'creation_id' => $creationId,
        ]);
        $mediaId = (string) ($published['id'] ?? '');
        if ($mediaId === '') {
            throw new \RuntimeException('Meta-API: Instagram-Veroeffentlichung ohne Media-ID.');
        }

        // Permalink ist Komfort - ein Fehler hier macht den Post nicht kaputt.
        $externalUrl = null;
        try {
            $media = $this->call('get', $mediaId, ['fields' => 'permalink']);
            $externalUrl = $media['permalink'] ?? null;
        } catch (\Throwable $e) {
        }

        return [$mediaId, $externalUrl];
    }

    /** Graph-API-Aufruf mit verstaendlicher Fehlermeldung. */
    private function call(string $method, string $path, array $params): array
    {
        $url = 'https://graph.facebook.com/'
            . config('services.meta.graph_version', 'v23.0') . '/' . ltrim($path, '/');
        $params['access_token'] = (string) config('services.meta.token');

        try {
            $resp = $method === 'get'
                ? Http::timeout(25)->get($url, $params)
                : Http::timeout(25)->asForm()->post($url, $params);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Meta-API nicht erreichbar: ' . $e->getMessage());
        }

        $json = $resp->json() ?? [];
        if ($resp->failed() || isset($json['error'])) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $resp->status());
            throw new \RuntimeException('Meta-API: ' . $msg);
        }

        return $json;
    }
}
