<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\ExternalReference;
use App\Models\Partner;
use App\Models\Commission;
use App\Models\User;
use App\Services\Mailbox\AttachmentAnalysisService;
use App\Services\Mailbox\EmailAttachmentService;
use App\Services\Mailbox\PdfTextExtractor;
use App\Services\Workflow\EmailWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 2: PDF-Anhang-Analyse, Dokumentenerkennung und automatische
 * Zuordnung von Dokumenten zu Vorgängen (Verträgen).
 */
class AttachmentAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        User::factory()->create(['role' => 'admin']);
    }

    private function account(): EmailAccount
    {
        return EmailAccount::firstOrCreate(
            ['email_address' => 'info@dienstly24.de'],
            ['name' => 'Test', 'provider' => 'imap', 'folders' => ['INBOX'], 'is_active' => true]
        );
    }

    /** Minimal gültiges PDF (mit korrekter xref-Tabelle) mit Textinhalt. */
    private function pdfWithText(string $text): string
    {
        // Jede Zeile als eigener Textblock mit eigener Y-Position -
        // so erzeugen echte PDF-Generatoren Zeilen, und der Parser
        // liefert sie als getrennte Zeilen zurück.
        $streamBody = '';
        $y = 712;
        foreach (explode("\n", $text) as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $streamBody .= "BT /F1 12 Tf 72 $y Td ($escaped) Tj ET\n";
            $y -= 14;
        }
        $streamBody = rtrim($streamBody);

        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
            4 => "<< /Length " . strlen($streamBody) . " >>\nstream\n$streamBody\nendstream",
            5 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n$body\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";

        return $pdf;
    }

    public function test_pdf_text_extractor_reads_text_and_fails_defensively(): void
    {
        Storage::disk('local')->put('t/ok.pdf', $this->pdfWithText('Vertragsnummer: XY-1'));
        Storage::disk('local')->put('t/kaputt.pdf', 'kein echtes pdf');

        $extractor = app(PdfTextExtractor::class);
        $this->assertStringContainsString('Vertragsnummer: XY-1', (string) $extractor->extractFromStorage('local', 't/ok.pdf'));
        $this->assertNull($extractor->extractFromStorage('local', 't/kaputt.pdf'));
        $this->assertNull($extractor->extractFromStorage('local', 't/fehlt.pdf'));
    }

    public function test_document_categorization_by_filename_and_text(): void
    {
        $analysis = app(AttachmentAnalysisService::class);

        $this->assertSame('police', $analysis->categorize('Versicherungsschein_2026.pdf'));
        $this->assertSame('invoice', $analysis->categorize('scan.pdf', 'Rechnung Nr. 4711 über 100 EUR'));
        $this->assertSame('claim', $analysis->categorize('schadenmeldung-kfz.pdf'));
        $this->assertSame('identity', $analysis->categorize('personalausweis.jpg'));
        $this->assertSame('other', $analysis->categorize('foto.jpg', 'Urlaubsgruß'));
    }

    public function test_fonds_finanz_import_falls_back_to_pdf_attachment(): void
    {
        $account = $this->account();
        $message = EmailMessage::create([
            'email_account_id' => $account->id, 'message_uid' => 'INBOX:ffpdf',
            'from_address' => 'service@fondsfinanz.de', 'from_name' => 'Fonds Finanz',
            'subject' => 'Neue Vertragsinformation',
            'body_text' => 'Details siehe beigefügtes Dokument.', // Body allein nicht importierbar
        ]);
        app(EmailAttachmentService::class)->storeFiles($message, [[
            'filename' => 'antrag.pdf', 'mime' => 'application/pdf',
            'content' => $this->pdfWithText("Kunde: Petra Pdfkundin\nVertragsnummer: PDF-100\nGesellschaft: Allianz\nSparte: Kfz"),
        ]]);

        app(EmailWorkflowService::class)->process($message->fresh());

        $message->refresh();
        $this->assertSame('confirmed', $message->match_status, 'PDF-Inhalt muss den Import speisen');

        $contract = Contract::where('contract_number', 'PDF-100')->first();
        $this->assertNotNull($contract);
        $this->assertSame('kfz', $contract->type);

        // Automatische Zuordnung: Anhang-Dokument hängt am Kunden UND am Vertrag.
        $document = Document::where('file_name', 'antrag.pdf')->first();
        $this->assertNotNull($document);
        $this->assertSame((string) $contract->customer_id, (string) $document->customer_id);
        $this->assertSame((string) $contract->id, (string) $document->contract_id);
        $this->assertSame('contract', $document->category); // "antrag" -> Vertragskategorie
    }

    public function test_commission_workflow_falls_back_to_pdf_attachment(): void
    {
        Partner::create(['name' => 'Fonds Finanz Maklerservice GmbH', 'email_domains' => ['fondsfinanz.de'], 'is_active' => true]);

        $message = EmailMessage::create([
            'email_account_id' => $this->account()->id, 'message_uid' => 'INBOX:provpdf',
            'from_address' => 'provision@fondsfinanz.de', 'from_name' => 'Fonds Finanz',
            'subject' => 'Ihre Provisionsabrechnung',
            'body_text' => 'Ihre Abrechnung finden Sie im Anhang.',
        ]);
        app(EmailAttachmentService::class)->storeFiles($message, [[
            'filename' => 'gutschrift.pdf', 'mime' => 'application/pdf',
            'content' => $this->pdfWithText("Gutschrift-Nr: GS-PDF-1\nBetrag: 500,00\nDatum: 01.07.2026"),
        ]]);

        app(EmailWorkflowService::class)->process($message->fresh());

        $commission = Commission::first();
        $this->assertNotNull($commission, 'Gutschrift-Daten müssen aus dem PDF gelesen werden');
        $this->assertSame('GS-PDF-1', $commission->credit_note_number);
        $this->assertSame('500.00', (string) $commission->amount);
        $this->assertSame('pending_review', $commission->status);
    }

    public function test_scanned_pdf_without_text_falls_back_to_manual_task(): void
    {
        $message = EmailMessage::create([
            'email_account_id' => $this->account()->id, 'message_uid' => 'INBOX:scan',
            'from_address' => 'service@fondsfinanz.de', 'from_name' => 'Fonds Finanz',
            'subject' => 'Vertragsinformation',
            'body_text' => 'Siehe Anhang.',
        ]);
        app(EmailAttachmentService::class)->storeFiles($message, [[
            'filename' => 'scan.pdf', 'mime' => 'application/pdf', 'content' => 'kein-echtes-pdf',
        ]]);

        app(EmailWorkflowService::class)->process($message->fresh());

        // Defensiv: nichts importiert, manuelle Prüfaufgabe statt geratener Daten.
        $this->assertSame('unmatched', $message->fresh()->match_status);
        $this->assertSame(0, Contract::count());
        $this->assertSame(0, Customer::count());
    }
}
