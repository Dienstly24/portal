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
 * customers:merge-alternative-email - verschiebt die Alternativ-E-Mail (email2)
 * in die Haupt-/Login-E-Mail, leert email2 und laedt den Kunden ein. Schuetzt
 * bestehende Login-E-Mails und respektiert das Einladungs-Tagesbudget.
 */
class MergeAlternativeEmailTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $userAttr = [], array $custAttr = []): Customer
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

    public function test_moves_alternative_email_to_login_and_invites(): void
    {
        Mail::fake();
        $c = $this->customer(
            ['email' => 'platzhalter@dienstly24.internal'],
            ['email2' => 'echt@beispiel.de']
        );

        $this->artisan('customers:merge-alternative-email')->assertSuccessful();

        $c->refresh();
        $this->assertSame('echt@beispiel.de', $c->user->email);
        $this->assertNull($c->email2);
        $this->assertNotNull($c->user->invitation_sent_at);
        Mail::assertQueued(CustomerWelcomeMail::class, 1);
    }

    public function test_moves_when_login_email_is_null(): void
    {
        Mail::fake();
        $c = $this->customer(['email' => null], ['email2' => 'neu@beispiel.de']);

        $this->artisan('customers:merge-alternative-email')->assertSuccessful();

        $c->refresh();
        $this->assertSame('neu@beispiel.de', $c->user->email);
        $this->assertNull($c->email2);
    }

    public function test_never_overwrites_existing_real_login_email(): void
    {
        Mail::fake();
        $c = $this->customer(
            ['email' => 'login@beispiel.de'],
            ['email2' => 'anders@beispiel.de']
        );

        $this->artisan('customers:merge-alternative-email')->assertSuccessful();

        $c->refresh();
        // Login unangetastet, email2 bleibt erhalten (nur Konflikt gemeldet).
        $this->assertSame('login@beispiel.de', $c->user->email);
        $this->assertSame('anders@beispiel.de', $c->email2);
        Mail::assertNothingSent();
    }

    public function test_clears_duplicate_alternative_without_inviting(): void
    {
        Mail::fake();
        $c = $this->customer(
            ['email' => 'gleich@beispiel.de'],
            ['email2' => 'Gleich@beispiel.de'] // gleiche Adresse, andere Schreibweise
        );

        $this->artisan('customers:merge-alternative-email')->assertSuccessful();

        $c->refresh();
        $this->assertSame('gleich@beispiel.de', $c->user->email);
        $this->assertNull($c->email2);
        Mail::assertNothingSent();
    }

    public function test_skips_when_address_taken_by_other_user(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'belegt@beispiel.de']);
        $c = $this->customer(
            ['email' => 'platzhalter@dienstly24.internal'],
            ['email2' => 'belegt@beispiel.de']
        );

        $this->artisan('customers:merge-alternative-email')->assertSuccessful();

        $c->refresh();
        // email2 unveraendert, Login-Platzhalter unangetastet (Unique-Schutz).
        $this->assertSame('belegt@beispiel.de', $c->email2);
        $this->assertFalse($c->user->hasRealEmail());
        Mail::assertNothingSent();
    }

    public function test_respects_daily_cap_for_invitations(): void
    {
        Mail::fake();
        SystemSetting::set('portal_invite_daily_cap', '1');
        $this->customer(['email' => 'p1@dienstly24.internal'], ['email2' => 'a@beispiel.de', 'customer_number' => 'C-0001']);
        $this->customer(['email' => 'p2@dienstly24.internal'], ['email2' => 'b@beispiel.de', 'customer_number' => 'C-0002']);

        $this->artisan('customers:merge-alternative-email')->assertSuccessful();

        // Beide verschoben, aber nur eine Einladung (Budget = 1).
        Mail::assertQueued(CustomerWelcomeMail::class, 1);
        $this->assertNull(Customer::where('customer_number', 'C-0001')->first()->email2);
        $this->assertNull(Customer::where('customer_number', 'C-0002')->first()->email2);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Mail::fake();
        $c = $this->customer(
            ['email' => 'platzhalter@dienstly24.internal'],
            ['email2' => 'echt@beispiel.de']
        );

        $this->artisan('customers:merge-alternative-email --dry-run')->assertSuccessful();

        $c->refresh();
        $this->assertSame('echt@beispiel.de', $c->email2);
        $this->assertFalse($c->user->hasRealEmail());
        Mail::assertNothingSent();
    }

    public function test_ignores_invalid_alternative_address(): void
    {
        Mail::fake();
        $c = $this->customer(
            ['email' => 'platzhalter@dienstly24.internal'],
            ['email2' => 'kein-email']
        );

        $this->artisan('customers:merge-alternative-email')->assertSuccessful();

        $c->refresh();
        $this->assertSame('kein-email', $c->email2);
        $this->assertFalse($c->user->hasRealEmail());
        Mail::assertNothingSent();
    }
}
