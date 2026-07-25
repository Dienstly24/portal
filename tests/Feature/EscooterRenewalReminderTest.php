<?php

namespace Tests\Feature;

use App\Mail\EscooterRenewalMail;
use App\Models\Contract;
use App\Models\ContractSwitchReminder;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\User;
use App\Services\EscooterRenewalReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Jaehrliche E-Scooter-Erneuerungs-Erinnerung (Betreiber-Vorgabe 25.07.2026):
 * Anfang Februar bekommt jeder aktive E-Scooter-Vertrag EINMAL pro Saison einen
 * Hinweis, dass das Kennzeichen Ende Februar auslaeuft und ab 01.03. ein neues
 * noetig ist. Idempotent, nur im Februar-Fenster.
 */
class EscooterRenewalReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeEscooter(string $start = '2026-07-20', string $email = 'kunde@example.com'): Contract
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => $email]);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
        $contract = Contract::create([
            'customer_id' => $customer->id, 'type' => 'escooter', 'insurer' => 'die Bayerische',
            'status' => 'active', 'start_date' => $start, 'premium_amount' => 41.60, 'premium_interval' => 'einmalig',
        ]);
        ContractVehicleDetail::create([
            'contract_id' => $contract->id, 'vehicle_type' => 'escooter',
            'license_plate' => '611 MDS', 'vin' => 'ZSF10Z23075358', 'has_teilkasko' => false,
        ]);
        return $contract->fresh();
    }

    public function test_reminder_sent_in_february_window(): void
    {
        Mail::fake();
        // Saison endet 2027-02-28; wir sind Anfang Februar 2027.
        Carbon::setTestNow('2027-02-03 08:40:00');
        $contract = $this->makeEscooter();

        $sent = app(EscooterRenewalReminderService::class)->run();

        $this->assertSame(1, $sent);
        Mail::assertQueued(EscooterRenewalMail::class, fn($m) => $m->hasTo('kunde@example.com'));
        $this->assertDatabaseHas('contract_switch_reminders', [
            'contract_id' => $contract->id, 'stage' => 'renewal', 'anchor' => '2027-02-28 00:00:00',
        ]);
    }

    public function test_no_reminder_outside_february(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-11-15 08:40:00');
        $this->makeEscooter();

        $this->assertSame(0, app(EscooterRenewalReminderService::class)->run());
        Mail::assertNothingQueued();
    }

    public function test_reminder_is_idempotent_per_season(): void
    {
        Mail::fake();
        Carbon::setTestNow('2027-02-03 08:40:00');
        $this->makeEscooter();

        $this->assertSame(1, app(EscooterRenewalReminderService::class)->run());
        // Zweiter Lauf am naechsten Tag darf NICHT erneut senden.
        Carbon::setTestNow('2027-02-04 08:40:00');
        $this->assertSame(0, app(EscooterRenewalReminderService::class)->run());
        Mail::assertQueued(EscooterRenewalMail::class, 1);
        $this->assertSame(1, ContractSwitchReminder::where('stage', 'renewal')->count());
    }

    public function test_internal_placeholder_email_is_skipped(): void
    {
        Mail::fake();
        Carbon::setTestNow('2027-02-03 08:40:00');
        $this->makeEscooter(email: 'import123@dienstly24.internal');

        $this->assertSame(0, app(EscooterRenewalReminderService::class)->run());
        Mail::assertNothingQueued();
    }

    public function test_command_dry_run_lists_due_without_sending(): void
    {
        Mail::fake();
        Carbon::setTestNow('2027-02-03 08:40:00');
        $this->makeEscooter();

        $this->artisan('escooter:renewal-reminders --dry-run')
            ->expectsOutputToContain('611 MDS')
            ->assertExitCode(0);
        Mail::assertNothingQueued();
        $this->assertSame(0, ContractSwitchReminder::count());
    }
}
