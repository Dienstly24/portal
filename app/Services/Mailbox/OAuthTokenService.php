<?php
namespace App\Services\Mailbox;

use App\Models\EmailAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OAuth2-Anbindung für Gmail / Microsoft 365 (Phase 2, Architekturplan
 * Abschnitt 3.1/3.3): Consent-Redirect, Code-Tausch, Refresh.
 * Es wird NUR der Refresh-Token dauerhaft (verschlüsselt in
 * email_accounts.credentials) gespeichert; Access-Tokens leben kurz
 * und werden bei Bedarf erneuert.
 */
class OAuthTokenService
{
    private const GOOGLE_AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    private const MS_SCOPE = 'offline_access https://graph.microsoft.com/Mail.Read';

    public function isConfigured(EmailAccount $account): bool
    {
        return match ($account->provider) {
            'gmail_oauth' => (bool) config('services.google.client_id'),
            'microsoft_oauth' => (bool) config('services.microsoft.client_id'),
            default => false,
        };
    }

    public function isConnected(EmailAccount $account): bool
    {
        return !empty(($account->credentials ?? [])['refresh_token']);
    }

    /** Consent-URL für den "Verbinden"-Button im Admin. */
    public function authorizationUrl(EmailAccount $account): string
    {
        $this->assertConfigured($account);
        // CSRF-Schutz (Audit): der State bindet die Rueckleitung an DIESE
        // Sitzung. Eine zufaellige Nonce liegt in der Session; nur wer den
        // Consent hier gestartet hat, kann den Callback gueltig abschliessen.
        // Der verschluesselte State schuetzt zusaetzlich vor Manipulation.
        $nonce = Str::random(40);
        session(['email_oauth_state' => ['account' => (string) $account->id, 'nonce' => $nonce]]);
        $state = Crypt::encryptString($account->id . '|' . $nonce);
        $redirect = route('admin.email_accounts.oauth_callback');

        if ($account->provider === 'gmail_oauth') {
            return self::GOOGLE_AUTH . '?' . http_build_query([
                'client_id' => config('services.google.client_id'),
                'redirect_uri' => $redirect,
                'response_type' => 'code',
                'scope' => self::GOOGLE_SCOPE,
                'access_type' => 'offline',
                'prompt' => 'consent', // erzwingt Refresh-Token auch bei erneuter Verbindung
                'login_hint' => $account->email_address,
                'state' => $state,
            ]);
        }

        return $this->msEndpoint('authorize') . '?' . http_build_query([
            'client_id' => config('services.microsoft.client_id'),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => self::MS_SCOPE,
            'login_hint' => $account->email_address,
            'state' => $state,
        ]);
    }

    /** Callback: Code gegen Tokens tauschen, Refresh-Token verschlüsselt ablegen. */
    public function handleCallback(string $code, string $state): EmailAccount
    {
        [$accountId, $nonce] = array_pad(explode('|', Crypt::decryptString($state), 2), 2, null);

        // Der Callback muss aus derselben Sitzung stammen, die den Consent
        // gestartet hat (State-Nonce). Fehlt sie oder weicht sie ab (fremd
        // untergeschobener/alter Link), wird abgelehnt - die Nonce ist
        // einmalig und wird sofort verbraucht.
        $expected = session('email_oauth_state');
        session()->forget('email_oauth_state');
        if (! is_array($expected)
            || ! hash_equals((string) ($expected['nonce'] ?? ''), (string) $nonce)
            || ! hash_equals((string) ($expected['account'] ?? ''), (string) $accountId)) {
            throw new \RuntimeException('OAuth-Rueckleitung ungueltig oder abgelaufen. Bitte im Admin erneut "Verbinden" starten.');
        }

        $account = EmailAccount::findOrFail($accountId);
        $this->assertConfigured($account);

        $response = Http::asForm()->post($this->tokenEndpoint($account), $this->tokenRequest($account, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('admin.email_accounts.oauth_callback'),
        ]));

        if (!$response->successful() || empty($response->json('access_token'))) {
            throw new \RuntimeException('OAuth-Token-Tausch fehlgeschlagen: ' . mb_substr($response->body(), 0, 200));
        }

        $credentials = $account->credentials ?? [];
        $credentials['access_token'] = $response->json('access_token');
        $credentials['expires_at'] = now()->addSeconds((int) $response->json('expires_in', 3600))->timestamp;
        if ($response->json('refresh_token')) {
            $credentials['refresh_token'] = $response->json('refresh_token');
        }

        $account->update(['credentials' => $credentials, 'last_error' => null]);

        return $account;
    }

    /** Gültigen Access-Token liefern; bei Ablauf über den Refresh-Token erneuern. */
    public function accessToken(EmailAccount $account): string
    {
        $credentials = $account->credentials ?? [];

        if (empty($credentials['refresh_token'])) {
            throw new \RuntimeException(
                (EmailAccount::PROVIDERS[$account->provider] ?? $account->provider)
                . ': Postfach ist noch nicht verbunden - bitte im Admin "Verbinden" ausführen.'
            );
        }

        $expiresAt = (int) ($credentials['expires_at'] ?? 0);
        if (!empty($credentials['access_token']) && $expiresAt > now()->addMinute()->timestamp) {
            return $credentials['access_token'];
        }

        $response = Http::asForm()->post($this->tokenEndpoint($account), $this->tokenRequest($account, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $credentials['refresh_token'],
        ]));

        if (!$response->successful() || empty($response->json('access_token'))) {
            // Sichtbar machen statt still abreißen (Prüfbericht-Risiko OAuth-Widerruf).
            $account->update(['last_error' => 'OAuth-Refresh fehlgeschlagen: ' . mb_substr($response->body(), 0, 150)]);
            throw new \RuntimeException('OAuth-Refresh fehlgeschlagen für ' . $account->email_address);
        }

        $credentials['access_token'] = $response->json('access_token');
        $credentials['expires_at'] = now()->addSeconds((int) $response->json('expires_in', 3600))->timestamp;
        if ($response->json('refresh_token')) {
            $credentials['refresh_token'] = $response->json('refresh_token');
        }
        $account->update(['credentials' => $credentials]);

        return $credentials['access_token'];
    }

    private function tokenEndpoint(EmailAccount $account): string
    {
        return $account->provider === 'gmail_oauth' ? self::GOOGLE_TOKEN : $this->msEndpoint('token');
    }

    private function tokenRequest(EmailAccount $account, array $extra): array
    {
        $base = $account->provider === 'gmail_oauth'
            ? ['client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret')]
            : ['client_id' => config('services.microsoft.client_id'), 'client_secret' => config('services.microsoft.client_secret'), 'scope' => self::MS_SCOPE];

        return $base + $extra;
    }

    private function msEndpoint(string $type): string
    {
        return 'https://login.microsoftonline.com/' . config('services.microsoft.tenant', 'common') . '/oauth2/v2.0/' . $type;
    }

    private function assertConfigured(EmailAccount $account): void
    {
        if (!$this->isConfigured($account)) {
            throw new \RuntimeException(
                (EmailAccount::PROVIDERS[$account->provider] ?? $account->provider)
                . ': OAuth-App ist nicht konfiguriert (GOOGLE_CLIENT_ID/MICROSOFT_CLIENT_ID in der Umgebung setzen).'
            );
        }
    }
}
