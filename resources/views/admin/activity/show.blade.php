@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.activity.index') }}">Aktivität &amp; Arbeitszeiten</a><span class="breadcrumb-sep">›</span><span>{{ $employee->name }}</span></div>
    <div class="page-title">{{ $employee->name }}</div>
    <div class="page-sub">{{ ucfirst($employee->role) }} · Aktivitätsbericht {{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }}</div>
</div>

@php
    $qs = fn(array $extra) => http_build_query(array_filter($extra));
    $periodQuery = $qs([
        'zeitraum' => $preset !== 'eigener' ? $preset : null,
        'von' => $preset === 'eigener' ? $from->format('Y-m-d') : null,
        'bis' => $preset === 'eigener' ? $to->format('Y-m-d') : null,
    ]);
    $endedLabels = ['logout' => 'Abmeldung', 'timeout' => 'Timeout', 'new_login' => 'Neue Anmeldung', 'duplicate' => 'Doppelte Sitzung'];
@endphp

{{-- Zeitraum-Filter --}}
<div class="card" style="margin-bottom:24px;">
    <div style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;">
            <a href="{{ route('admin.activity.show', $employee->id) }}?zeitraum=heute" class="btn btn-sm {{ $preset === 'heute' ? 'btn-primary' : 'btn-ghost' }}">Heute</a>
            <a href="{{ route('admin.activity.show', $employee->id) }}?zeitraum=woche" class="btn btn-sm {{ $preset === 'woche' ? 'btn-primary' : 'btn-ghost' }}">Diese Woche</a>
            <a href="{{ route('admin.activity.show', $employee->id) }}?zeitraum=monat" class="btn btn-sm {{ $preset === 'monat' ? 'btn-primary' : 'btn-ghost' }}">Dieser Monat</a>
        </div>
        <form method="GET" action="{{ route('admin.activity.show', $employee->id) }}" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
            <div>
                <label style="display:block;font-size:12px;color:var(--ink-soft);font-weight:600;margin-bottom:6px;">Von Datum</label>
                <input type="date" name="von" value="{{ $from->format('Y-m-d') }}" style="padding:9px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            </div>
            <div>
                <label style="display:block;font-size:12px;color:var(--ink-soft);font-weight:600;margin-bottom:6px;">Bis Datum</label>
                <input type="date" name="bis" value="{{ $to->format('Y-m-d') }}" style="padding:9px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Anwenden</button>
        </form>
        <div style="margin-left:auto;display:flex;gap:8px;">
            <a href="{{ route('admin.activity.user_export', $employee->id) }}?{{ $periodQuery }}" class="btn btn-ghost btn-sm">⬇ CSV-Export</a>
            <a href="{{ route('admin.activity.index') }}?{{ $periodQuery }}" class="btn btn-ghost btn-sm">← Zur Übersicht</a>
        </div>
    </div>
</div>

{{-- Kennzahlen --}}
<div class="metrics-grid" style="margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon icon-green">⚡</div>
        <div class="metric-label">Aktive Arbeitszeit</div>
        <div class="metric-value">{{ \App\Support\Duration::human($stats->active_seconds ?? 0) }}</div>
        <div class="metric-sub">{{ number_format($stats->productive_ops ?? 0, 0, ',', '.') }} produktive Aktionen</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-blue">🕐</div>
        <div class="metric-label">Anmeldezeit</div>
        <div class="metric-value">{{ \App\Support\Duration::human($stats->login_seconds ?? 0) }}</div>
        <div class="metric-sub">Leerlauf: {{ \App\Support\Duration::human($stats->idle_seconds ?? 0) }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">📋</div>
        <div class="metric-label">Angelegt / Bearbeitet / Uploads</div>
        <div class="metric-value">{{ $stats->creates ?? 0 }} / {{ $stats->updates ?? 0 }} / {{ $stats->uploads ?? 0 }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">🏆</div>
        <div class="metric-label">Punkte</div>
        <div class="metric-value">{{ number_format($stats->points ?? 0, 0, ',', '.') }}</div>
        <div class="metric-sub">{{ str_replace('.', ',', (string) ($stats->points_per_hour ?? 0)) }} Punkte je aktive Stunde</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
    {{-- Tagesübersicht --}}
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="card-header" style="padding:16px 20px 0 20px;"><div class="card-title">📅 Tagesübersicht</div></div>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr style="background:#F8F9FA;">
                <th style="padding:12px 20px;">Datum</th>
                <th style="text-align:right;">Anmeldezeit</th>
                <th style="text-align:right;">Aktiv</th>
                <th style="text-align:right;">Leerlauf</th>
                <th style="text-align:right;">Aktionen</th>
                <th style="text-align:right;">Punkte</th>
            </tr></thead>
            <tbody>
            @forelse($days as $day)
            <tr>
                <td style="padding:13px 20px;font-weight:600;">{{ \Carbon\Carbon::parse($day->day)->format('d.m.Y') }}</td>
                <td style="text-align:right;">{{ \App\Support\Duration::human($day->login_seconds) }}</td>
                <td style="text-align:right;font-weight:600;color:#17A65B;">{{ \App\Support\Duration::human($day->active_seconds) }}</td>
                <td style="text-align:right;color:var(--ink-soft);">{{ \App\Support\Duration::human($day->idle_seconds) }}</td>
                <td style="text-align:right;">{{ $day->productive_ops }}</td>
                <td style="text-align:right;font-weight:700;">{{ $day->points }}</td>
            </tr>
            @empty
            <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Daten im Zeitraum.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- Aktionen nach Typ --}}
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="card-header" style="padding:16px 20px 0 20px;"><div class="card-title">🧾 Aktionen nach Typ</div></div>
        <div style="overflow-x:auto;max-height:420px;overflow-y:auto;">
        <table>
            <thead><tr style="background:#F8F9FA;">
                <th style="padding:12px 20px;">Aktion</th>
                <th style="text-align:right;">Anzahl</th>
                <th style="text-align:right;">Punkte</th>
            </tr></thead>
            <tbody>
            @forelse($actions as $a)
            <tr>
                <td style="padding:11px 20px;">{{ $a->label }}</td>
                <td style="text-align:right;">{{ number_format($a->count, 0, ',', '.') }}</td>
                <td style="text-align:right;font-weight:600;">{{ number_format($a->points, 0, ',', '.') }}</td>
            </tr>
            @empty
            <tr><td colspan="3" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Aktionen im Zeitraum.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>

{{-- Sitzungen --}}
<div class="card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div class="card-header" style="padding:16px 20px 0 20px;"><div class="card-title">🖥 Arbeitssitzungen</div></div>
    <div style="overflow-x:auto;">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 20px;">Anmeldung</th>
            <th>Abmeldung</th>
            <th>Beendet durch</th>
            <th style="text-align:right;">Dauer</th>
            <th style="text-align:right;">Aktiv</th>
            <th>IP</th>
            <th>Gerät</th>
        </tr></thead>
        <tbody>
        @forelse($sessions as $s)
        <tr>
            <td style="padding:13px 20px;white-space:nowrap;">{{ $s->login_at->format('d.m.Y H:i') }}</td>
            <td style="white-space:nowrap;">{{ $s->logout_at?->format('d.m.Y H:i') ?? '— aktiv —' }}</td>
            <td>
                @if($s->ended_by)
                <span class="badge badge-closed">{{ $endedLabels[$s->ended_by] ?? $s->ended_by }}</span>
                @else
                <span class="badge badge-active">Laufend</span>
                @endif
            </td>
            <td style="text-align:right;">{{ \App\Support\Duration::human($s->durationSeconds()) }}</td>
            <td style="text-align:right;font-weight:600;color:#17A65B;">{{ \App\Support\Duration::human((int) $s->active_seconds) }}</td>
            <td style="font-size:12px;color:var(--ink-soft);">{{ $s->ip }}</td>
            <td style="font-size:12px;color:var(--ink-soft);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $s->user_agent }}">{{ $s->user_agent }}</td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Sitzungen im Zeitraum.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

{{-- Vollständiger Verlauf --}}
<div class="card" style="padding:0;overflow:hidden;">
    <div class="card-header" style="padding:16px 20px 0 20px;"><div class="card-title">🕘 Vollständiger Aktivitätsverlauf</div></div>
    <div style="overflow-x:auto;">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 20px;">Zeitpunkt</th>
            <th>Aktion</th>
            <th>Seite</th>
            <th>Datensatz</th>
            <th style="text-align:right;">Punkte</th>
            <th style="text-align:right;">Aktivzeit</th>
            <th>IP</th>
        </tr></thead>
        <tbody>
        @forelse($timeline as $log)
        <tr>
            <td style="padding:11px 20px;font-size:13px;color:var(--ink-soft);white-space:nowrap;">{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
            <td>
                <span class="badge {{ $log->is_productive ? 'badge-active' : 'badge-closed' }}">{{ $catalog->labelFor($log->action) }}</span>
                @if(($log->metaArray()['failed'] ?? false))
                <span class="badge badge-rejected" title="Anfrage fehlgeschlagen (z. B. Validierung)">fehlgeschlagen</span>
                @endif
            </td>
            <td style="font-size:12px;color:var(--ink-soft);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $log->url_path }}">{{ $log->route ?? $log->url_path }}</td>
            <td style="font-size:12px;color:var(--ink-soft);">
                @if($log->entity_id){{ $log->entity_type }} #{{ $log->entity_id }}@endif
            </td>
            <td style="text-align:right;font-weight:600;">{{ $log->points > 0 ? $log->points : '' }}</td>
            <td style="text-align:right;font-size:12px;color:var(--ink-soft);">{{ $log->active_seconds > 0 ? gmdate($log->active_seconds >= 3600 ? 'G:i:s' : 'i:s', $log->active_seconds) : '' }}</td>
            <td style="font-size:12px;color:var(--ink-soft);">{{ $log->ip }}</td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Aktivitäten im Zeitraum.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
<div style="margin-top:16px;">{{ $timeline->links() }}</div>
@endsection
