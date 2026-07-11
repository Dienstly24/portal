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
 * Wichtig: Das Kunden-Matching läuft hier über die im TEXT genannten
 * Kundendaten ("Kunde: ...", "Geburtsdatum: ..."), NICHT über den
 * Mail-Absender - der Absender ist die Fonds Finanz selbst und darf
 * niemals als Kunde angelegt werden.
 */
class FondsFinanzImportService
{
    public function __construct(
        private readonly FondsFinanzParser $parser,
        private readonly CustomerMatchingService $matcher,
        private readonly CustomerAutoCreationService $autoCreator,
        private readonly SystemUserResolver $systemUser,
    ) {
    }

    public function process(EmailMessage $message): void
    {
        $data = $this->parser->parse((string) $message->body_text);

        if (!$data->isImportable()) {
            // Defensives Scheitern: unbekanntes Layout -> manuelle Prüfung statt geratener Daten.
            $this->finish($message, 'unmatched', null, 0);
            $this->createTask($message, null, 'Fonds-Finanz-Mail konnte nicht automatisch gelesen werden – manuell prüfen', 3, 'high');
            return;
        }

        // 1) Deterministische Referenz zuerst (Abschnitt 8: "ergänzt um
        //    Fonds-Finanz-Nummer/Vertragsnummer, falls bereits bekannt"):
        //    Ist die Vertragsnummer bereits eindeutig im Bestand, steht der
        //    Kunde fest - kein Score-Raten für Folge-Mitteilungen.
        //    contract_number ist global UNIQUE: Existiert die Nummer, aber
        //    der genannte Kunde passt nicht dazu, ist das ein Datenkonflikt
        //    -> zwingend manuelle Prüfung, nie Blindzuordnung/Neuanlage.
        if (Contract::where('contract_number', $data->contractNumber)->exists()) {
            if ($customer = $this->customerByKnownContract($data)) {
                $this->import($message, $data, $customer, null);
            } else {
                $this->finish($message, 'unmatched', null, null);
                $this->createTask($message, null, sprintf(
                    'Fonds-Finanz-Konflikt prüfen: Vertragsnummer %s existiert, genannter Kunde "%s" passt nicht zum Vertragsinhaber',
                    $data->contractNumber, $data->customerName
                ), 3, 'high');
            }
            return;
        }

        $criteria = array_filter([
            'full_name' => $data->customerName,
            'birth_date' => $data->birthDate,
        ]);
        $match = $this->matcher->match($criteria);

        if ($match->tier() === 'confirm') {
            // 70-90%: kein automatischer Import an einen möglicherweise
            // falschen Kunden - Vorschlag speichern, Mitarbeiter bestätigt (Abschnitt 13).
            $this->finish($message, 'suggested', $match->customer, $match->score);
            $this->createTask($message, $match->customer, sprintf(
                'Fonds-Finanz-Zuordnung bestätigen: "%s" (Vertrag %s, Übereinstimmung %d%%)',
                $data->customerName, $data->contractNumber, $match->score
            ), 3, 'high');
            return;
        }

        if ($match->tier() === 'manual' && $match->hasMatch()) {
            // Es gibt einen (schwachen) Kandidaten: KEINE automatische
            // Neuanlage neben einem ähnlichen Bestandskunden (Duplikatsschutz,
            // Abschnitt 6) - manuelle Prüfung.
            $this->finish($message, 'unmatched', null, $match->score);
            $this->createTask($message, null, sprintf(
                'Fonds-Finanz-Kunde "%s" manuell zuordnen (Vertrag %s)',
                $data->customerName, $data->contractNumber
            ), 3, 'high');
            return;
        }

        $score = null;
        if ($match->tier() === 'auto') {
            $customer = $match->customer;
            $score = $match->score;
        } else {
            try {
                $customer = $this->autoCreator->createFromUnmatched($criteria, 'fonds_finanz');
            } catch (DuplicateCustomerException) {
                // Sicherheitsnetz hat doch einen Kandidaten gefunden -> manuelle Prüfung.
                $this->finish($message, 'unmatched', null, $match->score);
                $this->createTask($message, null, sprintf(
                    'Fonds-Finanz-Kunde "%s" manuell zuordnen (Vertrag %s)',
                    $data->customerName, $data->contractNumber
                ), 3, 'high');
                return;
            }
        }

        $this->import($message, $data, $customer, $score);
    }

    /**
     * Import nach expliziter Mitarbeiter-Bestätigung (HITL-Stufe 70-90%
     * bzw. manuelle Zuordnung im Posteingang): Der Mensch hat den Kunden
     * festgelegt - es wird nicht erneut gematcht. Ist die Mitteilung
     * nicht parsebar, wird nur die Zuordnung gespeichert.
     */
    public function importForCustomer(EmailMessage $message, Customer $customer): void
    {
        $data = $this->parser->parse((string) $message->body_text);

        if (!$data->isImportable()) {
            $this->finish($message, 'confirmed', $customer, $message->match_score);
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
            ), 3, 'high');
            return;
        }

        $this->import($message, $data, $customer, $message->match_score);
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

    private function import(EmailMessage $message, FondsFinanzData $data, Customer $customer, ?int $score): void
    {
        DB::transaction(function () use ($message, $data, $customer, $score) {
            $contract = $this->upsertContract($customer, $data);
            $this->storeReferences($customer, $contract, $data);

            $this->finish($message, 'confirmed', $customer, $score);

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

            $this->createTask($message, $customer, sprintf(
                'Fonds-Finanz-Import prüfen: Vertrag %s%s',
                $data->contractNumber,
                $data->company ? ' (' . $data->company . ')' : ''
            ), 3, 'medium');
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

    private function createTask(EmailMessage $message, ?Customer $customer, string $title, int $dueInDays, string $priority): void
    {
        Task::forceCreate([
            'id' => (string) Str::uuid(),
            'assigned_to' => $customer?->betreuer()->first()?->id ?? $this->systemUser->resolveId(),
            'created_by' => $this->systemUser->resolveId(),
            'customer_id' => $customer?->id,
            'title' => $title,
            'description' => 'Ausgelöst durch Fonds-Finanz-Mail "' . ($message->subject ?: '(kein Betreff)') . '" von ' . $message->from_address,
            'type' => 'email',
            'status' => 'open',
            'priority' => $priority,
            'due_date' => now()->addDays($dueInDays)->toDateString(),
        ]);
    }
}
