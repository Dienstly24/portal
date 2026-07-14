<?php
namespace App\Services\FondsFinanz;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmailMessage;
use App\Models\ExternalReference;
use App\Models\Task;
use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerCreation\DuplicateCustomerException;
use App\Services\Mailbox\AttachmentAnalysisService;
use App\Services\Mailbox\EmailAttachmentService;
use App\Services\Matching\CustomerMatchingService;
use App\Services\Workflow\SystemUserResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fonds-Finanz-Workflow (Architekturplan Abschnitt 8): Mitteilung parsen,
 * Kunde über die BESTEHENDE Matching-Engine finden (Abschnitt 5) oder
 * über die BESTEHENDE Auto-Anlage erstellen (Abschnitt 6), Vertrag
 * anlegen/ergänzen, externe Referenzen speichern.
 *
 * Datenquellen (in dieser Prioritaet zusammengefuehrt): Mail-Body
 * ("Label: Wert"), PDF-Anhaenge, und - entscheidend fuer reale Mails -
 * der BETREFF ("... zum Kunden <Name>, <Sparte>"). Ohne die
 * Betreff-Auswertung scheiterte frueher jede reale Benachrichtigung und
 * erzeugte nur "konnte nicht gelesen werden"-Aufgaben.
 *
 * Wichtig: Das Kunden-Matching läuft über die im TEXT/Betreff genannten
 * Kundendaten, NICHT über den Mail-Absender - der Absender ist die Fonds
 * Finanz selbst und darf niemals als Kunde angelegt werden.
 */
class FondsFinanzImportService
{
    public function __construct(
        private readonly FondsFinanzParser $parser,
        private readonly FondsFinanzSubjectParser $subjectParser,
        private readonly CustomerMatchingService $matcher,
        private readonly CustomerAutoCreationService $autoCreator,
        private readonly SystemUserResolver $systemUser,
        private readonly AttachmentAnalysisService $attachmentAnalysis,
        private readonly EmailAttachmentService $attachments,
    ) {
    }

    public function process(EmailMessage $message): void
    {
        $data = $this->parseMessage($message);

        if (!$data->hasCustomer()) {
            // Weder Vertragsnummer noch Kunde erkennbar (z. B. reine
            // Verwaltungs-/Serviceanfrage "Bitte bestätigen Sie Ihre
            // Angaben"): eine EINZELNE, mit Kontext versehene Aufgabe -
            // statt geratener Fachdaten.
            $this->finish($message, 'unmatched', null, 0);
            $this->createTask(
                $message, null,
                'Fonds-Finanz-Mail bearbeiten: ' . ($message->subject ?: '(kein Betreff)'),
                3, 'medium', $data
            );
            return;
        }

        if ($data->hasContract()) {
            $this->processWithContract($message, $data);
            return;
        }

        // Kunde bekannt, aber keine Vertragsnummer (haeufigster reale Fall,
        // z. B. "Neues Dokument zum Kunden ..."): Kunde zuordnen/anlegen und
        // das Dokument seiner Akte zufuehren, statt es liegen zu lassen.
        $this->processCustomerOnly($message, $data);
    }

    /**
     * Vertragsnummer vorhanden -> vollstaendiger Vertragsimport
     * (deterministisch ueber bekannte Nummer, sonst Score-Matching /
     * Neuanlage mit Duplikatsschutz).
     */
    private function processWithContract(EmailMessage $message, FondsFinanzData $data): void
    {
        // 1) Deterministische Referenz zuerst: Ist die Vertragsnummer bereits
        //    eindeutig im Bestand, steht der Kunde fest - kein Score-Raten.
        //    contract_number ist global UNIQUE: passt der genannte Kunde
        //    nicht zum Inhaber, ist das ein Datenkonflikt -> manuelle Pruefung.
        if (Contract::where('contract_number', $data->contractNumber)->exists()) {
            if ($customer = $this->customerByKnownContract($data)) {
                // Folge-Mitteilung zu bekanntem Vertrag/Kunde: automatisch,
                // ohne Routine-Aufgabe (Kunde ist bereits geprueft).
                $this->import($message, $data, $customer, null, null);
            } else {
                $this->finish($message, 'unmatched', null, null);
                $this->createTask($message, null, sprintf(
                    'Fonds-Finanz-Konflikt prüfen: Vertragsnummer %s existiert, genannter Kunde "%s" passt nicht zum Vertragsinhaber',
                    $data->contractNumber, $data->customerName
                ), 3, 'high', $data);
            }
            return;
        }

        $criteria = array_filter([
            'full_name' => $data->customerName,
            'birth_date' => $data->birthDate,
        ]);
        $match = $this->matcher->match($criteria);

        if ($match->tier() === 'confirm') {
            // 70-90%: kein automatischer Import an einen moeglicherweise
            // falschen Kunden - Vorschlag speichern, Mitarbeiter bestaetigt.
            $this->finish($message, 'suggested', $match->customer, $match->score);
            $this->createTask($message, $match->customer, sprintf(
                'Fonds-Finanz-Zuordnung bestätigen: "%s" (Vertrag %s, Übereinstimmung %d%%)',
                $data->customerName, $data->contractNumber, $match->score
            ), 3, 'high', $data);
            return;
        }

        if ($match->tier() === 'manual' && $match->hasMatch()) {
            // Schwacher Kandidat: KEINE automatische Neuanlage neben einem
            // aehnlichen Bestandskunden (Duplikatsschutz) - manuelle Pruefung.
            $this->finish($message, 'unmatched', null, $match->score);
            $this->createTask($message, null, sprintf(
                'Fonds-Finanz-Kunde "%s" manuell zuordnen (Vertrag %s)',
                $data->customerName, $data->contractNumber
            ), 3, 'high', $data);
            return;
        }

        if ($match->tier() === 'auto') {
            // >90%: hohe Konfidenz auf Bestandskunde - automatisch, ohne
            // Routine-Aufgabe (Dokument wird der Akte/dem Vertrag zugefuehrt).
            $this->import($message, $data, $match->customer, $match->score, null);
            return;
        }

        // Kein Kandidat -> Neuanlage (mit Duplikatsschutz im AutoCreator).
        try {
            $customer = $this->autoCreator->createFromUnmatched($criteria, 'fonds_finanz');
        } catch (DuplicateCustomerException) {
            $this->finish($message, 'unmatched', null, $match->score);
            $this->createTask($message, null, sprintf(
                'Fonds-Finanz-Kunde "%s" manuell zuordnen (Vertrag %s)',
                $data->customerName, $data->contractNumber
            ), 3, 'high', $data);
            return;
        }

        // Neu angelegter Kunde -> hier IST eine Pruefaufgabe sinnvoll
        // (Duplikat ausschliessen, Stammdaten vervollstaendigen).
        $this->import($message, $data, $customer, null, sprintf(
            'Neu angelegten Fonds-Finanz-Kunden prüfen: %s (Vertrag %s)',
            $data->customerName, $data->contractNumber
        ));
    }

    /**
     * Kunde bekannt, aber keine Vertragsnummer: reine Dokument-/
     * Kundenzuordnung. Ergebnis ist immer EINE Routing-Aufgabe
     * ("Dokument dem richtigen Vertrag zuordnen") mit vollem Kontext und
     * Link zur Mail - nie mehr ein kontextloses "konnte nicht gelesen
     * werden".
     */
    private function processCustomerOnly(EmailMessage $message, FondsFinanzData $data): void
    {
        $criteria = array_filter([
            'full_name' => $data->customerName,
            'birth_date' => $data->birthDate,
        ]);
        $match = $this->matcher->match($criteria);

        if ($match->tier() === 'confirm') {
            $this->finish($message, 'suggested', $match->customer, $match->score);
            $this->createTask($message, $match->customer, sprintf(
                'Fonds-Finanz-Dokument zuordnen bestätigen: "%s" (Übereinstimmung %d%%)',
                $data->customerName, $match->score
            ), 3, 'high', $data);
            return;
        }

        if ($match->tier() === 'manual' && $match->hasMatch()) {
            $this->finish($message, 'unmatched', null, $match->score);
            $this->createTask($message, null, sprintf(
                'Fonds-Finanz-Dokument manuell zuordnen: Kunde "%s"',
                $data->customerName
            ), 3, 'high', $data);
            return;
        }

        if ($match->tier() === 'auto') {
            $customer = $match->customer;
        } else {
            try {
                $customer = $this->autoCreator->createFromUnmatched($criteria, 'fonds_finanz');
            } catch (DuplicateCustomerException) {
                $this->finish($message, 'unmatched', null, $match->score);
                $this->createTask($message, null, sprintf(
                    'Fonds-Finanz-Dokument manuell zuordnen: Kunde "%s"',
                    $data->customerName
                ), 3, 'high', $data);
                return;
            }
        }

        $this->finish($message, 'confirmed', $customer, $match->tier() === 'auto' ? $match->score : null);

        // Anhaenge JETZT (bestaetigte Zuordnung) in die Akte uebernehmen.
        $this->attachments->createDocuments($message->fresh());

        ActivityLog::create([
            'user_id' => null,
            'action' => 'fonds_finanz_document',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode([
                'email_message_id' => (string) $message->id,
                'customer_name' => $data->customerName,
                'line' => $data->line,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // EINE Routing-Aufgabe: Dokument dem passenden Vertrag zuordnen.
        $this->createTask($message, $customer, sprintf(
            'Fonds-Finanz-Dokument dem Vertrag zuordnen: %s', $data->customerName
        ), 5, 'medium', $data);
    }

    /**
     * Mail-Body + PDF-Anhaenge + Betreff zu EINEM Datensatz zusammenfuehren.
     * Prioritaet: strukturierter Body -> PDF -> Betreff (Body/PDF sind
     * praeziser, der Betreff fuellt die haeufig einzige Kundenangabe auf).
     */
    private function parseMessage(EmailMessage $message): FondsFinanzData
    {
        $data = $this->parser->parse((string) $message->body_text);

        if (!$data->isImportable()) {
            $pdfText = $this->attachmentAnalysis->textFromPdfAttachments($message);
            if ($pdfText !== '') {
                $data = $data->mergeMissing($this->parser->parse($pdfText));
            }
        }

        // Betreff immer als Fallback fuer fehlende Felder heranziehen.
        return $data->mergeMissing($this->subjectParser->parse($message->subject));
    }

    public function importForCustomer(EmailMessage $message, Customer $customer): void
    {
        $data = $this->parseMessage($message);

        if (!$data->hasContract()) {
            // Kein Vertrag im Text - trotzdem Zuordnung + Dokumentuebernahme
            // fuer den (durch den Mitarbeiter bestaetigten) Kunden.
            $this->finish($message, 'confirmed', $customer, $message->match_score);
            $this->attachments->createDocuments($message->fresh());
            return;
        }

        // Vertragsnummer darf nicht bereits einem ANDEREN Kunden gehören.
        $conflict = Contract::where('contract_number', $data->contractNumber)
            ->where('customer_id', '!=', $customer->id)->exists();
        if ($conflict) {
            $this->finish($message, 'confirmed', $customer, $message->match_score);
            $this->createTask($message, $customer, sprintf(
                'Fonds-Finanz-Konflikt prüfen: Vertragsnummer %s gehört einem anderen Kunden',
                $data->contractNumber
            ), 3, 'high', $data);
            return;
        }

        $this->import($message, $data, $customer, $message->match_score, null);
    }

    /**
     * Eindeutig bekannte Vertragsnummer -> Kunde steht fest. Zusätzliche
     * Absicherung: Der im Text genannte Kundenname muss grob zum
     * Bestandskunden passen, sonst manuelles Matching statt Blindzuordnung.
     */
    private function customerByKnownContract(FondsFinanzData $data): ?Customer
    {
        $contracts = Contract::where('contract_number', $data->contractNumber)->limit(2)->get();
        if ($contracts->count() !== 1) {
            return null; // unbekannt oder mehrdeutig -> normales Matching
        }

        $customer = $contracts->first()->customer;
        $existingName = mb_strtolower((string) $customer?->user?->name);
        $parsedName = mb_strtolower((string) $data->customerName);
        if ($customer === null || $existingName === '' || $parsedName === '') {
            return null;
        }

        similar_text($existingName, $parsedName, $percent);
        return $percent >= 50 ? $customer : null;
    }

    /**
     * @param ?string $reviewTaskTitle Wenn gesetzt, wird eine Pruefaufgabe
     *        angelegt (nur bei Faellen, die Menschen brauchen - z. B.
     *        Neuanlage). Bei vollautomatischen Importen zu bereits
     *        bekannten Kunden bleibt es aus (kein Aufgaben-Spam), die
     *        Uebernahme steht im ActivityLog und im Posteingang.
     */
    private function import(EmailMessage $message, FondsFinanzData $data, Customer $customer, ?int $score, ?string $reviewTaskTitle): void
    {
        DB::transaction(function () use ($message, $data, $customer, $score, $reviewTaskTitle) {
            $contract = $this->upsertContract($customer, $data);
            $this->storeReferences($customer, $contract, $data);

            $this->finish($message, 'confirmed', $customer, $score);

            // Anhaenge in die Akte uebernehmen und dem Vertrag zuordnen.
            $this->attachments->createDocuments($message->fresh());
            $this->attachments->linkDocumentsToContract($message->fresh(), $contract);

            ActivityLog::create([
                'user_id' => null,
                'action' => 'fonds_finanz_import',
                'entity_type' => 'contract',
                'entity_id' => $contract->id,
                'meta' => json_encode([
                    'customer_id' => (string) $customer->id,
                    'email_message_id' => (string) $message->id,
                    'contract_number' => $data->contractNumber,
                    'company' => $data->company,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            if ($reviewTaskTitle !== null) {
                $this->createTask($message, $customer, $reviewTaskTitle, 3, 'medium', $data, $contract);
            }
        });
    }

    /**
     * Vertrag über die Vertragsnummer finden und fehlende Felder ergänzen,
     * sonst neu anlegen. Vorhandene Werte werden NIE stillschweigend
     * überschrieben (Abschnitt 20.5: defensiv statt still falsch).
     */
    private function upsertContract(Customer $customer, FondsFinanzData $data): Contract
    {
        $contract = Contract::where('customer_id', $customer->id)
            ->where('contract_number', $data->contractNumber)
            ->first();

        if ($contract) {
            $contract->fill(array_filter([
                'insurer' => $contract->insurer ?: $data->company,
                'type' => $contract->type ?: $this->normalizeLine($data->line),
                'notes' => $contract->notes ?: $data->product,
            ]))->save();
            return $contract;
        }

        return Contract::create([
            'customer_id' => $customer->id,
            'contract_number' => $data->contractNumber,
            'type' => $this->normalizeLine($data->line) ?? 'andere',
            'insurer' => $data->company ?? '',
            'notes' => $data->product,
            // Wie gemeldete Verträge aus dem Self-Service: 'pending', bis das Team die Übernahme abschließt.
            'status' => 'pending',
        ]);
    }

    private function storeReferences(Customer $customer, Contract $contract, FondsFinanzData $data): void
    {
        if ($data->fondsFinanzNumber !== null) {
            ExternalReference::firstOrCreate([
                'referenceable_type' => Contract::class,
                'referenceable_id' => $contract->id,
                'type' => ExternalReference::TYPE_FONDS_FINANZ_NUMBER,
                'value' => $data->fondsFinanzNumber,
            ], ['source' => 'fonds_finanz']);
        }

        if ($data->documentNumber !== null) {
            ExternalReference::firstOrCreate([
                'referenceable_type' => Contract::class,
                'referenceable_id' => $contract->id,
                'type' => ExternalReference::TYPE_FONDS_FINANZ_DOCUMENT,
                'value' => $data->documentNumber,
            ], ['source' => 'fonds_finanz']);
        }
    }

    /**
     * Sparte auf die BESTEHENDEN contracts.type-Enum-Werte abbilden
     * (kfz|krankenversicherung|internet|strom_gas|andere) - bewusst keine
     * neuen Parallel-Kategorien; Unbekanntes landet in 'andere'.
     */
    private function normalizeLine(?string $line): ?string
    {
        if ($line === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($line));

        return match (true) {
            str_contains($normalized, 'kfz'), str_contains($normalized, 'kraftfahrt'), str_contains($normalized, 'auto') => 'kfz',
            str_contains($normalized, 'kranken'), $normalized === 'kv', $normalized === 'pkv', $normalized === 'gkv' => 'krankenversicherung',
            str_contains($normalized, 'strom'), str_contains($normalized, 'gas'), str_contains($normalized, 'energie') => 'strom_gas',
            str_contains($normalized, 'internet'), str_contains($normalized, 'dsl'), str_contains($normalized, 'telekommunikation') => 'internet',
            default => 'andere',
        };
    }

    private function finish(EmailMessage $message, string $matchStatus, ?Customer $customer, ?int $score): void
    {
        $message->forceFill([
            'category' => 'fonds_finanz',
            'match_status' => $matchStatus,
            'customer_id' => $customer?->id,
            'match_score' => $score,
            'processed_at' => now(),
        ])->save();
    }

    private function createTask(EmailMessage $message, ?Customer $customer, string $title, int $dueInDays, string $priority, ?FondsFinanzData $data = null, ?Contract $contract = null): void
    {
        Task::forceCreate([
            'id' => (string) Str::uuid(),
            'assigned_to' => $customer?->betreuer()->first()?->id ?? $this->systemUser->resolveId(),
            'created_by' => $this->systemUser->resolveId(),
            'customer_id' => $customer?->id,
            'email_message_id' => $message->id,
            'contract_id' => $contract?->id,
            'title' => $title,
            'description' => $this->taskDescription($message, $data),
            'type' => 'email',
            'status' => 'open',
            'priority' => $priority,
            'due_date' => now()->addDays($dueInDays)->toDateString(),
        ]);
    }

    /** Aussagekraeftige Beschreibung: was steht drin, was ist zu tun. */
    private function taskDescription(EmailMessage $message, ?FondsFinanzData $data): string
    {
        $parts = ['Ausgelöst durch Fonds-Finanz-Mail "' . ($message->subject ?: '(kein Betreff)') . '" von ' . $message->from_address . '.'];

        if ($data !== null) {
            $facts = array_filter([
                $data->customerName ? 'Kunde: ' . $data->customerName : null,
                $data->line ? 'Sparte: ' . $data->line : null,
                $data->company ? 'Gesellschaft: ' . $data->company : null,
                $data->contractNumber ? 'Vertragsnummer: ' . $data->contractNumber : null,
                $data->fondsFinanzNumber ? 'Vorgang/Nr.: ' . $data->fondsFinanzNumber : null,
            ]);
            if (!empty($facts)) {
                $parts[] = 'Erkannt: ' . implode(' · ', $facts) . '.';
            }
        }

        $attCount = count($message->attachments_meta ?? []);
        if ($attCount > 0) {
            $parts[] = $attCount . ' Anhang/Anhänge.';
        }

        return implode(' ', $parts);
    }
}
