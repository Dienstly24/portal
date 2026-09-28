<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Hat ein signierter Zugangslink seinen Zweck schon erfuellt?
 * (System-Audit 28.09.2026, KI-026)
 *
 * Magic-Login (90 Tage) und Einladungslink (14 Tage) prueften nur
 * Signatur und Ablauf. Nichts hielt fest, dass der Berechtigte inzwischen
 * sein EIGENES Passwort gewaehlt hatte - wer eine alte Willkommens- oder
 * Einladungsmail in die Haende bekam (weitergeleitet, geteiltes Postfach,
 * altes Geraet), meldete sich Wochen spaeter damit beim Kunden an bzw.
 * setzte das Passwort eines Mitarbeiters neu.
 *
 * DIE REGEL: ein Link gilt nur, wenn er NACH dem letzten bewusst
 * gesetzten Passwort ausgestellt wurde (`users.password_changed_at` -
 * gesetzt ausschliesslich von `User::setPassword()`, also nie von einem
 * vom System vergebenen Startpasswort).
 *
 * DER AUSSTELLUNGSZEITPUNKT STEHT SCHON IM LINK: `expires` ist Teil der
 * Signatur, die Gueltigkeitsdauer ist fest - Ausstellung = Ablauf minus
 * Dauer. Deshalb braucht es weder eine neue Spalte noch einen neuen
 * Parameter, und bereits verschickte, noch UNBENUTZTE Links
 * funktionieren nach dem Deployment unveraendert weiter.
 */
final class EinmalLink
{
    public static function nochGueltig(Request $request, User $user, int $gueltigTage): bool
    {
        $gewechselt = $user->password_changed_at;
        if ($gewechselt === null) {
            return true;
        }

        $ablauf = (int) $request->query('expires', 0);
        if ($ablauf <= 0) {
            // Ohne Ablauf laesst die Signatur-Middleware keinen Link durch;
            // sollte doch einer ankommen, gilt er nicht.
            return false;
        }

        $ausgestellt = $ablauf - $gueltigTage * 86400;

        // STRENG spaeter: ein Link, der in derselben Sekunde benutzt wurde,
        // in der er ausgestellt wurde, ist verbraucht. Kein Ablauf im
        // System setzt ein Passwort und stellt in derselben Sekunde einen
        // Link aus (Startpasswort und Registrierung setzen
        // `password_changed_at` bewusst nicht).
        return $ausgestellt > $gewechselt->getTimestamp();
    }
}
