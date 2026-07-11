<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\ExternalReference;
use App\Models\Task;
use App\Models\User;
use App\Services\Workflow\EmailWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FondsFinanzImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function fondsFinanzMessage(array $overrides = []): EmailMessage
    {
        return EmailMessage::create(array_merge([
            'email_account_id' => $this->account()->id,
            'message_uid' => 'INBOX:' . uniqid(),
            'from_address' => 'service@fondsfinanz.de',
            'from_name' => 'Fonds Finanz Maklerservice GmbH',
            'subject' => 'Neue Vertragsinformation',
            'body_text' => implode("\n", [
                'Kunde: Max Mustermann',
                'Geburtsdatum: 12.04.1988',
                'Gesellschaft: Allianz Versicherungs-AG',
                'Sparte: Kfz',
                'Produkt: Kfz-Haftpflicht Komfort',
                'Vertragsnummer: AZ-123456789',
                'Dokumentnummer: DOC-2026-0815',
                'Vorgangsnummer: FF-778899',
            ]),
        ], $overrides));
    }

    private function customer(string $name, string $birthDate): Customer
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'K-' . uniqid(),
            'birth_date' => $birthDate,
        ]);
    }

    public function test_creates_customer_contract_and_references_when_no_match_exists(): void
    {
        $message = $this->fondsFinanzMessage();

        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        $this->assertSame('fonds_finanz', $message->category);
        $this->assertSame('confirmed', $message->match_status);
        $this->assertNotNull($message->customer_id);

        $customer = Customer::find($message->customer_id);
        $this->assertSame('fonds_finanz', $customer->source);
        $this->assertSame('Max Mustermann', $customer->user->name);
        $this->assertSame('1988-04-12', (string) $customer->birth_date);

        $contract = Contract::where('customer_id', $customer->id)->where('contract_number', 'AZ-123456789')->first();
        $this->assertNotNull($contract);
        $this->assertSame('pending', $contract->status);
        $this->assertSame('Allianz Versicherungs-AG', $contract->insurer);
        $this->assertSame('kfz', $contract->type);

        $refs = ExternalReference::where('referenceable_id', $contract->id)->pluck('value', 'type');
        $this->assertSame('FF-778899', $refs[ExternalReference::TYPE_FONDS_FINANZ_NUMBER] ?? null);
        $this->assertSame('DOC-2026-0815', $refs[ExternalReference::TYPE_FONDS_FINANZ_DOCUMENT] ?? null);

        $this->assertTrue(Task::where('customer_id', $customer->id)->where('title', 'like', 'Fonds-Finanz-Import prüfen%')->exists());
    }

    public function test_sender_is_never_created_as_customer(): void
    {
        app(EmailWorkflowService::class)->process($this->fondsFinanzMessage());

        $this->assertFalse(User::where('name', 'Fonds Finanz Maklerservice GmbH')->exists());
        $this->assertFalse(User::where('email', 'service@fondsfinanz.de')->exists());
    }

    public function test_existing_customer_with_matching_data_requires_confirmation(): void
    {
        // Name + Geburtsdatum exakt = 70 Punkte -> Bestätigungsstufe
        // (dokumentiertes HITL-Verhalten aus CustomerMatchingServiceTest):
        // KEIN automatischer Import, Vorschlag + Bestätigungsaufgabe.
        $existing = $this->customer('Max Mustermann', '1988-04-12');
        $message = $this->fondsFinanzMessage();

        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        $this->assertSame('suggested', $message->match_status);
        $this->assertSame((string) $existing->id, (string) $message->customer_id);
        $this->assertSame(1, Customer::count()); // kein Duplikat angelegt
        $this->assertSame(0, Contract::count()); // kein Import ohne Bestätigung
        $this->assertTrue(Task::where('title', 'like', 'Fonds-Finanz-Zuordnung bestätigen%')->exists());
    }

    public function test_known_contract_number_assigns_customer_deterministically(): void
    {
        // Folge-Mitteilung zu einem bereits erfassten Vertrag: Die
        // Vertragsnummer identifiziert den Kunden eindeutig - kein
        // Score-Raten, direkter Import (Architekturplan Abschnitt 8).
        $existing = $this->customer('Max Mustermann', '1988-04-12');
        Contract::create([
            'customer_id' => $existing->id,
            'contract_number' => 'AZ-123456789',
            'type' => 'andere',
            'insurer' => 'Bestehender Versicherer',
            'status' => 'active',
        ]);

        $message = $this->fondsFinanzMessage();
        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        $this->assertSame('confirmed', $message->match_status);
        $this->assertSame((string) $existing->id, (string) $message->customer_id);

        $contract = Contract::where('contract_number', 'AZ-123456789')->first();
        // Vorhandene Werte bleiben unangetastet (defensiv, kein stilles Überschreiben).
        $this->assertSame('Bestehender Versicherer', $contract->insurer);
        $this->assertSame('andere', $contract->type);
        $this->assertSame('active', $contract->status);
        $this->assertSame(1, Contract::count());
        // Referenzen werden trotzdem ergänzt.
        $this->assertTrue(ExternalReference::where('referenceable_id', $contract->id)->exists());
    }

    public function test_known_contract_number_with_mismatched_name_is_not_blindly_assigned(): void
    {
        $existing = $this->customer('Erika Beispiel', '1970-01-01');
        Contract::create([
            'customer_id' => $existing->id,
            'contract_number' => 'AZ-123456789',
            'type' => 'andere',
            'insurer' => 'X',
            'status' => 'active',
        ]);

        $message = $this->fondsFinanzMessage(); // Text nennt "Max Mustermann"
        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        // Name passt nicht zum Vertragsinhaber -> Datenkonflikt: kein
        // Blind-Import an Erika, keine Neuanlage (Nummer ist UNIQUE),
        // sondern manuelle Prüfung.
        $this->assertSame('unmatched', $message->match_status);
        $this->assertNull($message->customer_id);
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Contract::count());
        $this->assertTrue(Task::where('title', 'like', 'Fonds-Finanz-Konflikt prüfen%')->exists());
    }

    public function test_weak_candidate_blocks_auto_creation_and_requires_manual_review(): void
    {
        // Gleicher Name, aber abweichendes Geburtsdatum (Score 30, <70):
        // weder Import an den falschen Kunden noch automatische Neuanlage
        // eines Duplikats - manuelle Prüfung (Abschnitt 6 Duplikatsschutz).
        $this->customer('Max Mustermann', '1970-01-01');
        $message = $this->fondsFinanzMessage();

        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        $this->assertSame('unmatched', $message->match_status);
        $this->assertSame(0, Contract::count());
        $this->assertSame(1, Customer::count()); // keine automatische Neuanlage
        $this->assertTrue(Task::where('title', 'like', '%manuell zuordnen%')->exists());
    }

    public function test_unparseable_fonds_finanz_mail_falls_back_to_manual_task(): void
    {
        $message = $this->fondsFinanzMessage([
            'body_text' => 'Fonds Finanz Newsletter: Unsere neuen Tarife im Überblick.',
        ]);

        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        $this->assertSame('fonds_finanz', $message->category);
        $this->assertSame('unmatched', $message->match_status);
        $this->assertNotNull($message->processed_at);
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Contract::count());
        $this->assertTrue(Task::where('title', 'like', '%manuell prüfen%')->exists());
    }

    public function test_processing_is_idempotent(): void
    {
        $message = $this->fondsFinanzMessage();

        app(EmailWorkflowService::class)->process($message);
        app(EmailWorkflowService::class)->process($message->refresh());

        $this->assertSame(1, Contract::count());
        $this->assertSame(1, Customer::count());
    }
}
