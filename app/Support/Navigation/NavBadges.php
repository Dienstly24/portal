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
use Illuminate\Support\Facades\Cache;

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
 *
 * ZWISCHENSPEICHER (UX-3): der Request-Cache oben half nur INNERHALB eines
 * Aufrufs - die zehn COUNT-Abfragen liefen trotzdem bei JEDEM Seitenaufruf
 * der Beraterwelt erneut, also auf jeder Unterseite, jedem Formular, jedem
 * Zurueck-Klick. Die Zahlen sind aber keine Buchhaltung: eine Aufforderung
 * darf ein paar Sekunden alt sein.
 *
 * Der Schluessel traegt IMMER die Benutzer-ID. Er MUSS das: die meisten
 * dieser Zahlen sind auf das Portfolio des Mitarbeiters begrenzt - ein
 * gemeinsamer Schluessel wuerde einem eingeschraenkten Mitarbeiter die Zahl
 * eines Admins zeigen und damit die Groesse eines Bestandes verraten, den
 * er nicht sehen darf. Aus demselben Grund gibt es hier bewusst KEINEN
 * globalen "alle Badges"-Eintrag.
 *
 * KEINE EREIGNIS-GESTEUERTE INVALIDIERUNG: sie muesste an jeder Stelle
 * haengen, die eine dieser zehn Zahlen veraendert (jede Nachricht, jedes
 * Ticket, jeder Upload, jede Freigabe) - viel Verdrahtung, die beim naechsten
 * neuen Schreibweg vergessen wird und dann eine DAUERHAFT falsche Zahl
 * hinterlaesst. Eine kurze Laufzeit ist selbstheilend: schlimmstenfalls ist
 * eine Zahl {@see self::TTL_SEKUNDEN} Sekunden alt.
 */
final class NavBadges
{
    public const TONE_ATTENTION = 'attention'; // wartet auf uns
    public const TONE_URGENT = 'urgent';       // ein Mensch wartet auf Antwort

    /**
     * Lebensdauer im Cache. Kurz genug, dass eine erledigte Aufgabe gleich
     * verschwindet, lang genug, dass ein Klick durch die Beraterwelt nicht
     * jedes Mal zehn COUNT-Abfragen ausloest.
     */
    private const TTL_SEKUNDEN = 30;

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
        return $this->cache[$key] ??= (int) Cache::remember(
            $this->cacheSchluessel($key),
            self::TTL_SEKUNDEN,
            fn () => $this->compute($key),
        );
    }

    /**
     * Immer je Benutzer - siehe Klassenkopf. Die Version im Praefix erlaubt
     * es, bei einer geaenderten Zaehlregel den Altbestand ungueltig zu
     * machen, ohne den ganzen Cache zu leeren.
     */
    private function cacheSchluessel(string $key): string
    {
        return 'nav_badges:v1:'.$this->user->id.':'.$key;
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

            // PORTFOLIO-SCOPE (UX-3): die Terminseite zeigt einem
            // eingeschraenkten Mitarbeiter ausschliesslich Termine von Kunden
            // seines Portfolios (AppointmentController::index, Audit SEC-P2) -
            // das Badge zaehlte dagegen ALLE Termine des Hauses. Die Zahl
            // stimmte damit nie mit der Seite ueberein und verriet obendrein,
            // wie viel anderswo los ist. Die Bedingung ist bewusst dieselbe
            // wie dort, damit Badge und Liste nicht wieder auseinanderlaufen.
            'appointments_today' => Appointment::whereDate('starts_at', today())
                ->where('status', 'scheduled')
                ->when($this->ids() !== null, fn ($q) => $q->whereIn('customer_id', $this->ids()))->count(),

            'change_requests' => CustomerChangeRequest::where('status', 'pending')
                ->when($this->ids() !== null, fn ($q) => $q->whereIn('customer_id', $this->ids()))->count(),

            // Eingeschraenkte Mitarbeiter sehen im Eingang nur eigene Uploads -
            // die Zahl muss zu dem passen, was die Seite dann zeigt.
            'document_inbox' => Document::inbox()
                ->when(! $this->user->canSeeAllCustomers(), fn ($q) => $q->where('uploaded_by', $this->user->id))->count(),

            // Ebenfalls portfolio-begrenzt (UX-3) - die Seite
            // DocumentRequestController::index zaehlt seit derselben
            // Aenderung nur noch sichtbare Kunden.
            'document_requests' => DocumentRequest::awaitingReview()
                ->when($this->ids() !== null, fn ($q) => $q->whereIn('customer_id', $this->ids()))->count(),

            'commissions' => $this->isOneOf(['admin', 'manager'])
                ? Commission::pendingReview()->count() : 0,

            default => 0,
        };
    }

    private function ids(): ?array
    {
        if (! $this->visibleIdsResolved) {
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
