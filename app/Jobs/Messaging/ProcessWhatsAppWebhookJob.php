<?php

namespace App\Jobs\Messaging;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\ChannelEvent;
use App\Services\Messaging\Channels\WhatsAppAdapter;
use App\Services\Messaging\ConversationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Verarbeitung einer bereits GEPRUEFTEN WhatsApp-Zustellung.
 *
 * Die Signatur wurde im Controller geprueft; hier wird nur noch
 * normalisiert und an den Conversation Engine uebergeben.
 *
 * `tries = 3`: die Verarbeitung ist IDEMPOTENT (Ereignis-Register +
 * eindeutige externe Nachrichten-Kennung), ein Wiederholungsversuch
 * kann also keine zweite Nachricht erzeugen. Anders als beim Senden -
 * dort waere ein Retry ein zweiter Beitrag beim Kunden.
 */
class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param array<string,mixed> $payload */
    public function __construct(
        public int $accountId,
        public array $payload,
    ) {}

    public function handle(WhatsAppAdapter $adapter, ConversationEngine $engine): void
    {
        $account = ChannelAccount::find($this->accountId);
        $channel = Channel::where('key', WhatsAppAdapter::KEY)->first();

        if (! $account || ! $channel) {
            return;
        }

        // Zustellungen wiederholen sich - das Register laesst jede genau
        // einmal durch. Der Schluessel kommt aus der Nutzlast selbst.
        foreach ($adapter->parseInbound($this->payload, $account) as $inbound) {
            if ($inbound->externalMessageId
                && ! ChannelEvent::claim($account->id, 'msg:'.$inbound->externalMessageId, 'message')) {
                continue;
            }

            $engine->handleInbound($inbound, $channel, $account);
        }

        // VERLAUFS-ABSCHNITTE festhalten. Meta liefert den bisherigen
        // Schriftwechsel in Teilen und meldet den Fortschritt mit; ohne
        // diesen Vermerk waere spaeter nicht zu sagen, ob der Verlauf
        // vollstaendig angekommen ist - und die Oberflaeche muesste
        // behaupten, was sie nicht weiss.
        $this->rememberHistoryProgress($account);

        // Zustell- und Lesemeldungen. Sie sind eigene Ereignisse und
        // brauchen einen eigenen Schluessel - sonst wuerde die
        // Statusmeldung zu einer Nachricht als deren Duplikat gelten.
        foreach ($adapter->parseStatusUpdates($this->payload, $account) as $status) {
            $schluessel = 'status:'.$status['external_message_id'].':'.$status['status'];
            if (! ChannelEvent::claim($account->id, $schluessel, 'status')) {
                continue;
            }

            $engine->handleStatus(
                $status['external_message_id'],
                $status['status'],
                $status['reason'] ?? null
            );
        }
    }

    /**
     * Was von Meta ueber den Verlauf gemeldet wurde, am Konto vermerken.
     *
     * Bewusst nur die METADATEN (Abschnitt, Reihenfolge, Fortschritt) -
     * die Nachrichten selbst stehen in der Unterhaltung. Und bewusst
     * ohne Anspruch auf Vollstaendigkeit: Meta liefert hoechstens die
     * letzten 180 Tage und nur, wenn der Betrieb das Teilen bestaetigt
     * hat. Was wir haben, ist ein AUSSCHNITT - und die Oberflaeche sagt
     * das so.
     */
    private function rememberHistoryProgress(ChannelAccount $account): void
    {
        $abschnitte = [];
        foreach (($this->payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                foreach ((($change['value'] ?? [])['history'] ?? []) as $abschnitt) {
                    $abschnitte[] = $abschnitt['metadata'] ?? [];
                }
            }
        }

        if ($abschnitte === []) {
            return;
        }

        try {
            $stand = $account->settings ?? [];
            $verlauf = $stand['history'] ?? [];
            $verlauf['last_chunk_at'] = now()->toIso8601String();
            $verlauf['chunks'] = (int) ($verlauf['chunks'] ?? 0) + count($abschnitte);
            $letzte = end($abschnitte) ?: [];
            $verlauf['phase'] = $letzte['phase'] ?? ($verlauf['phase'] ?? null);
            $verlauf['progress'] = $letzte['progress'] ?? ($verlauf['progress'] ?? null);
            $stand['history'] = $verlauf;

            $account->forceFill(['settings' => $stand])->save();
        } catch (\Throwable) {
            // Der Vermerk darf den Empfang nie scheitern lassen - die
            // Nachrichten sind wichtiger als die Statistik darueber.
        }
    }
}
