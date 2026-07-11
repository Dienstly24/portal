<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\Mailbox\MailboxMessageData;
use App\Services\Mailbox\MailboxProviderFactory;
use App\Services\Mailbox\MailboxProviderInterface;
use App\Services\Mailbox\MailboxSyncService;
use App\Services\Workflow\EmailWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MailboxSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['role' => 'admin']);
        Storage::fake('local');
    }

    private function account(): EmailAccount
    {
        return EmailAccount::create([
            'name' => 'Test-Postfach',
            'email_address' => 'info@dienstly24.de',
            'provider' => 'imap',
            'folders' => ['INBOX'],
            'is_active' => true,
        ]);
    }

    /** Baut einen MailboxSyncService mit einer Fake-Factory, die feste Testdaten statt einer echten IMAP-Verbindung liefert. */
    private function serviceWithQueue(array $queue): MailboxSyncService
    {
        $factory = new class($queue) extends MailboxProviderFactory {
            public function __construct(private readonly array $queue)
            {
            }

            public function make($account): MailboxProviderInterface
            {
                $queue = $this->queue;
                return new class($queue) implements MailboxProviderInterface {
                    public function __construct(private readonly array $queue)
                    {
                    }

                    public function testConnection($account): bool
                    {
                        return true;
                    }

                    public function fetchNewMessages($account, int $limit = 50): array
                    {
                        return $this->queue;
                    }
                };
            }
        };

        return new MailboxSyncService($factory, app(EmailWorkflowService::class), app(\App\Services\Mailbox\EmailAttachmentService::class));
    }

    public function test_sync_stores_new_message_and_marks_account_synced(): void
    {
        $account = $this->account();
        $data = new MailboxMessageData(
            uid: 'INBOX:1',
            fromAddress: 'kunde@example.com',
            fromName: 'Kunde Beispiel',
            toAddress: 'info@dienstly24.de',
            subject: 'Ich habe eine Frage zu meinem Konto',
            bodyText: 'Bitte um Rückruf.',
            bodyHtml: null,
            receivedAt: Carbon::now(),
        );

        $stored = $this->serviceWithQueue([$data])->syncAccount($account);

        $this->assertSame(1, $stored);
        $this->assertSame(1, EmailMessage::count());
        $account->refresh();
        $this->assertNotNull($account->last_synced_at);
        $this->assertNull($account->last_error);
    }

    public function test_sync_is_idempotent_on_repeated_uid(): void
    {
        $account = $this->account();
        $data = new MailboxMessageData(
            uid: 'INBOX:dup',
            fromAddress: 'kunde@example.com',
            fromName: 'Kunde Beispiel',
            toAddress: null,
            subject: 'Frage',
            bodyText: null,
            bodyHtml: null,
            receivedAt: null,
        );

        $service = $this->serviceWithQueue([$data]);
        $this->assertSame(1, $service->syncAccount($account));
        $this->assertSame(0, $service->syncAccount($account)); // gleiche UID - kein zweiter Datensatz

        $this->assertSame(1, EmailMessage::count());
    }

    public function test_attachments_are_stored_as_documents_once_customer_is_matched(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Anna Beispiel', 'email' => 'anna@example.com']);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-ANNA001',
            'birth_date' => '1990-05-04',
            'phone' => '030555555',
        ]);

        $account = $this->account();
        $data = new MailboxMessageData(
            uid: 'INBOX:2',
            fromAddress: 'anna@example.com',
            fromName: 'Anna Beispiel',
            toAddress: null,
            subject: 'Schadenmeldung mit Unterlagen',
            bodyText: "geb. 04.05.1990\nTel.: 030555555",
            bodyHtml: null,
            receivedAt: null,
            attachments: [
                ['filename' => 'nachweis.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 test content'],
            ],
        );

        $this->serviceWithQueue([$data])->syncAccount($account);

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame((string) $customer->id, (string) $document->customer_id);
        $this->assertSame('internal', $document->visibility);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_connection_failure_is_recorded_without_throwing(): void
    {
        $account = $this->account();
        $factory = new class extends MailboxProviderFactory {
            public function make($account): MailboxProviderInterface
            {
                return new class implements MailboxProviderInterface {
                    public function testConnection($account): bool
                    {
                        return true;
                    }

                    public function fetchNewMessages($account, int $limit = 50): array
                    {
                        throw new \RuntimeException('Verbindung fehlgeschlagen (simuliert)');
                    }
                };
            }
        };

        $service = new MailboxSyncService($factory, app(EmailWorkflowService::class), app(\App\Services\Mailbox\EmailAttachmentService::class));
        $stored = $service->syncAccount($account);

        $this->assertSame(0, $stored);
        $this->assertSame('Verbindung fehlgeschlagen (simuliert)', $account->fresh()->last_error);
    }
}
