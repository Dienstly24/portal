<?php

namespace App\Console\Commands;

use App\Models\BannerSocialChannel;
use App\Services\Notifications\NotificationService;
use App\Services\Social\MetaPublisher;
use App\Support\Facades\Notify;
use Illuminate\Console\Command;

/**
 * Geplante Social-Media-Posts automatisch veroeffentlichen (Phase 2):
 * Kanaele (Facebook/Instagram) faelliger Posts (scheduled_for erreicht)
 * werden ueber die Meta Graph API gepostet. Laeuft alle 15 Minuten.
 *
 * Regeln:
 * - Genau EIN Auto-Versuch je Kanal (auto_attempted_at wird VOR dem
 *   API-Aufruf gesetzt): schlaegt er fehl, meldet die Glocke den Fehler
 *   und ein erneuter Versuch ist eine bewusste Mitarbeiter-Aktion -
 *   nie-doppelt-posten geht vor nice-to-have-Retry.
 * - Manuell als veroeffentlicht markierte Kanaele (published_at gesetzt)
 *   und bereits erstellte Beitraege (external_post_id) werden nie
 *   angefasst.
 * - Ohne Meta-Konfiguration wird uebersprungen OHNE den Versuch zu
 *   verbrauchen - nach dem Eintragen der .env-Werte holt der naechste
 *   Lauf die faelligen Posts nach.
 */
class PublishScheduledSocialPosts extends Command
{
    protected $signature = 'social:publish-scheduled';

    protected $description = 'Faellige Social-Media-Posts ueber die Meta-API veroeffentlichen';

    public function handle(MetaPublisher $publisher): int
    {
        $due = BannerSocialChannel::query()
            ->whereNull('external_post_id')
            ->whereNull('published_at')
            ->whereNull('auto_attempted_at')
            ->whereIn('platform', MetaPublisher::AUTO_PLATFORMS)
            ->whereHas('post', fn ($q) => $q->whereNotNull('scheduled_for')->where('scheduled_for', '<=', now()))
            ->with('post.banner')
            ->get();

        $ok = 0;
        $failed = 0;
        foreach ($due as $channel) {
            if (!MetaPublisher::configuredFor($channel->platform)) {
                $this->line($channel->platform . ': Meta-API nicht konfiguriert - uebersprungen.');
                continue;
            }

            $label = $channel->platformInfo()['label'];
            $banner = $channel->post?->banner;
            $link = $banner ? route('admin.banners.social', $banner->id) : null;
            $empfaenger = (int) ($channel->post?->created_by ?? 0);

            // Versuch VOR dem API-Aufruf verbrauchen (Doppel-Post-Schutz,
            // auch wenn der Prozess mitten im Aufruf abbrechen sollte).
            $channel->forceFill(['auto_attempted_at' => now()])->save();

            try {
                $publisher->publish($channel, null);
                $ok++;
                $this->info($label . ': veroeffentlicht (' . ($banner?->title ?? '?') . ').');
                if ($empfaenger) {
                    Notify::push($empfaenger, [
                        'type' => NotificationService::TYPE_SYSTEM,
                        'title' => 'Social-Media: automatisch veroeffentlicht',
                        'body' => $label . ' - "' . ($banner?->title ?? '') . '"',
                        'link' => $link,
                        'dedup_key' => 'social-auto-ok-' . $channel->id,
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                $channel->forceFill(['publish_error' => $e->getMessage()])->save();
                $this->error($label . ': ' . $e->getMessage());
                if ($empfaenger) {
                    Notify::push($empfaenger, [
                        'type' => NotificationService::TYPE_SYSTEM,
                        'title' => 'Social-Media: Veroeffentlichung fehlgeschlagen',
                        'body' => $label . ' - "' . ($banner?->title ?? '') . '": ' . $e->getMessage(),
                        'link' => $link,
                        'dedup_key' => 'social-auto-fail-' . $channel->id,
                    ]);
                }
            }
        }

        $this->info('Fertig: ' . $ok . ' veroeffentlicht, ' . $failed . ' fehlgeschlagen, ' . $due->count() . ' faellig.');

        return self::SUCCESS;
    }
}
