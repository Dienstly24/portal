<?php

namespace App\Services\Messaging\Inbox;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * DIE EINE Leseschicht des Postfachs.
 *
 * Bisher hatte jede Seite des Postfachs ihre eigene Abfrage, ihren
 * eigenen Ungelesen-Begriff und ihre eigene Suche. Solange das so ist,
 * bedeutet "Alle" nur "alles, woran jemand gedacht hat", und ein neuer
 * Kanal muss in jeder dieser Abfragen nachgetragen werden.
 *
 * Sicht, Filter, Suche und Zaehler kommen deshalb aus DIESER Klasse -
 * die Liste, die Kanalspalte und die Zaehler koennen sich damit nicht
 * widersprechen.
 *
 * KEIN KANALNAME. Gefiltert wird ueber `channels.key` aus der Datenbank;
 * ob dort `whatsapp` oder `telegram` steht, weiss diese Klasse nicht.
 */
class ConversationInbox
{
    /**
     * Grundabfrage MIT Sichtbarkeitsgrenze.
     *
     * UNBEKANNTE KONTAKTE sind bewusst fuer JEDEN Mitarbeiter sichtbar:
     * eine Unterhaltung ohne Kundenakte gehoert niemandem, und genau das
     * war der Fehler des alten Zustands - sie war fuer ALLE unsichtbar.
     * Eine Nachricht, die niemand sieht, kann auch niemand zuordnen.
     */
    /** @return Builder<Conversation> */
    public function scope(User $user): Builder
    {
        $query = Conversation::query()
            ->with(['channel', 'channelAccount', 'customer.user', 'assignee'])
            ->withCount(['messages as unread_count' => fn ($q) => $q
                ->where('direction', CustomerMessage::DIRECTION_INCOMING)
                ->whereNull('read_at'),
            ]);

        if ($user->isAdmin() || $user->can_see_all_customers) {
            return $query;
        }

        $ids = $user->assignedCustomers()->pluck('customers.id')->all();

        return $query->where(fn ($q) => $q
            ->whereIn('customer_id', $ids)
            ->orWhereNull('customer_id')
        );
    }

    /**
     * Die gefilterte, sortierte Liste.
     *
     * @return Builder<Conversation>
     */
    public function query(User $user, InboxFilters $filters): Builder
    {
        $query = $this->scope($user);

        $query->when($filters->channel, fn ($q, $key) => $q
            ->whereHas('channel', fn ($c) => $c->where('key', $key))
        );

        $query->when($filters->account, fn ($q, $id) => $q->where('channel_account_id', $id));
        $query->when($filters->status, fn ($q, $s) => $q->where('status', $s));
        $query->when($filters->customer, fn ($q, $id) => $q->where('customer_id', $id));
        $query->when($filters->assignee, fn ($q, $id) => $q->where('assigned_employee_id', $id));

        // Der BETREUER haengt am Kunden, nicht an der Unterhaltung - das
        // ist der Unterschied, auf dem das ganze Postfach steht.
        $query->when($filters->betreuer, fn ($q, $id) => $q
            ->whereHas('customer.betreuer', fn ($b) => $b->where('users.id', $id))
        );

        // Zeitraum ueber die letzte Aktivitaet. `bis` schliesst den Tag
        // ein - sonst faellt alles nach 00:00 des Endtages heraus, und
        // "heute bis heute" waere leer.
        $query->when($filters->from, fn ($q, $d) => $q->where('last_message_at', '>=', $d.' 00:00:00'));
        $query->when($filters->to, fn ($q, $d) => $q->where('last_message_at', '<=', $d.' 23:59:59'));

        match ($filters->view) {
            InboxFilters::VIEW_UNREAD => $query->whereHas('messages', fn ($m) => $m
                ->where('direction', CustomerMessage::DIRECTION_INCOMING)
                ->whereNull('read_at')
            ),
            InboxFilters::VIEW_MINE => $query->where('assigned_employee_id', $user->id),
            default => null,
        };

        if ($filters->search !== '') {
            $this->applySearch($query, $filters->search);
        }

        // Archiv ist eine bewusste Entscheidung: es taucht nur auf, wenn
        // ausdruecklich danach gefragt wird.
        if ($filters->status !== Conversation::STATUS_ARCHIVED) {
            $query->whereNull('archived_at');
        }

        return $query->orderByDesc('last_message_at')->orderByDesc('created_at');
    }

    /**
     * EINE Suche fuer alle Kanaele (Auftrag 12).
     *
     * Gesucht wird ueber Kunde, Nachrichtentext, Telefon, E-Mail, die
     * externen Kennungen und die Unterhaltungs-Kennung selbst. Mehrere
     * Woerter sind UND-verknuepft; `%` und `_` werden maskiert, damit
     * eine Nutzereingabe nie einen Platzhalter erzeugt.
     */
    /** @param Builder<Conversation> $query */
    private function applySearch(Builder $query, string $suche): void
    {
        foreach (preg_split('/\s+/', $suche) ?: [] as $wort) {
            if ($wort === '') {
                continue;
            }
            $muster = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $wort).'%';

            $query->where(fn ($q) => $q
                ->where('external_user_id', 'like', $muster)
                ->orWhere('external_conversation_id', 'like', $muster)
                ->orWhere('id', 'like', $muster)
                ->orWhere('subject', 'like', $muster)
                ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', $muster))
                // Die Kundensuche ist die BESTEHENDE (`Customer::scopeSearch`) -
                // als Unterabfrage eingehaengt statt nachgebaut. Zwei
                // Suchbegriffe fuer denselben Kunden waeren zwei
                // Wahrheiten.
                ->orWhereIn('customer_id', Customer::search($wort)->select('id'))
            );
        }
    }

    /**
     * Zaehler je Kanal und je Sicht - als reine COUNT-Abfragen, es wird
     * keine Zeile geladen.
     *
     * Die Zaehler folgen den UEBRIGEN Filtern, damit die Zahl zu dem
     * passt, was die Liste zeigt (dieselbe Regel wie bei der
     * Vertragsliste, 20.08.2026). Nur der Kanal selbst wird jeweils
     * ausgetauscht - sonst zeigte jede Kanalzeile ihre eigene Auswahl.
     *
     * @return array{views: array<string,int>, channels: array<string,int>}
     */
    public function counts(User $user, InboxFilters $filters): array
    {
        $sichten = [];
        foreach (array_keys(InboxFilters::VIEWS) as $sicht) {
            $sichten[$sicht] = $this->query($user, new InboxFilters(
                view: $sicht, channel: $filters->channel, assignee: $filters->assignee,
                betreuer: $filters->betreuer, status: $filters->status,
                customer: $filters->customer, account: $filters->account,
                from: $filters->from, to: $filters->to, search: $filters->search,
            ))->count();
        }

        $kanaele = [];
        foreach (Channel::active()->orderBy('sort')->get() as $kanal) {
            $kanaele[$kanal->key] = $this->query($user, new InboxFilters(
                view: $filters->view, channel: $kanal->key, assignee: $filters->assignee,
                betreuer: $filters->betreuer, status: $filters->status,
                customer: $filters->customer, account: $filters->account,
                from: $filters->from, to: $filters->to, search: $filters->search,
            ))->count();
        }

        return ['views' => $sichten, 'channels' => $kanaele];
    }
}
