<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Activity\ActivityCatalog;
use App\Services\Activity\ActivityReportService;
use App\Support\Duration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use League\Csv\EscapeFormula;
use League\Csv\Writer;

/**
 * Aktivitaets- und Arbeitszeitberichte fuer die Verwaltung.
 * Sichtbar NUR fuer admin/manager (Routen entsprechend beschraenkt);
 * Mitarbeiter sehen weder Berichte noch Berechnungsparameter.
 */
class ActivityReportController extends Controller
{
    public function __construct(
        protected ActivityReportService $reports,
        protected ActivityCatalog $catalog,
    ) {
    }

    /** Uebersicht: Ranking + Vergleich aller Mitarbeiter im Zeitraum. */
    public function index(Request $request)
    {
        [$from, $to, $preset] = $this->resolvePeriod($request);

        $rows = $this->reports->overview($from, $to);

        $totals = (object) [
            'login_seconds' => $rows->sum('login_seconds'),
            'active_seconds' => $rows->sum('active_seconds'),
            'idle_seconds' => $rows->sum('idle_seconds'),
            'points' => $rows->sum('points'),
            'productive_ops' => $rows->sum('productive_ops'),
        ];

        // Vergleichs-Chart: Punkte und aktive Stunden je Mitarbeiter.
        $chart = [
            'labels' => $rows->pluck('user.name')->values(),
            'points' => $rows->pluck('points')->values(),
            'active_hours' => $rows->map(fn($r) => round($r->active_seconds / 3600, 2))->values(),
        ];

        return view('admin.activity.index', [
            'rows' => $rows,
            'totals' => $totals,
            'chart' => $chart,
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
        ]);
    }

    /** Detailansicht eines Mitarbeiters: Tage, Sitzungen, Verlauf. */
    public function show(Request $request, int $id)
    {
        $employee = User::whereIn('role', config('activity.staff_roles', []))->findOrFail($id);
        [$from, $to, $preset] = $this->resolvePeriod($request);

        $overview = $this->reports->overview($from, $to)->firstWhere('user.id', $employee->id);
        $days = $this->reports->dailyRows($employee, $from, $to);
        $sessions = $this->reports->sessionsFor($employee, $from, $to)->take(30);
        $actions = $this->reports->actionBreakdown($employee, $from, $to);

        $timeline = ActivityLog::where('user_id', $employee->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.activity.show', [
            'employee' => $employee,
            'stats' => $overview,
            'days' => $days,
            'sessions' => $sessions,
            'actions' => $actions,
            'timeline' => $timeline,
            'catalog' => $this->catalog,
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
        ]);
    }

    /** CSV-Export der Uebersicht (deutsches Excel: Semikolon). */
    public function export(Request $request)
    {
        [$from, $to] = $this->resolvePeriod($request);
        $rows = $this->reports->overview($from, $to);

        $csv = Writer::createFromString();
        $csv->setDelimiter(';');
        // Schutz vor Formel-Injektion in Excel/LibreOffice (Namen sind Nutzereingaben)
        $csv->addFormatter(new EscapeFormula());
        $csv->insertOne([
            'Mitarbeiter', 'Rolle', 'Anmeldezeit (hh:mm)', 'Aktive Zeit (hh:mm)',
            'Leerlauf (hh:mm)', 'Aktionen', 'Angelegt', 'Bearbeitet', 'Uploads',
            'Punkte', 'Punkte je aktive Stunde',
        ]);
        foreach ($rows as $row) {
            $csv->insertOne([
                $row->user->name,
                $row->user->role,
                Duration::hhmm($row->login_seconds),
                Duration::hhmm($row->active_seconds),
                Duration::hhmm($row->idle_seconds),
                $row->productive_ops,
                $row->creates,
                $row->updates,
                $row->uploads,
                $row->points,
                str_replace('.', ',', (string) $row->points_per_hour),
            ]);
        }

        $filename = 'aktivitaet_' . $from->format('Y-m-d') . '_' . $to->format('Y-m-d') . '.csv';

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** CSV-Export der Tagesuebersicht eines einzelnen Mitarbeiters. */
    public function exportEmployee(Request $request, int $id)
    {
        $employee = User::whereIn('role', config('activity.staff_roles', []))->findOrFail($id);
        [$from, $to] = $this->resolvePeriod($request);
        $days = $this->reports->dailyRows($employee, $from, $to);

        $csv = Writer::createFromString();
        $csv->setDelimiter(';');
        $csv->addFormatter(new EscapeFormula());
        $csv->insertOne(['Mitarbeiter', $employee->name, 'Zeitraum', $from->format('d.m.Y') . ' - ' . $to->format('d.m.Y')]);
        $csv->insertOne([
            'Datum', 'Anmeldezeit (hh:mm)', 'Aktive Zeit (hh:mm)', 'Leerlauf (hh:mm)',
            'Produktive Aktionen', 'Ereignisse gesamt', 'Punkte',
        ]);
        foreach ($days as $day) {
            $csv->insertOne([
                $day->day,
                Duration::hhmm($day->login_seconds),
                Duration::hhmm($day->active_seconds),
                Duration::hhmm($day->idle_seconds),
                $day->productive_ops,
                $day->total_events,
                $day->points,
            ]);
        }

        $filename = 'aktivitaet_mitarbeiter_' . $employee->id . '_' . $from->format('Y-m-d') . '_' . $to->format('Y-m-d') . '.csv';

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** Einstellungen: Schwellwerte + Punkte-Gewichte (nur admin). */
    public function settings()
    {
        // Nur produktive Aktionen sind bepunktbar; nach Kategorie gruppiert.
        $actions = collect($this->catalog->actions())
            ->filter(fn($def) => !empty($def['productive']))
            ->map(fn($def, $key) => (object) [
                'key' => $key,
                'label' => $def['label'],
                'category' => $def['category'],
                'default' => (int) $def['points'],
                'current' => $this->catalog->pointsFor($key),
            ])
            ->groupBy('category');

        return view('admin.activity.settings', [
            'actions' => $actions,
            'idleThreshold' => (int) SystemSetting::get('activity_idle_threshold_minutes', config('activity.idle_threshold_minutes', 5)),
            'sessionTimeout' => (int) SystemSetting::get('activity_session_timeout_minutes', config('activity.session_timeout_minutes', 30)),
        ]);
    }

    public function settingsUpdate(Request $request)
    {
        $data = $request->validate([
            'idle_threshold' => 'required|integer|min:1|max:240',
            'session_timeout' => 'required|integer|min:5|max:480',
            'points' => 'array',
            'points.*' => 'nullable|integer|min:0|max:100',
        ]);

        SystemSetting::set('activity_idle_threshold_minutes', (string) $data['idle_threshold']);
        SystemSetting::set('activity_session_timeout_minutes', (string) $data['session_timeout']);

        // Nur bekannte, produktive Aktionen uebernehmen (kein Freitext).
        $known = collect($this->catalog->actions())
            ->filter(fn($def) => !empty($def['productive']))
            ->keys()
            ->all();
        $points = collect($data['points'] ?? [])
            ->only($known)
            ->filter(fn($v) => $v !== null)
            ->map(fn($v) => (int) $v)
            ->all();
        SystemSetting::set('activity_points', json_encode($points));

        return back()->with('success', 'Einstellungen zur Aktivitaetserfassung gespeichert.');
    }

    /**
     * Zeitraum aus dem Request: Schnellauswahl (heute/woche/monat) oder
     * eigener Von/Bis-Bereich. Ungueltige Eingaben fallen auf heute zurueck.
     */
    protected function resolvePeriod(Request $request): array
    {
        $now = now();

        if ($request->filled('von') || $request->filled('bis')) {
            try {
                $from = Carbon::parse($request->query('von', $now->toDateString()))->startOfDay();
                $to = Carbon::parse($request->query('bis', $now->toDateString()))->endOfDay();
                if ($from->lessThanOrEqualTo($to)) {
                    return [$from, $to, 'eigener'];
                }
            } catch (\Throwable) {
                // faellt unten auf "heute" zurueck
            }
        }

        return match ($request->query('zeitraum')) {
            'woche' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'woche'],
            'monat' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'monat'],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'heute'],
        };
    }
}
