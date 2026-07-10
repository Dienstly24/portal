<?php
namespace App\Services\Workflow;

use App\Models\Customer;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\Ticket;
use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerCreation\DuplicateCustomerException;
use App\Services\FondsFinanz\FondsFinanzImportService;
use App\Services\Matching\CustomerMatchingService;
use Illuminate\Support\Str;

/**
 * Verbindet Kategorisierung (Abschnitt 4), Kunden-Matching (Abschnitt 5)
 * und Aktionsauslösung zu einem Workflow pro eingehender E-Mail
 * (Architekturplan Abschnitt 4 "Aktion auslösen"). Erzeugt bewusst
 * KEINE neue Aufgaben-/Ticket-Verwaltung - nutzt ausschließlich die
 * bestehenden Task- und Ticket-Modelle.
 */
class EmailWorkflowService
{
    /**
     * Kategorien, für die eine automatische Neuanlage plausibel ist, wenn
     * wirklich kein Kandidat existiert. fonds_finanz gehört bewusst NICHT
     * dazu: Bei diesen Mails ist der Absender die Fonds Finanz selbst -
     * die Kundendaten stehen im Text und werden vom
     * FondsFinanzImportService verarbeitet.
     */
    private const AUTO_CREATE_CATEGORIES = ['kundenanfrage', 'versicherung'];

    public function __construct(
        private readonly EmailClassificationService $classifier,
        private readonly CustomerMatchingService $matcher,
        private readonly CustomerAutoCreationService $autoCreator,
        private readonly FondsFinanzImportService $fondsFinanz,
        private readonly SystemUserResolver $systemUser,
    ) {
    }

    public function process(EmailMessage $message): void
    {
        if ($message->processed_at !== null) {
            return; // bereits verarbeitet - idempotent bei erneutem Aufruf
        }

        $category = $this->classifier->classify($message);

        if ($category === 'fonds_finanz') {
            // Eigener Workflow (Architekturplan Abschnitt 8): Kundendaten
            // stehen im Mail-TEXT, nicht im Absender-Header.
            $this->fondsFinanz->process($message);
            return;
        }
        $criteria = $this->buildCriteria($message);
        $match = $this->matcher->match($criteria);

        $customer = null;
        $matchStatus = 'unmatched';

        if ($match->tier() === 'auto') {
            $customer = $match->customer;
            $matchStatus = 'confirmed';
        } elseif ($match->tier() === 'confirm') {
            // Vorschlag: Kunde wird gespeichert, aber erst durch Mitarbeiter bestätigt (Abschnitt 13).
            $customer = $match->customer;
            $matchStatus = 'suggested';
        } elseif (!$match->hasMatch() && $this->isEligibleForAutoCreation($category, $message)) {
            try {
                $customer = $this->autoCreator->createFromUnmatched($criteria, 'email_import');
                $matchStatus = 'confirmed';
            } catch (DuplicateCustomerException) {
                // Sicherheitsnetz hat doch einen Kandidaten gefunden - zur manuellen Prüfung.
                $matchStatus = 'unmatched';
            }
        }

        $message->forceFill([
            'category' => $category,
            'match_status' => $matchStatus,
            'customer_id' => $customer?->id,
            'match_score' => $match->score,
            'processed_at' => now(),
        ])->save();

        $this->dispatchAction($message, $category, $matchStatus === 'confirmed' ? $customer : ($matchStatus === 'suggested' ? null : null));
    }

    /**
     * Kriterien aus der E-Mail für das Matching (Abschnitt 5). Name/E-Mail
     * kommen aus dem Header; Geburtsdatum/Telefon werden nur über simple,
     * deterministische Muster im Freitext erkannt ("geb. 12.04.1988",
     * "Tel.: 030 123456") - das ist bewusst KEINE KI-Interpretation
     * (Abschnitt 12 ist als eigene Ausbaustufe geplant), sondern eine
     * einfache Regel-Ergänzung, die zusätzlich verfügbare Signale nutzt,
     * statt sie ungenutzt zu lassen.
     */
    private function buildCriteria(EmailMessage $message): array
    {
        $criteria = ['full_name' => $message->from_name, 'email' => $message->from_address];

        $text = (string) $message->body_text;
        if ($birthDate = $this->extractBirthDate($text)) {
            $criteria['birth_date'] = $birthDate;
        }
        if ($phone = $this->extractPhone($text)) {
            $criteria['phone'] = $phone;
        }

        return $criteria;
    }

    private function extractBirthDate(string $text): ?string
    {
        if (preg_match('/(?:geb\.?|geboren am)\s*(\d{2})\.(\d{2})\.(\d{4})/i', $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return null;
    }

    private function extractPhone(string $text): ?string
    {
        if (preg_match('/(?:tel\.?|telefon)[:\s]*([0-9 \/\-]{6,20})/i', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function isEligibleForAutoCreation(string $category, EmailMessage $message): bool
    {
        if (!in_array($category, self::AUTO_CREATE_CATEGORIES, true)) {
            return false;
        }
        // Braucht mindestens einen erkennbaren Namen - reine "unbekannt@..."-Absender nie automatisch anlegen.
        return $message->from_name !== null && trim($message->from_name) !== '';
    }

    private function dispatchAction(EmailMessage $message, string $category, ?Customer $confirmedCustomer): void
    {
        match ($category) {
            'kundenanfrage' => $this->createTicket($message, $confirmedCustomer),
            'versicherung' => $this->createTask($message, $confirmedCustomer, 'Dokument/Information für Versicherung prüfen', 7, 'high'),
            'energie' => $this->createTask($message, $confirmedCustomer, 'Energievertrag-Hinweis prüfen – Kunde kontaktieren', 14, 'medium'),
            'provisionen' => $this->createTask($message, $confirmedCustomer, 'Provisionsabrechnung prüfen (Lexoffice-Anbindung folgt)', 5, 'medium'),
            'dokumente' => $confirmedCustomer === null ? $this->createTask($message, null, 'Eingereichtes Dokument manuell zuordnen', 3, 'medium') : null,
            default => $this->createTask($message, $confirmedCustomer, 'E-Mail manuell prüfen', 5, 'low'),
        };
    }

    private function createTicket(EmailMessage $message, ?Customer $customer): void
    {
        Ticket::forceCreate([
            'id' => (string) Str::uuid(),
            'customer_id' => $customer?->id,
            'type' => 'other',
            'status' => 'open',
            'priority' => 'mittel',
            'source' => 'email',
            'subject' => $message->subject ?: ('E-Mail-Anfrage von ' . ($message->from_name ?: $message->from_address)),
            'description' => $message->body_text ?: strip_tags((string) $message->body_html),
            'guest_name' => $customer ? null : $message->from_name,
            'guest_email' => $customer ? null : $message->from_address,
        ]);
    }

    private function createTask(EmailMessage $message, ?Customer $customer, string $title, int $dueInDays, string $priority): void
    {
        $assignedTo = $customer?->betreuer()->first()?->id ?? $this->systemUserId();

        Task::forceCreate([
            'id' => (string) Str::uuid(),
            'assigned_to' => $assignedTo,
            'created_by' => $this->systemUserId(),
            'customer_id' => $customer?->id,
            'title' => $title,
            'description' => 'Ausgelöst durch E-Mail "' . ($message->subject ?: '(kein Betreff)') . '" von ' . $message->from_address,
            'type' => 'email',
            'status' => 'open',
            'priority' => $priority,
            'due_date' => now()->addDays($dueInDays)->toDateString(),
        ]);
    }

    private function systemUserId(): int
    {
        return $this->systemUser->resolveId();
    }
}
