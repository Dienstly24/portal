@extends('layouts.admin')
@section('content')
{{--
  MONATSUEBERSICHT (Betreiber-Auftrag 26.11.2026): wie viele Kunden und
  Vertraege in diesem Monat, aus welcher Sparte - und WELCHE Kunden.

  Die Zahlen kommen aus denselben Abfragen wie die Berichtsseite
  (ReportController::monat -> DashboardAnalyticsService::liste). Die Namen
  je Sparte stehen in <details> - ohne JavaScript, die Seite bleibt auch bei
  vielen Sparten kurz.
--}}
@php
    $monatName = $monat->locale('de')->isoFormat('MMMM YYYY');
    $mq = ['zeitraum' => 'monatswahl', 'monat' => $monat->format('Y-m')];
    $trend = function (int $jetzt, int $vorher) {
        if ($vorher === 0) {
            return $jetzt > 0 ? ['neu', 'an-up'] : ['–', ''];
        }
        $p = round(($jetzt - $vorher) / $vorher * 100);
        return [($p > 0 ? '+' : '').$p.' %', $p > 0 ? 'an-up' : ($p < 0 ? 'an-down' : '')];
    };
@endphp

<div class="page-header" style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
        <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.reports') }}">Berichte</a><span class="breadcrumb-sep">›</span><span>Monatsübersicht</span></div>
        <h1 class="page-title">Monatsübersicht {{ $monatName }}</h1>
        <div class="page-sub">Abgeschlossene Verträge und Kunden je Sparte – eigener Bestand, Abschluss = Unterschrift, sonst Antrag, sonst Beginn, sonst Anlage.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="{{ route('admin.contracts') }}" class="btn btn-ghost btn-sm">📄 Zu den Verträgen</a>
    </div>
</div>

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
    <a href="{{ route('admin.reports') }}" class="rep-tab">Übersicht</a>
    <a href="{{ route('admin.reports.monat') }}" class="rep-tab rep-tab-active">Monatsübersicht</a>
    <a href="{{ route('admin.reports.neukunden') }}" class="rep-tab">Neukunden</a>
</div>

{{-- Monat waehlen: Pfeile + Monatsfeld (GET, jeder Monat ist ein Link). --}}
<form method="GET" action="{{ route('admin.reports.monat') }}" class="mo-nav">
    <a href="{{ route('admin.reports.monat', ['monat' => $vormonat->format('Y-m')]) }}" class="btn btn-ghost btn-sm" title="Vormonat">← {{ $vormonat->locale('de')->isoFormat('MMM YYYY') }}</a>
    <input type="month" name="monat" value="{{ $monat->format('Y-m') }}" aria-label="Monat wählen" data-datum-nativ>
    <button type="submit" class="btn btn-primary btn-sm">Anzeigen</button>
    @unless($istAktuell)
    <a href="{{ route('admin.reports.monat', ['monat' => $monat->copy()->addMonthNoOverflow()->format('Y-m')]) }}" class="btn btn-ghost btn-sm" title="Folgemonat">{{ $monat->copy()->addMonthNoOverflow()->locale('de')->isoFormat('MMM YYYY') }} →</a>
    <a href="{{ route('admin.reports.monat') }}" class="btn btn-ghost btn-sm">Aktueller Monat</a>
    @endunless
</form>

{{-- Kennzahlen mit Vergleich zum Vormonat (Vergleichszahl steht immer dabei). --}}
<div class="mo-kz-reihe">
    @foreach([
        ['Abgeschlossene Verträge', $zahlen['vertraege'], $zahlen['vor_vertraege'], 'vertraege_neu'],
        ['Kunden mit Abschluss', $zahlen['kunden'], $zahlen['vor_kunden'], 'kunden_abschluss'],
        ['Neue Kunden angelegt', $zahlen['neukunden'], $zahlen['vor_neukunden'], 'neukunden'],
    ] as [$label, $wert, $vor, $liste])
    @php [$tText, $tKlasse] = $trend($wert, $vor); @endphp
    <a href="{{ route('admin.reports.liste', array_merge($mq, ['liste' => $liste])) }}" class="mo-kz">
        <div class="mo-kz-wert">{{ number_format($wert, 0, ',', '.') }}</div>
        <div class="mo-kz-label">{{ $label }}</div>
        <div class="mo-kz-trend {{ $tKlasse }}">{{ $tText }} <span class="muted-2xs">vs. Vormonat ({{ number_format($vor, 0, ',', '.') }})</span></div>
    </a>
    @endforeach
    <div class="mo-kz">
        <div class="mo-kz-wert">{{ number_format($zahlen['jahresbeitrag'], 2, ',', '.') }} €</div>
        <div class="mo-kz-label">Jahresbeitrag der Abschlüsse</div>
        <div class="muted-2xs">Beitrag der Kunden, nicht unser Ertrag</div>
    </div>
</div>

@if($zahlen['antraege'] > 0 || $zahlen['neukunden_ohne_vertrag'] > 0)
<div class="mo-hinweis">
    @if($zahlen['antraege'] > 0)
    <span>📝 {{ $zahlen['antraege'] }} der Abschlüsse sind noch <strong>Anträge</strong> (warten auf Bestätigung der Gesellschaft).</span>
    @endif
    @if($zahlen['neukunden_ohne_vertrag'] > 0)
    <a href="{{ route('admin.reports.liste', array_merge($mq, ['liste' => 'neukunden_ohne_vertrag'])) }}">👤 {{ $zahlen['neukunden_ohne_vertrag'] }} neue Kunden bisher ohne Vertrag →</a>
    @endif
</div>
@endif

@if($gekuerzt)
<div class="mo-hinweis mo-warnung">⚠️ Dieser Monat hat mehr Abschlüsse, als hier namentlich aufgelistet werden. Die Zahlen oben sind vollständig; die vollständige Liste öffnet „Als Liste öffnen“ je Sparte.</div>
@endif

{{-- Je Sparte: Zahlen + aufklappbare Namensliste. --}}
<div class="card card-flush">
    <table class="mo-tabelle">
        <thead>
            <tr>
                <th>Sparte</th>
                <th class="mo-zahl">Verträge</th>
                <th class="mo-zahl">Kunden</th>
                <th class="mo-zahl">davon Anträge</th>
                <th class="mo-zahl">Jahresbeitrag</th>
            </tr>
        </thead>
        <tbody>
        @forelse($sparten as $sp)
            <tr>
                <td colspan="5" class="mo-sparte-zelle">
                    <details class="mo-sparte">
                        <summary>
                            <span class="mo-sparte-name">{{ $sp['icon'] }} {{ $sp['label'] }}</span>
                            <span class="mo-zahl">{{ $sp['vertraege'] }}</span>
                            <span class="mo-zahl">{{ $sp['kunden'] }}</span>
                            <span class="mo-zahl">{{ $sp['antraege'] ?: '–' }}</span>
                            <span class="mo-zahl">{{ number_format($sp['jahresbeitrag'], 2, ',', '.') }} €</span>
                        </summary>
                        <div class="mo-namen">
                            <div class="mo-aktionen">
                                <a href="{{ route('admin.contracts', ['gruppe' => 'alle', 'herkunft' => 'eigen', 'sparte' => $sp['typ'], 'abschluss' => $monat->format('Y-m')]) }}" class="btn btn-ghost btn-sm">In Verträgen öffnen</a>
                                <a href="{{ route('admin.reports.liste', array_merge($mq, ['liste' => 'vertraege_neu', 'sparte' => $sp['typ']])) }}" class="btn btn-ghost btn-sm">Als Liste öffnen</a>
                                @if($darfExport)
                                <a href="{{ route('admin.reports.liste', array_merge($mq, ['liste' => 'vertraege_neu', 'sparte' => $sp['typ'], 'format' => 'xlsx'])) }}" class="btn btn-ghost btn-sm">⬇ Excel</a>
                                @endif
                            </div>
                            <table class="mo-namensliste">
                                <thead><tr><th>Kunde</th><th>Gesellschaft</th><th>Abschluss</th><th>Status</th></tr></thead>
                                <tbody>
                                @foreach($sp['zeilen'] as $c)
                                    @php $st = $c->displayStatus(); @endphp
                                    <tr>
                                        <td>
                                            @if($c->customer)
                                            <a href="{{ route('admin.customer', $c->customer_id) }}">{{ $c->customer->user?->name ?? $c->customer->customer_number }}</a>
                                            @if($c->customer->customer_number)<span class="muted-2xs">{{ $c->customer->customer_number }}</span>@endif
                                            @else
                                            <span class="muted-xs">—</span>
                                            @endif
                                        </td>
                                        <td><a href="{{ route('admin.contract.edit', $c->id) }}">{{ $c->insurer ?: '—' }}</a></td>
                                        <td class="nowrap">{{ $c->abschluss_datum ? \Carbon\Carbon::parse($c->abschluss_datum)->format('d.m.Y') : '—' }}</td>
                                        <td>
                                            <span class="badge badge-{{ $st['badge'] }} nowrap">{{ $st['label'] }}</span>
                                            @if($c->stage === \App\Models\Contract::STAGE_ANTRAG)<span class="muted-2xs nowrap">📝 Antrag</span>@endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--ink-soft);">Im {{ $monatName }} wurden keine Verträge abgeschlossen.</td></tr>
        @endforelse
        </tbody>
        @if($sparten !== [])
        <tfoot>
            <tr>
                <th>Gesamt</th>
                <th class="mo-zahl">{{ $zahlen['vertraege'] }}</th>
                <th class="mo-zahl">{{ $zahlen['kunden'] }}</th>
                <th class="mo-zahl">{{ $zahlen['antraege'] ?: '–' }}</th>
                <th class="mo-zahl">{{ number_format($zahlen['jahresbeitrag'], 2, ',', '.') }} €</th>
            </tr>
        </tfoot>
        @endif
    </table>
</div>
<p class="muted-2xs" style="margin-top:8px;">„Kunden“ zählt jeden Kunden einmal je Sparte; in „Gesamt“ einmal insgesamt – ein Kunde mit Kfz und Haftpflicht steht in beiden Sparten, aber nur einmal in der Summe.</p>

<style>
.rep-tab{padding:8px 16px;border-radius:999px;border:1px solid var(--line);background:var(--tiefe-hell);box-shadow:var(--tiefe-schatten);font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;white-space:nowrap;}
.rep-tab-active{background:var(--tiefe-graphit);color:#fff;border-color:transparent;box-shadow:var(--tiefe-schatten-aktiv);}
.mo-nav{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;}
.mo-nav input{padding:7px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:#fff;}
.mo-kz-reihe{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px;}
.mo-kz{display:block;background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px 16px;text-decoration:none;color:var(--ink);min-width:0;}
a.mo-kz:hover{border-color:var(--ink-soft);}
.mo-kz-wert{font-size:24px;font-weight:800;}
.mo-kz-label{font-size:12px;color:var(--ink-soft);margin-top:2px;}
.mo-kz-trend{font-size:12px;font-weight:700;margin-top:6px;}
.an-up{color:var(--status-success);}
.an-down{color:var(--status-danger);}
.mo-hinweis{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:12.5px;padding:10px 14px;border-radius:8px;margin-bottom:14px;background:var(--surface-soft);border:1px solid var(--line);}
.mo-warnung{background:#FEF3C7;border-color:#F0E0B0;color:#92400E;}
.mo-tabelle{width:100%;border-collapse:collapse;}
.mo-tabelle > thead th,.mo-tabelle > tfoot th{padding:10px 16px;text-align:left;font-size:12px;}
.mo-tabelle > tfoot th{border-top:2px solid var(--line);font-size:13px;}
.mo-zahl{text-align:right !important;white-space:nowrap;}
.mo-sparte-zelle{padding:0 !important;border-top:1px solid var(--line);}
.mo-sparte > summary{display:grid;grid-template-columns:minmax(0,1fr) repeat(4,110px);gap:8px;align-items:center;padding:12px 16px;cursor:pointer;list-style:none;font-size:14px;}
.mo-sparte > summary::-webkit-details-marker{display:none;}
.mo-sparte-name::before{content:'▸ ';color:var(--ink-soft);}
.mo-sparte[open] .mo-sparte-name::before{content:'▾ ';}
.mo-sparte > summary:hover{background:var(--surface-soft);}
.mo-sparte-name{font-weight:700;padding-left:4px;}
.mo-tabelle > thead th:not(:first-child),.mo-tabelle > tfoot th:not(:first-child){width:110px;}
.mo-namen{padding:4px 16px 16px;}
.mo-aktionen{display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 10px;}
.mo-namensliste{width:100%;font-size:13px;border-collapse:collapse;}
.mo-namensliste th{font-size:11.5px;color:var(--ink-soft);text-align:left;padding:6px 8px;border-bottom:1px solid var(--line);}
.mo-namensliste td{padding:7px 8px;border-bottom:1px solid var(--line);vertical-align:top;}
.mo-namensliste td .muted-2xs{display:block;}
@media (max-width:760px){
    .mo-kz-reihe{grid-template-columns:repeat(2,minmax(0,1fr));}
    .mo-sparte > summary{grid-template-columns:minmax(0,1fr) repeat(2,64px);}
    .mo-sparte > summary .mo-zahl:nth-of-type(4),.mo-sparte > summary .mo-zahl:nth-of-type(5){display:none;}
    .mo-tabelle > thead th:nth-child(n+4),.mo-tabelle > tfoot th:nth-child(n+4){display:none;}
    .mo-tabelle > thead th:not(:first-child),.mo-tabelle > tfoot th:not(:first-child){width:64px;}
    .mo-namensliste{display:block;overflow-x:auto;}
}
</style>
@endsection
