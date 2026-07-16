<?php

namespace App\Http\Middleware;

use App\Services\Activity\ActivityTracker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Erfasst jeden Request eingeloggter Mitarbeiter serverseitig
 * (Aktivitaetslog, Arbeitssitzung, Aktivzeit). Laeuft NACH dem
 * Request-Handling, damit der Response-Status (Erfolg/Fehler) in die
 * Bewertung einfliesst. Fehler in der Erfassung duerfen die Anwendung
 * niemals beeintraechtigen.
 */
class TrackStaffActivity
{
    public function __construct(protected ActivityTracker $tracker)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->tracker->trackRequest($request, $response);
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }
}
