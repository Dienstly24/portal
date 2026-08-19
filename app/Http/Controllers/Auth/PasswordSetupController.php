<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * Zwei Wege zum EIGENEN Passwort - beide enden im selben Bildschirm:
 *
 * 1. EINLADUNG (signierter Link, 14 Tage): Der Kunde bzw. der neue
 *    Mitarbeiter bekommt keinen Klartext mehr per Mail, sondern einen
 *    signierten Link, der genau zu diesem Konto gehoert. Die Signatur
 *    haengt am APP_KEY: jede Manipulation an ID oder Ablaufzeit macht
 *    den Link ungueltig. Gleiche Bauart wie der bereits bestehende
 *    Magic-Login, nur kuerzer gueltig (14 statt 90 Tage) und ohne
 *    Anmeldung fuer den Fall, dass jemand ihn abfaengt: der Link setzt
 *    ein Passwort, er oeffnet keine Sitzung.
 *
 * 2. ERZWUNGENER WECHSEL (angemeldet): Wer sich mit einem vom SYSTEM
 *    vergebenen Passwort anmeldet (Geburtsdatum, Admin-Reset, CLI),
 *    landet ueber EnsurePasswordChanged hier und kommt erst weiter,
 *    wenn ein eigenes Passwort steht.
 */
class PasswordSetupController extends Controller
{
    /** Gueltigkeit des Einladungs-Links. */
    public const INVITATION_DAYS = 14;

    /**
     * Signierten Einladungs-Link fuer ein Konto bauen.
     *
     * Die Signatur wird bewusst RELATIV berechnet (absolute: false) und
     * erst danach zu einer vollen Adresse ergaenzt. Grund: Kundenmails
     * entstehen in der Beraterwelt (admin.dienstly24.de), CustomerWelcomeMail
     * schreibt jeden Kundenlink anschliessend auf die Portal-Domain um.
     * Eine ueber den HOST mitsignierte Adresse waere nach dieser
     * Umschreibung ungueltig - der Kunde saehe "Link nicht gueltig",
     * obwohl alles richtig lief. Relativ signiert ueberlebt der Link den
     * Domainwechsel; geschuetzt bleiben Konto-ID und Ablaufzeitpunkt.
     * Geprueft wird entsprechend mit 'signed:relative'.
     */
    public static function invitationUrl(User $user): string
    {
        $relative = URL::temporarySignedRoute(
            'password.setup',
            now()->addDays(self::INVITATION_DAYS),
            ['user' => $user->id],
            absolute: false,
        );

        return url($relative);
    }

    /* ------------------------------------------------------------------
     | 1) Einladung: signierter Link
     * ----------------------------------------------------------------- */

    public function create(Request $request, string $user)
    {
        $account = $this->accountForSignedLink($user);

        return view('auth.set-password', [
            'mode' => 'invitation',
            'account' => $account,
            'action' => $request->fullUrl(),
            'minLength' => PasswordPolicy::minimumFor($account->role),
        ]);
    }

    public function store(Request $request, string $user)
    {
        $account = $this->accountForSignedLink($user);

        $request->validate(
            ['password' => ['required', 'confirmed', PasswordPolicy::forRole($account->role)]],
            [],
            ['password' => __('Passwort')],
        );

        $account->setPassword($request->input('password'));

        ActivityLog::create([
            'user_id' => $account->id,
            'action' => 'password_set_via_invitation',
            'entity_type' => 'user',
            'entity_id' => (string) $account->id,
            'meta' => json_encode(['ip' => $request->ip()], JSON_UNESCAPED_UNICODE),
        ]);

        return redirect()->route('login')->with(
            'status',
            __('Ihr Passwort ist gesetzt. Sie können sich jetzt anmelden.')
        );
    }

    /**
     * Konto zum signierten Link aufloesen. Deaktivierte Konten werden
     * abgewiesen - ein alter Einladungslink darf ein gesperrtes Konto
     * nicht wiederbeleben.
     */
    private function accountForSignedLink(string $user): User
    {
        $account = User::find($user);

        if ($account === null || (isset($account->is_active) && ! $account->is_active)) {
            abort(403, __('Dieser Link ist nicht mehr gültig.'));
        }

        return $account;
    }

    /* ------------------------------------------------------------------
     | 2) Erzwungener Wechsel fuer angemeldete Konten
     * ----------------------------------------------------------------- */

    public function forced(Request $request)
    {
        $account = $request->user();

        // Kein Wechsel faellig? Dann gehoert der Nutzer nicht hierher.
        if (! $account->needsPasswordChange()) {
            return redirect()->to($this->homeFor($account));
        }

        return view('auth.set-password', [
            'mode' => 'forced',
            'account' => $account,
            'action' => route('password.forced.store'),
            'minLength' => PasswordPolicy::minimumFor($account->role),
        ]);
    }

    public function forcedStore(Request $request)
    {
        $account = $request->user();

        $request->validate(
            ['password' => ['required', 'confirmed', PasswordPolicy::for($account)]],
            [],
            ['password' => __('Passwort')],
        );

        // Das alte (system-vergebene) Passwort darf nicht einfach erneut
        // gesetzt werden - sonst waere der Zwangswechsel ein Klick ins
        // Leere und das Geburtsdatum bliebe das Passwort.
        if (\Illuminate\Support\Facades\Hash::check($request->input('password'), $account->password)) {
            return back()->withErrors([
                'password' => __('Bitte wählen Sie ein NEUES Passwort - nicht das bisherige.'),
            ]);
        }

        $account->setPassword($request->input('password'));

        // Andere Sitzungen dieses Kontos entwerten (falls das bekannte
        // Startpasswort bereits von jemand anderem benutzt wurde) - und
        // die EIGENE Sitzung ausdruecklich am Leben halten.
        Auth::logoutOtherDevices($request->input('password'));
        \App\Support\SessionPasswordHash::refresh($request);

        ActivityLog::create([
            'user_id' => $account->id,
            'action' => 'password_changed_forced',
            'entity_type' => 'user',
            'entity_id' => (string) $account->id,
            'meta' => json_encode(['ip' => $request->ip()], JSON_UNESCAPED_UNICODE),
        ]);

        return redirect()->to($this->homeFor($account))->with(
            'success',
            __('Ihr Passwort wurde gesetzt. Willkommen!')
        );
    }

    /** Startseite je Rolle (gleiche Aufteilung wie nach dem Login). */
    private function homeFor(User $account): string
    {
        if (in_array($account->role, ['admin', 'manager', 'support', 'employee'], true)) {
            return route('admin.dashboard');
        }
        if ($account->role === 'partner') {
            return route('partner.dashboard');
        }

        return route('portal.dashboard');
    }
}
