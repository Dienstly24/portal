<?php

namespace App\Jobs\Messaging;

use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Services\Messaging\Channels\ChannelManager;
use App\Services\Messaging\Dto\OutboundMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

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
        $message = CustomerMessage::with('conversation.channel', 'conversation.channelAccount', 'attachments')
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

        $treiber = $manager->driver($conversation->channel->key);
        $kannMedien = (bool) $conversation->channel->supports('supportsMedia');

        // Die Dateien werden HIER gelesen, nicht im Adapter: wo eine
        // Datei liegt, ist Sache der Anwendung - der Adapter kennt nur
        // Bytes. So bleibt er von unserer Ablage unabhaengig.
        $dateien = $kannMedien
            ? $message->attachments->map(fn (CustomerMessageAttachment $a) => $this->datei($a))->filter()->values()
            : collect();

        // EINE Datei je Nachricht ist die Regel der Plattform. Mehrere
        // Anhaenge werden deshalb NACHEINANDER gesendet; der Text haengt
        // an der ERSTEN, sonst stuende er unter jedem Bild erneut.
        $sendungen = $dateien->isEmpty()
            ? [[null, (string) $message->body]]
            : $dateien->map(fn ($d, $i) => [$d, $i === 0 ? (string) $message->body : ''])->all();

        $ersteKennung = null;

        foreach ($sendungen as [$datei, $text]) {
            $ergebnis = $treiber->send(
                new OutboundMessage(
                    recipientId: $empfaenger,
                    text: $text,
                    type: $datei ? 'media' : 'text',
                    attachments: $datei ? [$datei] : [],
                    lastInboundAt: $conversation->lastInboundAt(),
                ),
                $conversation->channelAccount
            );

            if (! $ergebnis->ok) {
                // Teilerfolg EHRLICH melden: was raus ist, ist raus. Die
                // Nachricht als "fehlgeschlagen" zu fuehren, ohne das zu
                // sagen, waere die schlechtere Luege - ein Mitarbeiter
                // wuerde erneut senden und der Kunde bekaeme alles doppelt.
                $message->advanceStatus(
                    CustomerMessage::STATUS_FAILED,
                    $ersteKennung
                        ? 'Teilweise gesendet - ein weiterer Anhang schlug fehl: '.$ergebnis->error
                        : $ergebnis->error
                );

                if ($ersteKennung) {
                    $message->forceFill(['external_message_id' => $ersteKennung])->save();
                }

                return;
            }

            // Die externe Kennung ist der WICHTIGSTE Rueckgabewert: nur
            // mit ihr lassen sich spaetere Zustell- und Lesemeldungen
            // dieser Nachricht zuordnen. Bei mehreren Sendungen zaehlt
            // die erste - sie traegt den Text.
            $ersteKennung ??= $ergebnis->externalMessageId;
        }

        $message->forceFill(['external_message_id' => $ersteKennung])->save();
        $message->advanceStatus(CustomerMessage::STATUS_SENT);
    }

    /**
     * Anhang als Bytes - oder null, wenn die Datei (noch) nicht da ist.
     *
     * Ein fehlender Anhang darf den Versand nicht zum Absturz bringen;
     * er wird uebersprungen, und der Text geht trotzdem raus.
     *
     * @return array{contents:string,mime_type:string,file_name:string}|null
     */
    private function datei(CustomerMessageAttachment $anhang): ?array
    {
        $platte = $anhang->disk ?: 'local';

        try {
            if (! $anhang->file_path || ! Storage::disk($platte)->exists($anhang->file_path)) {
                return null;
            }

            return [
                'contents' => (string) Storage::disk($platte)->get($anhang->file_path),
                'mime_type' => $anhang->mimeType(),
                'file_name' => $anhang->file_name ?: 'datei',
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
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
