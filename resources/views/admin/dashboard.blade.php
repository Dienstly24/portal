@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="#">🏠</a><span class="breadcrumb-sep">›</span><span>Dashboard</span></div>
    <div class="page-title">Hallo, {{ auth()->user()->name }}</div>
    <div class="page-sub">{{ now()->format('d.m.Y') }} — Willkommen in Ihrer Beraterwelt</div>
</div>

<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-icon icon-green">👥</div>
        <div class="metric-label">Kunden gesamt</div>
        <div class="metric-value">{{ $totalCustomers }}</div>
        <div class="metric-sub">Registrierte Kunden</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-blue">📄</div>
        <div class="metric-label">Aktive Verträge</div>
        <div class="metric-value">{{ $activeContracts }}</div>
        <div class="metric-sub">Im Bestand</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">💬</div>
        <div class="metric-label">Offene Anträge</div>
        <div class="metric-value">{{ $openTickets }}</div>
        <div class="metric-sub">Warten auf Bearbeitung</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-red">⏳</div>
        <div class="metric-label">Genehmigungen</div>
        <div class="metric-value">{{ $pendingApprovals }}</div>
        <div class="metric-sub">Ausstehend</div>
    </div>
</div>

<div class="grid-2">
<div class="card">
    <div class="card-header">
        <div class="card-title">Vertragsstruktur</div>
        <a href="{{ route('admin.contracts') }}" class="card-link">Alle anzeigen →</a>
    </div>
    <canvas id="contractChart" height="200"></canvas>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">Neueste Anträge</div>
        <a href="{{ route('admin.tickets') }}" class="card-link">Alle anzeigen →</a>
    </div>
    @forelse($recentTickets as $t)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $t->subject }}</div>
            <div style="font-size:12px;color:var(--ink-soft);">{{ $t->customer?->user?->name }} · {{ $t->created_at->format('d.m.Y') }}</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <span class="badge badge-{{ $t->status === 'open' ? 'open' : ($t->status === 'closed' ? 'closed' : 'pending') }}">{{ ['open'=>'Offen','in_progress'=>'In Bearbeitung','waiting'=>'Wartend','closed'=>'Geschlossen'][$t->status] ?? $t->status }}</span>
            <a href="{{ route('admin.ticket', $t->id) }}" class="btn btn-ghost btn-sm">Details</a>
        </div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;padding:12px 0;">Keine Anträge vorhanden.</p>
    @endforelse
</div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">Zuletzt geöffnete Kunden</div>
        <a href="{{ route('admin.customers') }}" class="card-link">Alle Kunden anzeigen →</a>
    </div>
    @php $recentCustomers = \App\Models\Customer::with('user')->latest()->take(8)->get(); @endphp
    <div class="customer-cards">
        @forelse($recentCustomers as $c)
        <a href="{{ route('admin.customer', $c->id) }}" class="customer-card" style="text-decoration:none;color:inherit;">
            <div class="name">{{ $c->user?->name }}</div>
            <div class="meta">Verträge: {{ $c->contracts()->count() }}</div>
        </a>
        @empty
        <p style="color:var(--ink-soft);font-size:14px;">Noch keine Kunden.</p>
        @endforelse
    </div>
</div>

<script>
const ctx = document.getElementById('contractChart').getContext('2d');
new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: ['Kfz', 'Krankenversicherung', 'Internet', 'Strom & Gas', 'Andere'],
        datasets: [{
            data: [
                {{ \App\Models\Contract::where('type','kfz')->count() }},
                {{ \App\Models\Contract::where('type','krankenversicherung')->count() }},
                {{ \App\Models\Contract::where('type','internet')->count() }},
                {{ \App\Models\Contract::where('type','strom_gas')->count() }},
                {{ \App\Models\Contract::where('type','andere')->count() }}
            ],
            backgroundColor: ['#0F3D3D','#C9963E','#3B7A57','#185FA5','#B4B2A9'],
            borderWidth: 0,
        }]
    },
    options: {
        cutout: '65%',
        plugins: {
            legend: { position: 'right', labels: { font: { size: 12 }, padding: 16 } }
        }
    }
});
</script>
@endsection
