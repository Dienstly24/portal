<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Mailbox\GmailApiMailboxProvider;
use App\Services\Mailbox\GraphApiMailboxProvider;
use App\Services\Mailbox\MailboxProviderFactory;
use App\Services\Mailbox\OAuthTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthMailboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'google-client',
            'services.google.client_secret' => 'google-secret',
            'services.microsoft.client_id' => 'ms-client',
            'services.microsoft.client_secret' => 'ms-secret',
        ]);
    }

    private function account(string $provider, array $credentials = []): EmailAccount
    {
        return EmailAccount::create([
            'name' => 'OAuth Test', 'email_address' => uniqid() . '@dienstly24.de',
            'provider' => $provider, 'credentials' => $credentials,
            'folders' => ['INBOX'], 'is_active' => true,
        ]);
    }

    public function test_factory_routes_oauth_providers(): void
    {
        $factory = new MailboxProviderFactory();
        $this->assertInstanceOf(GmailApiMailboxProvider::class, $factory->make($this->account('gmail_oauth')));
        $this->assertInstanceOf(GraphApiMailboxProvider::class, $factory->make($this->account('microsoft_oauth')));
    }

    public function test_authorization_url_contains_expected_parameters(): void
    {
        $service = app(OAuthTokenService::class);

        $googleUrl = $service->authorizationUrl($this->account('gmail_oauth'));
        $this->assertStringContainsString('accounts.google.com', $googleUrl);
        $this->assertStringContainsString('client_id=google-client', $googleUrl);
        $this->assertStringContainsString('gmail.readonly', $googleUrl);
        $this->assertStringContainsString('access_type=offline', $googleUrl);

        $msUrl = $service->authorizationUrl($this->account('microsoft_oauth'));
        $this->assertStringContainsString('login.microsoftonline.com', $msUrl);
        $this->assertStringContainsString('Mail.Read', $msUrl);
    }

    public function test_unconfigured_oauth_gives_clear_error(): void
    {
        config(['services.google.client_id' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nicht konfiguriert/');
        app(OAuthTokenService::class)->authorizationUrl($this->account('gmail_oauth'));
    }

    public function test_callback_exchanges_code_and_stores_encrypted_refresh_token(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600,
        ])]);

        $account = $this->account('gmail_oauth');
        $state = \Illuminate\Support\Facades\Crypt::encryptString((string) $account->id);

        app(OAuthTokenService::class)->handleCallback('the-code', $state);

        $account->refresh();
        $this->assertSame('rt-1', $account->credentials['refresh_token']);
        // Verschlüsselt in der DB, nie im Klartext
        $raw = \DB::table('email_accounts')->where('id', $account->id)->value('credentials');
        $this->assertStringNotContainsString('rt-1', (string) $raw);
    }

    public function test_expired_access_token_is_refreshed(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'at-new', 'expires_in' => 3600,
        ])]);

        $account = $this->account('gmail_oauth', [
            'refresh_token' => 'rt-1', 'access_token' => 'at-old',
            'expires_at' => now()->subMinute()->timestamp,
        ]);

        $token = app(OAuthTokenService::class)->accessToken($account);

        $this->assertSame('at-new', $token);
        $this->assertSame('at-new', $account->fresh()->credentials['access_token']);
    }

    public function test_failed_refresh_records_visible_error(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $account = $this->account('gmail_oauth', [
            'refresh_token' => 'rt-widerrufen', 'expires_at' => 0,
        ]);

        try {
            app(OAuthTokenService::class)->accessToken($account);
            $this->fail('Exception erwartet');
        } catch (\RuntimeException) {
        }

        // Prüfbericht-Risiko "OAuth-Widerruf reißt still ab": Fehler ist am Konto sichtbar.
        $this->assertStringContainsString('OAuth-Refresh fehlgeschlagen', (string) $account->fresh()->last_error);
    }

    public function test_unconnected_account_asks_for_connection(): void
    {
        $this->expectExceptionMessageMatches('/noch nicht verbunden/');
        app(OAuthTokenService::class)->accessToken($this->account('gmail_oauth'));
    }

    public function test_gmail_provider_parses_messages_and_attachments(): void
    {
        $body = base64_encode('Hallo, das ist der Text.');
        $pdf = base64_encode('%PDF-1.4 inhalt');
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'm1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1?*' => Http::response([
                'id' => 'm1', 'internalDate' => (string) (now()->timestamp * 1000),
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Erika Musterfrau <erika@kunde.de>'],
                        ['name' => 'To', 'value' => 'info@dienstly24.de'],
                        ['name' => 'Subject', 'value' => 'Testbetreff'],
                    ],
                    'mimeType' => 'multipart/mixed',
                    'parts' => [
                        ['mimeType' => 'text/plain', 'filename' => '', 'body' => ['data' => strtr($body, '+/', '-_')]],
                        ['mimeType' => 'application/pdf', 'filename' => 'doc.pdf', 'body' => ['attachmentId' => 'att1']],
                    ],
                ],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1/attachments/att1' => Http::response([
                'data' => strtr($pdf, '+/', '-_'),
            ]),
        ]);

        $account = $this->account('gmail_oauth', ['refresh_token' => 'rt', 'access_token' => 'at', 'expires_at' => now()->addHour()->timestamp]);
        $messages = (new GmailApiMailboxProvider(app(OAuthTokenService::class)))->fetchNewMessages($account);

        $this->assertCount(1, $messages);
        $this->assertSame('GMAIL:m1', $messages[0]->uid);
        $this->assertSame('erika@kunde.de', $messages[0]->fromAddress);
        $this->assertSame('Erika Musterfrau', $messages[0]->fromName);
        $this->assertSame('Testbetreff', $messages[0]->subject);
        $this->assertSame('Hallo, das ist der Text.', $messages[0]->bodyText);
        $this->assertCount(1, $messages[0]->attachments);
        $this->assertSame('doc.pdf', $messages[0]->attachments[0]['filename']);
        $this->assertSame('%PDF-1.4 inhalt', $messages[0]->attachments[0]['content']);
    }

    public function test_graph_provider_parses_messages_and_attachments(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/mailFolders/inbox/messages?*' => Http::response(['value' => [[
                'id' => 'g1', 'subject' => 'Graph-Betreff',
                'from' => ['emailAddress' => ['address' => 'max@kunde.de', 'name' => 'Max Mustermann']],
                'toRecipients' => [['emailAddress' => ['address' => 'info@dienstly24.de']]],
                'receivedDateTime' => now()->toIso8601String(),
                'body' => ['contentType' => 'text', 'content' => 'Textinhalt'],
                'bodyPreview' => 'Textinhalt',
                'hasAttachments' => true,
                'internetMessageId' => '<x@y>',
            ]]]),
            'graph.microsoft.com/v1.0/me/messages/g1/attachments' => Http::response(['value' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => 'abrechnung.pdf', 'contentType' => 'application/pdf',
                'contentBytes' => base64_encode('%PDF-1.4 x'),
            ]]]),
        ]);

        $account = $this->account('microsoft_oauth', ['refresh_token' => 'rt', 'access_token' => 'at', 'expires_at' => now()->addHour()->timestamp]);
        $messages = (new GraphApiMailboxProvider(app(OAuthTokenService::class)))->fetchNewMessages($account);

        $this->assertCount(1, $messages);
        $this->assertSame('GRAPH:g1', $messages[0]->uid);
        $this->assertSame('max@kunde.de', $messages[0]->fromAddress);
        $this->assertSame('Textinhalt', $messages[0]->bodyText);
        $this->assertSame('abrechnung.pdf', $messages[0]->attachments[0]['filename']);
    }

    public function test_oauth_callback_route_connects_account(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600,
        ])]);

        $admin = User::factory()->create(['role' => 'admin']);
        $account = $this->account('gmail_oauth');
        $state = \Illuminate\Support\Facades\Crypt::encryptString((string) $account->id);

        $this->actingAs($admin)
            ->get(route('admin.email_accounts.oauth_callback', ['code' => 'c', 'state' => $state]))
            ->assertRedirect(route('admin.email_accounts.index'));

        $this->assertSame('rt-1', $account->fresh()->credentials['refresh_token']);
    }

    public function test_oauth_redirect_route_sends_admin_to_provider(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $account = $this->account('gmail_oauth');

        $response = $this->actingAs($admin)->get(route('admin.email_accounts.oauth', $account->id));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }
}
