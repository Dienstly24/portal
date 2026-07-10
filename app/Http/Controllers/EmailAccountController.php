<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EmailAccount;
use App\Services\Mailbox\MailboxProviderFactory;
use Illuminate\Http\Request;

/**
 * Admin-Verwaltung von E-Mail-Postfächern (Architekturplan Abschnitt 3.2).
 * Nur für admin zugänglich (Route-Middleware) - Zugangsdaten sind
 * sensibel genug, dass auch manager/support hier keinen Zugriff haben.
 */
class EmailAccountController extends Controller
{
    public function index()
    {
        $accounts = EmailAccount::withCount('messages')->orderBy('name')->get();
        return view('admin.email_accounts.index', compact('accounts'));
    }

    public function create()
    {
        return view('admin.email_accounts.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $account = EmailAccount::create($this->toAttributes($data, $request) + ['created_by' => auth()->id()]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'email_account_created',
            'entity_type' => 'email_account',
            'entity_id' => $account->id,
            'meta' => json_encode(['email_address' => $account->email_address, 'provider' => $account->provider], JSON_UNESCAPED_UNICODE),
        ]);

        return redirect()->route('admin.email_accounts.index')->with('success', 'Postfach angelegt.');
    }

    public function edit(string $id)
    {
        $account = EmailAccount::findOrFail($id);
        return view('admin.email_accounts.edit', compact('account'));
    }

    public function update(Request $request, string $id)
    {
        $account = EmailAccount::findOrFail($id);
        $data = $this->validated($request, requirePassword: false);

        $attributes = $this->toAttributes($data, $request, existing: $account);
        $account->update($attributes);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'email_account_updated',
            'entity_type' => 'email_account',
            'entity_id' => $account->id,
            'meta' => json_encode(['email_address' => $account->email_address], JSON_UNESCAPED_UNICODE),
        ]);

        return redirect()->route('admin.email_accounts.index')->with('success', 'Postfach aktualisiert.');
    }

    public function destroy(string $id)
    {
        $account = EmailAccount::findOrFail($id);
        $account->delete();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'email_account_deleted',
            'entity_type' => 'email_account',
            'entity_id' => $id,
            'meta' => json_encode(['email_address' => $account->email_address], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Postfach entfernt.');
    }

    public function toggleActive(string $id)
    {
        $account = EmailAccount::findOrFail($id);
        $account->update(['is_active' => !$account->is_active]);
        return back()->with('success', $account->is_active ? 'Postfach aktiviert.' : 'Postfach deaktiviert.');
    }

    /** Verbindungstest vor dem Speichern / aus der Übersicht heraus. */
    public function testConnection(string $id, MailboxProviderFactory $factory)
    {
        $account = EmailAccount::findOrFail($id);

        try {
            $factory->make($account)->testConnection($account);
            $account->update(['last_error' => null]);
            return back()->with('success', 'Verbindung erfolgreich getestet.');
        } catch (\Throwable $e) {
            $account->update(['last_error' => $e->getMessage()]);
            return back()->with('error', 'Verbindung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function validated(Request $request, bool $requirePassword = true): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'email_address' => 'required|email|max:255',
            'provider' => 'required|in:' . implode(',', array_keys(EmailAccount::PROVIDERS)),
            'imap_host' => 'nullable|string|max:255',
            'imap_port' => 'nullable|integer|min:1|max:65535',
            'imap_encryption' => 'nullable|in:ssl,tls,none',
            'smtp_host' => 'nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|in:ssl,tls,none',
            'username' => 'nullable|string|max:255',
            'password' => ($requirePassword ? 'nullable' : 'nullable') . '|string|max:1024',
            'folders' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function toAttributes(array $data, Request $request, ?EmailAccount $existing = null): array
    {
        $folders = collect(explode(',', $data['folders'] ?? 'INBOX'))
            ->map(fn ($f) => trim($f))->filter()->values()->all();

        $credentials = $existing?->credentials ?? [];
        if ($request->filled('password')) {
            $credentials['password'] = $data['password'];
        }

        return [
            'name' => $data['name'],
            'email_address' => $data['email_address'],
            'provider' => $data['provider'],
            'imap_host' => $data['imap_host'] ?? null,
            'imap_port' => $data['imap_port'] ?? 993,
            'imap_encryption' => $data['imap_encryption'] ?? 'ssl',
            'smtp_host' => $data['smtp_host'] ?? null,
            'smtp_port' => $data['smtp_port'] ?? 587,
            'smtp_encryption' => $data['smtp_encryption'] ?? 'tls',
            'username' => $data['username'] ?? null,
            'credentials' => $credentials,
            'folders' => $folders ?: ['INBOX'],
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
