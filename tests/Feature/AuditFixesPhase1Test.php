<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Mailbox\EmailAttachmentService;
use App\Services\Mailbox\MailboxMessageData;
use App\Services\Mailbox\MailboxProviderFactory;
use App\Services\Mailbox\MailboxProviderInterface;
use App\Services\Mailbox\MailboxSyncService;
use App\Services\Workflow\EmailWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit-Fixes Phase 1 (Prüfbericht 2026-07-11):
 * H1 - Anhänge erst nach bestätigter Zuordnung in die Akte
 * H2 - Zugriffsprüfung bei Bestätigen/Ablehnen im Posteingang
 * H3 - physische Dateien bei Kundenlöschung/Prune entfernen
 * T1 - Kundenname aus dem Mail-Text fürs Matching nutzen
 */
class AuditFixesPhase1Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function customer(string $name = 'Erika Musterfrau', ?string $birthDate = '1985-03-15', string $email = 'erika@kunde.de'): Customer
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'customer', 'email' => $email]);
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'K-' . uniqid(), 'birth_date' => $birthDate]);
    }

    private function account(): EmailAccount
    {
        return EmailAccount::firstOrCreate(
            ['email_address' => 'info@dienstly24.de'],
            ['name' => 'Test', 'provider' => 'imap', 'folders' => ['INBOX'], 'is_active' => true]
        );
    }

    private function sync(array $mails): MailboxSyncService
    {
        $factory = new class($mails) extends MailboxProviderFactory {
            public function __construct(private array $mails) {}
            public function make(EmailAccount $account): MailboxProviderInterface
            {
                return new class($this->mails) implements MailboxProviderInterface {
                    public function __construct(private array $mails) {}
                    public function testConnection(EmailAccount $a): bool { return true; }
                    public function fetchNewMessages(EmailAccount $a, int $l = 50): array { return $this->mails; }
                };
            }
        };

        return new MailboxSyncService($factory, app(EmailWorkflowService::class), app(EmailAttachmentService::class));
    }

    private function suggestedMailWithAttachment(): MailboxMessageData
    {
        // E-Mail 20 + Name 30 + Geburtsdatum 40 = 90 -> suggested (70-90)
        return new MailboxMessageData(
            uid: 'INBOX:' . uniqid(),
            fromAddress: 'erika@kunde.de',
            fromName: 'Erika Musterfrau',
            toAddress: 'info@dienstly24.de',
            subject: 'Unterlagen',
            bodyText: "Anbei.\ngeb. 15.03.1985",
            bodyHtml: null,
            receivedAt: now(),
            attachments: [['filename' => 'unterlagen.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 inhalt']],
        );
    }

    // ---------------- H1 ----------------

    public function test_h1_suggested_match_stores_files_but_no_document(): void
    {
        $customer = $this->customer();
        $this->sync([$this->suggestedMailWithAttachment()])->syncAccount($this->account());

        $message = EmailMessage::first();
        $this->assertSame('suggested', $message->match_status);
        // Datei liegt neutral am Message-Datensatz ...
        $this->assertNotEmpty($message->attachments_meta);
        Storage::disk('local')->assertExists($message->attachments_meta[0]['path']);
        // ... aber KEIN Dokument in irgendeiner Kundenakte.
        $this->assertSame(0, Document::count());
    }

    public function test_h1_confirmation_transfers_attachment_into_customer_file(): void
    {
        $customer = $this->customer();
        $this->sync([$this->suggestedMailWithAttachment()])->syncAccount($this->account());
        $message = EmailMessage::first();

        $this->actingAs($this->admin)->post(route('admin.email_inbox.confirm', $message->id));

        $document = Document::first();
        $this->assertNotNull($document, 'Bestätigung muss den Anhang in die Akte übernehmen');
        $this->assertSame((string) $customer->id, (string) $document->customer_id);
        $this->assertSame('unterlagen.pdf', $document->file_name);
        $this->assertSame('internal', $document->visibility);

        // Idempotenz: erneute Übernahme erzeugt kein zweites Dokument.
        app(EmailAttachmentService::class)->createDocuments($message->fresh());
        $this->assertSame(1, Document::count());
    }

    public function test_h1_rejection_leaves_no_document_anywhere(): void
    {
        $this->customer();
        $this->sync([$this->suggestedMailWithAttachment()])->syncAccount($this->account());
        $message = EmailMessage::first();

        $this->actingAs($this->admin)->post(route('admin.email_inbox.reject', $message->id));

        $this->assertSame('unmatched', $message->fresh()->match_status);
        $this->assertSame(0, Document::count());
    }

    public function test_h1_manual_assignment_after_rejection_transfers_to_correct_customer(): void
    {
        $wrong = $this->customer();
        $this->sync([$this->suggestedMailWithAttachment()])->syncAccount($this->account());
        $message = EmailMessage::first();
        $this->actingAs($this->admin)->post(route('admin.email_inbox.reject', $message->id));

        $right = $this->customer('Erika Zweitfrau', '1985-03-15', 'zweitfrau@kunde.de');
        $this->actingAs($this->admin)->post(route('admin.email_inbox.assign', $message->id), [
            'customer_id' => (string) $right->id,
        ]);

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame((string) $right->id, (string) $document->customer_id);
        $this->assertSame(0, Document::where('customer_id', $wrong->id)->count());
    }

    public function test_h1_auto_match_over_90_still_creates_documents_at_sync(): void
    {
        // Bestandsverhalten für die Auto-Stufe bleibt: >90 -> sofort Akte.
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Anna Beispiel', 'email' => 'anna@example.com']);
        Customer::create(['user_id' => $user->id, 'customer_number' => 'K-A1', 'birth_date' => '1990-05-04', 'phone' => '030555555']);

        $mail = new MailboxMessageData(
            uid: 'INBOX:auto', fromAddress: 'anna@example.com', fromName: 'Anna Beispiel',
            toAddress: null, subject: 'Unterlagen', bodyText: "geb. 04.05.1990\nTel.: 030555555",
            bodyHtml: null, receivedAt: null,
            attachments: [['filename' => 'nachweis.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 x']],
        );
        $this->sync([$mail])->syncAccount($this->account());

        $this->assertSame('confirmed', EmailMessage::first()->match_status);
        $this->assertSame(1, Document::count());
    }

    // ---------------- H2 ----------------

    public function test_h2_confirm_requires_customer_access(): void
    {
        $customer = $this->customer();
        $message = EmailMessage::create([
            'email_account_id' => $this->account()->id, 'message_uid' => 'INBOX:h2',
            'from_address' => 'x@y.de', 'subject' => 'T', 'category' => 'kundenanfrage',
            'match_status' => 'suggested', 'customer_id' => $customer->id, 'match_score' => 75, 'processed_at' => now(),
        ]);

        $support = User::factory()->create(['role' => 'support', 'can_see_all_customers' => false]);

        $this->actingAs($support)->post(route('admin.email_inbox.confirm', $message->id))->assertForbidden();
        $this->actingAs($support)->post(route('admin.email_inbox.reject', $message->id))->assertForbidden();
        $this->assertSame('suggested', $message->fresh()->match_status);

        // Mit Zugriff (admin) funktioniert es weiterhin.
        $this->actingAs($this->admin)->post(route('admin.email_inbox.confirm', $message->id))->assertRedirect();
        $this->assertSame('confirmed', $message->fresh()->match_status);
    }

    // ---------------- H3 ----------------

    public function test_h3_customer_deletion_removes_files_from_disk(): void
    {
        $customer = $this->customer();

        // Reguläres Dokument + E-Mail mit Anhangdatei
        Storage::disk('local')->put("customers/{$customer->id}/documents/ausweis.pdf", 'inhalt');
        Document::create([
            'customer_id' => $customer->id, 'category' => 'identity',
            'file_name' => 'ausweis.pdf', 'file_path' => "customers/{$customer->id}/documents/ausweis.pdf",
            'disk' => 'local', 'visibility' => 'customer',
        ]);
        $message = EmailMessage::create([
            'email_account_id' => $this->account()->id, 'message_uid' => 'INBOX:h3',
            'from_address' => 'erika@kunde.de', 'subject' => 'T',
            'match_status' => 'confirmed', 'customer_id' => $customer->id, 'processed_at' => now(),
        ]);
        app(EmailAttachmentService::class)->storeFiles($message, [
            ['filename' => 'anhang.pdf', 'mime' => 'application/pdf', 'content' => 'x'],
        ]);
        $attachmentPath = $message->fresh()->attachments_meta[0]['path'];
        Storage::disk('local')->assertExists($attachmentPath);

        $this->actingAs($this->admin)->delete(route('admin.customers.delete', $customer->id));

        Storage::disk('local')->assertMissing("customers/{$customer->id}/documents/ausweis.pdf");
        Storage::disk('local')->assertMissing($attachmentPath);
        $this->assertDatabaseMissing('email_messages', ['id' => $message->id]);
    }

    public function test_h3_prune_removes_attachment_files(): void
    {
        $message = EmailMessage::create([
            'email_account_id' => $this->account()->id, 'message_uid' => 'INBOX:h3p',
            'from_address' => 'wer@da.de', 'subject' => 'Alt', 'processed_at' => now(),
        ]);
        app(EmailAttachmentService::class)->storeFiles($message, [
            ['filename' => 'alt.pdf', 'mime' => 'application/pdf', 'content' => 'x'],
        ]);
        $path = $message->fresh()->attachments_meta[0]['path'];
        $message->forceFill(['created_at' => now()->subDays(120)])->save();

        $this->artisan('emails:prune-unmatched')->assertSuccessful();

        $this->assertDatabaseMissing('email_messages', ['id' => $message->id]);
        Storage::disk('local')->assertMissing($path);
    }

    // ---------------- T1 ----------------

    public function test_t1_customer_name_in_body_reaches_suggestion_tier(): void
    {
        $customer = $this->customer(); // Erika, geb. 1985-03-15

        // Versicherer schreibt, Kundin steht nur im TEXT:
        // Name 30 + Geburtsdatum 40 = 70 -> suggested (vorher: 52 -> unmatched)
        $mail = new MailboxMessageData(
            uid: 'INBOX:t1', fromAddress: 'service@versicherer-xy.de', fromName: 'Versicherer XY',
            toAddress: 'info@dienstly24.de', subject: 'Unterlagen einreichen',
            bodyText: "Bitte Unterlagen einreichen.\nKunde: Erika Musterfrau\ngeb. 15.03.1985",
            bodyHtml: null, receivedAt: now(),
        );
        $this->sync([$mail])->syncAccount($this->account());

        $message = EmailMessage::first();
        $this->assertSame('suggested', $message->match_status);
        $this->assertSame((string) $customer->id, (string) $message->customer_id);
        $this->assertGreaterThanOrEqual(70, (int) $message->match_score);
    }

    public function test_t1_sender_email_not_credited_when_third_party_names_customer(): void
    {
        // Kundin existiert mit der Absenderadresse des VERSICHERERS als
        // E-Mail? Nein - umgekehrt: Der Versicherer nennt einen fremden
        // Namen; die Versicherer-Adresse darf nicht als Kunden-E-Mail
        // gewertet werden (kein 20-Punkte-Bonus für Dritte).
        $this->customer('Erika Musterfrau', '1985-03-15', 'service@versicherer-xy.de');

        $mail = new MailboxMessageData(
            uid: 'INBOX:t1b', fromAddress: 'service@versicherer-xy.de', fromName: 'Versicherer XY',
            toAddress: 'info@dienstly24.de', subject: 'Unterlagen',
            bodyText: "Kunde: Ganz Anderer Name", bodyHtml: null, receivedAt: now(),
        );
        $this->sync([$mail])->syncAccount($this->account());

        // Ohne E-Mail-Signal und mit fremdem Namen: kein Vorschlag >= 70.
        $this->assertNotSame('suggested', EmailMessage::first()->match_status);
        $this->assertNotSame('confirmed', EmailMessage::first()->match_status);
    }
}
