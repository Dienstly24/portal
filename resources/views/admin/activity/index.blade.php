@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Aktivität &amp; Arbeitszeiten</span></div>
    <div class="page-title">Aktivität &amp; Arbeitszeiten</div>
    <div class="page-sub">Aktive Arbeitszeit, Leerlauf und Produktivität je Mitarbeiter — nur für die Verwaltung sichtbar.</div>
</div>

@php
    $qs = fn(array $extra) => http_build_query(array_filter($extra));
@endphp

{{-- Zeitraum-Filter --}}
<div class="card" style="margin-bottom:24px;">
    <div style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;">
            <a href="{{ route('admin.activity.index') }}?zeitraum=heute" class="btn btn-sm {{ $preset === 'heute' ? 'btn-primary' : 'btn-ghost' }}">Heute</a>
            <a href="{{ route('admin.activity.index') }}?zeitraum=woche" class="btn btn-sm {{ $preset === 'woche' ? 'btn-primary' : 'btn-ghost' }}">Diese Woche</a>
            <a href="{{ route('admin.activity.index') }}?zeitraum=monat" class="btn btn-sm {{ $preset === 'monat' ? 'btn-primary' : 'btn-ghost' }}">Dieser Monat</a>
        </div>
        <form method="GET" action="{{ route('admin.activity.index') }}" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
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
            <a href="{{ route('admin.activity.export') }}?{{ $qs(['zeitraum' => $preset !== 'eigener' ? $preset : null, 'von' => $preset === 'eigener' ? $from->format('Y-m-d') : null, 'bis' => $preset === 'eigener' ? $to->format('Y-m-d') : null]) }}" class="btn btn-ghost btn-sm">⬇ CSV-Export</a>
            @if(auth()->user()->role === 'admin')
            <a href="{{ route('admin.activity.settings') }}" class="btn btn-ghost btn-sm">⚙ Einstellungen</a>
            @endif
        </div>
    </div>
    <div style="margin-top:10px;font-size:12px;color:var(--ink-soft);">
        Zeitraum: {{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }}
    </div>
</div>

{{-- Team-Kennzahlen --}}
<div class="metrics-grid" style="margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon icon-green">⚡</div>
        <div class="metric-label">Aktive Arbeitszeit (Team)</div>
        <div class="metric-value">{{ \App\Support\Duration::human($totals->active_seconds) }}</div>
        <div class="metric-sub">nur echte Arbeit zählt</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-blue">🕐</div>
        <div class="metric-label">Anmeldezeit (Team)</div>
        <div class="metric-value">{{ \App\Support\Duration::human($totals->login_seconds) }}</div>
        <div class="metric-sub">Leerlauf: {{ \App\Support\Duration::human($totals->idle_seconds) }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">📋</div>
        <div class="metric-label">Produktive Aktionen</div>
        <div class="metric-value">{{ number_format($totals->productive_ops, 0, ',', '.') }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">🏆</div>
        <div class="metric-label">Punkte gesamt</div>
        <div class="metric-value">{{ number_format($totals->points, 0, ',', '.') }}</div>
        @if($rows->isNotEmpty() && $rows->first()->points > 0)
        <div class="metric-sub">Top: {{ $rows->first()->user->name }}</div>
        @endif
    </div>
</div>

{{-- Vergleich --}}
@if($rows->count() > 1)
<div class="card" style="margin-bottom:24px;">
    <div class="card-header"><div class="card-title">📊 Vergleich: Punkte und aktive Stunden</div></div>
    <div style="max-height:320px;"><canvas id="activityCompareChart" height="110"></canvas></div>
</div>
@endif

{{-- Ranking --}}
<div class="card" style="padding:0;overflow:hidden;">
    <div class="card-header" style="padding:16px 20px 0 20px;"><div class="card-title">Mitarbeiter-Ranking nach Produktivität</div></div>
    <div style="overflow-x:auto;">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 20px;">Rang</th>
            <th>Mitarbeiter</th>
            <th style="text-align:right;">Anmeldezeit</th>
            <th style="text-align:right;">Aktive Zeit</th>
            <th style="text-align:right;">Leerlauf</th>
            <th style="text-align:right;">Aktionen</th>
            <th style="text-align:right;">Angelegt</th>
            <th style="text-align:right;">Bearbeitet</th>
            <th style="text-align:right;">Uploads</th>
            <th style="text-align:right;">Punkte</th>
            <th style="text-align:right;">Punkte/Std.</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse($rows as $row)
        <tr @if($row->rank === 1 && $row->points > 0) style="background:#F6FBF4;" @endif>
            <td style="padding:13px 20px;font-weight:700;">{{ $row->rank === 1 && $row->points > 0 ? '🏆' : $row->rank }}</td>
            <td>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="width:28px;height:28px;border-radius:50%;background:var(--petrol);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex:none;">
                        {{ strtoupper(substr($row->user->name, 0, 2)) }}
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:600;">{{ $row->user->name }}</div>
                        <div style="font-size:11px;color:var(--ink-soft);">{{ ucfirst($row->user->role) }}</div>
                    </div>
                </div>
            </td>
            <td style="text-align:right;">{{ \App\Support\Duration::human($row->login_seconds) }}</td>
            <td style="text-align:right;font-weight:600;color:#17A65B;">{{ \App\Support\Duration::human($row->active_seconds) }}</td>
            <td style="text-align:right;color:var(--ink-soft);">{{ \App\Support\Duration::human($row->idle_seconds) }}</td>
            <td style="text-align:right;">{{ number_format($row->productive_ops, 0, ',', '.') }}</td>
            <td style="text-align:right;">{{ number_format($row->creates, 0, ',', '.') }}</td>
            <td style="text-align:right;">{{ number_format($row->updates, 0, ',', '.') }}</td>
            <td style="text-align:right;">{{ number_format($row->uploads, 0, ',', '.') }}</td>
            <td style="text-align:right;font-weight:700;">{{ number_format($row->points, 0, ',', '.') }}</td>
            <td style="text-align:right;">{{ str_replace('.', ',', (string) $row->points_per_hour) }}</td>
            <td style="text-align:right;padding-right:20px;">
                <a href="{{ route('admin.activity.show', $row->user->id) }}?{{ $qs(['zeitraum' => $preset !== 'eigener' ? $preset : null, 'von' => $preset === 'eigener' ? $from->format('Y-m-d') : null, 'bis' => $preset === 'eigener' ? $to->format('Y-m-d') : null]) }}" class="btn btn-ghost btn-sm">Details</a>
            </td>
        </tr>
        @empty
        <tr><td colspan="12" style="text-align:center;padding:32px;color:var(--ink-soft);">Keine Mitarbeiter gefunden.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

@if($rows->count() > 1)
<script>
(function () {
    var el = document.getElementById('activityCompareChart');
    if (!el || typeof Chart === 'undefined') return;
    new Chart(el, {
        type: 'bar',
        data: {
            labels: @json($chart['labels']),
            datasets: [
                {
                    label: 'Punkte',
                    data: @json($chart['points']),
                    backgroundColor: '#17A65B',
                    borderRadius: 6,
                    yAxisID: 'y'
                },
                {
                    label: 'Aktive Stunden',
                    data: @json($chart['active_hours']),
                    backgroundColor: '#185FA5',
                    borderRadius: 6,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Punkte' }, grid: { color: 'rgba(0,0,0,.05)' } },
                y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Stunden' }, grid: { drawOnChartArea: false } }
            },
            plugins: { legend: { labels: { color: '#6B7280' } } }
        }
    });
})();
</script>
@endif
@endsection
