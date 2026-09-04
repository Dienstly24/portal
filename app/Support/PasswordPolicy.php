<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rules\Password;

/**
 * EINE Quelle fuer die Passwort-Anforderungen des gesamten Systems
 * (Betreiber-Vorgabe 18.08.2026 "Sicherheit sehr hoch").
 *
 * Bisher stand in jedem Pfad eine eigene Regel: Registrierung nutzte
 * Password::defaults() (= Laravel-Default min 8), das Kundenportal
 * 'min:8', der Admin-CLI-Befehl 'min:8', der Mitarbeiter-Anlage-Dialog
 * 'min:8'. Wer die Anforderung anheben wollte, musste vier Stellen
 * finden - und uebersah dabei zuverlaessig eine. Ab jetzt fragen ALLE
 * Pfade hier.
 *
 * Fachliche Wahl (BSI TR-02102-1 / NIST SP 800-63B): LAENGE schlaegt
 * Zeichenklassen-Zwang. Erzwungene Sonderzeichen produzieren
 * "Passwort1!" - kurz, vorhersagbar und in jeder Breach-Liste. Deshalb:
 *
 * - Kunden  : mindestens 12 Zeichen
 * - Personal: mindestens 14 Zeichen (admin/manager/support/employee und
 *             partner - diese Konten sehen fremde personenbezogene Daten)
 * - IMMER   : Abgleich gegen bekannte Datenlecks (HaveIBeenPwned, k-Anonymity;
 *             es wird nur ein 5-Zeichen-Hash-Praefix uebertragen, nie das
 *             Passwort). Das faengt "Passwort1234" und "Sommer2026!" ab,
 *             was keine Zeichenklassen-Regel je koennte.
 *
 * Der Leck-Abgleich ist in der Testumgebung AUS (sonst haengt jeder
 * Testlauf an einem externen Dienst und wird flaky) und laesst sich per
 * PASSWORD_BREACH_CHECK=false abschalten, falls der Server die API nicht
 * erreicht. Laravel faellt bei Netzfehlern ohnehin auf "bestanden"
 * zurueck - der Abgleich kann einen Login also nie blockieren.
 */
class PasswordPolicy
{
    /** Mindestlaenge fuer Kundenkonten. */
    public const MIN_CUSTOMER = 12;

    /** Mindestlaenge fuer Konten mit Zugriff auf fremde Daten. */
    public const MIN_STAFF = 14;

    /** Rollen, fuer die die strengere Laenge gilt. */
    private const PRIVILEGED_ROLES = ['admin', 'manager', 'support', 'employee', 'partner'];

    /** Regel fuer einen konkreten Nutzer (Rolle entscheidet ueber die Laenge). */
    public static function for(?User $user): Password
    {
        return self::rule(self::minimumFor($user?->role));
    }

    /** Regel fuer eine Rolle, wenn noch kein Nutzer existiert (Neuanlage). */
    public static function forRole(?string $role): Password
    {
        return self::rule(self::minimumFor($role));
    }

    /** Regel fuer Kundenkonten (Portal, Selbst-Registrierung). */
    public static function customer(): Password
    {
        return self::rule(self::MIN_CUSTOMER);
    }

    /** Regel fuer Personal-/Partnerkonten. */
    public static function staff(): Password
    {
        return self::rule(self::MIN_STAFF);
    }

    /** Mindestlaenge fuer eine Rolle (unbekannt/null = Kundenmass). */
    public static function minimumFor(?string $role): int
    {
        return in_array($role, self::PRIVILEGED_ROLES, true)
            ? self::MIN_STAFF
            : self::MIN_CUSTOMER;
    }

    /**
     * Menschlicher Hinweistext fuer Formulare - damit im Formular exakt
     * das steht, was die Validierung auch prueft (bisher versprach das
     * Portal "mindestens 8 Zeichen", waehrend andere Pfade mehr wollten).
     */
    public static function hint(?string $role = null): string
    {
        return __('Mindestens :min Zeichen. Bitte kein Passwort verwenden, das Sie bereits woanders nutzen.', [
            'min' => self::minimumFor($role),
        ]);
    }

    /** Baut die eigentliche Regel inkl. optionalem Leck-Abgleich. */
    private static function rule(int $min): Password
    {
        $rule = Password::min($min);

        return self::breachCheckEnabled() ? $rule->uncompromised() : $rule;
    }

    /** Leck-Abgleich aktiv? (in Tests immer aus - kein externer Aufruf) */
    private static function breachCheckEnabled(): bool
    {
        if (app()->environment('testing')) {
            return false;
        }

        return (bool) config('auth.password_breach_check', true);
    }
}
