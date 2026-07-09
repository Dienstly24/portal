@extends('layouts.portal')
@section('content')
<div class="page-title">Übersicht</div>
<div class="page-sub">Willkommen zurück, {{ auth()->user()->name }}.</div>
<div class="grid-3">
    <div class="metric"><div class="label">Aktive Verträge</div><div class="value">{{ $contractsCount }}</div></div>
    <div class="metric"><div class="label">Offene Anfragen</div><div class="value">{{ $openTickets }}</div></div>
    <div class="metric"><div class="label">Änderungen in Prüfung</div><div class="value">{{ $pendingApprovals }}</div></div>
</div>

{{-- Kundenakte-Vollständigkeit (Final Polish Punkt 5) --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title" style="margin-bottom:0;">📋 Ihre Kundenakte</div>
        <span style="font-size:20px;font-weight:800;color:{{ $completeness['percent'] >= 80 ? '#3B7A57' : ($completeness['percent'] >= 50 ? '#B5651D' : '#A32D2D') }};">{{ $completeness['percent'] }} %</span>
    </div>
    <div style="height:10px;background:var(--canvas);border:1px solid var(--line);border-radius:6px;overflow:hidden;margin-bottom:6px;">
        <div style="height:100%;width:{{ $completeness['percent'] }}%;background:{{ $completeness['percent'] >= 80 ? '#3B7A57' : ($completeness['percent'] >= 50 ? '#D9A441' : '#E24B4A') }};transition:width .3s;"></div>
    </div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">{{ $completeness['percent'] }} % vollständig</div>
    @if(count($completeness['missing']))
    <div style="display:flex;flex-direction:column;gap:8px;">
        @foreach($completeness['missing'] as $m)
        <a href="{{ route($m['route']) }}" style="display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--ink);font-size:13.5px;{{ !empty($m['optional']) ? 'opacity:.7;' : '' }}">
            <span>⚠ {{ $m['label'] }}</span>
            <span style="color:var(--petrol);font-size:12px;">ergänzen →</span>
        </a>
        @endforeach
    </div>
    @else
    <div style="font-size:13.5px;color:#3B7A57;">✓ Ihre Kundenakte ist vollständig.</div>
    @endif
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
