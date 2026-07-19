<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignJob;
use App\Mail\CampaignMail;
use App\Mail\ContractSwitchMail;
use App\Models\Contract;
use App\Models\ContractSwitchReminder;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\User;
use App\Services\ContractSwitchReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * E-Mail-Marketing Verbesserungsplan 2026-07-12:
 * Paket A (Abmeldung, Queue, Protokoll), Paket B (Entwürfe, Planung,
 * Test), Paket C (spartenspezifische Wechsel-Erinnerungen).
 */
class EmailMarketingImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function customer(array $overrides = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'K-' . uniqid(),
        ], $overrides));
    }

    private function contract(Customer $customer, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $customer->id,
            'contract_number' => 'V-' . uniqid(),
            'type' => 'internet',
            'insurer' => 'Telekom',
            'status' => 'active',
        ], $overrides));
    }

    // ---------------- Paket A ----------------

    public function test_unsubscribe_link_marks_customer_and_is_idempotent(): void
    {
        $customer = $this->customer();
        $token = $customer->unsubscribeToken();

        $this->get('/abmelden/' . $token)->assertOk()->assertSee('erfolgreich abgemeldet');

        $customer->refresh();
        $this->assertFalse($customer->marketing_consent);
        $this->assertNotNull($customer->unsubscribed_at);
        $firstStamp = $customer->unsubscribed_at;

        $this->get('/abmelden/' . $token)->assertOk();
        $this->assertEquals($firstStamp, $customer->fresh()->unsubscribed_at);

        $this->get('/abmelden/gibt-es-nicht')->assertNotFound();
    }

    public function test_campaign_send_skips_unsubscribed_and_logs_each_recipient(): void
    {
        Mail::fake();
        $reachable = $this->customer();
        $unsubscribed = $this->customer(['marketing_consent' => false, 'unsubscribed_at' => now()]);

        $this->actingAs($this->admin)->post(route('admin.email_marketing.send'), [
            'subject' => 'Angebot', 'body' => 'Hallo', 'target' => 'all',
        ])->assertSessionHas('success');

        $campaign = EmailCampaign::firstOrFail();
        $this->assertEquals('sent', $campaign->status);
        $this->assertEquals(1, $campaign->sent_count);
        $this->assertNotNull($campaign->sent_at);

        Mail::assertSent(CampaignMail::class, 1);
        $this->assertDatabaseHas('email_logs', [
            'campaign_id' => $campaign->id, 'user_id' => $reachable->user_id,
            'type' => 'campaign', 'status' => 'sent',
        ]);
        $this->assertDatabaseMissing('email_logs', ['user_id' => $unsubscribed->user_id]);
    }

    public function test_campaign_mail_contains_unsubscribe_link(): void
    {
        $html = (new CampaignMail('Betreff', 'Text', 'Max', 'https://example.test/abmelden/tok'))->render();
        $this->assertStringContainsString('https://example.test/abmelden/tok', $html);
        $this->assertStringContainsString('Abmelden', $html);
    }

    // ---------------- Paket B ----------------

    public function test_draft_is_saved_without_sending(): void
    {
        Mail::fake();
        $this->customer();

        $this->actingAs($this->admin)->post(route('admin.email_marketing.send'), [
            'subject' => 'Entwurf', 'body' => 'Später', 'target' => 'all', 'action' => 'draft',
        ])->assertSessionHas('success');

        $this->assertEquals('draft', EmailCampaign::firstOrFail()->status);
        Mail::assertNothingSent();
    }

    public function test_scheduled_campaign_dispatches_when_due(): void
    {
        Mail::fake();
        $this->customer();

        $this->actingAs($this->admin)->post(route('admin.email_marketing.send'), [
            'subject' => 'Geplant', 'body' => 'Bald', 'target' => 'all',
            'action' => 'schedule', 'scheduled_for' => now()->addHour()->format('Y-m-d H:i:s'),
        ]);
        $this->assertEquals('scheduled', EmailCampaign::firstOrFail()->status);

        // Noch nicht fällig
        $this->assertEquals(0, SendCampaignJob::dispatchDueScheduled());

        $this->travel(2)->hours();
        $this->assertEquals(1, SendCampaignJob::dispatchDueScheduled());
        $this->assertEquals('sent', EmailCampaign::firstOrFail()->status);
        Mail::assertSent(CampaignMail::class, 1);
    }

    public function test_test_send_goes_only_to_own_address_without_campaign(): void
    {
        Mail::fake();
        $this->customer();

        $this->actingAs($this->admin)->post(route('admin.email_marketing.test'), [
            'subject' => 'Probe', 'body' => 'Inhalt',
        ])->assertSessionHas('success');

        Mail::assertSent(CampaignMail::class, fn($m) => $m->hasTo($this->admin->email));
        $this->assertEquals(0, EmailCampaign::count());
        $this->assertEquals(0, EmailLog::count());
    }

    public function test_draft_can_be_dispatched_and_deleted(): void
    {
        Mail::fake();
        $this->customer();
        $draft = EmailCampaign::create([
            'created_by' => $this->admin->id, 'subject' => 'D', 'body' => 'B',
            'target' => 'all', 'status' => 'draft',
        ]);

        $this->actingAs($this->admin)->post(route('admin.email_marketing.dispatch', $draft->id));
        $this->assertEquals('sent', $draft->fresh()->status);

        $second = EmailCampaign::create([
            'created_by' => $this->admin->id, 'subject' => 'D2', 'body' => 'B',
            'target' => 'all', 'status' => 'draft',
        ]);
        $this->actingAs($this->admin)->delete(route('admin.email_marketing.destroy', $second->id));
        $this->assertNull(EmailCampaign::find($second->id));
    }

    // ---------------- Paket C ----------------

    public function test_kfz_first_reminder_inside_two_month_window(): void
    {
        Mail::fake();
        $contract = $this->contract($this->customer(), ['type' => 'kfz', 'insurer' => 'HUK', 'end_date' => now()->addDays(50)->toDateString()]);

        $sent = app(ContractSwitchReminderService::class)->run();

        $this->assertEquals(1, $sent);
        Mail::assertQueued(ContractSwitchMail::class, fn($m) => $m->stage === 'first');
        $this->assertDatabaseHas('contract_switch_reminders', ['contract_id' => $contract->id, 'stage' => 'first']);
        $this->assertDatabaseHas('email_logs', ['type' => 'contract_switch', 'status' => 'sent']);

        // Idempotent: zweiter Lauf (Button nach Cron) sendet nichts
        $this->assertEquals(0, app(ContractSwitchReminderService::class)->run());
    }

    public function test_kfz_after_cancellation_deadline_gets_no_reminder(): void
    {
        Mail::fake();
        $this->contract($this->customer(), ['type' => 'kfz', 'end_date' => now()->addDays(20)->toDateString()]);
        $this->assertEquals(0, app(ContractSwitchReminderService::class)->run());
        Mail::assertNothingSent();
    }

    public function test_internet_followup_only_after_gap_and_without_response(): void
    {
        Mail::fake();
        $contract = $this->contract($this->customer(), ['type' => 'internet', 'end_date' => now()->addMonths(2)->toDateString()]);

        // Erster Lauf im Followup-Fenster ohne bisherige Erinnerung -> "first"
        $this->assertEquals(1, app(ContractSwitchReminderService::class)->run());
        // Direkt danach kein Followup (Mindestabstand 14 Tage)
        $this->assertEquals(0, app(ContractSwitchReminderService::class)->run());

        ContractSwitchReminder::query()->update(['sent_at' => now()->subDays(15)]);
        $this->assertEquals(1, app(ContractSwitchReminderService::class)->run());
        Mail::assertQueued(ContractSwitchMail::class, fn($m) => $m->stage === 'followup');
        $this->assertEquals(2, $contract->switchReminders()->count());
    }

    public function test_customer_response_cancels_followup(): void
    {
        Mail::fake();
        $contract = $this->contract($this->customer(), ['type' => 'strom_gas', 'insurer' => 'Vattenfall', 'end_date' => now()->addMonths(2)->toDateString()]);

        app(ContractSwitchReminderService::class)->run();
        ContractSwitchReminder::query()->update(['sent_at' => now()->subDays(15)]);

        $this->actingAs($this->admin)->post(route('admin.contracts.switch_responded', $contract->id))
            ->assertSessionHas('success');

        $this->assertEquals(0, app(ContractSwitchReminderService::class)->run());
        $this->assertNotNull($contract->switchReminders()->first()->responded_at);
    }

    public function test_gkv_reminder_after_twelve_months_binding_period(): void
    {
        Mail::fake();
        // Noch in der Bindungsfrist -> nichts
        $this->contract($this->customer(), [
            'type' => 'krankenversicherung', 'subtype' => 'gkv', 'insurer' => 'TK',
            'start_date' => now()->subMonths(11)->toDateString(),
        ]);
        $this->assertEquals(0, app(ContractSwitchReminderService::class)->run());

        // Bindungsfrist abgelaufen -> Erinnerung, auch ohne end_date
        $eligible = $this->contract($this->customer(), [
            'type' => 'krankenversicherung', 'subtype' => 'gkv', 'insurer' => 'AOK',
            'start_date' => now()->subMonths(13)->toDateString(),
        ]);
        $this->assertEquals(1, app(ContractSwitchReminderService::class)->run());
        $this->assertDatabaseHas('contract_switch_reminders', ['contract_id' => $eligible->id, 'stage' => 'first']);

        // PKV bzw. ohne subtype: nie
        $this->contract($this->customer(), [
            'type' => 'krankenversicherung', 'subtype' => 'pkv', 'insurer' => 'Allianz',
            'start_date' => now()->subMonths(24)->toDateString(),
        ]);
        $this->assertEquals(0, app(ContractSwitchReminderService::class)->run());
    }

    public function test_gkv_active_from_is_first_of_third_following_month(): void
    {
        $contract = $this->contract($this->customer(), [
            'type' => 'krankenversicherung', 'subtype' => 'gkv', 'insurer' => 'TK',
            'start_date' => now()->subMonths(13)->toDateString(),
        ]);
        // §175 SGB V: Antragsmonat zählt nicht + 2 volle Monate
        $expected = now()->startOfMonth()->addMonths(3);
        $this->assertTrue((new ContractSwitchMail($contract, 'first'))->gkvActiveFrom()->eq($expected));
    }

    public function test_unsubscribed_customer_gets_no_switch_reminder(): void
    {
        Mail::fake();
        $customer = $this->customer(['marketing_consent' => false, 'unsubscribed_at' => now()]);
        $this->contract($customer, ['type' => 'kfz', 'end_date' => now()->addDays(50)->toDateString()]);

        $this->assertEquals(0, app(ContractSwitchReminderService::class)->run());
        Mail::assertNothingSent();
    }

    public function test_manual_reminder_button_uses_same_engine(): void
    {
        Mail::fake();
        $this->contract($this->customer(), ['type' => 'internet', 'end_date' => now()->addMonths(4)->toDateString()]);

        $this->actingAs($this->admin)->post(route('admin.email_marketing.reminders'))
            ->assertSessionHas('success', '1 Wechsel-Erinnerungen gesendet.');
        Mail::assertQueued(ContractSwitchMail::class, 1);

        // Button direkt nochmal: kein Doppelversand
        $this->actingAs($this->admin)->post(route('admin.email_marketing.reminders'))
            ->assertSessionHas('success', '0 Wechsel-Erinnerungen gesendet.');
    }
}
