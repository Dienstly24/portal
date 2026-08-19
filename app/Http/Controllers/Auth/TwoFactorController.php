<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\TwoFactorService;
use App\Support\QrCode;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Einrichtung und Abfrage der Zwei-Faktor-Anmeldung.
 *
 * Der QR-Code wird als INLINE-SVG erzeugt (App\Support\QrCode). Das
 * Geheimnis verlaesst den Server damit nie als Datei, landet in keinem
 * Cache und erreicht keinen fremden Dienst - eine QR-Grafik von einem
 * externen Generator zu holen, waere das Gegenteil dessen, wozu der
 * zweite Faktor da ist.
 *
 * Zusaetzlich steht das Geheimnis IMMER auch als abtippbarer Schluessel
 * da: nicht jedes Telefon kann scannen, und ein QR-Code, der auf einem
 * Bildschirm nicht sauber erscheint, darf niemanden aussperren.
 */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    /* ----------------------------- Einrichtung ----------------------------- */

    public function setup(Request $request)
    {
        $user = $request->user();

        if ($user->hasTwoFactor()) {
            return redirect()->route('two_factor.recovery_codes');
        }

        $secret = $this->twoFactor->beginSetup($user);
        $uri = Totp::provisioningUri($secret, $user->email ?: (string) $user->id, $this->issuer());

        return view('auth.two-factor-setup', [
            'secret' => $secret,
            'secretFormatted' => Totp::formatSecret($secret),
            'qrSvg' => QrCode::svg($uri, 5, 3, __('QR-Code für die Authenticator-App')),
            'issuer' => $this->issuer(),
        ]);
    }

    public function setupStore(Request $request)
    {
        $user = $request->user();

        $request->validate(['code' => ['required', 'string']], [
            'code.required' => 'Bitte geben Sie den sechsstelligen Code aus Ihrer App ein.',
        ]);

        $codes = $this->twoFactor->confirmSetup($user, (string) $request->input('code'));

        if ($codes === null) {
            return back()->withErrors([
                'code' => 'Der Code stimmt nicht. Bitte prüfen Sie, ob die Uhrzeit Ihres Telefons automatisch gestellt wird, und versuchen Sie es mit dem aktuell angezeigten Code erneut.',
            ]);
        }

        // Wer gerade eingerichtet hat, hat den Faktor damit auch erbracht.
        $this->twoFactor->markVerified($request, $user);

        // Ersatzcodes stehen NUR jetzt im Klartext zur Verfuegung - danach
        // liegen sie nur noch gehasht vor und sind nicht wiederherstellbar.
        return redirect()->route('two_factor.recovery_codes')
            ->with('recovery_codes', $codes);
    }

    /** Ersatzcodes anzeigen (nur direkt nach dem Erzeugen im Klartext). */
    public function recoveryCodes(Request $request)
    {
        $user = $request->user();

        return view('auth.two-factor-recovery-codes', [
            'codes' => session('recovery_codes', []),
            'remaining' => $this->twoFactor->remainingRecoveryCodes($user),
            'active' => $user->hasTwoFactor(),
        ]);
    }

    /** Neue Ersatzcodes erzeugen - nur mit gueltigem zweiten Faktor. */
    public function regenerate(Request $request)
    {
        $user = $request->user();

        $request->validate(['code' => ['required', 'string']]);

        if (! $this->twoFactor->verify($user, (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'Der Code stimmt nicht.']);
        }

        $codes = $this->twoFactor->generateRecoveryCodes($user);
        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'two_factor_recovery_codes_renewed',
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
            'meta' => json_encode([], JSON_UNESCAPED_UNICODE),
        ]);

        return redirect()->route('two_factor.recovery_codes')->with('recovery_codes', $codes);
    }

    /* ------------------------------- Abfrage ------------------------------- */

    public function challenge(Request $request)
    {
        $user = $request->user();

        if (! $user->hasTwoFactor()) {
            return redirect()->route('two_factor.setup');
        }
        if ($this->twoFactor->isVerified($request, $user)) {
            return redirect()->to($this->homeFor($request));
        }

        return view('auth.two-factor-challenge', [
            'remaining' => $this->twoFactor->remainingRecoveryCodes($user),
        ]);
    }

    public function challengeStore(Request $request)
    {
        $user = $request->user();

        $request->validate(['code' => ['required', 'string']], [
            'code.required' => 'Bitte geben Sie den Code aus Ihrer App oder einen Ersatzcode ein.',
        ]);

        // Eigene Bremse: der Code hat nur eine Million Moeglichkeiten und
        // steht bei jedem Versuch wieder zur Verfuegung. Ohne Begrenzung
        // waere er in Minuten durchprobiert.
        $key = '2fa:' . $user->id . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Zu viele Fehlversuche. Bitte warten Sie ' . RateLimiter::availableIn($key) . ' Sekunden.',
            ]);
        }

        if (! $this->twoFactor->verify($user, (string) $request->input('code'))) {
            RateLimiter::hit($key, 300);
            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'two_factor_failed',
                'entity_type' => 'user',
                'entity_id' => (string) $user->id,
                'meta' => json_encode(['ip' => $request->ip()], JSON_UNESCAPED_UNICODE),
            ]);

            return back()->withErrors(['code' => 'Der Code stimmt nicht. Bitte versuchen Sie es erneut.']);
        }

        RateLimiter::clear($key);
        $this->twoFactor->markVerified($request, $user);

        return redirect()->intended($this->homeFor($request));
    }

    /* -------------------------------- Sonstiges ---------------------------- */

    private function issuer(): string
    {
        return (string) (\App\Models\SystemSetting::get('company_name') ?: config('app.name', 'Dienstly24'));
    }

    private function homeFor(Request $request): string
    {
        $user = $request->user();

        if (in_array($user->role, ['admin', 'manager', 'support', 'employee'], true)) {
            return route('admin.dashboard');
        }
        if ($user->role === 'partner') {
            return route('partner.dashboard');
        }

        return route('portal.dashboard');
    }
}
