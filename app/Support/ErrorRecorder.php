<?php

namespace App\Support;

use App\Models\ErrorEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

/**
 * Schreibt unerwartete Fehler in die Tabelle error_events, damit sie auf
 * /admin/systemzustand und /admin/fehler sichtbar werden.
 *
 * Warum: ein 500er trifft heute einen echten Nutzer und landet in
 * storage/logs/laravel.log - einer Datei, die im Alltag niemand oeffnet.
 * Der Betreiber erfaehrt davon nur, wenn sich jemand beschwert. Die
 * Logdatei bleibt die ausfuehrliche Quelle (Stacktrace); diese Tabelle ist
 * das SIGNAL "es ist etwas kaputt".
 *
 * Drei Regeln:
 *
 *  1. NUR DEFEKTE. Ein 404, ein 403, eine fehlgeschlagene Validierung oder
 *     ein abgelaufenes CSRF-Token sind normales Nutzerverhalten, kein
 *     Fehler im System. Wuerden sie mitgezaehlt, ginge der eine echte
 *     Defekt zwischen tausend Rauscheintraegen unter - und die Anzeige
 *     waere wertlos.
 *  2. KEINE PERSONENBEZOGENEN INHALTE. Gespeichert werden Klasse, Meldung
 *     (gekuerzt), Datei/Zeile, Route und Methode. NIE der Request-Inhalt
 *     (Formularfelder, Query, Header, Cookies) und NIE die IP.
 *  3. DAS AUFZEICHNEN DARF NIE SELBST FEHLSCHLAGEN. Jeder Fehler hier wird
 *     geschluckt - sonst wuerde aus einem Fehler ein zweiter, und die
 *     eigentliche Fehlerseite kaeme nie beim Nutzer an.
 */
class ErrorRecorder
{
    /**
     * Ausnahmen, die normales Nutzerverhalten beschreiben und deshalb NICHT
     * als Defekt gezaehlt werden.
     */
    private const IGNORED = [
        AuthenticationException::class,
        AuthorizationException::class,
        ValidationException::class,
        TokenMismatchException::class,
        ModelNotFoundException::class,
        ThrottleRequestsException::class,
        NotFoundHttpException::class,
        MethodNotAllowedHttpException::class,
        RouteNotFoundException::class,
    ];

    public static function record(Throwable $e): void
    {
        try {
            if (! self::shouldRecord($e)) {
                return;
            }

            // Waehrend der Migrationen (oder vor der ersten Migration) gibt
            // es die Tabelle noch nicht - dann still nichts tun.
            if (! Schema::hasTable('error_events')) {
                return;
            }

            $fingerprint = sha1($e::class.'|'.$e->getFile().'|'.$e->getLine());

            $eintrag = ErrorEvent::firstOrNew(['fingerprint' => $fingerprint]);
            $vorhanden = $eintrag->exists;

            $eintrag->fill([
                'exception_class' => $e::class,
                // Gekuerzt: eine Meldung kann Datenbank-Ausschnitte enthalten.
                'message' => mb_substr($e->getMessage(), 0, 500),
                'file' => mb_substr((string) $e->getFile(), 0, 500),
                'line' => $e->getLine(),
                'route' => self::currentRoute(),
                'method' => request()?->method(),
                'status_code' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
                'last_seen_at' => now(),
                'last_user_id' => auth()->id(),
            ]);

            if (! $vorhanden) {
                $eintrag->first_seen_at = now();
                $eintrag->occurrences = 1;
            } else {
                $eintrag->occurrences = (int) $eintrag->occurrences + 1;
                // Ein erneutes Auftreten oeffnet einen als erledigt
                // markierten Fehler wieder - "behoben" ist er erst, wenn er
                // ausbleibt.
                $eintrag->resolved_at = null;
                $eintrag->resolved_by = null;
            }

            $eintrag->save();
        } catch (Throwable $ignored) {
            // Bewusst still: aus einem Fehler darf nie ein zweiter werden.
        }
    }

    /** Ist das ein Defekt - oder nur normales Nutzerverhalten? */
    public static function shouldRecord(Throwable $e): bool
    {
        foreach (self::IGNORED as $klasse) {
            if ($e instanceof $klasse) {
                return false;
            }
        }

        // Alles unter 500 ist eine Antwort an den Nutzer (nicht gefunden,
        // nicht erlaubt, zu viele Anfragen), kein Systemfehler.
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return false;
        }

        return true;
    }

    /** Routenname bzw. Pfad - nie mit Query-String (dort stehen Nutzerdaten). */
    private static function currentRoute(): ?string
    {
        $request = request();
        if (! $request) {
            return null;
        }

        $name = $request->route()?->getName();
        if (is_string($name) && $name !== '') {
            return mb_substr($name, 0, 191);
        }

        return mb_substr('/'.ltrim($request->path(), '/'), 0, 191);
    }
}
