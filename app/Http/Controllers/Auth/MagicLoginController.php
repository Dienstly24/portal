<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Magischer Erst-Login aus der Willkommens-E-Mail: signierter Link
 * (90 Tage gültig), der den Kunden ohne Passworteingabe anmeldet und
 * direkt zur Passwortänderung führt.
 *
 * Sicherheitsregeln:
 * - Nur mit gültiger Signatur erreichbar (Middleware 'signed' – jede
 *   Manipulation an ID oder Ablaufzeit macht den Link ungültig).
 * - Funktioniert AUSSCHLIESSLICH für Kunden-Accounts. Staff-, Partner-
 *   oder deaktivierte Konten werden abgewiesen (kein Privilegienpfad).
 * - Ratenbegrenzung gegen Durchprobieren, jede Nutzung im Audit-Log.
 */
class MagicLoginController extends Controller
{
    public function __invoke(Request $request, string $user)
    {
        $account = User::find($user);

        if (
            $account === null
            || $account->role !== 'customer'
            || (isset($account->is_active) && !$account->is_active)
        ) {
            abort(403, 'Dieser Anmeldelink ist nicht gültig.');
        }

        // Bereits als jemand anderes eingeloggt? Sauber trennen.
        if (auth()->check() && auth()->id() !== $account->id) {
            auth()->logout();
        }

        auth()->login($account);
        $request->session()->regenerate();

        $account->forceFill([
            'last_login_at' => now(),
            'first_login_at' => $account->first_login_at ?? now(),
        ])->save();

        ActivityLog::create([
            'user_id' => $account->id,
            'action' => 'magic_login_used',
            'entity_type' => 'user',
            'entity_id' => (string) $account->id,
            'meta' => json_encode(['ip' => $request->ip()], JSON_UNESCAPED_UNICODE),
        ]);

        return redirect()->route('portal.profile')
            ->with('success', 'Sie wurden automatisch angemeldet. Bitte legen Sie jetzt unter „Passwort ändern" Ihr persönliches Passwort fest.');
    }
}
