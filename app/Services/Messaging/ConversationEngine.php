<?php

namespace App\Services\Messaging;

use App\Events\Messaging\ConversationCreated;
use App\Events\Messaging\InboundMessageReceived;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Services\Messaging\Dto\InboundMessage;
use Illuminate\Support\Facades\DB;

/**
 * Der Kern. Er nimmt eine bereits NORMALISIERTE Nachricht entgegen und
 * macht daraus Unterhaltung, Nachricht, Anhaenge und Zustaendigkeit.
 *
 * HIER DARF KEIN KANAL BEIM NAMEN VORKOMMEN. Keine Bedingung der Form
 * "wenn WhatsApp, dann ...", kein Feld, das es nur bei einer Plattform
 * gibt. Was ein Kanal kann, steht in `channels.capabilities`; wie er es
 * tut, im Adapter. `MessagingArchitectureTest` prueft diese Regel - eine
 * Architekturregel, die niemand misst, haelt keine sechs Monate.
 */
class ConversationEngine
{
    public function __construct(
        private readonly CustomerResolver $customers,
        private readonly AssignmentService $assignments,
    ) {}

    /**
     * Eine eingegangene Nachricht verarbeiten.
     *
     * IDEMPOTENT: dieselbe Plattform-Nachricht ergibt beim zweiten
     * Aufruf dieselbe Zeile, keine neue. Webhooks werden von jeder
     * Plattform mehrfach zugestellt; ohne diese Eigenschaft saehe der
     * Kunde sich selbst doppelt im Chat.
     *
     * @return CustomerMessage|null null = bewusst nichts gespeichert
     *                              (leeres Ereignis oder Wiederholung)
     */
    public function handleInbound(
        InboundMessage $inbound,
        Channel $channel,
        ?ChannelAccount $account = null,
    ): ?CustomerMessage {
        if ($inbound->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($inbound, $channel, $account) {
            $customer = $this->customers->resolve($inbound, $channel, $account);
            $conversation = $this->locate($inbound, $channel, $account, $customer);

            // Wiederholte Zustellung: dieselbe externe Kennung in
            // derselben Unterhaltung ist dieselbe Nachricht.
            if ($inbound->externalMessageId) {
                $vorhanden = CustomerMessage::where('conversation_id', $conversation->id)
                    ->where('external_message_id', $inbound->externalMessageId)
                    ->first();
                if ($vorhanden) {
                    return null;
                }
            }

            $message = CustomerMessage::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $customer?->id,
                'direction' => CustomerMessage::DIRECTION_INCOMING,
                'sender_type' => CustomerMessage::SENDER_CUSTOMER,
                'external_message_id' => $inbound->externalMessageId,
                'message_type' => $inbound->type,
                'body' => (string) $inbound->text,
                'status' => CustomerMessage::STATUS_DELIVERED,
                'metadata' => $inbound->metadata ?: null,
                'delivered_at' => now(),
            ]);

            foreach ($inbound->attachments as $anhang) {
                CustomerMessageAttachment::create([
                    'message_id' => $message->id,
                    // Datei kommt spaeter ueber den Adapter - der Anhang
                    // existiert als Datensatz aber sofort, sonst waere er
                    // bei einem Fehlschlag des Abrufs komplett verloren.
                    'file_name' => $anhang->fileName ?: 'anhang',
                    'file_path' => '',
                    'type' => $anhang->type,
                    'mime_type' => $anhang->mimeType,
                    'file_size' => $anhang->fileSize,
                    'external_media_id' => $anhang->externalMediaId,
                ]);
            }

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                // Eine Kundenantwort holt eine geschlossene Unterhaltung
                // zurueck: der Kunde schreibt weiter, also ist der Vorgang
                // nicht erledigt. Ein ARCHIV bleibt dagegen Archiv - es ist
                // eine bewusste Entscheidung eines Menschen.
                'status' => $conversation->archived_at
                    ? $conversation->status
                    : Conversation::STATUS_OPEN,
                'reopened_at' => $conversation->status === Conversation::STATUS_CLOSED
                    ? now() : $conversation->reopened_at,
            ])->save();

            $this->assignments->autoAssign($conversation);

            event(new InboundMessageReceived($conversation, $message));

            return $message;
        });
    }

    /**
     * Zustellmeldung einer Plattform verarbeiten. Ohne die externe
     * Kennung ist keine Zuordnung moeglich - dann passiert bewusst
     * nichts, statt die falsche Nachricht zu markieren.
     */
    public function handleStatus(string $externalMessageId, string $status, ?string $reason = null): bool
    {
        $message = CustomerMessage::where('external_message_id', $externalMessageId)->first();
        if (! $message || ! $message->advanceStatus($status, $reason)) {
            return false;
        }

        event(new MessageStatusChanged($message, $status));

        return true;
    }

    /**
     * Die Unterhaltung finden oder anlegen.
     *
     * Die Regel ist KANALBEWUSST, ohne den Kanal zu kennen: nennt die
     * Plattform eine eigene Unterhaltungs-Kennung, gilt sie; sonst ist
     * die Unterhaltung durch Konto und Gegenstelle bestimmt. Beides ohne
     * eine einzige Bedingung auf einen Kanalnamen.
     */
    public function locate(
        InboundMessage $inbound,
        Channel $channel,
        ?ChannelAccount $account,
        ?Customer $customer,
    ): Conversation {
        $query = Conversation::where('channel_id', $channel->id)
            ->when($account, fn ($q) => $q->where('channel_account_id', $account->id));

        $conversation = $inbound->externalConversationId
            ? (clone $query)->where('external_conversation_id', $inbound->externalConversationId)->first()
            : (clone $query)->where('external_user_id', $inbound->externalUserId)
                // Eine ARCHIVIERTE Unterhaltung wird nicht fortgesetzt -
                // sie wurde bewusst abgelegt. Eine neue Nachricht beginnt
                // dann einen neuen Vorgang, statt das Archiv zu stoeren.
                ->whereNull('archived_at')
                ->latest('last_message_at')->first();

        if ($conversation) {
            // Die Akte kann sich NACHTRAEGLICH klaeren (der Mitarbeiter
            // legt sie an, die Nummer wird bekannt). Nur ERGAENZEN, nie
            // ueberschreiben - eine bestehende Zuordnung ist eine
            // menschliche Entscheidung.
            if ($customer && ! $conversation->customer_id) {
                $conversation->forceFill(['customer_id' => $customer->id])->save();
            }

            return $conversation;
        }

        $conversation = Conversation::create([
            'customer_id' => $customer?->id,
            'channel_id' => $channel->id,
            'channel_account_id' => $account?->id,
            'external_conversation_id' => $inbound->externalConversationId,
            'external_user_id' => $inbound->externalUserId,
            'status' => Conversation::STATUS_OPEN,
        ]);

        event(new ConversationCreated($conversation));

        return $conversation;
    }

    /**
     * Die Unterhaltung eines Kunden in einem Kanal - fuer AUSGEHENDE
     * Nachrichten, die kein Webhook ausloest (Mitarbeiter schreibt
     * zuerst). Ohne diesen Weg entstuende beim ersten Schreiben keine
     * Unterhaltung und die Nachricht haette kein Zuhause.
     */
    public function forCustomer(Customer $customer, Channel $channel, ?ChannelAccount $account = null): Conversation
    {
        $conversation = Conversation::where('customer_id', $customer->id)
            ->where('channel_id', $channel->id)
            ->whereNull('archived_at')
            ->latest('last_message_at')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel_id' => $channel->id,
            'channel_account_id' => $account?->id,
            'status' => Conversation::STATUS_OPEN,
        ]);

        event(new ConversationCreated($conversation));

        return $conversation;
    }
}
