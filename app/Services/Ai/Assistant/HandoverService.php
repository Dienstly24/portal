<?php
namespace App\Services\Ai\Assistant;

use App\Models\AiConversation;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Workflow\SystemUserResolver;
use App\Support\Facades\Notify;
use Illuminate\Support\Str;

/**
 * Uebergabe an einen Mitarbeiter - der wichtigste Sicherheitsmechanismus
 * des Assistenten (Spezifikation Abschnitte 12/13/14).
 *
 * Eine Uebergabe erzeugt IMMER dreierlei:
 *  1. Uebergabe-Status an der Unterhaltung -> die KI schweigt danach.
 *  2. Einen Vorgang (Ticket), damit die Anfrage nicht im Chat versickert -
 *     ein bestehender offener Vorgang wird weiterverwendet (Abschnitt 24).
 *  3. Eine Glocke an das zustaendige Team mit Kunde, Grund und
 *     Zusammenfassung, verlinkt in die Unterhaltung (Abschnitt 13).
 *
 * Die Glocke geht auch dann raus, wenn der Betreiber die automatische
 * Uebergabe abgeschaltet hat: eine unsichere Anfrage darf nie unbemerkt
 * liegen bleiben. Ohne den Schalter entsteht dann nur kein Vorgang.
 */
class HandoverService
{
    public function __construct(
        private AssistantSettings $settings,
        private SystemUserResolver $systemUser,
        private DocumentStatusReader $documents,
    ) {
    }

    /**
     * @param string $reason einer der AiConversation::REASON_* Gruende
     * @param string $lastQuestion letzte Kundenfrage (fuer die Zusammenfassung)
     * @return array{ticket: ?Ticket, summary: string}
     */
    public function handOver(
        Customer $customer,
        AiConversation $conversation,
        string $reason,
        string $lastQuestion,
        ?string $aiNote = null,
    ): array {
        $summary = $this->buildSummary($customer, $reason, $lastQuestion, $aiNote);

        $conversation->markHandover($reason, $summary);

        // Vorgang nur, wenn der Betreiber die Automatik zulaesst.
        $ticket = null;
        if ($this->settings->autoHandover()) {
            $ticket = $this->ensureTicket($customer, $reason, $lastQuestion, $summary);
        }

        $this->notifyTeam($customer, $reason, $summary, $ticket);

        return ['ticket' => $ticket, 'summary' => $summary];
    }

    /**
     * Angebot beim Team anfordern (Spezifikation Abschnitt 5).
     *
     * Bewusst KEINE vollstaendige Uebergabe: der Vorgang wandert zum
     * Mitarbeiter, das Gespraech bleibt aber bei der KI. Sobald das
     * Angebot hinterlegt ist, fuehrt sie es weiter - wuerde sie hier
     * stummgeschaltet, muesste der Mitarbeiter jedes Angebot selbst
     * erklaeren, und der Gewinn des Assistenten waere dahin.
     */
    public function requestOffer(
        Customer $customer,
        AiConversation $conversation,
        \App\Services\Ai\Assistant\Sales\ConversationContext $context,
    ): ?Ticket {
        $summary = $this->offerSummary($customer, $conversation, $context);

        $conversation->forceFill([
            'summary' => $summary,
            'next_action' => 'Angebot hinterlegen (Mitarbeiter)',
        ])->save();

        $ticket = null;
        if ($this->settings->autoTicket()) {
            $ticket = $this->ensureOfferTicket($customer, $conversation, $summary);
        }

        $customer->loadMissing('user');
        $name = $customer->user?->name ?: trim((string) $customer->company_name) ?: 'Kunde';

        $recipients = $customer->betreuer()->pluck('users.id')
            ->merge(User::whereIn('role', ['admin', 'manager'])->pluck('id'))
            ->unique()->values();

        Notify::pushMany($recipients, [
            'type' => NotificationService::TYPE_MESSAGE,
            'title' => '💡 Angebot gesucht: Kunde wartet',
            'body' => $name . ' (Nr. ' . $customer->customer_number . ') – '
                . \App\Services\Ai\Assistant\Sales\RequirementProfile::intentLabel($conversation->intent)
                . ($ticket ? ' · Vorgang ' . $ticket->ticket_number : ''),
            'link' => route('admin.customer_chat') . '?kunde=' . $customer->id,
            'dedup_key' => 'ai-offer-' . $customer->id,
        ]);

        return $ticket;
    }

    /**
     * Zusammenfassung des Bedarfs - aus ECHTEN erfassten Angaben, nie vom
     * Modell formuliert. Sensible Angaben erscheinen nur als "liegt vor".
     */
    public function offerSummary(
        Customer $customer,
        AiConversation $conversation,
        \App\Services\Ai\Assistant\Sales\ConversationContext $context,
    ): string {
        $lines = ['Anliegen: ' . \App\Services\Ai\Assistant\Sales\RequirementProfile::intentLabel($conversation->intent)];

        $bekannt = $context->known();
        foreach (\App\Services\Ai\Assistant\Sales\RequirementProfile::fieldsForStage($conversation->intent, 'bedarf') as $feld) {
            $wert = $bekannt[$feld['key']] ?? null;
            if ($wert === null || trim((string) $wert) === '') {
                continue;
            }
            $lines[] = $feld['label'] . ': ' . Str::limit((string) $wert, 120);
        }

        $fortschritt = $context->progress('bedarf');
        $lines[] = 'Erfasst: ' . $fortschritt['erledigt'] . '/' . $fortschritt['gesamt'] . ' Pflichtangaben';
        $lines[] = 'Nächster Schritt: Angebot auswählen und im Kundenchat hinterlegen.';

        return implode("\n", $lines);
    }

    /** Ein Angebots-Vorgang je Kunde - kein zweiter fuer dieselbe Anfrage. */
    private function ensureOfferTicket(Customer $customer, AiConversation $conversation, string $summary): ?Ticket
    {
        $existing = Ticket::where('customer_id', $customer->id)
            ->where('source', 'ai_assistant')
            ->where('subject', 'like', 'Angebot gesucht%')
            ->active()
            ->latest()
            ->first();

        if ($existing) {
            $existing->messages()->create([
                'sender_id' => null,
                'body' => "Aktualisierter Bedarf des KI-Assistenten:\n" . $summary,
                'is_internal' => true,
            ]);

            return $existing;
        }

        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'type' => 'other',
            'status' => 'open',
            'subject' => 'Angebot gesucht: ' . \App\Services\Ai\Assistant\Sales\RequirementProfile::intentLabel($conversation->intent),
            'description' => $summary,
            'priority' => 'hoch',
            'source' => 'ai_assistant',
            'assigned_to' => $this->assignee($customer),
        ]);

        $ticket->logEvent('note_added', 'Bedarf vollständig erfasst durch den KI-Assistenten.');

        return $ticket;
    }

    /**
     * Zusammenfassung fuer den Mitarbeiter (Abschnitt 14): er soll den
     * Fall in Sekunden erfassen, ohne den Chat zu lesen. Bewusst aus
     * ECHTEN Daten gebaut, nicht vom Modell formuliert - eine erfundene
     * Zusammenfassung waere schlimmer als keine.
     */
    public function buildSummary(
        Customer $customer,
        string $reason,
        string $lastQuestion,
        ?string $aiNote = null,
    ): string {
        $lines = [];
        $lines[] = 'Grund der Übergabe: ' . (AiConversation::REASON_LABELS[$reason] ?? $reason);

        if ($aiNote !== null && trim($aiNote) !== '') {
            $lines[] = 'KI-Einschätzung: ' . Str::limit(trim($aiNote), 300);
        }

        $overview = $this->documents->overview($customer);
        if ($overview['fehlt'] !== []) {
            $lines[] = 'Noch benötigt: ' . implode(', ', array_map(
                fn ($d) => (string) ($d['titel'] ?? '?'),
                $overview['fehlt']
            ));
        }
        if ($overview['in_pruefung'] !== []) {
            $lines[] = 'In Prüfung: ' . implode(', ', array_map(
                fn ($d) => (string) ($d['titel'] ?? '?'),
                $overview['in_pruefung']
            ));
        }

        $openTickets = Ticket::where('customer_id', $customer->id)->active()->count();
        if ($openTickets > 0) {
            $lines[] = 'Offene Vorgänge: ' . $openTickets;
        }

        if (trim($lastQuestion) !== '') {
            $lines[] = 'Letzte Kundenfrage: "' . Str::limit(trim($lastQuestion), 200) . '"';
        }

        return implode("\n", $lines);
    }

    /**
     * Vorhandenen offenen Uebergabe-Vorgang weiterverwenden oder einen
     * neuen anlegen (Duplikat-Schutz, Abschnitt 24).
     */
    private function ensureTicket(
        Customer $customer,
        string $reason,
        string $lastQuestion,
        string $summary,
    ): ?Ticket {
        $existing = Ticket::where('customer_id', $customer->id)
            ->where('source', 'ai_assistant')
            ->active()
            ->latest()
            ->first();

        if ($existing) {
            // Kein zweiter Vorgang fuer dieselbe laufende Uebergabe - nur
            // eine Notiz, damit der Verlauf sichtbar bleibt.
            $existing->messages()->create([
                'sender_id' => null,
                'body' => "Weitere Übergabe durch den KI-Assistenten:\n" . $summary,
                'is_internal' => true,
            ]);
            $existing->logEvent('note_added', 'KI-Assistent: erneute Übergabe (' . $reason . ')');

            return $existing;
        }

        $priority = $this->priorityFor($reason);

        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'type' => $this->ticketTypeFor($reason),
            'status' => 'open',
            'subject' => 'KI-Übergabe: ' . Str::limit(
                trim($lastQuestion) !== '' ? trim($lastQuestion) : (AiConversation::REASON_LABELS[$reason] ?? $reason),
                120
            ),
            'description' => $summary,
            'priority' => $priority,
            'source' => 'ai_assistant',
            'assigned_to' => $this->assignee($customer),
        ]);

        $ticket->logEvent('note_added', 'Automatisch durch den KI-Kundenassistenten erstellt.');

        return $ticket;
    }

    /**
     * Prioritaet nach Uebergabegrund. Beschwerden und ausdrueckliche
     * Mitarbeiter-Wuensche sind dringender als eine Wissensluecke.
     */
    private function priorityFor(string $reason): string
    {
        return match ($reason) {
            AiConversation::REASON_COMPLAINT => 'hoch',
            AiConversation::REASON_CUSTOMER_REQUEST, AiConversation::REASON_SENSITIVE => 'hoch',
            AiConversation::REASON_INJECTION => 'mittel',
            default => 'mittel',
        };
    }

    /**
     * Ticket-Art aus dem Grund. Nur belegte Zuordnungen - im Zweifel
     * 'other', damit die Statistik nicht mit geratenen Arten verfaelscht
     * wird.
     */
    private function ticketTypeFor(string $reason): string
    {
        return match ($reason) {
            AiConversation::REASON_COMPLAINT => 'complaint',
            default => 'other',
        };
    }

    /**
     * Zustaendigkeit: der erste Betreuer des Kunden, sonst der System-
     * Fallback (erster Admin) - nie hart auf eine ID verdrahtet.
     */
    private function assignee(Customer $customer): ?int
    {
        $betreuer = $customer->betreuer()->value('users.id');
        if ($betreuer) {
            return (int) $betreuer;
        }

        try {
            return $this->systemUser->resolveId();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Glocke an Betreuer + admin/manager (gleicher Kreis wie TicketNotifier). */
    private function notifyTeam(Customer $customer, string $reason, string $summary, ?Ticket $ticket): void
    {
        $customer->loadMissing('user');
        $name = $customer->user?->name
            ?: trim((string) $customer->company_name)
            ?: 'Kunde';

        $recipients = $customer->betreuer()->pluck('users.id')
            ->merge(User::whereIn('role', ['admin', 'manager'])->pluck('id'))
            ->unique()->values();

        Notify::pushMany($recipients, [
            'type' => NotificationService::TYPE_MESSAGE,
            'title' => '🤖 KI-Übergabe: Mitarbeiter erforderlich',
            'body' => $name . ' (Nr. ' . $customer->customer_number . ') – '
                . (AiConversation::REASON_LABELS[$reason] ?? $reason)
                . ($ticket ? ' · Vorgang ' . $ticket->ticket_number : ''),
            // Direkt in die Unterhaltung springen (Abschnitt 13).
            'link' => route('admin.customer_chat') . '?kunde=' . $customer->id,
            // Eine offene Uebergabe je Kunde buendelt sich zu EINER Glocke.
            'dedup_key' => 'ai-handover-' . $customer->id,
        ]);
    }
}
