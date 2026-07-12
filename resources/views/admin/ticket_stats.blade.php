@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.tickets') }}">Tickets</a><span class="breadcrumb-sep">›</span><span>Statistik</span></div>
    <div class="page-title">Ticket-Statistik</div>
    <div class="page-sub">Anfragen, Erledigungsquote und Team-Leistung seit {{ $from->format('d.m.Y') }}.</div>
</div>

{{-- Zeitraum-Auswahl --}}
<div class="tab-row">
    @foreach(['heute' => 'Heute', '7' => '7 Tage', '30' => '30 Tage', '90' => '90 Tage', 'jahr' => 'Dieses Jahr'] as $key => $label)
    <a href="{{ route('admin.tickets.stats', ['zeitraum' => $key]) }}" class="tab {{ $zeitraum === $key ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
</div>

{{-- Kennzahlen: Eingang & Bestand der im Zeitraum erstellten Tickets --}}
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-icon icon-blue">📥</div>
        <div class="metric-label">Neue Tickets</div>
        <div class="metric-value">{{ $kpis['neu'] }}</div>
        <div class="metric-sub">von {{ $kpis['kunden'] }} {{ $kpis['kunden'] === 1 ? 'Kunde' : 'Kunden' }}</div>
    </div>
    <a href="{{ route('admin.tickets', ['status' => 'open']) }}" class="metric-card metric-card-link">
        <div class="metric-icon icon-blue">🎫</div>
        <div class="metric-label">Noch offen</div>
        <div class="metric-value">{{ $kpis['offen'] }}</div>
        <div class="metric-sub">aus diesem Zeitraum · ansehen →</div>
    </a>
    <a href="{{ route('admin.tickets', ['status' => 'aktiv']) }}" class="metric-card metric-card-link">
        <div class="metric-icon icon-amber">⚙️</div>
        <div class="metric-label">In Arbeit / wartend</div>
        <div class="metric-value">{{ $kpis['in_arbeit'] }}</div>
        <div class="metric-sub">aus diesem Zeitraum · ansehen →</div>
    </a>
    <div class="metric-card">
        <div class="metric-icon icon-green">✅</div>
        <div class="metric-label">Erledigt</div>
        <div class="metric-value">{{ $kpis['erledigt'] }}</div>
        <div class="metric-sub">{{ $kpis['neu'] > 0 ? number_format($kpis['erledigt'] / $kpis['neu'] * 100, 0) . ' % der neuen Tickets' : '—' }}</div>
    </div>
</div>
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-icon icon-blue">⚡</div>
        <div class="metric-label">Ø Zeit bis erste Antwort</div>
        <div class="metric-value" style="font-size:24px;">{{ $kpis['avg_first_response_h'] !== null ? number_format($kpis['avg_first_response_h'], 1, ',', '.') . ' h' : '—' }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">🏁</div>
        <div class="metric-label">Ø Zeit bis Lösung</div>
        <div class="metric-value" style="font-size:24px;">{{ $kpis['avg_resolution_h'] !== null ? number_format($kpis['avg_resolution_h'], 1, ',', '.') . ' h' : '—' }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">⭐</div>
        <div class="metric-label">Ø Kundenbewertung</div>
        <div class="metric-value" style="font-size:24px;">{{ $kpis['avg_rating'] !== null ? number_format($kpis['avg_rating'], 1, ',', '.') . ' / 5' : '—' }}</div>
        <div class="metric-sub">{{ $kpis['rating_count'] }} {{ $kpis['rating_count'] === 1 ? 'Bewertung' : 'Bewertungen' }}</div>
    </div>
    <a href="{{ route('admin.tickets', ['overdue' => 1]) }}" class="metric-card metric-card-link">
        <div class="metric-icon icon-red">⏰</div>
        <div class="metric-label">Aktuell überfällig</div>
        <div class="metric-value" style="font-size:24px;">{{ $kpis['overdue_now'] }}</div>
        <div class="metric-sub">gesamter Bestand · ansehen →</div>
    </a>
</div>

{{-- Tagesverlauf --}}
<div class="card">
    <div class="card-title">Tickets pro Tag – erstellt vs. erledigt</div>
    <canvas id="dailyChart" height="90"></canvas>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-top:10px;">
        Gesamt im Zeitraum: <strong>{{ array_sum($createdPerDay) }}</strong> erstellt · <strong>{{ array_sum($finishedPerDay) }}</strong> erledigt
    </div>
</div>

{{-- Verteilungen (Kohorte des Zeitraums) --}}
<div class="grid-3">
    <div class="card">
        <div class="card-title">Nach Status</div>
        <canvas id="statusChart" height="210"></canvas>
    </div>
    <div class="card">
        <div class="card-title">Nach Priorität</div>
        <canvas id="prioChart" height="210"></canvas>
    </div>
    <div class="card">
        <div class="card-title">Nach Typ</div>
        <canvas id="typeChart" height="210"></canvas>
    </div>
</div>

<div class="grid-2">
    {{-- Team-Leistung --}}
    <div class="card">
        <div class="card-title">👥 Team-Leistung (zugewiesene Tickets)</div>
        <table>
            <thead><tr><th>Mitarbeiter</th><th style="text-align:right;">Zugewiesen</th><th style="text-align:right;">Erledigt</th><th style="text-align:right;">Ø Bewertung</th></tr></thead>
            <tbody>
            @forelse($byEmployee as $e)
            <tr>
                <td style="font-weight:600;">{{ $e['name'] }}</td>
                <td style="text-align:right;">{{ $e['total'] }}</td>
                <td style="text-align:right;">{{ $e['erledigt'] }} <span style="color:var(--ink-soft);font-size:12px;">({{ $e['total'] > 0 ? number_format($e['erledigt'] / $e['total'] * 100, 0) : 0 }} %)</span></td>
                <td style="text-align:right;">{{ $e['rating'] !== null ? '★ ' . number_format($e['rating'], 1, ',', '.') : '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="4" style="text-align:center;color:var(--ink-soft);padding:20px;">Keine zugewiesenen Tickets im Zeitraum.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{-- Top-Kunden --}}
    <div class="card">
        <div class="card-title">📬 Kunden mit den meisten Anfragen</div>
        <table>
            <thead><tr><th>Kunde</th><th>Kundennr.</th><th style="text-align:right;">Anfragen</th><th></th></tr></thead>
            <tbody>
            @forelse($topCustomers as $c)
            <tr>
                <td style="font-weight:600;">{{ $c['name'] }}</td>
                <td style="color:var(--ink-soft);">{{ $c['number'] }}</td>
                <td style="text-align:right;">{{ $c['n'] }}</td>
                <td style="text-align:right;"><a href="{{ route('admin.customer', $c['id']) }}" class="btn btn-ghost btn-sm">Akte öffnen</a></td>
            </tr>
            @empty
            <tr><td colspan="4" style="text-align:center;color:var(--ink-soft);padding:20px;">Keine Kundenanfragen im Zeitraum.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<a href="{{ route('admin.tickets') }}" class="btn btn-ghost">← Zur Ticketliste</a>

<script>
(function () {
    // Farben: validierte Palette (Slot 1 Blau + Slot 2 Aqua; Ordinal-Rampen Blau)
    const C_CREATED = '#2a78d6';
    const C_FINISHED = '#1baf7a';
    const RAMP5 = ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab', '#104281'];
    // Dringend = dunkelster Schritt (Reihenfolge der PRIORITIES: dringend zuerst)
    const RAMP4 = ['#0d366b', '#1c5cab', '#3987e5', '#86b6ef'];
    const grid = { color: 'rgba(0,0,0,.05)' };
    const ticks = { color: '#6B7280', font: { size: 11 } };
    const labels = @json($labels);
    // Bei langen Zeitraeumen Linien statt Balken (lesbarer)
    const asLine = labels.length > 45;

    new Chart(document.getElementById('dailyChart'), {
        type: asLine ? 'line' : 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: 'Erstellt', data: @json($createdPerDay), backgroundColor: asLine ? C_CREATED + '22' : C_CREATED, borderColor: C_CREATED, borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, tension: 0.25, fill: asLine, borderRadius: 4 },
                { label: 'Erledigt', data: @json($finishedPerDay), backgroundColor: asLine ? 'transparent' : C_FINISHED, borderColor: C_FINISHED, borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, tension: 0.25, fill: false, borderRadius: 4 },
            ],
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: true, labels: { color: '#152826', boxWidth: 14, font: { size: 12 } } } },
            scales: {
                y: { beginAtZero: true, grid: grid, ticks: Object.assign({ precision: 0 }, ticks) },
                x: { grid: { display: false }, ticks: Object.assign({ maxTicksLimit: 12 }, ticks) },
            },
        },
    });

    // Verteilungs-Charts: horizontale Balken, Anzahl direkt im Label
    function distChart(id, rows, colors) {
        new Chart(document.getElementById(id), {
            type: 'bar',
            data: {
                labels: rows.map(r => r.label + ' (' + r.n + ')'),
                datasets: [{ data: rows.map(r => r.n), backgroundColor: colors, borderRadius: 4 }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: grid, ticks: Object.assign({ precision: 0 }, ticks) },
                    y: { grid: { display: false }, ticks: Object.assign({ autoSkip: false }, ticks) },
                },
            },
        });
    }

    const byStatus = @json($byStatus);
    const byPrio = @json($byPriority);
    const byType = @json($byType);
    distChart('statusChart', byStatus, RAMP5);
    distChart('prioChart', byPrio, RAMP4);
    distChart('typeChart', byType, byType.map(() => C_CREATED));
})();
</script>
@endsection
