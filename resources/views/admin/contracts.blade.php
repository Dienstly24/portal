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
    if ($herkunft === 'fremd') {
        $hinweise[$gruppe] = ['📁 Fremdbestand: nicht über uns vermittelt, nur zur Dokumentation erfasst. Kein Mandat, keine Courtage – diese Verträge zählen NIE zum Bestand.', '#ECEAE4', '#CFCBC0', '#4B5250'];
    }
    $h = $hinweise[$gruppe] ?? $hinweise['alle'];
    $anzahl = $contracts->total();
    // Sachfilter reisen bei JEDEM Link mit (Reiter, Herkunft, Suche) - sonst
    // verliert ein Klick auf "In Bearbeitung" die eben gewaehlte Sparte.
    $fq = array_filter($filter);
    $mitFilter = fn (array $extra) => array_filter(array_merge($fq, $extra), fn ($v) => $v !== null && $v !== '');
    $hatFilter = $fq !== [];
    $wort = $anzahl === 1 ? ($reiter[$gruppe][1] ?? 'Vertrag') : ($reiter[$gruppe][2] ?? 'Verträge');
@endphp

<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Verträge</span></div>
    <h1 class="page-title">Verträge</h1>
</div>

{{-- EIN Bereich: Liste, Analyse, Monatsuebersicht, Neukunden (27.11.2026). --}}
@include('admin.partials.vertraege_bereich', [
    'aktiv' => 'liste',
    'analyseQuery' => $berichtQuery,
    'monatQuery' => array_filter(['monat' => $filter['abschluss']]),
])

{{-- Dieser Monat auf einen Blick - dieselben Abfragen wie die Analyse. --}}
<div class="vf-monat">
    <span class="vf-monat-titel">{{ $monatZahlen['titel'] }}:</span>
    <a href="{{ $monatZahlen['links']['vertraege'] }}"><strong>{{ number_format($monatZahlen['vertraege'], 0, ',', '.') }}</strong> neue Verträge</a>
    <a href="{{ $monatZahlen['links']['kunden'] }}"><strong>{{ number_format($monatZahlen['kunden'], 0, ',', '.') }}</strong> Kunden mit Abschluss</a>
    <a href="{{ $monatZahlen['links']['neukunden'] }}"><strong>{{ number_format($monatZahlen['neukunden'], 0, ',', '.') }}</strong> neue Kunden</a>
    <a href="{{ route('admin.reports.monat') }}" class="vf-monat-mehr">nach Sparte und Namen →</a>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:16px;flex-wrap:wrap;">
    {{-- Suche laeuft in der Datenbank: Gesellschaft, Vertragsnummer,
         Kundenname, Kundennummer (Contract::scopeSearch). --}}
    <form method="GET" action="{{ route('admin.contracts') }}" style="position:relative;flex:1;max-width:500px;">
        <input type="hidden" name="gruppe" value="{{ $gruppe }}">
        <input type="hidden" name="herkunft" value="{{ $herkunft }}">
        @foreach($fq as $fk => $fv)<input type="hidden" name="{{ $fk }}" value="{{ $fv }}">@endforeach
        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--ink-soft);">🔍</span>
        <input type="text" name="q" value="{{ $suche }}" placeholder="Verträge durchsuchen"
            style="width:100%;padding:11px 14px 11px 42px;border:1px solid var(--line);border-radius:10px;font-size:14px;background:#fff;" aria-label="Verträge durchsuchen">
        @if($suche !== '')
        <a href="{{ route('admin.contracts', $mitFilter(['gruppe' => $gruppe, 'herkunft' => $herkunft])) }}" title="Suche zurücksetzen"
            style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--ink-soft);text-decoration:none;font-size:16px;">✕</a>
        @endif
    </form>
    <a href="{{ route('admin.contract.new') }}" class="btn btn-primary nowrap">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Vertrag anlegen
    </a>
</div>

{{-- Bestandsgruppen statt "aktiv / inaktiv": eindeutige Bezeichnungen, damit
     "Inaktive Verträge" nicht mit "deaktivierter Zugang" oder "in Bearbeitung"
     verwechselt wird (Betreiber-Vorgabe 17.08.2026). Die Zaehler kommen aus
     COUNT-Abfragen und folgen der Suche. --}}
<div class="tab-row">
    @foreach($reiter as $key => $texte)
    <a href="{{ route('admin.contracts', $mitFilter(['gruppe' => $key, 'herkunft' => $herkunft, 'q' => $suche])) }}"
        class="tab {{ $gruppe === $key ? 'active' : '' }}">
        {{ $texte[0] }} ({{ $zaehler[$key] ?? 0 }})
    </a>
    @endforeach
</div>

{{-- Herkunft (28.09.2026): Eigenbestand ist Standard - Kennzahlen zaehlen nur ihn. --}}
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px;font-size:13px;">
    <span class="muted-xs">Herkunft:</span>
    @foreach(['eigen' => '🤝 Eigenbestand', 'fremd' => '📁 Fremdbestand', 'alle' => 'Alle'] as $hk => $hl)
    <a href="{{ route('admin.contracts', $mitFilter(['gruppe' => $gruppe, 'herkunft' => $hk, 'q' => $suche])) }}"
        class="btn btn-sm {{ $herkunft === $hk ? 'btn-primary' : 'btn-ghost' }}"
        title="{{ $hk === 'eigen' ? 'Von uns vermittelte und übernommene Verträge' : ($hk === 'fremd' ? 'Nicht über uns vermittelt – nur Dokumentation' : 'Eigen- und Fremdbestand') }}">{{ $hl }} ({{ $herkunftZaehler[$hk] ?? 0 }})</a>
    @endforeach
    <a href="{{ route('admin.contracts.fremdbestand') }}" class="muted-xs" style="margin-left:auto;">📈 Fremdbestand – Übernahmepotenzial →</a>
</div>

<div style="font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;line-height:1.55;
    background:{{ $h[1] }};border:1px solid {{ $h[2] }};color:{{ $h[3] }};">{{ $h[0] }}</div>

{{-- Filterleiste: Sparte, Gesellschaft, Stufe, Abschlussmonat. Ein
     normales GET-Formular - jeder Stand ist ein teilbarer Link. --}}
<form method="GET" action="{{ route('admin.contracts') }}" class="vf-leiste" aria-label="Verträge filtern">
    <input type="hidden" name="gruppe" value="{{ $gruppe }}">
    <input type="hidden" name="herkunft" value="{{ $herkunft }}">
    @if($suche !== '')<input type="hidden" name="q" value="{{ $suche }}">@endif
    <label class="vf-feld"><span>Sparte</span>
        <select name="sparte">
            <option value="">Alle Sparten</option>
            @foreach(\App\Models\Contract::TYPES as $tk => $tcfg)
            <option value="{{ $tk }}" @selected($filter['sparte'] === $tk)>{{ $tcfg['icon'] }} {{ $tcfg['label'] }}</option>
            @endforeach
        </select>
    </label>
    <label class="vf-feld"><span>Gesellschaft</span>
        <select name="gesellschaft">
            <option value="">Alle Gesellschaften</option>
            @foreach($gesellschaften as $gs)
            <option value="{{ $gs }}" @selected($filter['gesellschaft'] === $gs)>{{ $gs }}</option>
            @endforeach
        </select>
    </label>
    <label class="vf-feld"><span>Stufe</span>
        <select name="stufe">
            <option value="">Alle</option>
            <option value="bestaetigt" @selected($filter['stufe'] === 'bestaetigt')>Ohne offene Anträge</option>
            <option value="antrag" @selected($filter['stufe'] === 'antrag')>Nur Anträge (warten auf Bestätigung)</option>
        </select>
    </label>
    <label class="vf-feld"><span>Abschluss im Monat</span>
        <input type="month" name="abschluss" value="{{ $filter['abschluss'] }}" data-datum-nativ>
    </label>
    <div class="vf-knoepfe">
        <button type="submit" class="btn btn-primary btn-sm">Filtern</button>
        @if($hatFilter)
        <a href="{{ route('admin.contracts', array_filter(['gruppe' => $gruppe, 'herkunft' => $herkunft, 'q' => $suche])) }}" class="btn btn-ghost btn-sm">Filter zurücksetzen</a>
        @endif
    </div>
</form>

{{-- Kennzahlen der GEZEIGTEN Menge - dieselbe Abfrage wie die Liste. --}}
<div class="vf-kennzahlen">
    <div class="vf-kz"><div class="vf-kz-wert">{{ number_format($kennzahlen['vertraege'], 0, ',', '.') }}</div><div class="vf-kz-label">Verträge</div></div>
    <div class="vf-kz"><div class="vf-kz-wert">{{ number_format($kennzahlen['kunden'], 0, ',', '.') }}</div><div class="vf-kz-label">Kunden</div></div>
    <div class="vf-kz"><div class="vf-kz-wert">{{ number_format($kennzahlen['jahresbeitrag'], 2, ',', '.') }} €</div><div class="vf-kz-label">Jahresbeitrag (Kundenbeitrag, nicht unser Ertrag)</div></div>
    <div class="vf-kz">
        <div class="vf-kz-wert">{{ number_format($kennzahlen['antraege'], 0, ',', '.') }}</div>
        <div class="vf-kz-label">davon Anträge – warten auf Bestätigung</div>
        @if($kennzahlen['antraege'] > 0 && $filter['stufe'] !== 'antrag')
        <a href="{{ route('admin.contracts', $mitFilter(['gruppe' => $gruppe, 'herkunft' => $herkunft, 'q' => $suche, 'stufe' => 'antrag'])) }}" class="muted-2xs">anzeigen →</a>
        @endif
    </div>
</div>

<div style="font-size:14px;font-weight:700;margin-bottom:14px;">
    {{ $anzahl }} {{ $wort }}@if($suche !== '') <span style="font-weight:500;color:var(--ink-soft);">für „{{ $suche }}"</span>@endif
</div>

<div class="card card-flush">
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
        <tr class="contract-row{{ $cHistoric ? ' contract-row-historic' : '' }}{{ $c->isExternal() ? ' contract-row-fremd' : '' }}" data-status="{{ $c->status }}" data-group="{{ $cGroup }}">
            <td style="padding:14px 20px;">
                <div style="width:40px;height:40px;border-radius:10px;background:{{ $cfg['bg'] }};display:flex;align-items:center;justify-content:center;font-size:20px;">{{ $c->typeIcon() }}</div>
            </td>
            <td style="padding:14px 8px;">
                <div style="font-weight:700;font-size:14px;">{{ $c->typeLabel() }} @include('admin.partials.contract_origin_badge', ['contract' => $c])</div>
                <div class="muted-xs">{{ $c->insurer }}</div>
                @if($c->successor)<div class="muted-2xs">↪ Ersetzt durch: {{ $c->successor->insurer }}</div>@endif
                @if($c->predecessor)<div class="muted-2xs">↩ Ersetzt: {{ $c->predecessor->insurer }}@if($c->predecessor->isExternal()) (Fremdvertrag)@endif</div>@endif
            </td>
            <td style="font-size:13px;">{{ $c->customer?->user?->name ?? '—' }}</td>
            <td class="muted-sm">
                @if($c->start_date)<div>{{ \Carbon\Carbon::parse($c->start_date)->format('d.m.Y') }}</div>@endif
                @if($c->end_date)<div>{{ \Carbon\Carbon::parse($c->end_date)->format('d.m.Y') }}</div>@endif
            </td>
            @php $st = $c->displayStatus(); @endphp
            <td>
                <span class="badge badge-{{ $st['badge'] }} nowrap">{{ $st['label'] }}</span>
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
            @if($suche !== '')Keine Verträge zu „{{ $suche }}" in dieser Gruppe.@elseif($hatFilter) Keine Verträge für diese Filter in dieser Gruppe.@else Keine Verträge in dieser Gruppe.@endif
        </td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Seitennavigation (an das App-Design angepasst, ohne Framework-Theme) --}}
@if($contracts->hasPages())
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:16px 2px;flex-wrap:wrap;">
    <div class="muted-sm">
        {{ $contracts->firstItem() }}–{{ $contracts->lastItem() }} von {{ $contracts->total() }} Verträgen
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        @if($contracts->onFirstPage())
            <span class="btn btn-ghost btn-sm" style="opacity:.45;pointer-events:none;">← Zurück</span>
        @else
            <a href="{{ $contracts->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>
        @endif
        <span class="muted-sm">Seite {{ $contracts->currentPage() }} / {{ $contracts->lastPage() }}</span>
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
.vf-monat{display:flex;flex-wrap:wrap;gap:6px 18px;align-items:center;font-size:13px;margin:-6px 0 16px;padding:10px 14px;border-radius:10px;background:var(--emerald-soft);border:1px solid var(--line);}
.vf-monat a{color:var(--ink);text-decoration:none;}
.vf-monat a:hover{text-decoration:underline;}
.vf-monat-titel{font-weight:700;}
.vf-monat-mehr{margin-left:auto;color:var(--ink-soft) !important;}
.vf-leiste{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:14px;padding:12px 14px;
    background:var(--surface);border:1px solid var(--line);border-radius:10px;}
.vf-feld{display:flex;flex-direction:column;gap:4px;font-size:11.5px;color:var(--ink-soft);min-width:0;flex:1 1 160px;}
.vf-feld select,.vf-feld input{padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);width:100%;min-width:0;}
.vf-knoepfe{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.vf-kennzahlen{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:16px;}
.vf-kz{background:#fff;border:1px solid var(--line);border-radius:10px;padding:12px 14px;min-width:0;}
.vf-kz-wert{font-size:20px;font-weight:800;color:var(--ink);}
.vf-kz-label{font-size:11.5px;color:var(--ink-soft);margin-top:2px;}
@media (max-width:760px){.vf-kennzahlen{grid-template-columns:repeat(2,minmax(0,1fr));}}
.contract-histflag{display:inline-block;font-size:10.5px;font-weight:700;letter-spacing:.02em;
    text-transform:uppercase;color:#5F6B62;background:#EAE6DA;border:1px solid #E0DCD0;
    border-radius:6px;padding:1px 6px;white-space:nowrap;}
</style>
@endsection
