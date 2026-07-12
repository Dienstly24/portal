@extends('layouts.admin')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">Tickets</div>
        <div class="page-sub">Anfragen registrierter Kunden aus dem Kundenportal.</div>
    </div>
</div>

@php
    $s = $stats['statuses'];
    $activeCount = ($s['open'] ?? 0) + ($s['in_progress'] ?? 0) + ($s['waiting'] ?? 0);
@endphp
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-icon icon-blue">🎫</div>
        <div class="metric-label">Offene Tickets</div>
        <div class="metric-value">{{ $s['open'] ?? 0 }}</div>
        <div class="metric-sub">Noch nicht in Bearbeitung</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">⚙️</div>
        <div class="metric-label">In Bearbeitung</div>
        <div class="metric-value">{{ $s['in_progress'] ?? 0 }}</div>
        <div class="metric-sub">Wartet auf Kunde: {{ $s['waiting'] ?? 0 }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-red">⏰</div>
        <div class="metric-label">Überfällig</div>
        <div class="metric-value">{{ $stats['overdue'] }}</div>
        <div class="metric-sub">Reaktionszeit überschritten</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">👤</div>
        <div class="metric-label">Nicht zugewiesen</div>
        <div class="metric-value">{{ $stats['unassigned'] }}</div>
        <div class="metric-sub">Von {{ $activeCount }} aktiven Tickets</div>
    </div>
</div>

{{-- Status-Tabs (behalten alle uebrigen Filter bei) --}}
<div class="tab-row">
    @php $current = request('status', 'alle'); @endphp
    <a href="{{ request()->fullUrlWithQuery(['status' => null, 'page' => null]) }}" class="tab {{ $current === 'alle' ? 'active' : '' }}">Alle</a>
    @foreach(\App\Models\Ticket::STATUSES as $key => $label)
    <a href="{{ request()->fullUrlWithQuery(['status' => $key, 'page' => null]) }}" class="tab {{ $current === $key ? 'active' : '' }}">
        {{ $label }}<span class="tab-count">{{ $s[$key] ?? 0 }}</span>
    </a>
    @endforeach
</div>

<div class="card" style="margin-bottom:16px;">
    <form method="GET" action="{{ route('admin.tickets') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        <div class="field" style="flex:2;min-width:220px;margin-bottom:0;">
            <label>Suche</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Betreff, Ticket-Nr., Kunde, Kundennummer...">
        </div>
        <div class="field" style="flex:1;min-width:150px;margin-bottom:0;">
            <label>Priorität</label>
            <select name="priority" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach(\App\Models\Ticket::PRIORITIES as $key => $p)
                <option value="{{ $key }}" {{ request('priority') === $key ? 'selected' : '' }}>{{ $p['icon'] }} {{ $p['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="flex:1;min-width:170px;margin-bottom:0;">
            <label>Zugewiesen an</label>
            <select name="assigned" onchange="this.form.submit()">
                <option value="">Alle</option>
                <option value="me" {{ request('assigned') === 'me' ? 'selected' : '' }}>Mir zugewiesen</option>
                <option value="none" {{ request('assigned') === 'none' ? 'selected' : '' }}>Nicht zugewiesen</option>
                @foreach($staff as $u)
                <option value="{{ $u->id }}" {{ request('assigned') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filtern</button>
        @if(request()->hasAny(['q','priority','assigned','status']))
        <a href="{{ route('admin.tickets') }}" class="btn btn-ghost">Zurücksetzen</a>
        @endif
    </form>
</div>

<div class="card">
    <table>
        <thead><tr><th>Nr.</th><th>Betreff</th><th>Kunde</th><th>Priorität</th><th>Zugewiesen</th><th>Aktualisiert</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($tickets as $t)
        <tr>
            <td style="color:var(--ink-soft);font-size:13px;white-space:nowrap;">{{ $t->ticket_number }}</td>
            <td>
                <div style="font-weight:600;">{{ $t->subject }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">{{ $t->typeLabel() }} · erstellt {{ $t->created_at->format('d.m.Y') }}</div>
            </td>
            <td style="color:var(--ink-soft);">{{ $t->customer?->user?->name }}</td>
            <td style="white-space:nowrap;">{{ $t->priorityLabel() }}</td>
            <td style="color:var(--ink-soft);">{{ $t->assignedTo?->name ?? '—' }}</td>
            <td style="color:var(--ink-soft);white-space:nowrap;">{{ $t->updated_at->format('d.m.Y H:i') }}</td>
            <td>
                <span class="badge badge-{{ $t->statusBadge() }}">{{ $t->statusLabel() }}</span>
                @if($t->isOverdue())<div style="font-size:11px;color:#A32D2D;font-weight:600;margin-top:4px;">⏰ überfällig</div>@endif
            </td>
            <td><a href="{{ route('admin.ticket', $t->id) }}" class="btn btn-ghost btn-sm">Details</a></td>
        </tr>
        @empty
        <tr><td colspan="8" style="color:var(--ink-soft);text-align:center;padding:24px;">Keine Tickets gefunden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($tickets->hasPages())
<div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;">
    <div style="font-size:13px;color:var(--ink-soft);">
        {{ $tickets->firstItem() }}–{{ $tickets->lastItem() }} von {{ $tickets->total() }} Tickets
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        @if(!$tickets->onFirstPage())
            <a href="{{ $tickets->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>
        @endif
        <span style="font-size:13px;color:var(--ink-soft);">Seite {{ $tickets->currentPage() }} / {{ $tickets->lastPage() }}</span>
        @if($tickets->hasMorePages())
            <a href="{{ $tickets->nextPageUrl() }}" class="btn btn-ghost btn-sm">Weiter →</a>
        @endif
    </div>
</div>
@endif
@endsection
