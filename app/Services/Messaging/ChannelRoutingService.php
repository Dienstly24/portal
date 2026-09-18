<?php

namespace App\Services\Messaging;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\CustomerMessage;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WELCHE Kanaele traegt eine Unterhaltung, und ueber WELCHEN geht die
 * Antwort raus? (Auftrag Abschnitte 2, 7, 14)
 *
 * Die eine Stelle fuer alles, was mit der Kanal-Zugehoerigkeit zu tun
 * hat: anhaengen, zusammenfuehren, trennen und den Antwortweg
 * bestimmen. Verteilt auf Engine, Controller und Job waeren es drei
 * Auslegungen derselben Frage.
 *
 * HIER STEHT KEIN KANALNAME. Ob ein Kanal WhatsApp oder Telegram
 * heisst, entscheidet die Datenbank; was er kann, seine Faehigkeiten.
 */
class ChannelRoutingService
{
    /**
     * Schalter fuer das AUTOMATISCHE Zusammenfuehren.
     *
     * VOREINSTELLUNG AUS - dieselbe Haltung wie beim KI-Assistenten:
     * eine Aenderung, die bestehende Unterhaltungen anders fuehrt,
     * schaltet sich nicht selbst scharf. Nach dem Deployment verhaelt
     * sich das System exakt wie vorher; der Betreiber entscheidet, ab
     * wann WhatsApp und Portal als EIN Vorgang gelten sollen.
     */
    public const SETTING_AUTO_JOIN = 'messaging_kanaluebergreifend';

    /**
     * Wie alt darf die Unterhaltung sein, an die angeschlossen wird?
     *
     * Ein Vorgang von vor einem halben Jahr ist eine ANDERE Sache. Ihn
     * fortzuschreiben, weil derselbe Kunde ueber einen neuen Weg
     * schreibt, waere kein Zusammenhang, sondern eine Behauptung.
     */
    public const JOIN_MAX_AGE_DAYS = 30;

    public function autoJoinEnabled(): bool
    {
        return (bool) SystemSetting::get(self::SETTING_AUTO_JOIN, false);
    }

    /**
     * Einen Kanal an eine Unterhaltung haengen - IDEMPOTENT.
     *
     * Ein zweiter Aufruf legt nichts doppelt an, sondern ERGAENZT
     * fehlende Angaben. Die Herkunft (`join_method`) wird dabei NIE
     * ueberschrieben: wie ein Kanal dazukam, ist eine historische
     * Tatsache und keine Eigenschaft, die sich spaeter aendert.
     */
    public function attach(
        Conversation $conversation,
        Channel $channel,
        ?ChannelAccount $account = null,
        ?string $externalUserId = null,
        ?string $externalConversationId = null,
        string $method = ConversationChannel::JOIN_INITIAL,
        ?User $by = null,
    ): ConversationChannel {
        $key = ConversationChannel::linkKey($conversation->id, $channel->id, $account?->id);

        $link = ConversationChannel::firstOrNew(['link_key' => $key]);

        if (! $link->exists) {
            $link->fill([
                'conversation_id' => $conversation->id,
                'channel_id' => $channel->id,
                'channel_account_id' => $account?->id,
                'join_method' => $method,
                'joined_by' => $by?->id,
                'joined_at' => now(),
            ]);
        }

        // Nur ERGAENZEN: eine einmal bekannte Gegenstelle wird nicht
        // durch einen spaeteren leeren Aufruf geloescht.
        $link->external_user_id = $link->external_user_id ?: $externalUserId;
        $link->external_conversation_id = $link->external_conversation_id ?: $externalConversationId;
        $link->save();

        return $link;
    }

    /**
     * Die Unterhaltung, an die eine Nachricht aus einem NEUEN Kanal
     * angeschlossen werden darf - oder null.
     *
     * VIER BEDINGUNGEN, und alle vier muessen gelten:
     *
     * 1. Der Betreiber hat es eingeschaltet.
     * 2. Der Kunde ist BEKANNT. Eine Nachricht von einer unbekannten
     *    Nummer gehoert per Definition zu niemandem - sie an einen
     *    bestehenden Vorgang zu haengen waere reines Raten.
     * 3. Es gibt GENAU EINE offene Unterhaltung des Kunden. Bei zweien
     *    waere jede Wahl eine Vermutung - dieselbe Regel wie im
     *    Kundenabgleich und im Provisions-Import: bei zwei Treffern
     *    wird nichts zugeordnet.
     * 4. Sie ist nicht aelter als `JOIN_MAX_AGE_DAYS`.
     *
     * Eine ARCHIVIERTE oder geschlossene Unterhaltung kommt nie in
     * Frage: das Archiv ist eine bewusste Entscheidung, und ein
     * geschlossener Vorgang ist erledigt.
     */
    public function findJoinCandidate(?string $customerId, Channel $channel): ?Conversation
    {
        if (! $customerId || ! $this->autoJoinEnabled()) {
            return null;
        }

        $kandidaten = Conversation::query()
            ->where('customer_id', $customerId)
            ->whereNull('archived_at')
            ->where('status', Conversation::STATUS_OPEN)
            ->where('last_message_at', '>=', now()->subDays(self::JOIN_MAX_AGE_DAYS))
            // Eine Unterhaltung, die diesen Kanal schon traegt, ist nicht
            // "anzuschliessen" - sie waere ueber den normalen Weg
            // gefunden worden.
            ->whereDoesntHave('channels', fn ($q) => $q->where('channel_id', $channel->id))
            ->orderByDesc('last_message_at')
            ->limit(2)
            ->get();

        return $kandidaten->count() === 1 ? $kandidaten->first() : null;
    }

    /**
     * Der Kanal, ueber den eine Antwort standardmaessig geht
     * (Auftrag Abschnitt 14).
     *
     * Der zuletzt EINGEGANGENE Kanal, nicht der zuletzt benutzte:
     * geantwortet wird dort, wo der Kunde zuletzt geschrieben hat.
     * Sonst antwortete man im Portal, waehrend der Kunde gerade auf
     * seinem Telefon wartet.
     *
     * GELESEN, NICHT GESUCHT: die Angabe steht als Spalte an der
     * Unterhaltung, geschrieben vom Engine in der echten
     * Ankunftsreihenfolge. Die fruehere Suche nach der letzten
     * eingehenden Nachricht per `latest('created_at')` war UNBESTIMMT -
     * die Spalte hat keine Sekundenbruchteile, und zwei Nachrichten
     * derselben Sekunde (Webhook-Stapel ist der Normalfall) durfte die
     * Datenbank in beliebiger Reihenfolge liefern. Ein Tiebreaker auf
     * die Kennung half nicht: sie ist ein ZUFAELLIGES UUID v4, also
     * nicht zeitlich sortierbar - das Ergebnis waere wiederholbar
     * falsch statt zufaellig falsch gewesen.
     *
     * Die Rueckfallkette bleibt: fehlt die Spalte (Altbestand), ist der
     * zuletzt benutzte Kanal die beste verfuegbare Antwort.
     */
    public function defaultChannelId(Conversation $conversation): ?int
    {
        return $conversation->last_inbound_channel_id
            ?: $conversation->last_channel_id
            ?: $conversation->channel_id;
    }

    /**
     * Die Kanaele, ueber die DIESER Mitarbeiter hier antworten darf.
     *
     * DEM BROWSER WIRD NICHTS GEGLAUBT (Auftrag Abschnitt 14): dass
     * die Oberflaeche einen Kanal anbietet, ist keine Erlaubnis. Diese
     * Liste ist der Massstab - sie entsteht auf dem Server und wird beim
     * Senden ERNEUT gefragt.
     *
     * @return Collection<int, ConversationChannel>
     */
    public function availableChannels(Conversation $conversation): Collection
    {
        return $conversation->channels()
            ->with(['channel', 'channelAccount'])
            ->get()
            ->filter(fn (ConversationChannel $link) => $this->usable($link))
            ->values();
    }

    /**
     * Darf ueber diesen Kanal gesendet werden?
     *
     * Ein inaktiver Kanal ist kein Versandweg, und ohne Gegenstelle
     * gibt es keine Adresse. Beides wird hier geprueft und nicht in der
     * Oberflaeche - sonst waere die Pruefung eine Anzeige.
     */
    public function usable(ConversationChannel $link): bool
    {
        return (bool) $link->channel?->is_active && (bool) $link->external_user_id;
    }

    /** Die Gegenstelle fuer einen Kanal dieser Unterhaltung. */
    public function recipientFor(Conversation $conversation, int $channelId): ?string
    {
        return $conversation->channelLink($channelId)?->external_user_id;
    }

    /**
     * Den Zeitstempel eines Kanals nachziehen - nach jeder Nachricht.
     *
     * `first_message_at` wird nur gesetzt, wenn es fehlt; `last` nur
     * VORWAERTS. Eine nachgelieferte alte Nachricht darf den Stand
     * nicht zurueckdrehen (dieselbe Regel wie bei
     * `conversations.last_message_at`).
     */
    public function touch(ConversationChannel $link, \DateTimeInterface $zeitpunkt): void
    {
        $werte = [];

        if (! $link->first_message_at || $link->first_message_at->greaterThan($zeitpunkt)) {
            $werte['first_message_at'] = $zeitpunkt;
        }
        if (! $link->last_message_at || $link->last_message_at->lessThan($zeitpunkt)) {
            $werte['last_message_at'] = $zeitpunkt;
        }

        if ($werte) {
            $link->forceFill($werte)->save();
        }
    }

    /**
     * Einen Kanal wieder HERAUSLOESEN (Auftrag Abschnitt 8 sinngemaess).
     *
     * Der Rueckweg ist die Bedingung dafuer, dass Zusammenfuehren
     * ueberhaupt vertretbar ist. Ohne ihn waere eine falsche Verbindung
     * endgueltig - und ein Mitarbeiter saehe dauerhaft die Nachrichten
     * zweier Vorgaenge in einem.
     *
     * Er ist VERLUSTFREI, weil jede Nachricht ihren Kanal traegt: was
     * zu diesem Kanal gehoert, ist bestimmbar und nicht zu erraten.
     * Genau dafuer gibt es die Spalte.
     *
     * Der ERSTE Kanal wird nie geloest - er ist die Unterhaltung selbst.
     */
    public function detach(Conversation $conversation, int $channelId, ?User $by = null): ?Conversation
    {
        $link = $conversation->channelLink($channelId);

        if (! $link || $link->join_method === ConversationChannel::JOIN_INITIAL) {
            return null;
        }

        return DB::transaction(function () use ($conversation, $link, $by) {
            $neu = Conversation::create([
                'customer_id' => $conversation->customer_id,
                'channel_id' => $link->channel_id,
                'last_channel_id' => $link->channel_id,
                'channel_account_id' => $link->channel_account_id,
                'external_user_id' => $link->external_user_id,
                'external_conversation_id' => $link->external_conversation_id,
                'status' => Conversation::STATUS_OPEN,
                'assigned_employee_id' => $conversation->assigned_employee_id,
                'last_message_at' => $link->last_message_at ?: now(),
            ]);

            // Die Nachrichten DIESES Kanals wandern mit. Nachrichten ohne
            // Kanal bleiben bewusst zurueck: sie stammen aus der Zeit vor
            // Phase 2, und wo sie hingehoeren, ist nicht belegt - geraten
            // wird nie.
            CustomerMessage::where('conversation_id', $conversation->id)
                ->where('channel_id', $link->channel_id)
                ->update(['conversation_id' => $neu->id]);

            /*
             * Den Eintrag NICHT verschieben, sondern den bereits
             * vorhandenen ergaenzen.
             *
             * Die neue Unterhaltung hat sich ihren Eintrag beim Anlegen
             * selbst gegeben (Modell-Hook) - genau den, den ein
             * Verschieben erzeugen wuerde. Beides zusammen verletzt den
             * UNIQUE auf `link_key`, und der Trennen-Knopf endete in
             * einer Fehlerseite. Der Hook ist richtig; das Verschieben
             * war die Doppelung.
             */
            $neuerLink = $neu->channels()->first();
            $neuerLink?->forceFill([
                'external_user_id' => $link->external_user_id,
                'external_conversation_id' => $link->external_conversation_id,
                'joined_by' => $by?->id,
                'joined_at' => now(),
                'first_message_at' => $link->first_message_at,
                'last_message_at' => $link->last_message_at,
            ])->save();

            $link->delete();

            $this->nachziehen($conversation);

            return $neu;
        });
    }

    /**
     * Den zuletzt benutzten Kanal und den Aktivitaetszeitpunkt einer
     * Unterhaltung neu bestimmen - nach einer Trennung.
     */
    private function nachziehen(Conversation $conversation): void
    {
        // Hier ist die Reihenfolge nicht mehr bekannt (die Nachrichten
        // liegen schon), deshalb die Kennung als STABILER Tiebreaker:
        // sie macht das Ergebnis wiederholbar. Chronologisch ist sie
        // NICHT - ein zufaelliges UUID sagt nichts ueber die Zeit. Bei
        // zwei Nachrichten derselben Sekunde ist jede von beiden eine
        // vertretbare Antwort; unvertretbar waere nur, dass zwei
        // Aufrufe Verschiedenes liefern.
        $letzte = $conversation->messages()
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        $letzterEingang = $conversation->messages()
            ->where('direction', CustomerMessage::DIRECTION_INCOMING)
            ->whereNotNull('channel_id')
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        $conversation->forceFill([
            'last_channel_id' => $letzte?->channel_id ?: $conversation->channel_id,
            'last_inbound_channel_id' => $letzterEingang?->channel_id,
            'last_message_at' => $letzte?->created_at ?: $conversation->last_message_at,
        ])->save();
    }
}
