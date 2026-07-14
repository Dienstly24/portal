<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function customer(string $name = 'Max Mustermann'): Customer
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'customer']);
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'K-' . uniqid()]);
    }

    private function message(array $overrides = []): EmailMessage
    {
        $account = EmailAccount::firstOrCreate(
            ['email_address' => 'info@dienstly24.de'],
            ['name' => 'Test', 'provider' => 'imap', 'folders' => ['INBOX'], 'is_active' => true]
        );

        return EmailMessage::create(array_merge([
            'email_account_id' => $account->id,
            'message_uid' => 'INBOX:' . uniqid(),
            'from_address' => 'kunde@example.com',
            'from_name' => 'Max Mustermann',
            'subject' => 'Frage zu meinem Vertrag',
            'body_text' => 'Hallo',
            'category' => 'kundenanfrage',
            'processed_at' => now(),
        ], $overrides));
    }

    public function test_inbox_lists_suggested_and_unmatched_messages(): void
    {
        $customer = $this->customer();
        $this->message(['match_status' => 'suggested', 'customer_id' => $customer->id, 'match_score' => 75, 'subject' => 'Vorschlags-Mail']);
        $this->message(['match_status' => 'unmatched', 'subject' => 'Unklare Mail']);

        $this->actingAs($this->admin)->get(route('admin.email_inbox'))
            ->assertOk()
            ->assertSee('Vorschlags-Mail')
            ->assertSee('Unklare Mail')
            ->assertSee('Max Mustermann');
    }

    public function test_confirm_links_customer(): void
    {
        $customer = $this->customer();
        $message = $this->message(['match_status' => 'suggested', 'customer_id' => $customer->id, 'match_score' => 80]);

        $this->actingAs($this->admin)->post(route('admin.email_inbox.confirm', $message->id));

        $message->refresh();
        $this->assertSame('confirmed', $message->match_status);
        $this->assertSame((string) $customer->id, (string) $message->customer_id);
    }

    public function test_confirm_of_fonds_finanz_suggestion_runs_import(): void
    {
        $customer = $this->customer();
        $message = $this->message([
            'match_status' => 'suggested',
            'customer_id' => $customer->id,
            'match_score' => 70,
            'category' => 'fonds_finanz',
            'body_text' => "Kunde: Max Mustermann\nGesellschaft: Allianz\nSparte: Kfz\nVertragsnummer: AZ-999",
        ]);

        $this->actingAs($this->admin)->post(route('admin.email_inbox.confirm', $message->id));

        $this->assertSame('confirmed', $message->fresh()->match_status);
        $contract = Contract::where('customer_id', $customer->id)->where('contract_number', 'AZ-999')->first();
        $this->assertNotNull($contract, 'Bestätigung muss den Fonds-Finanz-Import ausführen');
        $this->assertSame('kfz', $contract->type);
    }

    public function test_reject_returns_message_to_manual_queue(): void
    {
        $customer = $this->customer();
        $message = $this->message(['match_status' => 'suggested', 'customer_id' => $customer->id, 'match_score' => 75]);

        $this->actingAs($this->admin)->post(route('admin.email_inbox.reject', $message->id));

        $message->refresh();
        $this->assertSame('unmatched', $message->match_status);
        $this->assertNull($message->customer_id);
    }

    public function test_manual_assignment_links_customer(): void
    {
        $customer = $this->customer();
        $message = $this->message(['match_status' => 'unmatched']);

        $this->actingAs($this->admin)->post(route('admin.email_inbox.assign', $message->id), [
            'customer_id' => (string) $customer->id,
        ]);

        $message->refresh();
        $this->assertSame('confirmed', $message->match_status);
        $this->assertSame((string) $customer->id, (string) $message->customer_id);
    }

    public function test_plain_employee_is_blocked_from_inbox_routes(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $customer = $this->customer();
        $message = $this->message(['match_status' => 'unmatched']);

        // Posteingang ist auf admin/manager/support beschränkt (Priorität 9).
        $this->actingAs($employee)->post(route('admin.email_inbox.assign', $message->id), [
            'customer_id' => (string) $customer->id,
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertNull($message->fresh()->customer_id);
    }

    public function test_restricted_support_cannot_assign_unassigned_customer(): void
    {
        $support = User::factory()->create(['role' => 'support', 'can_see_all_customers' => false]);
        $customer = $this->customer();
        $message = $this->message(['match_status' => 'unmatched']);

        // Portfolio-Scoping: auch im Posteingang nur eigene Kunden zuweisbar.
        $this->actingAs($support)->post(route('admin.email_inbox.assign', $message->id), [
            'customer_id' => (string) $customer->id,
        ])->assertForbidden();

        $this->assertNull($message->fresh()->customer_id);
    }

    public function test_already_processed_message_cannot_be_confirmed_twice(): void
    {
        $customer = $this->customer();
        $message = $this->message(['match_status' => 'confirmed', 'customer_id' => $customer->id]);

        $this->actingAs($this->admin)->post(route('admin.email_inbox.confirm', $message->id))
            ->assertSessionHas('error');
    }

    public function test_email_detail_page_shows_full_mail_and_attachments(): void
    {
        $message = $this->message([
            'subject' => 'Neues Dokument zum Kunden Alibrahim, Omar',
            'body_text' => 'Sehr geehrte Damen und Herren, anbei ein Dokument.',
            'match_status' => 'unmatched',
        ]);
        $message->forceFill(['attachments_meta' => [
            ['filename' => 'police.pdf', 'mime' => 'application/pdf', 'path' => 'email_attachments/x/police.pdf', 'size' => 2048, 'document_id' => null],
        ]])->save();

        $this->actingAs($this->admin)->get(route('admin.email_inbox.show', $message->id))
            ->assertOk()
            ->assertSee('Neues Dokument zum Kunden Alibrahim, Omar')
            ->assertSee('Sehr geehrte Damen und Herren')
            ->assertSee('police.pdf')
            ->assertSee(route('admin.email_inbox.attachment', [$message->id, 0]), false);
    }

    public function test_email_detail_respects_customer_access(): void
    {
        $support = User::factory()->create(['role' => 'support', 'can_see_all_customers' => false]);
        $customer = $this->customer();
        $message = $this->message(['match_status' => 'confirmed', 'customer_id' => $customer->id]);

        $this->actingAs($support)->get(route('admin.email_inbox.show', $message->id))->assertForbidden();
    }

    public function test_task_links_back_to_its_email(): void
    {
        $message = $this->message([
            'subject' => 'Neues Dokument zum Kunden Tiger GmbH',
            'category' => 'fonds_finanz',
            'from_address' => 'noreply@fondsfinanz.de',
            'processed_at' => null,
            'match_status' => 'unmatched',
        ]);

        app(\App\Services\Workflow\EmailWorkflowService::class)->process($message);

        $task = \App\Models\Task::where('email_message_id', $message->id)->first();
        $this->assertNotNull($task);

        $this->actingAs($this->admin)->get(route('admin.tasks', ['tab' => 'customer']))
            ->assertOk()
            ->assertSee(route('admin.email_inbox.show', $message->id), false);
    }
}
