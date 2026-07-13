<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConsent;
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
 * Einwilligungsbasierte E-Mail-Verbindung (Variante A): Consent-Erteilung,
 * Widerruf und die zweckgebundene Import-Pipeline (nur Vertragspost von
 * Whitelist-Absendern eines einwilligenden Kunden wird verarbeitet).
 */
class CustomerEmailImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['role' => 'admin']); // System-User fuer Task-Erstellung
        Storage::fake('local');
    }

    private function importAccount(): EmailAccount
    {
        return EmailAccount::create([
            'name' => 'Import',
            'email_address' => 'import@dienstly24.de',
            'provider' => 'imap',
            'folders' => ['INBOX'],
            'is_active' => true,
            'is_customer_import' => true,
        ]);
    }

    private function customerWithConsent(): array
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Max Kunde']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'K-IMP1']);
        $consent = CustomerConsent::create([
            'customer_id' => $customer->id,
            'type' => CustomerConsent::TYPE_EMAIL_PROCESSING,
            'granted_at' => now(),
            'consent_text_version' => CustomerConsent::EMAIL_TEXT_VERSION,
            'import_token' => CustomerConsent::newImportToken(),
        ]);

        return [$customer, $consent];
    }

    private function serviceWithQueue(array $queue): MailboxSyncService
    {
        $factory = new class($queue) extends MailboxProviderFactory {
            public function __construct(private readonly array $queue) {}
            public function make($account): MailboxProviderInterface
            {
                $queue = $this->queue;
                return new class($queue) implements MailboxProviderInterface {
                    public function __construct(private readonly array $queue) {}
                    public function testConnection($account): bool { return true; }
                    public function fetchNewMessages($account, int $limit = 50): array { return $this->queue; }
                };
            }
        };

        return new MailboxSyncService($factory, app(EmailWorkflowService::class), app(EmailAttachmentService::class));
    }

    private function message(string $importAddress, string $from, array $attachments = []): MailboxMessageData
    {
        return new MailboxMessageData(
            uid: 'INBOX:' . uniqid(),
            fromAddress: $from,
            fromName: 'Absender',
            toAddress: 'max@gmail.com', // Weiterleitung: To bleibt Kundenadresse
            subject: 'Ihre Police 2026',
            bodyText: 'Anbei Ihre Unterlagen.',
            bodyHtml: null,
            receivedAt: now(),
            attachments: $attachments,
            headers: ['delivered_to' => $importAddress], // echte Zustelladresse
        );
    }

    public function test_connection_page_renders_in_both_states(): void
    {
        [$customer, $consent] = $this->customerWithConsent();

        // Aktive Einwilligung: Import-Adresse sichtbar.
        $address = 'import+' . $consent->import_token . '@dienstly24.de';
        $this->actingAs($customer->user)->get(route('portal.email_connection'))
            ->assertOk()->assertSee($address);

        // Ohne Einwilligung: Einwilligungs-Formular statt Adresse.
        $other = User::factory()->create(['role' => 'customer']);
        $this->actingAs($other)->get(route('portal.email_connection'))
            ->assertOk()->assertSee(route('portal.email_connection.grant'));
    }

    public function test_grant_creates_consent_with_token_and_proof(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)
            ->post(route('portal.email_connection.grant'), ['consent' => '1'])
            ->assertRedirect(route('portal.email_connection'));

        $consent = CustomerConsent::first();
        $this->assertNotNull($consent);
        $this->assertTrue($consent->isActive());
        $this->assertNotEmpty($consent->import_token);
        $this->assertSame(CustomerConsent::EMAIL_TEXT_VERSION, $consent->consent_text_version);
        $this->assertNotNull($consent->ip_address);
    }

    public function test_grant_requires_checkbox(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)
            ->post(route('portal.email_connection.grant'), [])
            ->assertSessionHasErrors('consent');

        $this->assertSame(0, CustomerConsent::count());
    }

    public function test_revoke_stops_processing(): void
    {
        [$customer, $consent] = $this->customerWithConsent();
        $this->assertTrue($customer->hasActiveEmailConsent());

        $this->actingAs($customer->user)
            ->post(route('portal.email_connection.revoke'))
            ->assertRedirect(route('portal.email_connection'));

        $this->assertNotNull($consent->fresh()->revoked_at);
        $this->assertFalse($customer->fresh()->hasActiveEmailConsent());
    }

    public function test_forwarded_insurer_mail_binds_to_consenting_customer(): void
    {
        [$customer, $consent] = $this->customerWithConsent();
        $account = $this->importAccount();
        $address = 'import+' . $consent->import_token . '@dienstly24.de';

        $data = $this->message($address, 'service@allianz.de', [
            ['filename' => 'police.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 police'],
        ]);

        $stored = $this->serviceWithQueue([$data])->syncAccount($account);

        $this->assertSame(1, $stored);
        $message = EmailMessage::first();
        $this->assertSame((string) $customer->id, (string) $message->customer_id);
        $this->assertSame('confirmed', $message->match_status);

        // Anhang landet in der Kundenakte (bestaetigte Zuordnung).
        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame((string) $customer->id, (string) $document->customer_id);
    }

    public function test_forwarded_mail_without_active_consent_is_discarded(): void
    {
        [$customer, $consent] = $this->customerWithConsent();
        $consent->forceFill(['revoked_at' => now()])->save(); // widerrufen
        $account = $this->importAccount();
        $address = 'import+' . $consent->import_token . '@dienstly24.de';

        $data = $this->message($address, 'service@allianz.de');
        $stored = $this->serviceWithQueue([$data])->syncAccount($account);

        // Ohne aktive Einwilligung: nichts gespeichert (Data Minimization).
        $this->assertSame(0, $stored);
        $this->assertSame(0, EmailMessage::count());
    }

    public function test_non_whitelisted_sender_is_discarded(): void
    {
        [$customer, $consent] = $this->customerWithConsent();
        $account = $this->importAccount();
        $address = 'import+' . $consent->import_token . '@dienstly24.de';

        // Gueltiges Token, aber privater Absender -> nicht vertragsbezogen.
        $data = $this->message($address, 'freund@gmail.com');
        $stored = $this->serviceWithQueue([$data])->syncAccount($account);

        $this->assertSame(0, $stored);
        $this->assertSame(0, EmailMessage::count());
    }

    public function test_unknown_token_is_discarded(): void
    {
        $this->customerWithConsent();
        $account = $this->importAccount();

        $data = $this->message('import+doesnotexist12345@dienstly24.de', 'service@allianz.de');
        $stored = $this->serviceWithQueue([$data])->syncAccount($account);

        $this->assertSame(0, $stored);
        $this->assertSame(0, EmailMessage::count());
    }
}
