<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // E-Mail robust behandeln: fuehrende/nachfolgende Leerzeichen (z. B.
        // aus Autofill/Copy-Paste) fuehrten bisher zu "Zugangsdaten falsch".
        $email = trim((string) $this->input('email'));
        $password = (string) $this->input('password');
        $remember = $this->boolean('remember');

        $user = \App\Models\User::where('email', $email)->first();
        if ($user && !$user->is_active) {
            RateLimiter::hit($this->throttleKey());
            throw ValidationException::withMessages([
                'email' => 'Dieses Konto wurde deaktiviert. Bitte wenden Sie sich an die Verwaltung.',
            ]);
        }

        if (Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
            RateLimiter::clear($this->throttleKey());
            return;
        }

        // Startpasswort = Geburtsdatum (TT.MM.JJJJ). Kunden tippen es haeufig
        // in einer abweichenden, aber gleichwertigen Schreibweise (z. B.
        // 5.3.1985, 05031985, 1985-03-05, oder mit Leerzeichen) und wurden
        // dann faelschlich abgewiesen. Erkennen wir das eingegebene Passwort
        // als das Geburtsdatum dieses Kunden, versuchen wir den Login erneut
        // mit der kanonischen Form. Das gewaehrt KEINEN zusaetzlichen Zugriff:
        // der Zweitversuch gelingt nur, wenn das gespeicherte Passwort
        // tatsaechlich das Geburtsdatum ist (nicht bei selbst gesetztem PW).
        if ($user && $user->role === 'customer') {
            $canonical = $this->canonicalBirthdatePassword($user, $password);
            if ($canonical !== null && Auth::attempt(['email' => $email, 'password' => $canonical], $remember)) {
                RateLimiter::clear($this->throttleKey());
                return;
            }
        }

        RateLimiter::hit($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.failed'),
        ]);
    }

    /**
     * Liefert das kanonische Startpasswort (TT.MM.JJJJ), wenn das
     * eingegebene Passwort - unabhaengig von der Schreibweise - dem
     * Geburtsdatum des Kunden entspricht; sonst null.
     */
    private function canonicalBirthdatePassword(\App\Models\User $user, string $entered): ?string
    {
        $birthDate = $user->customer?->birth_date;
        if (!$birthDate) {
            return null;
        }
        try {
            $birth = \Carbon\Carbon::parse($birthDate);
        } catch (\Throwable) {
            return null;
        }

        $canonical = $birth->format('d.m.Y');
        $entered = trim($entered);

        // Exakt (nur an einem Leerzeichen gescheitert) - direkt uebernehmen.
        if ($entered === $canonical) {
            return $canonical;
        }

        // Gleichwertige Schreibweisen auf dasselbe Datum abbilden.
        $target = $birth->format('Y-m-d');
        foreach (['d.m.Y', 'j.n.Y', 'd-m-Y', 'j-n-Y', 'd/m/Y', 'j/n/Y', 'd.m.y', 'dmY', 'Y-m-d'] as $format) {
            try {
                $parsed = \Carbon\Carbon::createFromFormat('!' . $format, $entered);
            } catch (\Throwable) {
                continue;
            }
            if ($parsed && $parsed->format('Y-m-d') === $target) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
