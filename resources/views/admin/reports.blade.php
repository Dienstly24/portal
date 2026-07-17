@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Berichte & Analysen</span></div>
    <div class="page-title">Berichte & Analysen</div>
    <div class="page-sub">Ihre Leistungskennzahlen und Übersicht</div>
</div>

<div class="card" style="margin-bottom:24px;">
    <form method="GET" action="{{ route('admin.reports') }}" style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:12px;color:var(--ink-soft);font-weight:600;margin-bottom:6px;">Von Datum</label>
            <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" style="padding:9px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--ink-soft);font-weight:600;margin-bottom:6px;">Bis Datum</label>
            <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" style="padding:9px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
        </div>
        <button type="submit" class="btn btn-primary">Filter anwenden</button>
        <a href="{{ route('admin.reports') }}" class="btn btn-ghost">Löschen</a>
    </form>
</div>

{{-- Kunden --}}
<div style="font-size:16px;font-weight:700;margin-bottom:14px;color:var(--ink);">Kunden Übersicht</div>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;">
    <div class="metric-card">
        <div class="metric-icon icon-blue">👥</div>
        <div class="metric-label">Kunden gesamt</div>
        <div class="metric-value">{{ $customers_stats['total'] }}</div>
        <div class="metric-sub">Alle Kunden</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">🆕</div>
        <div class="metric-label">Neu im Zeitraum</div>
        <div class="metric-value">{{ $customers_stats['new'] }}</div>
        <div class="metric-sub">Im gewählten Zeitraum</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">👤</div>
        <div class="metric-label">Privatkunden</div>
        <div class="metric-value">{{ $customers_stats['privat'] }}</div>
        <div class="metric-sub">Privatpersonen</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-red">🏢</div>
        <div class="metric-label">Firmenkunden</div>
        <div class="metric-value">{{ $customers_stats['firma'] }}</div>
        <div class="metric-sub">Unternehmen</div>
    </div>
</div>

{{-- Verträge --}}
<div style="font-size:16px;font-weight:700;margin-bottom:14px;color:var(--ink);">Verträge Übersicht</div>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;">
    <div class="metric-card">
        <div class="metric-icon icon-green">✅</div>
        <div class="metric-label">Aktivierte Verträge</div>
        <div class="metric-value">{{ $contracts['active'] }}</div>
        <div class="metric-sub">{{ $contracts['total'] > 0 ? round($contracts['active']/$contracts['total']*100) : 0 }}% Abschlussrate</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">⏳</div>
        <div class="metric-label">In Bearbeitung</div>
        <div class="metric-value">{{ $contracts['pending'] }}</div>
        <div class="metric-sub">Ausstehende Aktivierung</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-blue">📄</div>
        <div class="metric-label">Gesamtverträge</div>
        <div class="metric-value">{{ $contracts['total'] }}</div>
        <div class="metric-sub">Alle Verträge</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-red">❌</div>
        <div class="metric-label">Gekündigt / Abgelaufen</div>
        <div class="metric-value">{{ $contracts['cancelled'] + $contracts['expired'] }}</div>
        <div class="metric-sub">Inaktive Verträge</div>
    </div>
</div>

<div class="grid-2">
<div class="card">
    <div class="card-header">
        <div class="card-title">Verträge nach Sparte</div>
    </div>
    <canvas id="contractTypeChart" height="220"></canvas>
    @php
    $typeLabels = ['kfz'=>'KFZ','krankenversicherung'=>'Kranken','internet'=>'Internet','strom'=>'Strom','gas'=>'Gas','strom_gas'=>'Strom & Gas','andere'=>'Andere','leben'=>'Leben','sach'=>'Sach'];
    @endphp
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">Tickets nach Status</div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
        <div style="background:#F4F5F7;border-radius:10px;padding:16px;text-align:center;">
            <div style="font-size:28px;font-weight:700;">{{ $tickets['total'] }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">Tickets gesamt</div>
        </div>
        <div style="background:#E4F0E7;border-radius:10px;padding:16px;text-align:center;">
            <div style="font-size:28px;font-weight:700;color:#3B7A57;">{{ $tickets['closed'] }}</div>
            <div style="font-size:13px;color:#3B7A57;">Gelöst</div>
        </div>
        <div style="background:#E6F1FB;border-radius:10px;padding:16px;text-align:center;">
            <div style="font-size:28px;font-weight:700;color:#185FA5;">{{ $tickets['open'] }}</div>
            <div style="font-size:13px;color:#185FA5;">Offen</div>
        </div>
        <div style="background:#F7E7D6;border-radius:10px;padding:16px;text-align:center;">
            <div style="font-size:28px;font-weight:700;color:#B5651D;">{{ $tickets['in_progress'] }}</div>
            <div style="font-size:13px;color:#B5651D;">In Bearbeitung</div>
        </div>
    </div>
    <canvas id="ticketTypeChart" height="140"></canvas>
</div>
</div>

{{-- Vertragserinnerungen --}}
<div style="font-size:16px;font-weight:700;margin:8px 0 14px;color:var(--ink);">
    Vertragserinnerungen
    @if($warnings > 0)
    <span style="background:#F9E3E3;color:#A32D2D;font-size:12px;padding:3px 10px;border-radius:999px;margin-left:8px;font-weight:600;">{{ $warnings }} überfällig</span>
    @endif
</div>
<div class="card" style="padding:0;overflow:hidden;">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 20px;">Kunde</th>
            <th>Versicherung</th>
            <th>Sparte</th>
            <th>Ablaufdatum</th>
            <th>Verbleibend</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse($expiring as $c)
        @php $days = now()->diffInDays(\Carbon\Carbon::parse($c->end_date)); @endphp
        <tr class="row-link" onclick="rowNav(event, '{{ route('admin.contract.edit', $c->id) }}')" title="Vertrag öffnen">
            <td style="padding:13px 20px;font-weight:600;">{{ $c->customer?->user?->name ?? '—' }}</td>
            <td><a href="{{ route('admin.contract.edit', $c->id) }}" style="color:inherit;">{{ $c->insurer }}</a></td>
            <td>{{ ['kfz'=>'KFZ','krankenversicherung'=>'Kranken','internet'=>'Internet','strom'=>'Strom','gas'=>'Gas','strom_gas'=>'Strom & Gas','andere'=>'Andere'][$c->type] ?? $c->type }}</td>
            <td>{{ \Carbon\Carbon::parse($c->end_date)->format('d.m.Y') }}</td>
            <td>
                <span style="background:{{ $days <= 7 ? '#F9E3E3' : ($days <= 14 ? '#F7E7D6' : '#FEF3C7') }};color:{{ $days <= 7 ? '#A32D2D' : ($days <= 14 ? '#B5651D' : '#92400E') }};padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;">
                    {{ $days }} Tage
                </span>
            </td>
            <td style="padding-right:20px;">
                <a href="{{ route('admin.customer', $c->customer_id) }}" class="btn btn-ghost btn-sm">Kunde</a>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--ink-soft);">Keine ablaufenden Verträge in den nächsten 30 Tagen.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<script>
const contractTypes = @json($contracts['by_type']);
const typeLabels = @json($typeLabels);
const labels = Object.keys(contractTypes).map(k => typeLabels[k] || k);
const data = Object.values(contractTypes);

new Chart(document.getElementById('contractTypeChart'), {
    type: 'doughnut',
    data: {
        labels: labels,
        datasets: [{
            data: data,
            backgroundColor: ['#0F3D3D','#C9963E','#3B7A57','#185FA5','#B4B2A9','#7F77DD','#D85A30'],
            borderWidth: 0,
        }]
    },
    options: {
        cutout: '65%',
        plugins: { legend: { position: 'right', labels: { font: { size: 12 }, padding: 14 } } }
    }
});

const ticketTypes = @json($tickets['by_type']);
const ticketTypeMap = {damage:'Schaden',change:'Änderung',offer:'Angebot',data_update:'Daten',cancellation:'Kündigung',complaint:'Beschwerde',other:'Sonstiges'};
const tLabels = Object.keys(ticketTypes).map(k => ticketTypeMap[k] || k);
const tData = Object.values(ticketTypes);

if(tData.length > 0) {
    new Chart(document.getElementById('ticketTypeChart'), {
        type: 'bar',
        data: {
            labels: tLabels,
            datasets: [{ data: tData, backgroundColor: '#0F3D3D', borderRadius: 6, borderWidth: 0 }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { grid: { display: false } } }
        }
    });
}
</script>
@endsection
