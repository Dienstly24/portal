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
    // Gruppierung zentral aus dem Modell (Contract::statusGroup()) - dieselbe
    // Quelle wie Vertragsstruktur, Kennzahlen und Filter. Ein Vertrag mit
    // status=active, dessen wirksames Ende erreicht ist, gilt hier korrekt
    // als beendet (frueher zaehlte er als "aktiv").
    $groups = $contracts->groupBy(fn($c) => $c->statusGroup());
    $G = \App\Models\Contract::class;
    $countAktiv = ($groups[$G::GROUP_ACTIVE] ?? collect())->count();
    $countAnbahnung = ($groups[$G::GROUP_PENDING] ?? collect())->count();
    $countHistorie = ($groups[$G::GROUP_HISTORY] ?? collect())->count();
@endphp

{{-- Bestandsgruppen statt "aktiv / inaktiv": eindeutige Bezeichnungen, damit
     "Inaktive Verträge" nicht mit "deaktivierter Zugang" oder "in Bearbeitung"
     verwechselt wird (Betreiber-Vorgabe 17.08.2026). --}}
<div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:16px;flex-wrap:wrap;">
    <button onclick="showTab('aktiv')" id="tab-aktiv" class="ctab"
        style="padding:12px 20px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:700;color:var(--petrol);border-bottom:2px solid var(--petrol);margin-bottom:-2px;">
        ✅ Aktiver Bestand ({{ $countAktiv }})
    </button>
    <button onclick="showTab('anbahnung')" id="tab-anbahnung" class="ctab"
        style="padding:12px 20px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:500;color:var(--ink-soft);border-bottom:2px solid transparent;margin-bottom:-2px;">
        🕓 In Bearbeitung ({{ $countAnbahnung }})
    </button>
    <button onclick="showTab('historie')" id="tab-historie" class="ctab"
        style="padding:12px 20px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:500;color:var(--ink-soft);border-bottom:2px solid transparent;margin-bottom:-2px;">
        🗄 Beendet / Historie ({{ $countHistorie }})
    </button>
    <button onclick="showTab('alle')" id="tab-alle" class="ctab"
        style="padding:12px 20px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:500;color:var(--ink-soft);border-bottom:2px solid transparent;margin-bottom:-2px;">
        Alle ({{ $contracts->count() }})
    </button>
</div>

{{-- Hinweisleiste: sagt fuer jede Gruppe ausdruecklich, ob die gezeigten
     Vertraege zum aktiven Bestand zaehlen. --}}
<div id="group-hint" style="font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;line-height:1.55;"></div>

<div style="font-size:14px;font-weight:700;margin-bottom:14px;" id="contract-count">{{ $contracts->count() }} Verträge</div>

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
        @php
            $cfg = $c->typeConfig();
            $cGroup = $c->statusGroup();
            $cHistoric = $cGroup === \App\Models\Contract::GROUP_HISTORY;
        @endphp
        <tr class="contract-row{{ $cHistoric ? ' contract-row-historic' : '' }}" data-status="{{ $c->status }}" data-group="{{ $cGroup }}"
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
            @php $st = $c->displayStatus(); @endphp
            <td>
                <span class="badge badge-{{ $st['badge'] }}" style="white-space:nowrap;">{{ $st['label'] }}</span>
                {{-- Klare Kennzeichnung: gehoert NICHT zum aktiven Bestand. --}}
                @if($st['historic'])
                <div style="margin-top:4px;"><span class="contract-histflag">🗄 Historie – nicht aktiv</span></div>
                @elseif($cGroup === \App\Models\Contract::GROUP_PENDING)
                <div style="margin-top:4px;"><span class="contract-histflag">🕓 Noch nicht im Bestand</span></div>
                @endif
                @include('admin.partials.contract_stage_badge', ['contract' => $c])
            </td>
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

<style>
/* Beendete Vertraege bleiben in der Historie sichtbar, sind aber nie mit
   aktiven Vertraegen zu verwechseln (ausgegraut + Textkennzeichen). */
.contract-row-historic{background:#F3F0E8;}
.contract-row-historic td{opacity:.72;}
.contract-histflag{display:inline-block;font-size:10.5px;font-weight:700;letter-spacing:.02em;
    text-transform:uppercase;color:#5F6B62;background:#EAE6DA;border:1px solid #E0DCD0;
    border-radius:6px;padding:1px 6px;white-space:nowrap;}
</style>
<script>
// Bestandsgruppen (Spiegel von Contract::GROUP_*): 'alle' = kein Filter.
let currentTab = 'aktiv';

const GROUP_HINTS = {
    'aktiv':     {text: '✅ Aktiver Bestand: laufende Verträge – nur diese zählen als aktive Verträge und bilden die Vertragsstruktur der Kunden.', bg: '#E7F6EE', border: '#BFE6D2', color: '#0E7A41'},
    'anbahnung': {text: '🕓 In Bearbeitung: Antrag/Angebot noch nicht abgeschlossen – zählt NICHT zum aktiven Bestand.', bg: '#FEF3C7', border: '#F0E0B0', color: '#92400E'},
    'historie':  {text: '🗄 Beendet / Historie: gekündigte und abgelaufene Verträge. Nur zur Nachvollziehbarkeit sichtbar – sie zählen NICHT als aktive Verträge und erscheinen nicht in der Vertragsstruktur.', bg: '#F3F0E8', border: '#E0DCD0', color: '#5F6B62'},
    'alle':      {text: 'ℹ️ Alle Verträge (aktiv + Historie gemischt). Beendete Verträge sind ausgegraut und mit „Historie – nicht aktiv" gekennzeichnet.', bg: '#EEF0F3', border: '#E0DCD0', color: '#5F6B62'},
};

function showTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.ctab').forEach(btn => {
        const on = btn.id === 'tab-' + tab;
        btn.style.color = on ? 'var(--petrol)' : 'var(--ink-soft)';
        btn.style.borderBottomColor = on ? 'var(--petrol)' : 'transparent';
        btn.style.fontWeight = on ? '700' : '500';
    });
    const hint = document.getElementById('group-hint');
    const cfg = GROUP_HINTS[tab] || GROUP_HINTS['alle'];
    hint.textContent = cfg.text;
    hint.style.background = cfg.bg;
    hint.style.border = '1px solid ' + cfg.border;
    hint.style.color = cfg.color;
    filterContracts();
}

function filterContracts() {
    const q = document.getElementById('contract-search').value.toLowerCase();
    let count = 0;
    document.querySelectorAll('.contract-row').forEach(row => {
        const groupMatch = currentTab === 'alle' || row.dataset.group === currentTab;
        const searchMatch = !q || row.dataset.search.includes(q);
        const show = groupMatch && searchMatch;
        row.style.display = show ? '' : 'none';
        if(show) count++;
    });
    // Deutsche Ein-/Mehrzahl korrekt bilden ("1 aktiver Vertrag").
    const eins = count === 1;
    const label = currentTab === 'aktiv' ? (eins ? ' aktiver Vertrag' : ' aktive Verträge')
        : (currentTab === 'historie' ? (eins ? ' beendeter Vertrag (Historie)' : ' beendete Verträge (Historie)')
        : (currentTab === 'anbahnung' ? (eins ? ' Vertrag in Bearbeitung' : ' Verträge in Bearbeitung')
        : (eins ? ' Vertrag' : ' Verträge')));
    document.getElementById('contract-count').textContent = count + label;
}

document.addEventListener('DOMContentLoaded', () => showTab('aktiv'));
</script>
@endsection
