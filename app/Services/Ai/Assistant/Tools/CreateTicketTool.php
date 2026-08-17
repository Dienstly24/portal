<?php
namespace App\Services\Ai\Assistant\Tools;

use App\Models\Ticket;
use App\Services\Ai\Assistant\AssistantSettings;
use App\Services\TicketNotifier;
use App\Services\Workflow\SystemUserResolver;
use Illuminate\Support\Str;

/**
 * Vorgang/Ticket anlegen (Spezifikation Abschnitt 11 - createTicket /
 * createProcess).
 *
 * DUPLIKAT-SCHUTZ (Abschnitt 24) ist der Kern dieses Tools: der Assistent
 * darf nicht bei jeder Nachricht ein neues Ticket erzeugen. Vor dem Anlegen
 * wird ein offener Vorgang zum SELBEN Thema gesucht - gibt es einen, wird
 * er weiterverwendet und nur ergaenzt.
 *
 * Das Ticket ist bewusst nur ein ARBEITSAUFTRAG an das Team: der Assistent
 * bearbeitet damit keinen Vertrag und entscheidet nichts (Abschnitt 18/23).
 */
class CreateTicketTool implements AssistantTool
{
    public function __construct(
        private AssistantSettings $settings,
        private SystemUserResolver $systemUser,
    ) {
    }

    public function name(): string
    {
        return 'createTicket';
    }

    public function description(): string
    {
        return 'Legt einen Vorgang (Ticket) fuer das Dienstly24-Team an, z.B. bei '
            . 'Vertragsaenderung, Schadenmeldung, Kuendigungswunsch oder einer Bitte um '
            . 'Rueckruf. Rufe VORHER getOpenTickets auf: existiert ein offener Vorgang zum '
            . 'selben Thema, wird dieser weiterverwendet und KEIN neuer erstellt. Der '
            . 'Vorgang ist nur ein Arbeitsauftrag an das Team - du entscheidest oder '
            . 'genehmigst damit nichts.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'thema' => [
                    'type' => 'string',
                    'description' => 'Kurzer Betreff aus Kundensicht, z.B. "Adressaenderung Kfz-Vertrag".',
                ],
                'beschreibung' => [
                    'type' => 'string',
                    'description' => 'Worum es geht, in eigenen Worten zusammengefasst - nur Angaben '
                        . 'des Kunden, nichts hinzugedichtet.',
                ],
                'art' => [
                    'type' => 'string',
                    'enum' => ['change', 'damage', 'offer', 'data_update', 'cancellation', 'complaint', 'other'],
                    'description' => 'Art des Vorgangs. Bei Unsicherheit "other".',
                ],
            ],
            'required' => ['thema', 'beschreibung'],
        ];
    }

    public function isWriting(): bool
    {
        return true;
    }

    public function run(array $arguments, AssistantToolContext $context): array
    {
        if (!$this->settings->autoTicket()) {
            return [
                'erstellt' => false,
                'hinweis' => 'Die automatische Vorgangserstellung ist abgeschaltet. Uebergib die '
                    . 'Anfrage mit escalateToTeam an das Team.',
            ];
        }

        $subject = trim((string) ($arguments['thema'] ?? ''));
        $description = trim((string) ($arguments['beschreibung'] ?? ''));
        if ($subject === '' || $description === '') {
            return ['erstellt' => false, 'hinweis' => 'Thema und Beschreibung sind erforderlich.'];
        }

        // Art gegen die Whitelist des Systems pruefen - nie ungeprueft
        // uebernehmen, was das Modell liefert.
        $type = (string) ($arguments['art'] ?? 'other');
        if (!array_key_exists($type, Ticket::TYPES)) {
            $type = 'other';
        }

        // Duplikat-Schutz: offener Vorgang mit gleicher Art ODER sehr
        // aehnlichem Betreff.
        $existing = $this->findDuplicate($context, $subject, $type);
        if ($existing) {
            $existing->messages()->create([
                'sender_id' => null,
                'body' => "Ergaenzung durch den KI-Assistenten:\n" . $description,
                'is_internal' => true,
            ]);
            $existing->logEvent('note_added', 'KI-Assistent: Kundenanliegen dem bestehenden Vorgang zugeordnet.');
            $context->recordAction('ticket_reused', [
                'ticket_number' => $existing->ticket_number,
                'ticket_id' => (string) $existing->id,
            ]);

            return [
                'erstellt' => false,
                'vorhandener_vorgang' => $existing->ticket_number,
                'status' => $existing->portalStatusLabel(),
                'hinweis' => 'Es gibt bereits einen offenen Vorgang zu diesem Thema. Er wurde '
                    . 'ergaenzt. Nenne dem Kunden diese Vorgangsnummer statt einer neuen.',
            ];
        }

        $ticket = Ticket::create([
            'customer_id' => $context->customer->id,
            'type' => $type,
            'status' => 'open',
            'subject' => Str::limit($subject, 150),
            'description' => $description,
            'priority' => $type === 'complaint' ? 'hoch' : 'mittel',
            'source' => 'ai_assistant',
            'assigned_to' => $this->assignee($context),
        ]);

        $ticket->logEvent('note_added', 'Automatisch durch den KI-Kundenassistenten erstellt.');

        // Dieselbe Benachrichtigung wie bei einer Kundenanfrage aus dem
        // Portal - das Team sieht den Vorgang an der gewohnten Stelle.
        TicketNotifier::notifyNewTicket($ticket);

        $context->recordAction('ticket_created', [
            'ticket_number' => $ticket->ticket_number,
            'ticket_id' => (string) $ticket->id,
            'type' => $type,
        ]);

        return [
            'erstellt' => true,
            'vorgangsnummer' => $ticket->ticket_number,
            'status' => $ticket->portalStatusLabel(),
            'hinweis' => 'Nenne dem Kunden die Vorgangsnummer und dass sich das Team meldet.',
        ];
    }

    /**
     * Offener Vorgang zum selben Thema? Gleiche Art zaehlt als selbes
     * Thema (ein Kunde hat selten zwei Kuendigungen gleichzeitig), sonst
     * entscheidet die Wortueberschneidung des Betreffs.
     */
    private function findDuplicate(AssistantToolContext $context, string $subject, string $type): ?Ticket
    {
        $candidates = Ticket::where('customer_id', $context->customer->id)
            ->active()
            ->latest()
            ->limit(20)
            ->get();

        foreach ($candidates as $ticket) {
            if ($type !== 'other' && $ticket->type === $type) {
                return $ticket;
            }
            if ($this->similarSubject((string) $ticket->subject, $subject)) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * Aehnlicher Betreff = mindestens zwei gemeinsame Woerter ab 4 Zeichen.
     * Bewusst einfach und nachvollziehbar; im Zweifel entsteht ein eigener
     * Vorgang (ein Vorgang zu viel ist besser als ein verlorener).
     */
    private function similarSubject(string $a, string $b): bool
    {
        $words = function (string $value): array {
            $value = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower($value));
            $parts = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_unique(array_filter($parts, fn ($w) => mb_strlen($w) >= 4)));
        };

        return count(array_intersect($words($a), $words($b))) >= 2;
    }

    private function assignee(AssistantToolContext $context): ?int
    {
        $betreuer = $context->customer->betreuer()->value('users.id');
        if ($betreuer) {
            return (int) $betreuer;
        }

        try {
            return $this->systemUser->resolveId();
        } catch (\Throwable) {
            return null;
        }
    }
}
