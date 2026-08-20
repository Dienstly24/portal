<?php

namespace App\Jobs;

use App\Models\BannerSocialChannel;
use App\Services\Notifications\NotificationService;
use App\Services\Social\MetaPublisher;
use App\Support\Facades\Notify;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sofort-Veroeffentlichung eines Social-Kanals ueber die Meta-API - im
 * HINTERGRUND statt im Web-Request.
 *
 * Warum: der Instagram-Weg besteht aus Container anlegen, bis zu viermal auf
 * die Verarbeitung warten, veroeffentlichen und Permalink holen. Im
 * schlechtesten Fall sind das rund drei Minuten - laenger als jede uebliche
 * PHP-Laufzeitgrenze. Riss der Request dabei ab, konnte der Beitrag auf
 * Instagram bereits stehen, waehrend die App nichts davon wusste: der
 * naechste Klick postete ihn ein zweites Mal.
 *
 * KEIN Retry ($tries = 1): ein zweiter Anlauf koennte einen bereits
 * abgesetzten Beitrag doppelt veroeffentlichen. Nie-doppelt-posten geht vor
 * nice-to-have-Retry - dieselbe Regel wie im geplanten Versand. Ein erneuter
 * Versuch ist eine bewusste Mitarbeiter-Aktion.
 */
class PublishSocialChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Grosszuegig: der IG-Weg darf seine Wartezeiten ausschoepfen. */
    public int $timeout = 300;

    public function __construct(
        public int $channelId,
        public ?int $actorId = null,
    ) {
    }

    public function handle(MetaPublisher $publisher): void
    {
        $channel = BannerSocialChannel::with('post.banner')->find($this->channelId);
        if (! $channel) {
            return;
        }

        // Zwischenzeitlich schon draussen (geplanter Lauf, zweiter Klick)?
        // Dann NICHT erneut posten.
        if ($channel->external_post_id) {
            $channel->forceFill(['publish_started_at' => null])->save();

            return;
        }

        try {
            $publisher->publish($channel, $this->actorId);
            $this->melde($channel, true, null);
        } catch (\Throwable $e) {
            // Marker loesen, damit ein bewusster zweiter Versuch moeglich ist.
            $channel->forceFill([
                'publish_started_at' => null,
                'publish_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            Log::warning('Social-Sofortversand fehlgeschlagen (Kanal ' . $channel->id . '): ' . $e->getMessage());
            $this->melde($channel, false, $e->getMessage());
        }
    }

    /**
     * Der Job laeuft im Hintergrund - ohne Glocke erfaehrt der Mitarbeiter
     * nie, ob sein Klick etwas bewirkt hat.
     */
    private function melde(BannerSocialChannel $channel, bool $erfolg, ?string $fehler): void
    {
        try {
            $empfaenger = $this->actorId ?? (int) ($channel->post?->created_by ?? 0);
            if (! $empfaenger) {
                return;
            }

            $banner = $channel->post?->banner;
            $label = $channel->platformInfo()['label'] ?? $channel->platform;

            Notify::push((int) $empfaenger, [
                'type' => NotificationService::TYPE_SYSTEM,
                'title' => $erfolg
                    ? 'Social-Media: ' . $label . ' veröffentlicht'
                    : 'Social-Media: ' . $label . ' fehlgeschlagen',
                'body' => ($banner?->title ? $banner->title . ' – ' : '')
                    . ($erfolg ? 'Der Beitrag ist online.' : mb_substr((string) $fehler, 0, 200)),
                'link' => $banner ? route('admin.banners.social', $banner->id) : null,
                'dedup_key' => 'social-publish-' . $channel->id,
            ]);
        } catch (\Throwable $e) {
            // Die Glocke darf den Versand nie nachtraeglich scheitern lassen.
            Log::warning('Social-Glocke fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * Auch ein harter Abbruch (Worker getoetet, Zeitlimit) muss den Kanal
     * wieder freigeben - sonst bliebe er dauerhaft "in Arbeit".
     */
    public function failed(\Throwable $e): void
    {
        try {
            BannerSocialChannel::whereKey($this->channelId)
                ->whereNull('external_post_id')
                ->update([
                    'publish_started_at' => null,
                    'publish_error' => mb_substr('Abgebrochen: ' . $e->getMessage(), 0, 1000),
                ]);
        } catch (\Throwable $ignored) {
        }
    }
}
