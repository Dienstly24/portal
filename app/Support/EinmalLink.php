<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Gilt ein signierter Zugangslink noch? (System-Audit 28.09.2026,
 * KI-026 / KI-041 / KI-043)
 *
 * Magic-Login (90 Tage) und Einladungslink (14 Tage) prueften nur
 * Signatur und Ablauf. Wer eine alte Willkommens- oder Einladungsmail in
 * die Haende bekam (weitergeleitet, geteiltes Postfach, altes Geraet,
 * Tippfehler in der Adresse), meldete sich Wochen spaeter damit beim
 * Kunden an bzw. setzte das Passwort eines Mitarbeiters neu.
 *
 * ZWEI REGELN, beide muessen erfuellt sein:
 *
 * 1. WIDERRUF (KI-043): jeder Link traegt den Stand
 *    `users.zugangslink_version` als signierten Parameter `v`. Er gilt nur,
 *    solange dieser Stand am Konto unveraendert ist. Hochgezaehlt wird
 *    ausschliesslich ueber `User::zugangslinksWiderrufen()` - bei neuer
 *    Login-Adresse, bei einem von der Verwaltung gesetzten Passwort, beim
 *    Portal-Reset und bei jeder neu verschickten Einladung. Danach gilt
 *    nur noch der NEUESTE Link. Links von vor dem Deployment tragen kein
 *    `v` und zaehlen als 0 - sie gelten, bis an ihrem Konto zum ersten Mal
 *    widerrufen wird.
 *
 * 2. EIGENES PASSWORT (KI-026): ein Link gilt nur, wenn er NACH dem
 *    letzten selbst gewaehlten Passwort ausgestellt wurde
 *    (`users.password_changed_at`, gesetzt von `User::setPassword()`).
 *    Der Ausstellungszeitpunkt steht schon im Link: `expires` ist Teil der
 *    Signatur, die Gueltigkeitsdauer ist fest - Ausstellung = Ablauf minus
 *    Dauer.
 *
 * Beide Werte (`v`, `expires`) sind durch die Signatur geschuetzt; wer
 * sie aendert, macht den Link ungueltig, bevor er hier ankommt.
 */
final class EinmalLink
{
    /**
     * Parameter fuer einen NEUEN Link. Die EINE Stelle, an der ein
     * Zugangslink seine Kennung und seinen Stand bekommt - der Stand kommt
     * aus der Datenbank, nie aus einem womoeglich veralteten Modell.
     *
     * @return array{user: int|string, v: int}
     */
    public static function parameter(User $user): array
    {
        return ['user' => $user->getKey(), 'v' => $user->aktuelleZugangslinkVersion()];
    }

    public static function nochGueltig(Request $request, User $user, int $gueltigTage): bool
    {
        $stand = $request->query('v', '0');
        if (! is_string($stand) || ! ctype_digit($stand) || (int) $stand !== (int) $user->zugangslink_version) {
            return false;
        }

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
