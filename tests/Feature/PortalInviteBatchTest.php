<?php

namespace Tests\Feature;

use App\Mail\CustomerWelcomeMail;
use App\Models\Customer;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Automatischer Portal-Einladungs-Batch: Tagesbudget, alphabetisch,
 * 7-Tage-Erinnerung, Schutz vor versehentlichem Massenversand.
 */
class PortalInviteBatchTest extends TestCase
{
    use RefreshDatabase;

    private function unregisteredCustomer(array $userAttr = [], array $custAttr = []): Customer
    {
        $user = User::factory()->create(array_merge([
            'role' => 'customer',
            'is_active' => true,
            'last_login_at' => null,
        ], $userAttr));

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(Str::random(6)),
            'birth_date' => '1990-01-01',
        ], $custAttr));
    }

    public function test_disabled_by_default_sends_nothing(): void
    {
        Mail::fake();
        $this->unregisteredCustomer();

        $this->artisan('portal:send-invitations')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_invites_eligible_customers_when_enabled(): void
    {
        Mail::fake();
        SystemSetting::set('portal_invite_auto_enabled', '1');
        $c = $this->unregisteredCustomer();
        $this->unregisteredCustomer();

        $this->artisan('portal:send-invitations')->assertSuccessful();

        Mail::assertSent(CustomerWelcomeMail::class, 2);
        $this->assertNotNull($c->user->fresh()->invitation_sent_at);
        $this->assertSame(1, (int) $c->user->fresh()->invitation_count);
    }

    public function test_skips_registered_placeholder_and_over_max_attempts(): void
    {
        Mail::fake();
        SystemSetting::set('portal_invite_auto_enabled', '1');
        $this->unregisteredCustomer(['last_login_at' => now()]);                 // schon registriert
        $this->unregisteredCustomer(['email' => 'platzhalter@dienstly24.internal']); // keine echte Mail
        $this->unregisteredCustomer(['invitation_count' => 6, 'invitation_sent_at' => now()->subDays(30)]); // Maximum erreicht

        $this->artisan('portal:send-invitations')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_reminder_only_after_seven_days(): void
    {
        Mail::fake();
        SystemSetting::set('portal_invite_auto_enabled', '1');
        $this->unregisteredCustomer(['invitation_sent_at' => now()->subDays(3), 'invitation_count' => 1]);  // zu frisch
        $this->unregisteredCustomer(['invitation_sent_at' => now()->subDays(10), 'invitation_count' => 1]); // faellig

        $this->artisan('portal:send-invitations')->assertSuccessful();

        Mail::assertSent(CustomerWelcomeMail::class, 1);
    }

    public function test_respects_daily_cap(): void
    {
        Mail::fake();
        SystemSetting::set('portal_invite_auto_enabled', '1');
        SystemSetting::set('portal_invite_daily_cap', '2');
        for ($i = 0; $i < 5; $i++) {
            $this->unregisteredCustomer();
        }

        $this->artisan('portal:send-invitations')->assertSuccessful();

        Mail::assertSent(CustomerWelcomeMail::class, 2);
    }
}
