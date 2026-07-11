<?php
namespace App\Services\Commission;

use App\Models\ActivityLog;
use App\Models\Commission;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Services\Mailbox\AttachmentAnalysisService;
use App\Services\Workflow\SystemUserResolver;
use Illuminate\Support\Str;

/**
 * Provisions-Workflow für eingehende Mails der Kategorie 'provisionen'
 * (Architekturplan Abschnitt 10): Partner erkennen, Gutschrift-Daten
 * lesen, Commission-Datensatz anlegen. Der Lexoffice-Beleg wird bewusst
 * NICHT hier erzeugt, sondern erst bei der Mitarbeiter-Buchung im
 * CommissionController (HITL Abschnitt 13: Buchung = Bestätigungsstufe).
 */
class CommissionWorkflowService
{
    public function __construct(
        private readonly PartnerRecognitionService $partnerRecognition,
        private readonly CommissionStatementParser $parser,
        private readonly SystemUserResolver $systemUser,
        private readonly AttachmentAnalysisService $attachmentAnalysis,
    ) {
    }

    public function process(EmailMessage $message): void
    {
        $partner = $this->partnerRecognition->recognize($message->from_address, $message->from_name);

        if ($partner === null) {
            // Unbekannter Absender: keine Blind-Erfassung, manuelle Prüfung.
            $this->finish($message);
            $this->createTask($message, sprintf(
                'Provisionsabrechnung prüfen: Absender "%s" ist kein bekannter Partner',
                $message->from_name ?: $message->from_address
            ));
            return;
        }

        $statement = $this->parser->parse((string) $message->body_text);

        // Phase 2 (PDF-Anhang-Analyse): Liefert der Mail-Text keine
        // Gutschrift-Daten, den Text der PDF-Anhänge parsen (typischer
        // Fall: Abrechnung nur als PDF, Mail-Body ist Boilerplate).
        if ($statement['credit_note_number'] === null && $statement['amount'] === null) {
            $pdfText = $this->attachmentAnalysis->textFromPdfAttachments($message);
            if ($pdfText !== '') {
                $statement = $this->parser->parse($pdfText);
            }
        }

        // Duplikatsschutz: dieselbe Gutschrift desselben Partners nur einmal.
        if ($statement['credit_note_number'] !== null) {
            $exists = Commission::where('partner_id', $partner->id)
                ->where('credit_note_number', $statement['credit_note_number'])
                ->exists();
            if ($exists) {
                $this->finish($message);
                return;
            }
        }

        $commission = Commission::create([
            'partner_id' => $partner->id,
            'credit_note_number' => $statement['credit_note_number'],
            'amount' => $statement['amount'],
            'statement_date' => $statement['date'],
            'status' => 'pending_review',
            'email_message_id' => $message->id,
        ]);

        ActivityLog::create([
            'user_id' => null,
            'action' => 'commission_received',
            'entity_type' => 'commission',
            'entity_id' => $commission->id,
            'meta' => json_encode([
                'partner_id' => (string) $partner->id,
                'credit_note_number' => $statement['credit_note_number'],
                'amount' => $statement['amount'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $this->finish($message);
        $this->createTask($message, sprintf(
            'Provisionsgutschrift prüfen und buchen: %s%s (%s)',
            $statement['credit_note_number'] ? 'Nr. ' . $statement['credit_note_number'] : 'ohne Nummer',
            $statement['amount'] !== null ? ', ' . number_format($statement['amount'], 2, ',', '.') . ' €' : '',
            $partner->name
        ));
    }

    private function finish(EmailMessage $message): void
    {
        $message->forceFill([
            'category' => 'provisionen',
            'match_status' => 'unmatched',
            'processed_at' => now(),
        ])->save();
    }

    private function createTask(EmailMessage $message, string $title): void
    {
        Task::forceCreate([
            'id' => (string) Str::uuid(),
            'assigned_to' => $this->systemUser->resolveId(),
            'created_by' => $this->systemUser->resolveId(),
            'customer_id' => null,
            'title' => $title,
            'description' => 'Ausgelöst durch E-Mail "' . ($message->subject ?: '(kein Betreff)') . '" von ' . $message->from_address,
            'type' => 'email',
            'status' => 'open',
            'priority' => 'medium',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);
    }
}
