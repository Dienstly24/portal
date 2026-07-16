<?php
namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Auswertungen der Aktivitaetserfassung fuer die Verwaltung:
 * Anmeldezeit, aktive Arbeitszeit, Leerlauf, Aktionszahlen und
 * Produktivitaetspunkte je Mitarbeiter und Zeitraum.
 */
class ActivityReportService
{
    public function __construct(protected ActivityCatalog $catalog)
    {
    }

    /** Kennzahlen je Mitarbeiter im Zeitraum, sortiert nach Punkten. */
    public function overview(Carbon $from, Carbon $to): Collection
    {
        $staff = User::whereIn('role', config('activity.staff_roles', []))
            ->orderBy('name')
            ->get();
        $staffIds = $staff->pluck('id');

        $loginSeconds = $this->loginSecondsByUser($staffIds, $from, $to);

        $logStats = ActivityLog::whereIn('user_id', $staffIds)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('user_id,
                COALESCE(SUM(active_seconds), 0) as active_seconds,
                COALESCE(SUM(points), 0) as points,
                SUM(CASE WHEN is_productive = 1 THEN 1 ELSE 0 END) as productive_ops')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $categoryCounts = $this->categoryCountsByUser($staffIds, $from, $to);

        $rows = $staff->map(function (User $user) use ($loginSeconds, $logStats, $categoryCounts) {
            $login = (int) ($loginSeconds[$user->id] ?? 0);
            $stats = $logStats->get($user->id);
            $active = min($login, (int) ($stats->active_seconds ?? 0));
            $cats = $categoryCounts[$user->id] ?? [];

            $points = (int) ($stats->points ?? 0);
            $activeHours = $active / 3600;

            return (object) [
                'user' => $user,
                'login_seconds' => $login,
                'active_seconds' => $active,
                'idle_seconds' => max(0, $login - $active),
                'points' => $points,
                'productive_ops' => (int) ($stats->productive_ops ?? 0),
                'creates' => $cats['create'] ?? 0,
                'updates' => $cats['update'] ?? 0,
                'uploads' => $cats['upload'] ?? 0,
                'points_per_hour' => $activeHours > 0 ? round($points / $activeHours, 1) : 0.0,
            ];
        });

        // Ranking nach Punkten; bei Gleichstand entscheidet aktive Zeit.
        $rows = $rows->sortByDesc(fn($r) => [$r->points, $r->active_seconds])->values();
        $rows->each(function ($row, $i) {
            $row->rank = $i + 1;
        });

        return $rows;
    }

    /** Tagesaufschluesselung fuer einen Mitarbeiter (neueste zuerst). */
    public function dailyRows(User $user, Carbon $from, Carbon $to): Collection
    {
        $logDays = ActivityLog::where('user_id', $user->id)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as day,
                COALESCE(SUM(active_seconds), 0) as active_seconds,
                COALESCE(SUM(points), 0) as points,
                SUM(CASE WHEN is_productive = 1 THEN 1 ELSE 0 END) as productive_ops,
                COUNT(*) as total_events')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $loginByDay = $this->loginSecondsByDay($user, $from, $to);

        $days = collect(array_keys($loginByDay))
            ->merge($logDays->keys())
            ->unique()
            ->sortDesc()
            ->values();

        return $days->map(function (string $day) use ($logDays, $loginByDay) {
            $log = $logDays->get($day);
            $login = (int) ($loginByDay[$day] ?? 0);
            $active = min($login > 0 ? $login : PHP_INT_MAX, (int) ($log->active_seconds ?? 0));
            return (object) [
                'day' => $day,
                'login_seconds' => $login,
                'active_seconds' => $active,
                'idle_seconds' => max(0, $login - $active),
                'points' => (int) ($log->points ?? 0),
                'productive_ops' => (int) ($log->productive_ops ?? 0),
                'total_events' => (int) ($log->total_events ?? 0),
            ];
        });
    }

    /** Sitzungen eines Mitarbeiters im Zeitraum (neueste zuerst). */
    public function sessionsFor(User $user, Carbon $from, Carbon $to): Collection
    {
        return WorkSession::where('user_id', $user->id)
            ->where('login_at', '<=', $to)
            ->where(fn($q) => $q->whereNull('logout_at')->orWhere('logout_at', '>=', $from))
            ->orderByDesc('login_at')
            ->get();
    }

    /** Aktionshaeufigkeit je Aktion fuer einen Mitarbeiter. */
    public function actionBreakdown(User $user, Carbon $from, Carbon $to): Collection
    {
        return ActivityLog::where('user_id', $user->id)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('action, COUNT(*) as c, COALESCE(SUM(points), 0) as points')
            ->groupBy('action')
            ->orderByDesc('c')
            ->get()
            ->map(fn($row) => (object) [
                'action' => $row->action,
                'label' => $this->catalog->labelFor($row->action),
                'category' => $this->catalog->categoryFor($row->action),
                'count' => (int) $row->c,
                'points' => (int) $row->points,
            ]);
    }

    /** Aktionszahlen je Nutzer, nach Katalog-Kategorie gebuendelt. */
    protected function categoryCountsByUser(Collection $userIds, Carbon $from, Carbon $to): array
    {
        $rows = ActivityLog::whereIn('user_id', $userIds)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('user_id, action, COUNT(*) as c')
            ->groupBy('user_id', 'action')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $category = $this->catalog->categoryFor($row->action);
            $result[$row->user_id][$category] = ($result[$row->user_id][$category] ?? 0) + (int) $row->c;
        }
        return $result;
    }

    /** Anmeldezeit (Sekunden) je Nutzer, auf den Zeitraum zugeschnitten. */
    protected function loginSecondsByUser(Collection $userIds, Carbon $from, Carbon $to): array
    {
        $sessions = WorkSession::whereIn('user_id', $userIds)
            ->where('login_at', '<=', $to)
            ->where(fn($q) => $q->whereNull('logout_at')->orWhere('logout_at', '>=', $from))
            ->get();

        $result = [];
        foreach ($sessions as $session) {
            $result[$session->user_id] = ($result[$session->user_id] ?? 0)
                + $this->overlapSeconds($session, $from, $to);
        }
        return $result;
    }

    /** Anmeldezeit je Kalendertag (Sitzungen werden am Tageswechsel geteilt). */
    protected function loginSecondsByDay(User $user, Carbon $from, Carbon $to): array
    {
        $sessions = $this->sessionsFor($user, $from, $to);

        $result = [];
        foreach ($sessions as $session) {
            $start = $session->login_at->greaterThan($from) ? $session->login_at->copy() : $from->copy();
            $end = $session->effectiveEnd();
            if ($end->greaterThan($to)) {
                $end = $to->copy();
            }
            $cursor = $start;
            while ($cursor->lessThan($end)) {
                $chunkEnd = $cursor->copy()->startOfDay()->addDay();
                if ($chunkEnd->greaterThan($end)) {
                    $chunkEnd = $end->copy();
                }
                $day = $cursor->toDateString();
                $result[$day] = ($result[$day] ?? 0) + max(0, (int) $cursor->diffInSeconds($chunkEnd));
                $cursor = $chunkEnd;
            }
        }
        return $result;
    }

    /** Ueberlappung einer Sitzung mit dem Zeitraum in Sekunden. */
    protected function overlapSeconds(WorkSession $session, Carbon $from, Carbon $to): int
    {
        $start = $session->login_at->greaterThan($from) ? $session->login_at : $from;
        $end = $session->effectiveEnd();
        if ($end->greaterThan($to)) {
            $end = $to;
        }
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }
        return (int) $start->diffInSeconds($end);
    }
}
