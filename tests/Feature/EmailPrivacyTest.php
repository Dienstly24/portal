<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sicherheit & DSGVO der neuen Module (Priorität 9): Aufbewahrungsfrist
 * für unzugeordnete Mails, Mitlöschung bei Kundenlöschung, Rollen-
 * beschränkung des Posteingangs, verschlüsselte Postfach-Zugangsdaten.
 */
class EmailPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function account(): EmailAccount
    {
        return EmailAccount::firstOrCreate(
            ['email_address' => 'info@dienstly24.de'],
            ['name' => 'Test', 'provider' => 'imap', 'folders' => ['INBOX'], 'is_active' => true]
        );
    }

    private function message(array $overrides = []): EmailMessage
    {
        return EmailMessage::create(array_merge([
            'email_account_id' => $this->account()->id,
            'message_uid' => 'INBOX:' . uniqid(),
            'from_address' => 'wer@example.com',
            'subject' => 'Test',
            'body_text' => 'Inhalt',
            'processed_at' => now(),
        ], $overrides));
    }

    public function test_prune_deletes_old_unmatched_mails_only(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'K-1']);

        $oldUnmatched = $this->message();
        $oldUnmatched->forceFill(['created_at' => now()->subDays(120)])->save();

        $oldMatched = $this->message(['customer_id' => $customer->id]);
        $oldMatched->forceFill(['created_at' => now()->subDays(120)])->save();

        $freshUnmatched = $this->message();

        $this->artisan('emails:prune-unmatched')->assertSuccessful();

        $this->assertDatabaseMissing('email_messages', ['id' => $oldUnmatched->id]);
        // Kundenakte-Mails und frische Mails bleiben.
        $this->assertDatabaseHas('email_messages', ['id' => $oldMatched->id]);
        $this->assertDatabaseHas('email_messages', ['id' => $freshUnmatched->id]);
    }

    public function test_retention_period_is_configurable(): void
    {
        SystemSetting::create(['key' => 'email_retention_days', 'value' => '10']);

        $mail = $this->message();
        $mail->forceFill(['created_at' => now()->subDays(15)])->save();

        $this->artisan('emails:prune-unmatched')->assertSuccessful();

        $this->assertDatabaseMissing('email_messages', ['id' => $mail->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $mail = $this->message();
        $mail->forceFill(['created_at' => now()->subDays(120)])->save();

        $this->artisan('emails:prune-unmatched', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('email_messages', ['id' => $mail->id]);
    }

    public function test_deleting_customer_deletes_linked_emails(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'customer']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'K-2']);
        $mail = $this->message(['customer_id' => $customer->id]);

        $this->actingAs($admin)->delete(route('admin.customers.delete', $customer->id));

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        // DSGVO Art. 17: Mail-Volltexte des Kunden werden mitgelöscht.
        $this->assertDatabaseMissing('email_messages', ['id' => $mail->id]);
    }

    public function test_plain_employee_cannot_open_email_inbox(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);

        $this->actingAs($employee)->get(route('admin.email_inbox'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_support_role_can_open_email_inbox(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->get(route('admin.email_inbox'))->assertOk();
    }

    public function test_mailbox_credentials_are_stored_encrypted(): void
    {
        $account = EmailAccount::create([
            'name' => 'Secure', 'email_address' => 'kv@dienstly24.de', 'provider' => 'imap',
            'credentials' => ['password' => 'geheim123'],
            'folders' => ['INBOX'], 'is_active' => true,
        ]);

        $raw = \DB::table('email_accounts')->where('id', $account->id)->value('credentials');
        $this->assertStringNotContainsString('geheim123', (string) $raw);
        $this->assertSame('geheim123', $account->fresh()->credentials['password']);
        // Nie in Array-/JSON-Ausgaben auftauchen (hidden).
        $this->assertArrayNotHasKey('credentials', $account->fresh()->toArray());
    }
}
