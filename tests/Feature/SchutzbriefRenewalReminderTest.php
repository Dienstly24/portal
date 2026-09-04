<?php

namespace Tests\Feature;

use App\Mail\SchutzbriefRenewalMail;
use App\Models\Contract;
use App\Models\ContractSwitchReminder;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentIntake\DocumentIntakeService;
use App\Services\SchutzbriefRenewalReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Schutzbrief-/Mobilclub-Verlaengerung (Betreiber-Vorgabe 28.07.2026):
 * Der Vertrag beginnt sofort und verlaengert sich jaehrlich automatisch.
 * Die Erinnerung geht ab 5 Monaten vor dem Stichtag (= 7 Monate nach Beginn)
 * bis zum letzten Kuendigungstag (Stichtag - 3 Monate) raus, einmal pro Jahr.
 */
class SchutzbriefRenewalReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeSchutzbrief(
        string $start = '2026-07-29',
        ?string $end = '2027-07-29',
        string $email = 'kunde@example.com',
    ): Contract {
        $user = User::factory()->create(['role' => 'customer', 'email' => $email]);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
        return Contract::create([
            'customer_id' => $customer->id, 'type' => 'schutzbrief', 'subtype' => 'basis',
            'insurer' => 'ADAC', 'contract_number' => '736673274', 'status' => 'active',
            'start_date' => $start, 'end_date' => $end,
            'premium_amount' => 54, 'premium_interval' => 'yearly',
        ])->fresh();
    }

    public function test_reminder_sent_seven_months_after_start(): void
    {
        Mail::fake();
        // Beginn 29.07.2026, Verlaengerung 29.07.2027 -> Fenster ab 28.02.2027
        // (5 Monate vorher = 7 Monate nach Beginn).
        Carbon::setTestNow('2027-03-01 08:45:00');
        $contract = $this->makeSchutzbrief();

        $sent = app(SchutzbriefRenewalReminderService::class)->run();

        $this->assertSame(1, $sent);
        Mail::assertQueued(SchutzbriefRenewalMail::class, fn ($m) => $m->hasTo('kunde@example.com'));
        $this->assertDatabaseHas('contract_switch_reminders', [
            'contract_id' => $contract->id, 'stage' => 'schutzbrief_renewal',
        ]);
    }

    public function test_no_reminder_too_early(): void
    {
        Mail::fake();
        // Nur 2 Monate nach Beginn - viel zu frueh.
        Carbon::setTestNow('2026-09-29 08:45:00');
        $this->makeSchutzbrief();

        $this->assertSame(0, app(SchutzbriefRenewalReminderService::class)->run());
        Mail::assertNothingQueued();
    }

    public function test_no_reminder_after_cancellation_deadline(): void
    {
        Mail::fake();
        // Letzter Kuendigungstag ist 29.04.2027 (3 Monate vor 29.07.2027).
        // Danach ist eine Kuendigung nicht mehr moeglich -> keine Erinnerung.
        Carbon::setTestNow('2027-05-15 08:45:00');
        $this->makeSchutzbrief();

        $this->assertSame(0, app(SchutzbriefRenewalReminderService::class)->run());
        Mail::assertNothingQueued();
    }

    public function test_reminder_is_idempotent_per_year(): void
    {
        Mail::fake();
        Carbon::setTestNow('2027-03-01 08:45:00');
        $this->makeSchutzbrief();

        $this->assertSame(1, app(SchutzbriefRenewalReminderService::class)->run());
        // Zweiter Lauf am naechsten Tag darf NICHT erneut senden.
        Carbon::setTestNow('2027-03-02 08:45:00');
        $this->assertSame(0, app(SchutzbriefRenewalReminderService::class)->run());
        Mail::assertQueued(SchutzbriefRenewalMail::class, 1);
        $this->assertSame(1, ContractSwitchReminder::where('stage', 'schutzbrief_renewal')->count());
    }

    public function test_renewal_date_rolls_forward_each_year(): void
    {
        // Ein vergangenes Ablaufdatum ist KEIN Vertragsende (stillschweigende
        // Verlaengerung): der Stichtag wandert jahrweise in die Zukunft.
        Carbon::setTestNow('2029-03-01 08:45:00');
        $contract = $this->makeSchutzbrief(start: '2026-07-29', end: '2027-07-29');

        $renewal = app(SchutzbriefRenewalReminderService::class)->nextRenewalDate($contract);
        $this->assertSame('2029-07-29', $renewal->toDateString());
    }

    public function test_internal_placeholder_email_is_skipped(): void
    {
        Mail::fake();
        Carbon::setTestNow('2027-03-01 08:45:00');
        $this->makeSchutzbrief(email: 'import123@dienstly24.internal');

        $this->assertSame(0, app(SchutzbriefRenewalReminderService::class)->run());
        Mail::assertNothingQueued();
    }

    public function test_command_dry_run_lists_due_without_sending(): void
    {
        Mail::fake();
        Carbon::setTestNow('2027-03-01 08:45:00');
        $this->makeSchutzbrief();

        $this->artisan('schutzbrief:renewal-reminders --dry-run')
            ->expectsOutputToContain('736673274')
            ->assertExitCode(0);
        Mail::assertNothingQueued();
        $this->assertSame(0, ContractSwitchReminder::count());
    }

    public function test_contract_from_document_starts_immediately_for_one_year(): void
    {
        // Schutzbrief beginnt sofort (Tag des Uploads) und laeuft 1 Jahr.
        Carbon::setTestNow('2026-07-29 14:00:00');
        $user = User::factory()->create(['role' => 'customer']);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
        $doc = Document::create([
            'customer_id' => $customer->id, 'category' => 'contract', 'file_name' => 'adac.png',
            'file_path' => 'adac.png', 'disk' => 'local', 'ai_type' => 'versicherungsvertrag',
            'ai_extracted' => ['versicherung' => [
                'insurer' => 'ADAC', 'sparte' => 'schutzbrief', 'subtype' => 'basis',
                'contract_number' => '736673274', 'premium_amount' => 54, 'premium_interval' => 'yearly',
            ]],
        ]);

        $contract = app(DocumentIntakeService::class)
            ->createContractFromExtraction($doc, $customer, null);

        $this->assertNotNull($contract);
        $this->assertSame('schutzbrief', $contract->type);
        $this->assertSame('basis', $contract->subtype);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'start_date' => '2026-07-29', // ab 0 Uhr des Upload-Tages
            'end_date' => '2027-07-29',   // 1 Jahr, dann Auto-Verlaengerung
        ]);
    }

    public function test_backfill_fills_missing_terms_for_existing_contracts(): void
    {
        Carbon::setTestNow('2026-07-29 10:00:00');
        // Bestandsvertrag ohne Laufzeit-Daten (vor der Regel angelegt).
        $contract = $this->makeSchutzbrief(start: '2026-07-29', end: null);
        $contract->forceFill(['start_date' => null, 'end_date' => null])->save();

        $this->artisan('schutzbrief:backfill-terms')->assertExitCode(0);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'start_date' => '2026-07-29',
            'end_date' => '2027-07-29',
        ]);
    }
}
