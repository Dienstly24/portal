<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Ein Ort fuer alles rund um die Zwei-Faktor-Anmeldung: Einrichten,
 * Bestaetigen, Pruefen, Ersatzcodes, Abschalten.
 *
 * Wichtige Entscheidungen:
 *
 * - Der Sitzungsschluessel traegt die BENUTZER-ID (`2fa_ok:<id>`). Ein
 *   einfaches Flag wuerde nach einem Kontowechsel in derselben Sitzung
 *   weitergelten - der zweite Faktor waere fuer das neue Konto geschenkt.
 *
 * - Ersatzcodes liegen GEHASHT (wie Passwoerter). Sie sind vollwertige
 *   Zugangsmittel; im Klartext waeren sie ein zweites Passwort in der
 *   Datenbank. Angezeigt werden sie genau einmal, beim Erzeugen.
 *
 * - Ein benutzter Ersatzcode wird sofort verbraucht. Sonst waere ein
 *   abgefischter Code dauerhaft gueltig.
 *
 * - Protokolliert wird JEDE Aenderung am zweiten Faktor (eingerichtet,
 *   abgeschaltet, zurueckgesetzt, Ersatzcode benutzt) - das sind genau
 *   die Ereignisse, die man nach einem Vorfall rekonstruieren will.
 *   Der Code selbst steht nie im Protokoll.
 */
class TwoFactorService
{
    /** Anzahl der Ersatzcodes bei jeder Erzeugung. */
    public const RECOVERY_CODE_COUNT = 8;

    /** Sitzungsschluessel fuer "zweiter Faktor in dieser Sitzung erbracht". */
    public static function sessionKey(User $user): string
    {
        return '2fa_ok:' . $user->id;
    }

    /**
     * Einrichtung beginnen: Geheimnis erzeugen und vormerken - aber noch
     * NICHT bestaetigen. Solange two_factor_confirmed_at leer ist, wird
     * beim Anmelden auch kein Code verlangt; sonst sperrt sich aus, wer
     * die Seite nur geoeffnet hat.
     */
    public function beginSetup(User $user): string
    {
        // Ein bereits bestaetigtes Geheimnis wird nie stillschweigend
        // ersetzt - sonst macht ein versehentlicher Seitenaufruf die
        // funktionierende App des Mitarbeiters ungueltig.
        if ($user->hasTwoFactor()) {
            return $user->two_factor_secret;
        }

        if ($user->two_factor_secret === null) {
            $user->forceFill(['two_factor_secret' => Totp::generateSecret()])->save();
        }

        return $user->two_factor_secret;
    }

    /**
     * Einrichtung abschliessen: der eingegebene Code muss zum Geheimnis
     * passen. Erst dann gilt der zweite Faktor als eingerichtet.
     *
     * @return array<int, string>|null Ersatzcodes im Klartext (einmalig
     *         anzuzeigen) oder null, wenn der Code falsch war.
     */
    public function confirmSetup(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null || ! Totp::verify($user->two_factor_secret, $code)) {
            return null;
        }

        $plainCodes = $this->generateRecoveryCodes($user);

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->log($user, 'two_factor_enabled');

        return $plainCodes;
    }

    /** Code oder Ersatzcode pruefen. Ein Ersatzcode wird dabei verbraucht. */
    public function verify(User $user, string $code): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }

        if (Totp::verify($user->two_factor_secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /** Neue Ersatzcodes erzeugen (alte werden ungueltig). */
    public function generateRecoveryCodes(User $user): array
    {
        $plain = [];
        $hashed = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            // Gut lesbar/vorlesbar: zwei Bloecke, keine mehrdeutigen Zeichen.
            $code = strtoupper(Str::random(5) . '-' . Str::random(5));
            $code = str_replace(['0', 'O', '1', 'I', 'L'], ['2', '3', '4', '5', '6'], $code);
            $plain[] = $code;
            $hashed[] = Hash::make($code);
        }

        $user->forceFill(['two_factor_recovery_codes' => $hashed])->save();

        return $plain;
    }

    /** Zweiten Faktor abschalten bzw. zuruecksetzen (Admin/CLI). */
    public function disable(User $user, ?int $actorId = null, string $action = 'two_factor_disabled'): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->log($user, $action, $actorId);
    }

    /** Sitzung als "zweiter Faktor erbracht" markieren. */
    public function markVerified(Request $request, User $user): void
    {
        $request->session()->put(self::sessionKey($user), true);
    }

    /** Wurde der zweite Faktor in DIESER Sitzung erbracht? */
    public function isVerified(Request $request, User $user): bool
    {
        return (bool) $request->session()->get(self::sessionKey($user), false);
    }

    /** Wie viele Ersatzcodes sind noch unbenutzt? */
    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /** Ersatzcode pruefen und - bei Treffer - sofort entwerten. */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $candidate = strtoupper(trim($code));

        foreach ($codes as $index => $hash) {
            if (Hash::check($candidate, $hash)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                $this->log($user, 'two_factor_recovery_code_used');

                return true;
            }
        }

        return false;
    }

    private function log(User $user, string $action, ?int $actorId = null): void
    {
        ActivityLog::create([
            'user_id' => $actorId ?? $user->id,
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
            'meta' => json_encode(['konto' => $user->email], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
