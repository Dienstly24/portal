<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Partner;
use App\Models\Task;
use App\Models\User;
use App\Services\Workflow\EmailWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['role' => 'admin']);
    }

    private function partner(array $overrides = []): Partner
    {
        return Partner::create(array_merge([
            'name' => 'Fonds Finanz Maklerservice GmbH',
            'email_domains' => ['fondsfinanz.de'],
            'is_active' => true,
        ], $overrides));
    }

    private function commissionMessage(array $overrides = []): EmailMessage
    {
        $account = EmailAccount::firstOrCreate(
            ['email_address' => 'info@dienstly24.de'],
            ['name' => 'Test-Postfach', 'provider' => 'imap', 'folders' => ['INBOX'], 'is_active' => true]
        );

        return EmailMessage::create(array_merge([
            'email_account_id' => $account->id,
            'message_uid' => 'INBOX:' . uniqid(),
            'from_address' => 'provision@fondsfinanz.de',
            'from_name' => 'Fonds Finanz Provisionsabrechnung',
            'subject' => 'Ihre Provisionsgutschrift',
            'body_text' => implode("\n", [
                'Gutschrift-Nr: GS-2026-1234',
                'Betrag: 1.234,56 €',
                'Datum: 05.07.2026',
            ]),
        ], $overrides));
    }

    public function test_known_partner_domain_creates_pending_commission(): void
    {
        $partner = $this->partner();
        $message = $this->commissionMessage();

        app(EmailWorkflowService::class)->process($message);

        $message->refresh();
        $this->assertSame('provisionen', $message->category);
        $this->assertNotNull($message->processed_at);

        $commission = Commission::first();
        $this->assertNotNull($commission);
        $this->assertSame((string) $partner->id, (string) $commission->partner_id);
        $this->assertSame('GS-2026-1234', $commission->credit_note_number);
        $this->assertSame('1234.56', (string) $commission->amount);
        $this->assertSame('2026-07-05', $commission->statement_date->format('Y-m-d'));
        $this->assertSame('pending_review', $commission->status);
        $this->assertNull($commission->lexoffice_voucher_id); // HITL: kein Auto-Beleg

        $this->assertTrue(Task::where('title', 'like', 'Provisionsgutschrift prüfen und buchen%')->exists());
    }

    public function test_unknown_sender_creates_review_task_without_commission(): void
    {
        $this->partner(); // Partner existiert, aber Absender passt nicht
        $message = $this->commissionMessage([
            'from_address' => 'noreply@unbekannte-firma.de',
            'from_name' => 'Unbekannte Firma AG',
        ]);

        app(EmailWorkflowService::class)->process($message);

        $this->assertSame(0, Commission::count());
        $this->assertTrue(Task::where('title', 'like', '%kein bekannter Partner%')->exists());
    }

    public function test_duplicate_credit_note_is_not_recorded_twice(): void
    {
        $this->partner();

        app(EmailWorkflowService::class)->process($this->commissionMessage());
        app(EmailWorkflowService::class)->process($this->commissionMessage());

        $this->assertSame(1, Commission::count());
    }

    public function test_partner_recognized_by_name_similarity_without_domain(): void
    {
        $this->partner(['email_domains' => []]);
        $message = $this->commissionMessage([
            'from_address' => 'abrechnung@ff-mail-dienst.de',
            'from_name' => 'Fonds Finanz Maklerservice GmbH',
        ]);

        app(EmailWorkflowService::class)->process($message);

        $this->assertSame(1, Commission::count());
    }

    public function test_booking_creates_lexoffice_voucher_and_updates_history(): void
    {
        Http::fake(['*/vouchers' => Http::response(['id' => 'lex-voucher-1'], 201)]);

        $partner = $this->partner();
        $commission = Commission::create([
            'partner_id' => $partner->id,
            'credit_note_number' => 'GS-1',
            'amount' => 100.00,
            'statement_date' => '2026-07-01',
            'status' => 'pending_review',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->post(route('admin.commissions.book', $commission->id), [
            'amount' => 150.00, // Mitarbeiter korrigiert den Betrag
            'credit_note_number' => 'GS-1',
            'statement_date' => '2026-07-01',
        ]);

        $response->assertRedirect();
        $commission->refresh();
        $this->assertSame('booked', $commission->status);
        $this->assertSame('lex-voucher-1', $commission->lexoffice_voucher_id);
        $this->assertSame('150.00', (string) $commission->amount);
        $this->assertSame($admin->id, $commission->reviewed_by);
        $this->assertSame(150.0, $partner->fresh()->bookedTotal());
    }

    public function test_booking_fails_gracefully_when_lexoffice_unreachable(): void
    {
        Http::fake(['*/vouchers' => Http::response(['message' => 'unauthorized'], 401)]);

        $commission = Commission::create([
            'partner_id' => $this->partner()->id,
            'amount' => 100.00,
            'status' => 'pending_review',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.commissions.book', $commission->id), [
            'amount' => 100.00,
            'statement_date' => '2026-07-01',
        ]);

        $commission->refresh();
        // Beleg fehlgeschlagen -> Gutschrift bleibt offen, nichts halb gebucht.
        $this->assertSame('pending_review', $commission->status);
        $this->assertNull($commission->lexoffice_voucher_id);
    }

    public function test_rejecting_requires_no_lexoffice_and_closes_commission(): void
    {
        $commission = Commission::create([
            'partner_id' => $this->partner()->id,
            'amount' => 100.00,
            'status' => 'pending_review',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.commissions.reject', $commission->id));

        $this->assertSame('rejected', $commission->fresh()->status);
    }

    public function test_employee_role_cannot_access_commissions(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);

        // EnsureUserRole leitet unberechtigte Staff-Rollen zum Dashboard um.
        $this->actingAs($employee)->get(route('admin.commissions'))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($employee)->get(route('admin.partners'))->assertRedirect(route('admin.dashboard'));
    }
}
