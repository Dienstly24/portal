<?php

namespace App\Jobs\Messaging;

use App\Models\CustomerMessage;
use App\Services\Messaging\Channels\ChannelManager;
use App\Services\Messaging\Dto\OutboundMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ausgehende Nachricht an die Plattform (Auftrag Abschnitt 80).
 *
 * Der Weg ist derselbe fuer JEDEN Kanal: Nachricht -> Conversation
 * Engine -> Adapter. Wer hier steht, weiss nicht, ob es WhatsApp oder
 * Telegram wird - das entscheidet die Unterhaltung ueber ihren Kanal.
 *
 * `tries = 1` - dieselbe harte Regel wie beim Social-Versand: ein
 * zweiter Versuch koennte eine bereits zugestellte Nachricht ein
 * zweites Mal beim Kunden abliefern. Ein Fehlschlag wird am Datensatz
 * vermerkt und ist danach eine bewusste Mitarbeiter-Entscheidung.
 */
class SendOutboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $messageId) {}

    public function handle(ChannelManager $manager): void
    {
        $message = CustomerMessage::with('conversation.channel', 'conversation.channelAccount')
            ->find($this->messageId);

        $conversation = $message?->conversation;
        if (! $message || ! $conversation || ! $conversation->channel) {
            return;
        }

        // Bereits abgesetzt? Dann nichts tun - der Schutz gegen das
        // doppelte Senden liegt am Datensatz, nicht an der Queue.
        if ($message->external_message_id) {
            return;
        }

        if (! $manager->has($conversation->channel->key)) {
            $message->advanceStatus(CustomerMessage::STATUS_FAILED, 'Kein Adapter fuer diesen Kanal.');

            return;
        }

        $empfaenger = $conversation->external_user_id;
        if (! $empfaenger) {
            $message->advanceStatus(CustomerMessage::STATUS_FAILED, 'Keine Gegenstelle hinterlegt.');

            return;
        }

        $ergebnis = $manager->driver($conversation->channel->key)->send(
            new OutboundMessage(recipientId: $empfaenger, text: (string) $message->body),
            $conversation->channelAccount
        );

        if (! $ergebnis->ok) {
            $message->advanceStatus(CustomerMessage::STATUS_FAILED, $ergebnis->error);

            return;
        }

        // Die externe Kennung ist der WICHTIGSTE Rueckgabewert: nur mit
        // ihr lassen sich spaetere Zustell- und Lesemeldungen dieser
        // Nachricht zuordnen.
        $message->forceFill(['external_message_id' => $ergebnis->externalMessageId])->save();
        $message->advanceStatus(CustomerMessage::STATUS_SENT);
    }

    public function failed(\Throwable $e): void
    {
        CustomerMessage::where('id', $this->messageId)->whereNull('external_message_id')
            ->update([
                'status' => CustomerMessage::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => 'Versand abgebrochen.',
            ]);
    }
}
