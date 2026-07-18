@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Verträge</span></div>
    <div class="page-title">Verträge</div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:16px;">
    <div style="position:relative;flex:1;max-width:500px;">
        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--ink-soft);">🔍</span>
        <input type="text" id="contract-search" placeholder="Verträge durchsuchen" onkeyup="filterContracts()"
            style="width:100%;padding:11px 14px 11px 42px;border:1px solid var(--line);border-radius:10px;font-size:14px;background:#fff;">
    </div>
    <a href="{{ route('admin.contract.new') }}" class="btn btn-primary" style="white-space:nowrap;">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Vertrag anlegen
    </a>
</div>

@php
    $active = $contracts->where('status','active');
    $inactive = $contracts->where('status','!=','active');
@endphp

<div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:20px;">
    <button onclick="showTab('active')" id="tab-active"
        style="padding:12px 20px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:700;color:var(--petrol);border-bottom:2px solid var(--petrol);margin-bottom:-2px;">
        Aktive Verträge ({{ $active->count() }})
    </button>
    <button onclick="showTab('inactive')" id="tab-inactive"
        style="padding:12px 20px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:500;color:var(--ink-soft);border-bottom:2px solid transparent;margin-bottom:-2px;">
        Inaktive Verträge ({{ $inactive->count() }})
    </button>
</div>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
    <div style="font-size:14px;font-weight:700;" id="contract-count">{{ $contracts->count() }} Verträge</div>
    {{-- Aktiver Kategorie-Filter (z.B. per Klick aus dem Dashboard-Diagramm) --}}
    <span id="type-filter-chip" style="display:none;align-items:center;gap:8px;font-size:12.5px;background:var(--canvas);border:1px solid var(--line);border-radius:999px;padding:4px 8px 4px 12px;">
        <span id="type-filter-label"></span>
        <a href="{{ route('admin.contracts') }}" style="text-decoration:none;color:var(--ink-soft);font-weight:700;" title="Filter entfernen">✕</a>
    </span>
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <table id="contracts-table">
        <thead>
            <tr style="background:#F8F9FA;">
                <th style="padding:12px 20px;width:48px;"></th>
                <th style="padding:12px 8px;">Versicherung</th>
                <th>VN / Versichert</th>
                <th>Beginn / Ablauf</th>
                <th>Status</th>
                <th>VSNR / V-NR</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse($contracts as $c)
        @php $cfg = $c->typeConfig(); @endphp
        <tr class="contract-row" data-status="{{ $c->status }}" data-type="{{ $c->type }}"
            data-search="{{ strtolower($c->insurer . ' ' . $c->contract_number . ' ' . ($c->customer?->user?->name ?? '')) }}">
            <td style="padding:14px 20px;">
                <div style="width:40px;height:40px;border-radius:10px;background:{{ $cfg['bg'] }};display:flex;align-items:center;justify-content:center;font-size:20px;">{{ $c->typeIcon() }}</div>
            </td>
            <td style="padding:14px 8px;">
                <div style="font-weight:700;font-size:14px;">{{ $c->typeLabel() }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">{{ $c->insurer }}</div>
            </td>
            <td style="font-size:13px;">{{ $c->customer?->user?->name ?? '—' }}</td>
            <td style="font-size:13px;color:var(--ink-soft);">
                @if($c->start_date)<div>{{ \Carbon\Carbon::parse($c->start_date)->format('d.m.Y') }}</div>@endif
                @if($c->end_date)<div>{{ \Carbon\Carbon::parse($c->end_date)->format('d.m.Y') }}</div>@endif
            </td>
            <td><span class="badge badge-{{ $c->status === 'active' ? 'active' : ($c->status === 'cancelled' ? 'rejected' : 'pending') }}">{{ ['active'=>'Aktiv','pending'=>'In Bearbeitung','cancelled'=>'Gekündigt','expired'=>'Abgelaufen'][$c->status] ?? $c->status }}</span></td>
            <td>
                <div style="font-size:13px;font-weight:600;">{{ $c->contract_number ?: '—' }}</div>
            </td>
            <td style="padding-right:20px;white-space:nowrap;">
                <a href="{{ route('admin.contract.edit', $c->id) }}" class="btn btn-ghost btn-sm">Bearbeiten</a>
                <a href="{{ route('admin.customer', $c->customer_id) }}" class="btn btn-ghost btn-sm">Kunde</a>
            </td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--ink-soft);">Keine Verträge vorhanden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<script>
let currentTab = 'active';
// Kategorie-Filter aus der URL (?type=...), z.B. Klick im Dashboard-Diagramm.
// 'energie' fasst Strom/Gas zusammen (wie im Diagramm gruppiert).
const TYPE_LABELS = {kfz:'KFZ',krankenversicherung:'Krankenversicherung',internet:'Internet & Mobilfunk',energie:'Strom & Gas',strom:'Strom',gas:'Gas',strom_gas:'Strom & Gas',andere:'Andere'};
const typeFilter = new URLSearchParams(window.location.search).get('type') || '';
const typeMatches = t => !typeFilter
    || (typeFilter === 'energie' ? ['strom','gas','strom_gas'].includes(t) : t === typeFilter);

function showTab(tab) {
    currentTab = tab;
    document.getElementById('tab-active').style.color = tab === 'active' ? 'var(--petrol)' : 'var(--ink-soft)';
    document.getElementById('tab-active').style.borderBottomColor = tab === 'active' ? 'var(--petrol)' : 'transparent';
    document.getElementById('tab-active').style.fontWeight = tab === 'active' ? '700' : '500';
    document.getElementById('tab-inactive').style.color = tab === 'inactive' ? 'var(--petrol)' : 'var(--ink-soft)';
    document.getElementById('tab-inactive').style.borderBottomColor = tab === 'inactive' ? 'var(--petrol)' : 'transparent';
    document.getElementById('tab-inactive').style.fontWeight = tab === 'inactive' ? '700' : '500';
    filterContracts();
}

function filterContracts() {
    const q = document.getElementById('contract-search').value.toLowerCase();
    let count = 0;
    document.querySelectorAll('.contract-row').forEach(row => {
        const statusMatch = currentTab === 'active' ? row.dataset.status === 'active' : row.dataset.status !== 'active';
        const searchMatch = !q || row.dataset.search.includes(q);
        const show = statusMatch && searchMatch && typeMatches(row.dataset.type);
        row.style.display = show ? '' : 'none';
        if(show) count++;
    });
    document.getElementById('contract-count').textContent = count + ' Verträge';
}

document.addEventListener('DOMContentLoaded', () => {
    if (typeFilter) {
        const chip = document.getElementById('type-filter-chip');
        document.getElementById('type-filter-label').textContent = 'Kategorie: ' + (TYPE_LABELS[typeFilter] || typeFilter);
        chip.style.display = 'inline-flex';
    }
    filterContracts();
});
</script>
@endsection
