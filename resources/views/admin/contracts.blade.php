@extends('layouts.admin')
@section('content')
@php
    $G = \App\Models\Contract::class;
    // Reiter: Schluessel = Wert des Query-Parameters "gruppe".
    $reiter = [
        $G::GROUP_ACTIVE  => ['✅ Aktiver Bestand', 'aktiver Vertrag', 'aktive Verträge'],
        $G::GROUP_PENDING => ['🕓 In Bearbeitung', 'Vertrag in Bearbeitung', 'Verträge in Bearbeitung'],
        $G::GROUP_HISTORY => ['🗄 Beendet / Historie', 'beendeter Vertrag (Historie)', 'beendete Verträge (Historie)'],
        'alle'            => ['Alle', 'Vertrag', 'Verträge'],
    ];
    // Hinweisleiste: sagt fuer jede Gruppe ausdruecklich, ob die gezeigten
    // Vertraege zum aktiven Bestand zaehlen.
    $hinweise = [
        $G::GROUP_ACTIVE  => ['✅ Aktiver Bestand: laufende Verträge – nur diese zählen als aktive Verträge und bilden die Vertragsstruktur der Kunden.', '#E7F6EE', '#BFE6D2', '#0E7A41'],
        $G::GROUP_PENDING => ['🕓 In Bearbeitung: Antrag/Angebot noch nicht abgeschlossen – zählt NICHT zum aktiven Bestand.', '#FEF3C7', '#F0E0B0', '#92400E'],
        $G::GROUP_HISTORY => ['🗄 Beendet / Historie: gekündigte und abgelaufene Verträge. Nur zur Nachvollziehbarkeit sichtbar – sie zählen NICHT als aktive Verträge und erscheinen nicht in der Vertragsstruktur.', '#F3F0E8', '#E0DCD0', '#5F6B62'],
        'alle'            => ['ℹ️ Alle Verträge (aktiv + Historie gemischt). Beendete Verträge sind ausgegraut und mit „Historie – nicht aktiv" gekennzeichnet.', '#EEF0F3', '#E0DCD0', '#5F6B62'],
    ];
    $h = $hinweise[$gruppe] ?? $hinweise['alle'];
    $anzahl = $contracts->total();
    $wort = $anzahl === 1 ? ($reiter[$gruppe][1] ?? 'Vertrag') : ($reiter[$gruppe][2] ?? 'Verträge');
@endphp

<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Verträge</span></div>
    <div class="page-title">Verträge</div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:16px;flex-wrap:wrap;">
    {{-- Suche laeuft in der Datenbank: Gesellschaft, Vertragsnummer,
         Kundenname, Kundennummer (Contract::scopeSearch). --}}
    <form method="GET" action="{{ route('admin.contracts') }}" style="position:relative;flex:1;max-width:500px;">
        <input type="hidden" name="gruppe" value="{{ $gruppe }}">
        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--ink-soft);">🔍</span>
        <input type="text" name="q" value="{{ $suche }}" placeholder="Verträge durchsuchen"
            style="width:100%;padding:11px 14px 11px 42px;border:1px solid var(--line);border-radius:10px;font-size:14px;background:#fff;">
        @if($suche !== '')
        <a href="{{ route('admin.contracts', ['gruppe' => $gruppe]) }}" title="Suche zurücksetzen"
            style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--ink-soft);text-decoration:none;font-size:16px;">✕</a>
        @endif
    </form>
    <a href="{{ route('admin.contract.new') }}" class="btn btn-primary" style="white-space:nowrap;">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Vertrag anlegen
    </a>
</div>

{{-- Bestandsgruppen statt "aktiv / inaktiv": eindeutige Bezeichnungen, damit
     "Inaktive Verträge" nicht mit "deaktivierter Zugang" oder "in Bearbeitung"
     verwechselt wird (Betreiber-Vorgabe 17.08.2026). Die Zaehler kommen aus
     COUNT-Abfragen und folgen der Suche. --}}
<div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:16px;flex-wrap:wrap;">
    @foreach($reiter as $key => $texte)
    @php $aktiv = $gruppe === $key; @endphp
    <a href="{{ route('admin.contracts', array_filter(['gruppe' => $key, 'q' => $suche])) }}"
        style="padding:12px 20px;text-decoration:none;font-size:14px;margin-bottom:-2px;
               font-weight:{{ $aktiv ? '700' : '500' }};
               color:{{ $aktiv ? 'var(--petrol)' : 'var(--ink-soft)' }};
               border-bottom:2px solid {{ $aktiv ? 'var(--petrol)' : 'transparent' }};">
        {{ $texte[0] }} ({{ $zaehler[$key] ?? 0 }})
    </a>
    @endforeach
</div>

<div style="font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;line-height:1.55;
    background:{{ $h[1] }};border:1px solid {{ $h[2] }};color:{{ $h[3] }};">{{ $h[0] }}</div>

<div style="font-size:14px;font-weight:700;margin-bottom:14px;">
    {{ $anzahl }} {{ $wort }}@if($suche !== '') <span style="font-weight:500;color:var(--ink-soft);">für „{{ $suche }}"</span>@endif
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
        @php
            $cfg = $c->typeConfig();
            $cGroup = $c->statusGroup();
            $cHistoric = $cGroup === \App\Models\Contract::GROUP_HISTORY;
        @endphp
        <tr class="contract-row{{ $cHistoric ? ' contract-row-historic' : '' }}" data-status="{{ $c->status }}" data-group="{{ $cGroup }}">
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
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--ink-soft);">
            @if($suche !== '')Keine Verträge zu „{{ $suche }}" in dieser Gruppe.@else Keine Verträge in dieser Gruppe.@endif
        </td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Seitennavigation (an das App-Design angepasst, ohne Framework-Theme) --}}
@if($contracts->hasPages())
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:16px 2px;flex-wrap:wrap;">
    <div style="font-size:13px;color:var(--ink-soft);">
        {{ $contracts->firstItem() }}–{{ $contracts->lastItem() }} von {{ $contracts->total() }} Verträgen
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        @if($contracts->onFirstPage())
            <span class="btn btn-ghost btn-sm" style="opacity:.45;pointer-events:none;">← Zurück</span>
        @else
            <a href="{{ $contracts->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>
        @endif
        <span style="font-size:13px;color:var(--ink-soft);">Seite {{ $contracts->currentPage() }} / {{ $contracts->lastPage() }}</span>
        @if($contracts->hasMorePages())
            <a href="{{ $contracts->nextPageUrl() }}" class="btn btn-ghost btn-sm">Weiter →</a>
        @else
            <span class="btn btn-ghost btn-sm" style="opacity:.45;pointer-events:none;">Weiter →</span>
        @endif
    </div>
</div>
@endif

<style>
/* Beendete Vertraege bleiben in der Historie sichtbar, sind aber nie mit
   aktiven Vertraegen zu verwechseln (ausgegraut + Textkennzeichen). */
.contract-row-historic{background:#F3F0E8;}
.contract-row-historic td{opacity:.72;}
.contract-histflag{display:inline-block;font-size:10.5px;font-weight:700;letter-spacing:.02em;
    text-transform:uppercase;color:#5F6B62;background:#EAE6DA;border:1px solid #E0DCD0;
    border-radius:6px;padding:1px 6px;white-space:nowrap;}
</style>
@endsection
