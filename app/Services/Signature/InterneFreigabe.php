<?php

namespace App\Services\Signature;

use App\Models\ActivityLog;
use App\Models\SignatureInternalSigning;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Darf dieser Mitarbeiter JETZT intern unterschreiben, ohne erneut nach dem
 * zweiten Faktor gefragt zu werden? (Betreiber-Vorgabe 07.10.2026)
 *
 * REGEL: Zwei-Faktor ist PFLICHT, ein Passwort allein genuegt nie. Eine
 * Zwei-Faktor-Bestaetigung - beim Anmelden oder unmittelbar vor dem
 * Unterschreiben - gilt ein ZEITFENSTER lang (Einstellung
 * `interne_signatur_2fa_stunden`, Voreinstellung 4), und zwar nur fuer
 * DIESE Sitzung auf DIESEM Geraet. Das Fenster erlischt
 *  - beim Abmelden (die Sitzung wird verworfen),
 *  - bei einem Wechsel des Geraets (User-Agent) oder der IP-Adresse,
 *  - bei einer Passwortaenderung (der Fingerabdruck des Passwort-Hashes
 *    stimmt dann nicht mehr).
 *
 * Unabhaengig davon steht vor JEDER internen Unterschrift der
 * Bestaetigungssatz "Ich unterschreibe dieses Dokument als ..." - das
 * Fenster spart nur den Code, nie die Bestaetigung.
 *
 * Fehlversuche laufen ueber DENSELBEN Limiter-Schluessel wie die Abfrage
 * beim Anmelden (`2fa:<id>|<ip>`, KI-042): ein weiterer Weg, der einen Code
 * annimmt, ist sonst ein weiteres Kontingent zum Raten.
 */
class InterneFreigabe
{
    public const STANDARD_STUNDEN = 4;

    private const MAX_FEHLVERSUCHE = 5;

    private const SPERRE_SEKUNDEN = 300;

    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    public static function sessionKey(User $user): string
    {
        return 'sig_freigabe:'.$user->id;
    }

    public static function stunden(): int
    {
        return max(1, min(12, (int) (SystemSetting::get('interne_signatur_2fa_stunden') ?: self::STANDARD_STUNDEN)));
    }

    /** Haelt eine bestandene Zwei-Faktor-Pruefung fest (Anmeldung oder Abfrage). */
    public function vermerke(Request $request, User $user, string $methode): void
    {
        if (! $request->hasSession()) {
            return;
        }
        $request->session()->put(self::sessionKey($user), [
            'at' => now()->getTimestamp(),
            'ip' => (string) $request->ip(),
            'ua' => hash('sha256', (string) $request->userAgent()),
            'pw' => $this->passwortAbdruck($user),
            'methode' => $methode,
        ]);
    }

    /**
     * Die gueltige Freigabe oder null.
     *
     * @return array{at: int, methode: string}|null
     */
    public function gueltig(Request $request, User $user): ?array
    {
        if (! $user->hasTwoFactor() || ! $request->hasSession()) {
            return null;
        }
        $f = $request->session()->get(self::sessionKey($user));
        if (! is_array($f) || ! isset($f['at'], $f['ip'], $f['ua'], $f['pw'], $f['methode'])) {
            return null;
        }
        $abgelaufen = (int) $f['at'] + self::stunden() * 3600 <= now()->getTimestamp();
        $anderesGeraet = ! hash_equals((string) $f['ua'], hash('sha256', (string) $request->userAgent()));
        $andereIp = (string) $f['ip'] !== (string) $request->ip();
        $anderesPasswort = ! hash_equals((string) $f['pw'], $this->passwortAbdruck($user));
        if ($abgelaufen || $anderesGeraet || $andereIp || $anderesPasswort) {
            $request->session()->forget(self::sessionKey($user));

            return null;
        }

        return ['at' => (int) $f['at'], 'methode' => (string) $f['methode']];
    }

    /**
     * Fragt den Code ab. Bestanden -> neues Zeitfenster (Methode
     * "abgefragt") und gilt zugleich als neue Anmeldebestaetigung.
     *
     * @return string|null Fehlermeldung oder null bei Erfolg
     */
    public function pruefeCode(Request $request, User $user, string $code): ?string
    {
        if (! $user->hasTwoFactor()) {
            return 'Für Ihr Konto ist die Zwei-Faktor-Anmeldung noch nicht eingerichtet. Bitte zuerst unter „Sicherheit" einrichten.';
        }
        $key = '2fa:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_FEHLVERSUCHE)) {
            return 'Zu viele Fehlversuche. Bitte warten Sie '.RateLimiter::availableIn($key).' Sekunden.';
        }
        if (! $this->twoFactor->verify($user, trim($code))) {
            RateLimiter::hit($key, self::SPERRE_SEKUNDEN);
            ActivityLog::record('two_factor_failed', 'user', (string) $user->id, ['ip' => $request->ip(), 'weg' => 'interne_signatur']);

            return 'Der Code stimmt nicht. Bitte erneut versuchen.';
        }
        RateLimiter::clear($key);
        $this->twoFactor->markVerified($request, $user);
        $this->vermerke($request, $user, SignatureInternalSigning::REAUTH_ABGEFRAGT);

        return null;
    }

    private function passwortAbdruck(User $user): string
    {
        return hash('sha256', 'sig-freigabe|'.(string) $user->getAuthPassword());
    }
}
