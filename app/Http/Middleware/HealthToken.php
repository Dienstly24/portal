<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zugang fuer eine externe Ueberwachung (Audit 15.09.2026).
 *
 * WARUM (gemessener Befund): `/admin/systemzustand.json` war fuer genau
 * diesen Zweck gebaut ("dieselbe Ampel fuer externe Ueberwachung, HTTP
 * 503 wenn etwas handlungsbeduerftig ist") - lag aber hinter `auth`,
 * `role:admin,manager`, Passwortzwang und Zweitem Faktor. Ein Aufruf
 * ohne Anmeldung bekam eine 302 auf die Anmeldeseite. KEIN
 * Ueberwachungsdienst der Welt kann das auswerten; die Ampel konnte
 * also nur ein Mensch sehen, der zufaellig hinschaut - genau das
 * Gegenteil dessen, wofuer die Seite gebaut wurde.
 *
 * Statt den Admin-Bereich zu oeffnen, gibt es EINEN eigenen Endpunkt
 * mit EINEM Zweck und EINEM Geheimnis aus der Server-.env.
 *
 * DREI REGELN:
 *  1. Ohne gesetzten `HEALTH_TOKEN` ist der Endpunkt AUS (404, nicht
 *     403) - eine unkonfigurierte Installation oeffnet nichts, und ein
 *     404 verraet nicht einmal, dass es ihn gibt.
 *  2. Verglichen wird mit `hash_equals`: ein gewoehnlicher Vergleich
 *     verraet ueber die Laufzeit, wie viele Zeichen gestimmt haben.
 *  3. Das Token darf im Header ODER als Query stehen. Der Header ist
 *     der bessere Weg (Query-Strings landen in Zugriffsprotokollen),
 *     aber viele Ueberwachungsdienste koennen nur eine URL abfragen -
 *     ein Schutz, den der Betreiber nicht einrichten kann, ist keiner.
 */
class HealthToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $erwartet = (string) config('security.health_token', '');

        if ($erwartet === '') {
            abort(404);
        }

        $gesendet = (string) ($request->header('X-Health-Token')
            ?: $request->bearerToken()
            ?: $request->query('token', ''));

        if ($gesendet === '' || ! hash_equals($erwartet, $gesendet)) {
            abort(404);
        }

        return $next($request);
    }
}
