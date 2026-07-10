<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_create_email_account_with_encrypted_credentials(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('admin.email_accounts.store'), [
            'name' => 'Info-Postfach',
            'email_address' => 'info@dienstly24.de',
            'provider' => 'hostinger_imap',
            'imap_host' => 'imap.hostinger.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'username' => 'info@dienstly24.de',
            'password' => 'super-secret-password',
            'folders' => 'INBOX',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.email_accounts.index'));

        $account = EmailAccount::firstOrFail();
        $this->assertSame('info@dienstly24.de', $account->email_address);

        // Verschlüsselt: das Klartext-Passwort taucht nicht in der DB-Rohspalte auf.
        $raw = \DB::table('email_accounts')->where('id', $account->id)->value('credentials');
        $this->assertStringNotContainsString('super-secret-password', (string) $raw);

        // Aber über den Cast entschlüsselbar für den Sync-Job.
        $this->assertSame('super-secret-password', $account->fresh()->credentials['password']);
    }

    public function test_non_admin_cannot_manage_email_accounts(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);

        // EnsureUserRole leitet Staff mit falscher Rolle in den Adminbereich um (kein 403) - siehe EnsureUserRole::handle.
        $this->actingAs($manager)->get(route('admin.email_accounts.index'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_updating_account_without_password_keeps_existing_credentials(): void
    {
        $admin = $this->admin();
        $account = EmailAccount::create([
            'name' => 'KV-Postfach',
            'email_address' => 'kv@dienstly24.de',
            'provider' => 'imap',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'credentials' => ['password' => 'original-secret'],
            'folders' => ['INBOX'],
            'is_active' => true,
        ]);

        $this->actingAs($admin)->put(route('admin.email_accounts.update', $account->id), [
            'name' => 'KV-Postfach (umbenannt)',
            'email_address' => 'kv@dienstly24.de',
            'provider' => 'imap',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'folders' => 'INBOX',
        ])->assertRedirect(route('admin.email_accounts.index'));

        $account->refresh();
        $this->assertSame('KV-Postfach (umbenannt)', $account->name);
        $this->assertSame('original-secret', $account->credentials['password']);
    }

    public function test_test_connection_reports_failure_gracefully_for_unreachable_host(): void
    {
        $admin = $this->admin();
        $account = EmailAccount::create([
            'name' => 'Nicht erreichbar',
            'email_address' => 'nope@dienstly24.de',
            'provider' => 'imap',
            'imap_host' => '127.0.0.1',
            'imap_port' => 1,
            'credentials' => ['password' => 'x'],
            'folders' => ['INBOX'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.email_accounts.test', $account->id));

        $response->assertRedirect();
        $this->assertNotNull($account->fresh()->last_error);
    }

    public function test_oauth_provider_test_connection_reports_not_yet_configured(): void
    {
        $admin = $this->admin();
        $account = EmailAccount::create([
            'name' => 'Gmail',
            'email_address' => 'gmail@dienstly24.de',
            'provider' => 'gmail_oauth',
            'folders' => ['INBOX'],
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.email_accounts.test', $account->id))->assertRedirect();
        $this->assertStringContainsString('noch nicht konfiguriert', $account->fresh()->last_error);
    }
}
