<?php

namespace App\Support\Navigation;

use App\Models\Appointment;
use App\Models\Commission;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerMessage;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\EmailMessage;
use App\Models\InternalConversationParticipant;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;

/**
 * Die Zahlen der Navigation - und zwar NUR die, die eine Handlung verlangen.
 *
 * REGEL (Betreiber-Vorgabe): ein Badge ist eine Aufforderung, keine
 * Statistik. "37 aktive Ankuendigungen" oder "alle offenen Aufgaben" sagen
 * niemandem, was er jetzt tun soll - solche Zahlen faerben die Seitenleiste
 * dauerhaft bunt, und genau dann sieht man die EINE Zahl nicht mehr, die
 * wirklich zaehlt. Gezaehlt wird deshalb ausschliesslich Unerledigtes mit
 * Faelligkeit: ungelesene Nachrichten, neue Tickets, heute faellige
 * Aufgaben, wartende Freigaben.
 *
 * Jede Zahl wird je Aufruf genau EINMAL ermittelt (die Seitenleiste liest
 * sie zweimal: am Punkt und in der Summe der eingeklappten Gruppe).
 */
final class NavBadges
{
    public const TONE_ATTENTION = 'attention'; // wartet auf uns
    public const TONE_URGENT = 'urgent';       // ein Mensch wartet auf Antwort

    /** @var array<string,int> */
    private array $cache = [];

    /** Sichtbare Kunden-IDs (null = darf alle sehen) - eine Abfrage, nicht drei. */
    private ?array $visibleIds = null;
    private bool $visibleIdsResolved = false;

    public function __construct(private readonly User $user)
    {
    }

    public function get(string $key): int
    {
        return $this->cache[$key] ??= $this->compute($key);
    }

    private function compute(string $key): int
    {
        return match ($key) {
            // Ein Kunde wartet auf eine Antwort.
            'customer_messages' => CustomerMessage::fromCustomer()->unread()
                ->when($this->ids() !== null, fn ($q) => $q->whereIn('customer_id', $this->ids()))->count(),

            // NEUE, noch nicht uebernommene Kundentickets.
            'tickets' => Ticket::customerOnly()->where('status', 'open')
                ->when($this->ids() !== null, fn ($q) => $q->whereIn('customer_id', $this->ids()))->count(),

            // E-Mails mit Zuordnungs-VORSCHLAG: sie warten auf Bestaetigung.
            'email_suggestions' => $this->isOneOf(['admin', 'manager', 'support'])
                ? EmailMessage::where('match_status', 'suggested')->count() : 0,

            'team_chat' => InternalConversationParticipant::where('user_id', $this->user->id)
                ->whereHas('conversation', function ($q) {
                    $q->whereColumn('internal_conversations.last_message_at', '>', 'internal_conversation_participants.last_read_at')
                      ->orWhereNull('internal_conversation_participants.last_read_at');
                })->count(),

            // Nur HEUTE faellig oder ueberfaellig - eine Aufgabe fuer naechsten
            // Monat ist heute keine Handlung (frueher zaehlte jede offene).
            'tasks_due' => Task::where('assigned_to', $this->user->id)->open()
                ->whereNotNull('due_date')->whereDate('due_date', '<=', today())->count(),

            'appointments_today' => Appointment::whereDate('starts_at', today())
                ->where('status', 'scheduled')->count(),

            'change_requests' => CustomerChangeRequest::where('status', 'pending')
                ->when($this->ids() !== null, fn ($q) => $q->whereIn('customer_id', $this->ids()))->count(),

            // Eingeschraenkte Mitarbeiter sehen im Eingang nur eigene Uploads -
            // die Zahl muss zu dem passen, was die Seite dann zeigt.
            'document_inbox' => Document::inbox()
                ->when(!$this->user->canSeeAllCustomers(), fn ($q) => $q->where('uploaded_by', $this->user->id))->count(),

            'document_requests' => DocumentRequest::awaitingReview()->count(),

            'commissions' => $this->isOneOf(['admin', 'manager'])
                ? Commission::pendingReview()->count() : 0,

            default => 0,
        };
    }

    private function ids(): ?array
    {
        if (!$this->visibleIdsResolved) {
            $this->visibleIds = $this->user->canSeeAllCustomers()
                ? null : $this->user->visibleCustomerIdsWithSubstitution();
            $this->visibleIdsResolved = true;
        }

        return $this->visibleIds;
    }

    private function isOneOf(array $roles): bool
    {
        return in_array($this->user->role, $roles, true);
    }
}
