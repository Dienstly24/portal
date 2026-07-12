@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.banners') }}">Banner</a><span class="breadcrumb-sep">›</span><span>Statistik</span></div>
    <div class="page-title">Banner-Statistik</div>
    <div class="page-sub">Ausspielungen, Klicks und Klickrate der letzten 30 Tage.</div>
</div>

{{-- Kennzahlen --}}
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-icon icon-blue">👁</div>
        <div class="metric-label">Impressions gesamt</div>
        <div class="metric-value">{{ number_format($totalImpressions, 0, ',', '.') }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">🖱</div>
        <div class="metric-label">Klicks gesamt</div>
        <div class="metric-value">{{ number_format($totalClicks, 0, ',', '.') }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">📈</div>
        <div class="metric-label">Ø Klickrate (CTR)</div>
        <div class="metric-value">{{ number_format($avgCtr, 1, ',', '.') }} %</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">🏆</div>
        <div class="metric-label">Bester Banner (CTR)</div>
        <div class="metric-value" style="font-size:17px;line-height:1.3;padding-top:6px;">{{ $best?->title ?? '—' }}</div>
        @if($best)<div class="metric-sub">{{ number_format($best->ctr(), 1, ',', '.') }} % CTR · {{ number_format($best->total_impressions, 0, ',', '.') }} Impressions</div>@endif
    </div>
</div>

{{-- 30-Tage-Verlauf: zwei getrennte Diagramme (unterschiedliche Größenordnung,
     deshalb bewusst KEINE gemeinsame/doppelte Achse) --}}
<div class="grid-2">
    <div class="card">
        <div class="card-title">Impressions – letzte 30 Tage</div>
        <canvas id="impressionsChart" height="190"></canvas>
    </div>
    <div class="card">
        <div class="card-title">Klicks – letzte 30 Tage</div>
        <canvas id="clicksChart" height="190"></canvas>
    </div>
</div>

{{-- Banner-Vergleich --}}
<div class="card">
    <div class="card-header">
        <div class="card-title">Banner im Vergleich</div>
        <a href="{{ route('admin.banners') }}" class="card-link">Zur Verwaltung →</a>
    </div>
    <table>
        <thead><tr><th>Banner</th><th>Status</th><th style="text-align:right;">Impressions</th><th style="text-align:right;">Kunden</th><th style="text-align:right;">Klicks</th><th style="text-align:right;">CTR</th><th>Zuletzt gezeigt</th></tr></thead>
        <tbody>
        @forelse($banners as $b)
        @php $st = $b->statusInfo(); $isBest = $best && $best->id === $b->id; @endphp
        <tr @if($isBest) style="background:#F6FBF4;" @endif>
            <td style="font-weight:600;">{{ $isBest ? '🏆 ' : '' }}{{ $b->title }}</td>
            <td><span style="background:{{ $st['bg'] }};color:{{ $st['color'] }};border-radius:12px;padding:2px 10px;font-size:11.5px;font-weight:600;">{{ $st['label'] }}</span></td>
            <td style="text-align:right;">{{ number_format($b->total_impressions, 0, ',', '.') }}</td>
            <td style="text-align:right;">{{ $b->uniqueViewers() }}</td>
            <td style="text-align:right;">{{ number_format($b->total_clicks, 0, ',', '.') }}</td>
            <td style="text-align:right;font-weight:600;">{{ number_format($b->ctr(), 1, ',', '.') }} %</td>
            <td style="color:var(--ink-soft);font-size:12.5px;">{{ $b->last_shown_at?->format('d.m.Y H:i') ?? '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;color:var(--ink-soft);padding:22px;">Noch keine Banner vorhanden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<script>
(function () {
    const labels = @json($labels);
    const grid = { color: 'rgba(0,0,0,.05)' };
    const ticks = { color: '#6B7280', font: { size: 11 } };

    function lineChart(id, data, hex) {
        new Chart(document.getElementById(id), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    borderColor: hex,
                    backgroundColor: hex + '22',
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: hex,
                    fill: true,
                    tension: 0.25,
                }],
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: grid, ticks: Object.assign({ precision: 0 }, ticks) },
                    x: { grid: { display: false }, ticks: Object.assign({ maxTicksLimit: 10 }, ticks) },
                },
            },
        });
    }

    lineChart('impressionsChart', @json($impressions), '#185FA5');
    lineChart('clicksChart', @json($clicks), '#17A65B');
})();
</script>
@endsection
