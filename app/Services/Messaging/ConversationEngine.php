<?php

namespace App\Services\Messaging;

use App\Events\Messaging\ConversationChannelJoined;
use App\Events\Messaging\ConversationCreated;
use App\Events\Messaging\InboundMessageReceived;
use App\Events\Messaging\MessageStatusChanged;
use App\Jobs\Messaging\FetchInboundMediaJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationChannel;
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
        private readonly ChannelRoutingService $routing,
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

            // Eine Meldung ueber unsere EIGENE Nachricht ist keine
            // Kundenfrage. Sie wird gespeichert - der Verlauf soll
            // vollstaendig sein, sonst fehlt im Postfach genau die
            // Antwort, die der Kunde bekommen hat - aber als AUSGEHEND
            // und bereits gelesen: sie erzeugt keinen Ungelesen-Stand,
            // keine Zuweisung und keine KI-Antwort (siehe unten).
            $vonUns = $inbound->fromBusiness;

            // NACHGELIEFERT heisst: speichern, aber nichts ausloesen.
            // Die Nachricht ist echt und gehoert in den Verlauf; sie ist
            // nur nicht JETZT passiert.
            $historisch = $inbound->historical;

            $message = CustomerMessage::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $customer?->id,
                // JEDE Nachricht traegt ihren Kanal (Auftrag Abschnitt 7).
                // Ohne diese Angabe waere nach einer Zusammenfuehrung
                // nicht mehr feststellbar, woher sie kam - und der
                // Rueckweg (Kanal wieder loesen) haette keine Grundlage.
                'channel_id' => $channel->id,
                'channel_account_id' => $account?->id,
                'direction' => $vonUns
                    ? CustomerMessage::DIRECTION_OUTGOING
                    : CustomerMessage::DIRECTION_INCOMING,
                // Getippt hat ein Mensch - nur eben nicht hier. Als
                // `system` zu buchen waere falsch: es war kein Automat.
                'sender_type' => $vonUns
                    ? CustomerMessage::SENDER_EMPLOYEE
                    : CustomerMessage::SENDER_CUSTOMER,
                'external_message_id' => $inbound->externalMessageId,
                'message_type' => $inbound->type,
                'body' => (string) $inbound->text,
                'status' => CustomerMessage::STATUS_DELIVERED,
                'source' => $historisch
                    ? CustomerMessage::SOURCE_HISTORICAL
                    : CustomerMessage::SOURCE_LIVE,
                'metadata' => $inbound->metadata ?: null,
                'delivered_at' => now(),
                // Ungelesen zaehlt nur, was auf eine Antwort wartet -
                // eine Nachricht von vor drei Monaten also nie.
                'read_at' => ($vonUns || $historisch) ? now() : null,
            ]);

            // Der ECHTE Zeitpunkt, nicht der des Imports. Ohne ihn
            // stuende der halbe Verlauf unter dem Datum des Tages, an
            // dem angebunden wurde - und die Reihenfolge waere Zufall.
            if ($historisch && $inbound->sentAt) {
                $message->forceFill(['created_at' => $inbound->sentAt])->saveQuietly();
            }

            foreach ($inbound->attachments as $anhang) {
                $datensatz = CustomerMessageAttachment::create([
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

                // Die Datei kommt NACHTRAEGLICH: hinter der Kennung
                // stehen zwei authentifizierte Abrufe, und der Webhook
                // muss zuegig quittiert werden. Der Anhang ist deshalb
                // sofort als Datensatz da, die Datei folgt.
                if ($anhang->externalMediaId) {
                    FetchInboundMediaJob::dispatch($datensatz->id);
                }
            }

            // Eine nachgelieferte Nachricht darf eine Unterhaltung NIE
            // nach oben holen: sie ist alt. Sie setzt den Zeitpunkt nur,
            // wenn es noch keinen gibt (die Unterhaltung entstand gerade
            // aus dem Verlauf selbst).
            $letzte = $historisch
                ? ($conversation->last_message_at
                    && $conversation->last_message_at->greaterThan($message->created_at)
                        ? $conversation->last_message_at
                        : $message->created_at)
                : $message->created_at;

            // Den Kanal dieser Unterhaltung nachziehen: WANN wurde er
            // zuletzt benutzt? Eine nachgelieferte Nachricht schiebt den
            // Stand nie vor (siehe `touch`).
            $link = $this->routing->attach(
                $conversation, $channel, $account,
                $inbound->externalUserId, $inbound->externalConversationId
            );
            $this->routing->touch($link, $message->created_at);

            $conversation->forceFill([
                'last_message_at' => $letzte,
                // Der zuletzt benutzte Kanal - die Grundlage dafuer, dass
                // eine Antwort dort landet, wo der Kunde gerade ist. Eine
                // nachgelieferte Nachricht aendert ihn nicht: sie sagt
                // nichts darueber, wo der Kunde HEUTE erreichbar ist.
                'last_channel_id' => $historisch
                    ? ($conversation->last_channel_id ?: $channel->id)
                    : $channel->id,
                // WOHIN die Antwort gehoert: der Kanal des letzten
                // EINGANGS - dort wartet der Kunde. Der zuletzt benutzte
                // Kanal waere unsere eigene Sicht (unsere Antwort ist
                // auch eine Benutzung).
                //
                // Geschrieben wird er HIER, weil nur hier die
                // Reihenfolge bekannt ist. `created_at` traegt keine
                // Sekundenbruchteile, und die Kennung ist ein
                // ZUFAELLIGES UUID - aus zwei Nachrichten derselben
                // Sekunde laesst sich hinterher nicht mehr ablesen,
                // welche die spaetere war.
                //
                // Nur eine echte Kundennachricht zaehlt: unsere eigene
                // Antwort ist kein Eingang, und eine nachgelieferte
                // Nachricht sagt nichts darueber, wo der Kunde HEUTE
                // erreichbar ist.
                'last_inbound_channel_id' => ($vonUns || $historisch)
                    ? $conversation->last_inbound_channel_id
                    : $channel->id,
                // Eine Kundenantwort holt eine geschlossene Unterhaltung
                // zurueck: der Kunde schreibt weiter, also ist der Vorgang
                // nicht erledigt. Ein ARCHIV bleibt dagegen Archiv - es ist
                // eine bewusste Entscheidung eines Menschen.
                'status' => ($conversation->archived_at || $vonUns || $historisch)
                    ? $conversation->status
                    : Conversation::STATUS_OPEN,
                // Nur der KUNDE holt eine geschlossene Unterhaltung
                // zurueck. Unsere eigene Nachricht ist oft genau das
                // Schlusswort - sie darf den Vorgang nicht wieder
                // aufmachen.
                'reopened_at' => (! $vonUns && ! $historisch && $conversation->status === Conversation::STATUS_CLOSED)
                    ? now() : $conversation->reopened_at,
            ])->save();

            // Beides gilt nur fuer eine echte Kundennachricht: eine
            // Meldung ueber die eigene Antwort darf weder die
            // Zustaendigkeit verschieben noch die KI anstossen. Ohne
            // diese Grenze antwortet die KI auf uns selbst, die Antwort
            // erzeugt die naechste Meldung, und die Schleife laeuft
            // beim Kunden aus.
            // Weder eine Meldung ueber die eigene Antwort noch eine
            // nachgelieferte Nachricht ist ein Ereignis. Bei der
            // Historie ist das der Kern der Sache: sonst antwortet die
            // KI beim Anbinden auf Monate alte Fragen, und der Kunde
            // bekommt eine Lawine.
            if (! $vonUns && ! $historisch) {
                $this->assignments->autoAssign($conversation);

                event(new InboundMessageReceived($conversation, $message));
            }

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
     * Gesucht wird ueber die KANAL-ZUGEHOERIGKEIT (`conversation_channels`),
     * nicht mehr ueber `conversations.channel_id`. Der Unterschied ist
     * der ganze Punkt von Phase 2: seit eine Unterhaltung mehrere
     * Kanaele tragen kann, sagt die Spalte nur noch, wo sie BEGONNEN
     * hat. Wer weiter danach sucht, findet einen spaeter
     * dazugekommenen Kanal nie und legt bei jeder Nachricht eine neue
     * Unterhaltung an.
     *
     * Die Regel bleibt KANALBEWUSST, ohne den Kanal zu kennen: nennt
     * die Plattform eine eigene Unterhaltungs-Kennung, gilt sie; sonst
     * ist die Unterhaltung durch Konto und Gegenstelle bestimmt.
     */
    public function locate(
        InboundMessage $inbound,
        Channel $channel,
        ?ChannelAccount $account,
        ?Customer $customer,
    ): Conversation {
        $conversation = $this->findByChannel($inbound, $channel, $account);

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

        // KANALUEBERGREIFEND ANSCHLIESSEN (Auftrag Abschnitt 2): derselbe
        // Kunde schreibt jetzt ueber einen anderen Weg. Ob das erlaubt
        // ist, entscheidet ausschliesslich der Routing-Dienst - dort
        // stehen die vier Bedingungen an EINER Stelle.
        $anschluss = $this->routing->findJoinCandidate($customer?->id, $channel);

        if ($anschluss) {
            $this->routing->attach(
                $anschluss, $channel, $account,
                $inbound->externalUserId, $inbound->externalConversationId,
                ConversationChannel::JOIN_AUTO
            );

            event(new ConversationChannelJoined($anschluss, $channel));

            return $anschluss;
        }

        $conversation = Conversation::create([
            'customer_id' => $customer?->id,
            'channel_id' => $channel->id,
            'last_channel_id' => $channel->id,
            'channel_account_id' => $account?->id,
            'external_conversation_id' => $inbound->externalConversationId,
            'external_user_id' => $inbound->externalUserId,
            'status' => Conversation::STATUS_OPEN,
        ]);

        $this->routing->attach(
            $conversation, $channel, $account,
            $inbound->externalUserId, $inbound->externalConversationId
        );

        event(new ConversationCreated($conversation));

        return $conversation;
    }

    /**
     * Die Unterhaltung, die diesen Kanal UND diese Gegenstelle bereits
     * traegt.
     */
    private function findByChannel(
        InboundMessage $inbound,
        Channel $channel,
        ?ChannelAccount $account,
    ): ?Conversation {
        $basis = fn () => ConversationChannel::query()
            ->where('channel_id', $channel->id)
            ->when($account, fn ($q) => $q->where('channel_account_id', $account->id));

        $link = $inbound->externalConversationId
            ? $basis()->where('external_conversation_id', $inbound->externalConversationId)->first()
            : null;

        if (! $link && $inbound->externalUserId) {
            $link = $basis()
                ->where('external_user_id', $inbound->externalUserId)
                // Eine ARCHIVIERTE Unterhaltung wird nicht fortgesetzt -
                // sie wurde bewusst abgelegt. Eine neue Nachricht beginnt
                // dann einen neuen Vorgang, statt das Archiv zu stoeren.
                ->whereHas('conversation', fn ($q) => $q->whereNull('archived_at'))
                ->orderByDesc('last_message_at')
                ->first();
        }

        return $link?->conversation;
    }

    /**
     * Die Unterhaltung eines Kunden in einem Kanal - fuer AUSGEHENDE
     * Nachrichten, die kein Webhook ausloest (Mitarbeiter schreibt
     * zuerst). Ohne diesen Weg entstuende beim ersten Schreiben keine
     * Unterhaltung und die Nachricht haette kein Zuhause.
     */
    public function forCustomer(Customer $customer, Channel $channel, ?ChannelAccount $account = null): Conversation
    {
        // Auch hier ueber die Zugehoerigkeit: ein Kanal, der spaeter
        // dazukam, gehoert genauso zu dieser Unterhaltung wie der erste.
        $conversation = Conversation::query()
            ->where('customer_id', $customer->id)
            ->whereNull('archived_at')
            ->whereHas('channels', fn ($q) => $q->where('channel_id', $channel->id))
            ->latest('last_message_at')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel_id' => $channel->id,
            'last_channel_id' => $channel->id,
            'channel_account_id' => $account?->id,
            'status' => Conversation::STATUS_OPEN,
        ]);

        $this->routing->attach($conversation, $channel, $account);

        event(new ConversationCreated($conversation));

        return $conversation;
    }
}
