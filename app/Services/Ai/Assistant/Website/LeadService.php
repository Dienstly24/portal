<?php
namespace App\Services\Ai\Assistant\Website;

use App\Models\AiConversationEvent;
use App\Models\AiLead;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\Assistant\Sales\ConversationJournal;
use App\Services\Ai\Assistant\Sales\IntentClassifier;
use App\Services\Notifications\NotificationService;
use App\Support\Facades\Notify;
use Illuminate\Support\Str;

/**
 * Interessenten aus dem Website-Assistenten (Spezifikation Abschnitt 20).
 *
 * Aufgabe: aus einem Gespraech einen verwertbaren Verkaufsdatensatz
 * machen - mit allem, was der Mitarbeiter braucht, um ohne Rueckfrage
 * weiterzuarbeiten.
 *
 * Ein Lead erzeugt beim Uebergeben zusaetzlich einen VORGANG (Ticket mit
 * Gastdaten), weil das Team dort ohnehin arbeitet. Der Lead selbst bleibt
 * die Verkaufssicht (Zustand, Angebot, Pruefstand).
 */
class LeadService
{
    public function __construct(
        private ConversationJournal $journal,
        private IntentClassifier $intents,
    ) {
    }

    /** Lead zu einer Sitzung holen oder anlegen. */
    public function forSession(string $sessionKey, string $firstMessage = ''): AiLead
    {
        $lead = AiLead::where('id', $sessionKey)->first();
        if ($lead) {
            return $lead;
        }

        $intent = $firstMessage !== '' ? $this->intents->classify($firstMessage) : null;

        $lead = new AiLead([
            'source' => AiLead::SOURCE_WEBSITE,
            'intent' => $intent,
            'customer_status' => AiLead::STATUS_NEW_CUSTOMER,
        ]);
        $lead->id = $sessionKey;
        $lead->save();

        $this->journal->record(null, AiConversationEvent::EVENT_LEAD, [
            'quelle' => AiLead::SOURCE_WEBSITE,
        ], AiConversationEvent::ACTOR_SYSTEM, null, null, null, $lead);

        return $lead;
    }

    /**
     * Lead an das Team uebergeben: Vorgang anlegen und Glocke stellen.
     *
     * Genau EIN Vorgang je Lead - meldet sich derselbe Interessent
     * mehrfach, wird der bestehende Vorgang ergaenzt statt dupliziert
     * (gleiche Regel wie bei der Kunden-Uebergabe).
     */
    public function handOver(AiLead $lead, string $reason, string $aiNote = ''): ?Ticket
    {
        $zusammenfassung = $this->summary($lead, $reason, $aiNote);

        $ticket = $lead->ticket_id ? Ticket::find($lead->ticket_id) : null;

        if ($ticket) {
            $ticket->messages()->create([
                'sender_id' => null,
                'body' => "Ergänzung durch den Website-Assistenten:\n" . $zusammenfassung,
                'is_internal' => true,
            ]);
        } else {
            $kontakt = $lead->contactData();

            $ticket = Ticket::create([
                'customer_id' => $lead->customer_id,
                'type' => $reason === 'beschwerde' ? 'complaint' : 'other',
                'status' => 'open',
                'subject' => 'Website-Interessent: ' . $lead->intentLabel(),
                'description' => $zusammenfassung,
                'priority' => $reason === 'angebot' ? 'hoch' : 'mittel',
                'source' => 'website',
                'guest_name' => $kontakt['name'] ?? null,
                'guest_email' => $kontakt['email'] ?? null,
                'guest_phone' => $kontakt['phone'] ?? null,
                'assigned_to' => $this->assignee(),
            ]);

            $ticket->logEvent('note_added', 'Automatisch durch den Website-Assistenten erstellt.');
            $lead->forceFill(['ticket_id' => $ticket->id])->save();
        }

        Notify::pushMany(
            User::whereIn('role', ['admin', 'manager', 'support'])->pluck('id'),
            [
                'type' => NotificationService::TYPE_MESSAGE,
                'title' => '🌐 Neuer Interessent von der Website',
                'body' => $lead->displayName() . ' – ' . $lead->intentLabel()
                    . ($ticket ? ' · Vorgang ' . $ticket->ticket_number : ''),
                'link' => route('admin.leads.index'),
                'dedup_key' => 'ai-lead-' . $lead->id,
            ]
        );

        return $ticket;
    }

    /**
     * Zusammenfassung fuer den Mitarbeiter - aus ECHTEN Angaben des
     * Interessenten, nicht vom Modell formuliert. Die Notiz des Modells
     * steht als solche gekennzeichnet daneben.
     */
    public function summary(AiLead $lead, string $reason, string $aiNote = ''): string
    {
        $zeilen = [
            'Anliegen: ' . $lead->intentLabel(),
            'Grund der Übergabe: ' . match ($reason) {
                'angebot' => 'Angaben für ein Angebot liegen vor',
                'mitarbeiter_gewuenscht' => 'Interessent wünscht einen Mitarbeiter',
                'beschwerde' => 'Beschwerde',
                default => 'Frage konnte nicht aus der Wissensbasis beantwortet werden',
            },
        ];

        foreach ($lead->contactData() as $key => $wert) {
            $zeilen[] = ucfirst($key) . ': ' . Str::limit((string) $wert, 120);
        }
        foreach ($lead->collectedData() as $key => $wert) {
            if (in_array($key, ['name', 'email', 'phone'], true)) {
                continue;
            }
            $zeilen[] = $this->fieldLabel($key) . ': ' . Str::limit((string) $wert, 150);
        }

        if (trim($aiNote) !== '') {
            $zeilen[] = 'KI-Notiz: ' . Str::limit(trim($aiNote), 300);
        }

        $zeilen[] = 'Nächster Schritt: ' . ($lead->next_action ?: 'Interessent kontaktieren');

        return implode("\n", $zeilen);
    }

    private function fieldLabel(string $key): string
    {
        return match ($key) {
            'installation_address' => 'Anschlussadresse',
            'situation' => 'Situation',
            'current_provider' => 'Aktueller Anbieter',
            'desired_speed' => 'Gewünschte Geschwindigkeit',
            'desired_start' => 'Gewünschter Start',
            'service' => 'Gewünschte Leistung',
            'note' => 'Anmerkung',
            default => $key,
        };
    }

    /** Zustaendigkeit: erster aktiver Support/Manager, sonst niemand. */
    private function assignee(): ?int
    {
        return User::whereIn('role', ['support', 'manager', 'admin'])
            ->where('is_active', true)
            ->orderByRaw("CASE role WHEN 'support' THEN 1 WHEN 'manager' THEN 2 ELSE 3 END")
            ->value('id');
    }
}
