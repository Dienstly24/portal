<?php
namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serverseitige Aktivitaetserfassung fuer Mitarbeiter.
 *
 * Grundsaetze:
 * - Nur Staff-Rollen werden erfasst (Kunden/Partner nie).
 * - Aktive Arbeitszeit entsteht AUSSCHLIESSLICH durch produktive
 *   Aktionen (Anlegen/Bearbeiten/Hochladen/...). Blosse Praesenz,
 *   Seitenwechsel oder fehlgeschlagene Requests zaehlen nicht.
 * - Pro produktiver Aktion wird die Luecke seit der letzten produktiven
 *   Aktion gutgeschrieben, gedeckelt auf den Idle-Schwellwert. Kehrt der
 *   Mitarbeiter nach laengerer Pause zurueck, laeuft die Zaehlung ab der
 *   naechsten produktiven Aktion einfach weiter.
 * - Alles laeuft serverseitig; Mitarbeiter koennen die Erfassung weder
 *   sehen noch beeinflussen. Log-Eintraege sind nirgends editierbar.
 */
class ActivityTracker
{
    public function __construct(protected ActivityCatalog $catalog)
    {
    }

    /** Loest der Auth-Login aus: neue Arbeitssitzung beginnen. */
    public function handleLogin(?User $user, Request $request): void
    {
        if (!$user || !$this->isTracked($user)) {
            return;
        }
        $now = now();

        // Liegengebliebene offene Sitzungen sauber beenden.
        WorkSession::open()->where('user_id', $user->id)->get()->each(function (WorkSession $s) {
            $s->update([
                'logout_at' => $s->last_seen_at ?? $s->login_at,
                'ended_by' => 'new_login',
            ]);
        });

        $session = WorkSession::create([
            'user_id' => $user->id,
            'login_at' => $now,
            'last_seen_at' => $now,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        $this->writeLog($user, $session, 'login', $request, false, 0, 0);
    }

    /** Loest der Auth-Logout aus: Sitzung beenden. */
    public function handleLogout(?User $user, Request $request): void
    {
        if (!$user || !$this->isTracked($user)) {
            return;
        }
        $now = now();
        $session = WorkSession::open()->where('user_id', $user->id)->latest('login_at')->first();
        if ($session) {
            $session->update([
                'last_seen_at' => $now,
                'logout_at' => $now,
                'ended_by' => 'logout',
            ]);
        }
        $this->writeLog($user, $session, 'logout', $request, false, 0, 0);
    }

    /** Wird von der Middleware fuer jeden Staff-Request aufgerufen. */
    public function trackRequest(Request $request, Response $response): void
    {
        $user = $request->user();
        if (!$user instanceof User || !$this->isTracked($user)) {
            return;
        }

        $routeName = $request->route()?->getName();
        if ($routeName !== null && $this->catalog->isIgnored($routeName)) {
            return; // Hintergrund-Polling: komplett unsichtbar.
        }

        $now = now();
        $session = $this->currentSession($user, $request, $now);

        $unlogged = ($routeName !== null && $this->catalog->isUnlogged($routeName))
            || ($routeName === null && $this->catalog->isUnloggedPath($request->path()));
        if ($unlogged) {
            $session->update(['last_seen_at' => $now]);
            return;
        }

        $method = strtoupper($request->method());
        if ($method === 'HEAD' || $method === 'OPTIONS') {
            $session->update(['last_seen_at' => $now]);
            return;
        }

        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $action = $isWrite
            ? $this->catalog->actionForWrite($routeName, count($request->allFiles()) > 0)
            : $this->catalog->actionForGet($routeName);

        $status = $response->getStatusCode();
        $failed = $status >= 400 || ($isWrite && $this->validationFailed($request));

        $productive = !$failed && $this->catalog->isProductive($action);
        $points = $productive ? $this->catalog->pointsFor($action) : 0;

        // Aktivzeit-Gutschrift: Luecke seit letzter produktiver Aktion
        // (bzw. Login), gedeckelt auf den Idle-Schwellwert. Exactly-once:
        // nur der Request, der last_productive_at tatsaechlich vom alten
        // Wert fortschreibt, kreditiert die Luecke - ein paralleler
        // Verlierer kreditiert 0 (seine Luecke ist praktisch 0). Das
        // verhindert Doppel-Gutschriften und Lost Updates.
        $credit = 0;
        if ($productive) {
            $base = $session->last_productive_at ?? $session->login_at;
            $gap = max(0, (int) $base->diffInSeconds($now));
            $credit = min($gap, $this->catalog->idleThresholdSeconds());

            $claim = WorkSession::where('id', $session->id);
            if ($session->last_productive_at === null) {
                $claim->whereNull('last_productive_at');
            } else {
                $claim->where('last_productive_at', $session->last_productive_at);
            }
            if ($claim->update(['last_productive_at' => $now]) === 1) {
                WorkSession::where('id', $session->id)->increment('active_seconds', $credit);
            } else {
                $credit = 0;
            }
        }

        WorkSession::where('id', $session->id)->update(['last_seen_at' => $now]);

        // Den Navigations-Log-INSERT aus dem kritischen Request-Pfad nehmen
        // (Audit PERF-1): er wird nach dem Senden der Antwort geschrieben
        // (terminating). Login/Logout-Logs bleiben synchron. In Tests laeuft
        // terminate() innerhalb des Request-Zyklus, die Assertions greifen also
        // weiterhin.
        app()->terminating(function () use ($user, $session, $action, $request, $productive, $points, $credit, $status, $failed) {
            $this->writeLog($user, $session, $action, $request, $productive, $points, $credit, [
                'status' => $status,
                'failed' => $failed,
            ]);
        });
    }

    /**
     * Offene Sitzung des Nutzers holen. War sie zu lange still, wird sie
     * per Timeout beendet und eine neue begonnen (die stille Zeit zaehlt
     * dadurch nicht als Anmeldezeit). Fehlt eine Sitzung (z. B. Login vor
     * Einfuehrung des Trackings, Remember-Me), wird implizit eine eroeffnet.
     */
    protected function currentSession(User $user, Request $request, Carbon $now): WorkSession
    {
        $open = WorkSession::open()->where('user_id', $user->id)
            ->orderByDesc('login_at')->orderByDesc('id')->get();
        $session = $open->first();

        // Selbstheilung: parallele Requests koennen im seltenen Rennen
        // kurzzeitig doppelte offene Sitzungen erzeugen - alle ausser der
        // neuesten schliessen, damit Anmeldezeit nicht doppelt zaehlt.
        $open->slice(1)->each(function (WorkSession $dup) {
            $dup->update([
                'logout_at' => $dup->last_seen_at ?? $dup->login_at,
                'ended_by' => 'duplicate',
            ]);
        });

        if ($session) {
            $lastSeen = $session->last_seen_at ?? $session->login_at;
            if ($lastSeen->diffInSeconds($now) > $this->catalog->sessionTimeoutSeconds()) {
                $session->update(['logout_at' => $lastSeen, 'ended_by' => 'timeout']);
                $session = null;
            }
        }

        return $session ?? WorkSession::create([
            'user_id' => $user->id,
            'login_at' => $now,
            'last_seen_at' => $now,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);
    }

    /** Verwaiste offene Sitzungen schliessen (Scheduler). */
    public function closeStaleSessions(): int
    {
        $cutoff = now()->subSeconds($this->catalog->sessionTimeoutSeconds());
        $stale = WorkSession::open()
            ->where(function ($q) use ($cutoff) {
                $q->where('last_seen_at', '<', $cutoff)
                  ->orWhere(fn($q2) => $q2->whereNull('last_seen_at')->where('login_at', '<', $cutoff));
            })
            ->get();

        foreach ($stale as $session) {
            $session->update([
                'logout_at' => $session->last_seen_at ?? $session->login_at,
                'ended_by' => 'timeout',
            ]);
        }

        return $stale->count();
    }

    protected function writeLog(
        User $user,
        ?WorkSession $session,
        string $action,
        Request $request,
        bool $productive,
        int $points,
        int $credit,
        array $extraMeta = []
    ): void {
        [$entityType, $entityId, $params] = $this->extractEntity($request);

        ActivityLog::create([
            'user_id' => $user->id,
            'work_session_id' => $session?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'meta' => array_filter([
                'params' => $params ?: null,
            ] + $extraMeta, fn($v) => $v !== null),
            'route' => $request->route()?->getName(),
            'url_path' => Str::limit('/' . ltrim($request->path(), '/'), 490, ''),
            'method' => strtoupper($request->method()),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'is_productive' => $productive,
            'points' => $points,
            'active_seconds' => $credit,
        ]);
    }

    /**
     * Verknuepften Datensatz aus den Routen-Parametern ableiten
     * (z. B. /customers/{id} -> entity_type=id-Parametername, entity_id=42).
     */
    protected function extractEntity(Request $request): array
    {
        $route = $request->route();
        if (!$route) {
            return [null, null, []];
        }

        $params = [];
        foreach ($route->parameters() as $name => $value) {
            if ($value instanceof Model) {
                $params[$name] = $value->getKey();
            } elseif (is_scalar($value)) {
                $params[$name] = $value;
            }
        }

        if ($params === []) {
            return [null, null, []];
        }

        $firstKey = array_key_first($params);
        return [$firstKey, (string) $params[$firstKey], $params];
    }

    /** Wurden in DIESEM Request Validierungsfehler geflasht? */
    protected function validationFailed(Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }
        $newFlash = $request->session()->get('_flash.new', []);
        return is_array($newFlash) && in_array('errors', $newFlash, true);
    }

    protected function isTracked(User $user): bool
    {
        return in_array($user->role, config('activity.staff_roles', []), true);
    }
}
