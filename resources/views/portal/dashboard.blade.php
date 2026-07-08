@extends('layouts.portal')
@section('content')
<div class="page-title">Übersicht</div>
<div class="page-sub">Willkommen zurück, {{ auth()->user()->name }}.</div>
<div class="grid-3">
    <div class="metric"><div class="label">Aktive Verträge</div><div class="value">{{ $contractsCount }}</div></div>
    <div class="metric"><div class="label">Offene Anfragen</div><div class="value">{{ $openTickets }}</div></div>
    <div class="metric"><div class="label">Änderungen in Prüfung</div><div class="value">{{ $pendingApprovals }}</div></div>
</div>
<div class="card">
    <div class="card-title">Letzte Verträge</div>
    @forelse($contracts as $c)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $c->insurer }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">{{ $c->contract_number }} · {{ ucfirst($c->type) }}</div>
        </div>
        <span class="badge badge-{{ $c->status === 'active' ? 'active' : 'pending' }}">{{ $c->status === 'active' ? 'Aktiv' : ucfirst($c->status) }}</span>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Verträge vorhanden.</p>
    @endforelse
</div>
<div class="card">
    <div class="card-title">Letzte Anfragen</div>
    @forelse($tickets as $t)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $t->subject }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">{{ $t->created_at->format('d.m.Y') }}</div>
        </div>
        <span class="badge badge-{{ $t->status === 'open' ? 'open' : 'closed' }}">{{ $t->status === 'open' ? 'Offen' : 'In Bearbeitung' }}</span>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Anfragen vorhanden.</p>
    @endforelse
</div>
@endsection
