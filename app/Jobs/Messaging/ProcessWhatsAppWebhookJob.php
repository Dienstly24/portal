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
}
