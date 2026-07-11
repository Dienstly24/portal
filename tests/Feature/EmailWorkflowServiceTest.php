<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflow\EmailWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ein Admin muss immer existieren, damit automatisiert erzeugte
        // Aufgaben ohne konkreten Betreuer zugewiesen werden können
        // (EmailWorkflowService::systemUserId()) - entspricht dem
        // realistischen Systemzustand (Ersteinrichtung legt Admin an).
        User::factory()->create(['role' => 'admin']);
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

    private function message(EmailAccount $account, array $overrides = []): EmailMessage
    {
        return EmailMessage::create(array_merge([
            'email_account_id' => $account->id,
            'message_uid' => 'INBOX:' . uniqid(),
            'from_address' => 'unbekannt@example.com',
            'from_name' => 'Unbekannt Sender',
            'subject' => 'Testmail',
            'body_text' => 'Testinhalt',
        ], $overrides));
    }

    public function test_processing_sets_category_and_marks_processed(): void
    {
        $message = $this->message($this->account(), ['subject' => 'Ich habe eine Frage zu meinem Konto']);

        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        $this->assertSame('kundenanfrage', $message->category);
        $this->assertNotNull($message->processed_at);
    }

    public function test_is_idempotent_and_does_not_reprocess(): void
    {
        $message = $this->message($this->account(), ['subject' => 'Frage zu meinem Konto']);
        $service = app(EmailWorkflowService::class);

        $service->process($message);
        $ticketsAfterFirst = Ticket::count();

        $message->refresh();
        $service->process($message); // bereits processed_at gesetzt - darf nichts doppelt anlegen

        $this->assertSame($ticketsAfterFirst, Ticket::count());
    }

    public function test_kundenanfrage_from_genuinely_new_sender_auto_creates_customer_and_links_ticket(): void
    {
        // Kein bestehender Kandidat, aber ein erkennbarer Name -> automatische
        // Kundenanlage (Priorität 3) statt eines "Gast"-Tickets ohne Zuordnung.
        $message = $this->message($this->account(), [
            'from_address' => 'anfrager@example.com',
            'from_name' => 'Anfrager Beispiel',
            'subject' => 'Ich habe eine Frage zu meinem Konto',
        ]);

        app(EmailWorkflowService::class)->process($message);

        $customer = Customer::where('source', 'email_import')->first();
        $this->assertNotNull($customer);
        $this->assertSame('anfrager@example.com', $customer->user->email);

        $ticket = Ticket::latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame((string) $customer->id, (string) $ticket->customer_id);
        $this->assertSame('email', $ticket->source);
    }

    public function test_kundenanfrage_without_recognizable_name_creates_guest_ticket(): void
    {
        // Kein Name im Header -> keine automatische Kundenanlage, Ticket bleibt Gast-Anfrage.
        $message = $this->message($this->account(), [
            'from_address' => 'anonym@example.com',
            'from_name' => null,
            'subject' => 'Ich habe eine Frage zu meinem Konto',
        ]);

        app(EmailWorkflowService::class)->process($message);

        $this->assertSame(0, Customer::count());

        $ticket = Ticket::latest()->first();
        $this->assertNotNull($ticket);
        $this->assertNull($ticket->customer_id);
        $this->assertSame('anonym@example.com', $ticket->guest_email);
        $this->assertSame('email', $ticket->source);
    }

    public function test_versicherung_category_with_strong_signals_auto_links_customer(): void
    {
        // Geburtsdatum (40) + Name exakt (30) + E-Mail (20) + Telefon-Bonus (5) = 95 > 90 -> auto
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Erika Musterfrau', 'email' => 'erika@example.com']);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-ERIKA001',
            'birth_date' => '1988-04-12',
            'phone' => '030123456',
        ]);

        $message = $this->message($this->account(), [
            'from_address' => 'erika@example.com',
            'from_name' => 'Erika Musterfrau',
            'subject' => 'Bitte Schadenmeldung unterlagen einreichen',
            'body_text' => "Sehr geehrte Damen und Herren,\ngeb. 12.04.1988\nTel.: 030123456\nBitte um Rückmeldung.",
        ]);

        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        $this->assertSame('versicherung', $message->category);
        $this->assertSame('confirmed', $message->match_status);
        $this->assertSame((string) $customer->id, (string) $message->customer_id);

        $task = Task::where('customer_id', $customer->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('email', $task->type);
        $this->assertSame('high', $task->priority);
    }

    public function test_versicherung_category_with_weak_signals_stays_unmatched(): void
    {
        // Nur Name + E-Mail (max. 50 Punkte) - unter der 70%-Schwelle,
        // Aufgabe wird trotzdem erzeugt, aber ohne automatische Kundenverknüpfung.
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Erika Musterfrau', 'email' => 'erika@example.com']);
        Customer::create(['user_id' => $user->id, 'customer_number' => 'C-ERIKA002']);

        $message = $this->message($this->account(), [
            'from_address' => 'erika@example.com',
            'from_name' => 'Erika Musterfrau',
            'subject' => 'Bitte Schadenmeldung unterlagen einreichen',
        ]);

        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        $this->assertSame('versicherung', $message->category);
        $this->assertNotSame('confirmed', $message->match_status);

        $task = Task::latest()->first();
        $this->assertNotNull($task);
        $this->assertNull($task->customer_id);
    }

    public function test_ambiguous_match_is_suggested_not_auto_linked(): void
    {
        // Nur Name matcht (kein Geburtsdatum/E-Mail) -> Score < 90, aber Kandidat existiert.
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Thomas Beispiel', 'email' => 'thomas.original@example.com']);
        Customer::create(['user_id' => $user->id, 'customer_number' => 'C-THOMAS01']);

        $message = $this->message($this->account(), [
            'from_address' => 'thomas.andere-adresse@example.com',
            'from_name' => 'Thomas Beispiel',
            'subject' => 'Allgemeine Frage zu meinem Konto',
        ]);

        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        $this->assertContains($message->match_status, ['suggested', 'unmatched']);
    }

    public function test_unknown_sender_without_name_is_never_auto_created(): void
    {
        $message = $this->message($this->account(), [
            'from_address' => 'noreply@irgendwas.invalid',
            'from_name' => null,
            'subject' => 'Automatische Benachrichtigung',
        ]);

        app(EmailWorkflowService::class)->process($message);

        $this->assertSame(0, Customer::count());
    }
}
