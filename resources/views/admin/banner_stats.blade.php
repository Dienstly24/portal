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
        <thead><tr><th>Banner</th><th>Status</th><th class="num">Impressions</th><th class="num">Kunden</th><th class="num">Klicks</th><th class="num">CTR</th><th>Zuletzt gezeigt</th></tr></thead>
        <tbody>
        @forelse($banners as $b)
        @php $st = $b->statusInfo(); $isBest = $best && $best->id === $b->id; @endphp
        <tr @if($isBest) style="background:#F6FBF4;" @endif>
            <td style="font-weight:600;">{{ $isBest ? '🏆 ' : '' }}{{ $b->title }}</td>
            <td><span style="background:{{ $st['bg'] }};color:{{ $st['color'] }};border-radius:12px;padding:2px 10px;font-size:11.5px;font-weight:600;">{{ $st['label'] }}</span></td>
            <td class="num">{{ number_format($b->total_impressions, 0, ',', '.') }}</td>
            <td class="num">{{ $b->uniqueViewers() }}</td>
            <td class="num">{{ number_format($b->total_clicks, 0, ',', '.') }}</td>
            <td style="text-align:right;font-weight:600;">{{ number_format($b->ctr(), 1, ',', '.') }} %</td>
            <td style="color:var(--ink-soft);font-size:12.5px;">{{ $b->last_shown_at?->lokal()->format('d.m.Y H:i') ?? '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;color:var(--ink-soft);padding:22px;">Noch keine Banner vorhanden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Meta-Seiten-Ueberblick (Cache aus social:refresh-insights) --}}
@if(!empty($metaPage))
<div class="card">
    <div class="card-header">
        <div class="card-title">📘 Meta-Seite: {{ $metaPage['name'] ?? '' }}</div>
        <span class="muted-xs">Stand: {{ \Illuminate\Support\Carbon::parse($metaPage['refreshed_at'])->lokal()->format('d.m.Y H:i') }}</span>
    </div>
    <div style="display:flex;gap:26px;flex-wrap:wrap;font-size:14px;">
        <span>👥 <strong>{{ number_format($metaPage['followers'] ?? 0, 0, ',', '.') }}</strong> Follower</span>
        <span>👍 <strong>{{ number_format($metaPage['fans'] ?? 0, 0, ',', '.') }}</strong> „Gefällt mir"</span>
        <span>👁 <strong>{{ number_format($metaPage['page_views_28d'] ?? 0, 0, ',', '.') }}</strong> Seitenaufrufe (28 Tage)</span>
        <a href="{{ route('admin.werbung') }}" style="font-size:13px;">🎯 Zu den Werbeanzeigen →</a>
    </div>
</div>
@endif

{{-- Social-Media: Klicks ueber die Tracking-Kurzlinks (getrennt von den
     Portal-Klicks - andere Zielgruppe, deshalb bewusst keine gemeinsame CTR) --}}
@if($socialPosts->isNotEmpty())
<div class="card">
    <div class="card-title">📣 Social-Media – Klicks über Tracking-Links</div>
    <div class="scroll-x">
    <table>
        <thead><tr><th>Banner</th><th>Plattform</th><th class="num">Klicks</th><th>Letzter Klick</th><th>Veröffentlicht</th></tr></thead>
        <tbody>
        @foreach($socialPosts as $sp)
            @foreach($sp->channels->sortBy('platform') as $ch)
            @php $info = $ch->platformInfo(); @endphp
            <tr>
                <td style="font-weight:600;">
                    @if($loop->first)<a href="{{ route('admin.banners.social', $sp->banner_id) }}">{{ $sp->banner->title }}</a>@endif
                </td>
                <td class="nowrap">{{ $info['icon'] }} {{ $info['label'] }}</td>
                <td style="text-align:right;font-weight:600;">{{ number_format($ch->clicks, 0, ',', '.') }}</td>
                <td style="color:var(--ink-soft);font-size:12.5px;">{{ $ch->last_click_at?->lokal()->format('d.m.Y H:i') ?? '—' }}</td>
                <td style="font-size:12.5px;">
                    @if($ch->published_at)<span style="color:#3B7A57;font-weight:600;">✓ {{ $ch->published_at->lokal()->format('d.m.Y') }}</span>{{ $ch->publisher ? ' von ' . $ch->publisher->name : '' }}
                    @else<span class="muted">noch nicht</span>@endif
                </td>
            </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endif

<script @cspNonce>
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
    lineChart('clicksChart', @json($clicks), brandColor('emerald'));
})();
</script>
@endsection
