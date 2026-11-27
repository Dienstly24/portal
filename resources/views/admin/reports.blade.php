@extends('layouts.admin')
@push('charts')
{{-- Diese Seite zeichnet ein Diagramm - deshalb wird Chart.js hier
     ausdruecklich angefordert (Audit 15.09.2026). Es liegt lokal
     (DSGVO: keine Besucher-IP an ein CDN) und laedt nur noch auf den
     fuenf Seiten, die es wirklich brauchen. --}}
<script src="/js/chart.umd.min.js"></script>
@endpush

@section('content')
{{--
  BERICHTE & ANALYSEN (Betreiber-Auftrag 06.09.2026).

  AUFBAU IST RANGFOLGE, nicht Geschmack. Der erste Bildschirm traegt genau
  das, wonach der Betrieb zuerst fragt: wie viele Vertraege dieser Monat,
  wie viele dieses Jahr, wie viele neue Kunden, welcher Vertragswert - und
  darunter die Entwicklung ueber die Zeit. Alles Weitere (Sparten, Karte,
  Kundenstruktur, Status, Beobachtungen) kommt beim Scrollen.

  DIE SEITE RECHNET NICHT. Jede Zahl kommt fertig aus
  App\Services\Reporting\DashboardAnalyticsService - dort steht jede
  Definition genau einmal. Stuende die Rechnung in der Vorlage, meinte
  "aktiv" in der Kachel frueher oder spaeter etwas anderes als in der
  Statusliste, und niemand koennte sagen, welche der beiden Zahlen stimmt.

  ALLE ZAHLEN SIND ECHT. Es gibt auf dieser Seite keinen Beispielwert und
  keinen Platzhalter - ein Bericht, der eine erfundene Zahl zeigt, ist
  schlimmer als gar kein Bericht (Betreiber-Regel "keine erfundenen Daten").
  Wo eine Angabe fehlt, steht das ausdruecklich da.
--}}
@php
    $zahl = fn ($w, $n = 0) => number_format((float) $w, $n, ',', '.');
    // DRILL-DOWN (Berichte 2.0): jede Zahl fuehrt auf die Liste genau
    // dieser Datensaetze - mit derselben Filterauswahl wie die Seite.
    $listeUrl = fn (string $liste, array $filter = [], ?array $zeitraum = null) => route('admin.reports.liste', $f->listeQuery($liste, $filter, $zeitraum));
@endphp

<div class="page-header an-head">
    <div>
        <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.contracts') }}">Verträge</a><span class="breadcrumb-sep">›</span><span>Analyse</span></div>
        <h1 class="page-title">Verträge – Analyse</h1>
        <div class="page-sub">Leistungskennzahlen, Vertragsentwicklung und Kundenanalyse</div>
    </div>
</div>
@include('admin.partials.vertraege_bereich', ['aktiv' => 'analyse', 'analyseQuery' => $f->alsQuery()])

@include('admin.partials.analytics.filter_bar', ['f' => $f, 'auswahl' => $auswahl])

{{-- ============ 1. Kennzahlen ============ --}}
<div class="an-kpis">
    @foreach($daten['kpis'] as $kpi)
        @include('admin.partials.analytics.kpi_card', ['kpi' => $kpi, 'link' => $listeUrl($kpi['liste'], [], $kpi['liste_zeitraum'] ?? null)])
    @endforeach
</div>
<div class="muted-xs" style="margin:-14px 0 22px;line-height:1.55;">
    Zeitraum: <strong>{{ $daten['zeitraum']['von'] }} – {{ $daten['zeitraum']['bis'] }}</strong>,
    Vergleich: {{ $daten['zeitraum']['vergleich_von'] }} – {{ $daten['zeitraum']['vergleich_bis'] }}.
    „Diesen Monat“ und „Dieses Jahr“ tragen den Zeitraum im Namen und bleiben deshalb fest;
    die übrigen Kacheln folgen der Auswahl oben. Ein Vertrag zählt zum Zeitpunkt seines
    <strong>Abschlusses</strong> (Unterschrift, sonst Antrag, sonst Beginn, sonst Anlage) – nicht zum Tag des Imports.
    In Klammern steht die Zahl des Vergleichszeitraums. <strong>Jede Zahl lässt sich anklicken</strong> und zeigt die Kunden bzw. Verträge dahinter.
</div>

{{-- ============ 2. Vertragsentwicklung + Sparten ============ --}}
<div class="an-grid-haupt">
    <div class="an-card">
        <div class="an-card-head">
            <div>
                <div class="an-card-title">Vertragsentwicklung</div>
                <div class="an-card-sub">Neue, verlängerte und gekündigte Verträge je Periode – gestrichelt die Vorperiode</div>
            </div>
            <span class="an-seg">
                @foreach(\App\Services\Reporting\AnalyticsFilters::GRANULARITAETEN as $schluessel => $label)
                    <a href="{{ route('admin.reports', $f->alsQuery(['takt' => $schluessel])) }}"
                       class="{{ $f->granularitaet === $schluessel ? 'on' : '' }}">{{ $label }}</a>
                @endforeach
            </span>
        </div>
        <div class="an-chart-box"><canvas id="verlaufChart" aria-label="Vertragsentwicklung als Diagramm"></canvas></div>
    </div>

    <div class="an-card">
        <div class="an-card-head">
            <div>
                <div class="an-card-title">Verträge nach Sparte</div>
                <div class="an-card-sub">Aktiver Bestand – Anzahl und Anteil</div>
            </div>
        </div>
        <div class="an-bars">
            {{-- Der Baustein spricht 'wert' - der Dienst nennt die Sparten-
                 Zahl fachlich 'anzahl'. Uebersetzt wird hier, damit weder
                 die Fachsprache des Dienstes noch der Baustein-Vertrag
                 nachgeben muss. --}}
            @include('admin.partials.analytics.bar_list', [
                'eintraege' => collect($daten['sparten'])->map(fn ($s) => [
                    'label' => $s['label'], 'wert' => $s['anzahl'],
                    'anteil' => $s['anteil'], 'farbe' => $s['farbe'],
                    'link' => $listeUrl('aktiv', ['sparte' => $s['schluessel']]),
                ])->all(),
                'basis' => collect($daten['sparten'])->max('anzahl') ?: 1,
                'leer' => 'Keine aktiven Verträge im gewählten Filter.',
            ])
        </div>
    </div>
</div>

{{-- ============ 3. Kundenverteilung in Deutschland ============ --}}
<div class="an-card">
    <div class="an-card-head">
        <div>
            <div class="an-card-title">Kundenverteilung in Deutschland</div>
            <div class="an-card-sub">Farbintensität = Kunden je Bundesland. Ein Klick filtert die gesamte Seite auf dieses Land.</div>
        </div>
        <div class="muted-xs">
            <a class="an-zahl-link" href="{{ $listeUrl('kunden') }}">{{ $zahl($daten['karte']['gesamt']) }} Kunden auf der Karte</a>
            @if($daten['karte']['ohne_zuordnung'] > 0)
                · <a class="an-zahl-link" href="{{ $listeUrl('kunden', ['bundesland' => \App\Support\Bundesland::UNBEKANNT]) }}">{{ $zahl($daten['karte']['ohne_zuordnung']) }} ohne auswertbare PLZ</a>
            @endif
        </div>
    </div>
    <div class="an-grid-karte">
        <div class="an-karte-feld" id="karteFeld">
            @include('admin.partials.analytics.germany_map')
            <div class="an-karte-legende">
                <span>0</span>
                <span class="an-karte-skala" id="karteSkala"></span>
                <span>{{ $zahl($daten['karte']['max']) }} Kunden</span>
            </div>
            <div class="an-karte-quelle">
                Bundesland aus der Postleitzahl abgeleitet – an einzelnen Leitbereichsgrenzen näherungsweise.
                Anteile beziehen sich auf die {{ $zahl($daten['karte']['gesamt']) }} Kunden mit auswertbarer PLZ.
                @if($daten['karte']['ohne_zuordnung'] > 0)
                    <strong>{{ $zahl($daten['karte']['ohne_zuordnung']) }}</strong> Kunden ohne auswertbare PLZ sind nicht enthalten (Liste über den Link oben).
                @endif
                Kartenumrisse: <a href="https://www.npmjs.com/package/&#64;svg-maps/germany" rel="noopener">&#64;svg-maps/germany</a>, CC BY 4.0.
            </div>
        </div>
        <div>
            <div class="an-card-title" style="margin-bottom:10px;">Top Regionen</div>
            @forelse($daten['karte']['top'] as $i => $land)
                <a class="an-rang" href="{{ route('admin.reports', $f->alsQuery(['bundesland' => $land['kuerzel']])) }}"
                   title="Seite auf {{ $land['name'] }} filtern">
                    <span class="an-rang-nr">{{ $i + 1 }}.</span>
                    <span class="an-rang-mitte">
                        <span class="an-rang-name">{{ $land['name'] }}</span>
                        <span class="an-rang-spur"><span class="an-rang-fuellung" style="width:{{ $daten['karte']['max'] > 0 ? round($land['kunden'] / $daten['karte']['max'] * 100) : 0 }}%;"></span></span>
                    </span>
                    <span class="an-rang-wert">{{ $zahl($land['kunden']) }}<span>{{ $zahl($land['anteil'], 1) }} %</span></span>
                </a>
            @empty
                <div class="an-leer">Keine Kunden mit auswertbarer Postleitzahl.</div>
            @endforelse
        </div>
    </div>
</div>

{{-- ============ 3b. Städte + Sparten im Zeitraum (Berichte 2.0) ============ --}}
<div class="an-grid-zwei">
    <div class="an-card">
        <div class="an-card-head">
            <div>
                <div class="an-card-title">Top Städte</div>
                <div class="an-card-sub">Nach neuen Verträgen im Zeitraum – erfasster Wohnort, nicht aus der PLZ geraten</div>
            </div>
            <div class="muted-xs">
                {{ $zahl($daten['staedte']['anzahl_orte']) }} Orte
                @if($daten['staedte']['ohne_ort'] > 0)
                    · {{ $zahl($daten['staedte']['ohne_ort']) }} Kunden ohne Ort
                @endif
            </div>
        </div>
        @if($daten['staedte']['zeilen'] === [])
            <div class="an-leer">Keine Kunden mit erfasstem Ort im gewählten Filter.</div>
        @else
        <div class="scroll-x">
        <table class="an-tabelle">
            <thead><tr><th>Stadt</th><th class="z">Kunden</th><th class="z">Neukunden</th><th class="z">Kunden mit Abschluss</th><th class="z">Neue Verträge</th><th class="z">Vertragswert</th></tr></thead>
            <tbody>
            @foreach($daten['staedte']['zeilen'] as $stadt)
                <tr>
                    <td><a href="{{ route('admin.reports', $f->alsQuery(['stadt' => $stadt['name']])) }}" title="Ganze Seite auf {{ $stadt['name'] }} filtern" style="font-weight:600;">{{ $stadt['name'] }}</a></td>
                    <td class="z"><a class="an-zahl-link" href="{{ $listeUrl('kunden', ['stadt' => $stadt['name']]) }}">{{ $zahl($stadt['kunden']) }}</a></td>
                    <td class="z"><a class="an-zahl-link" href="{{ $listeUrl('neukunden', ['stadt' => $stadt['name']]) }}">{{ $zahl($stadt['neukunden']) }}</a></td>
                    <td class="z"><a class="an-zahl-link" href="{{ $listeUrl('kunden_abschluss', ['stadt' => $stadt['name']]) }}">{{ $zahl($stadt['kunden_abschluss']) }}</a></td>
                    <td class="z"><a class="an-zahl-link" href="{{ $listeUrl('vertraege_neu', ['stadt' => $stadt['name']]) }}">{{ $zahl($stadt['neue_vertraege']) }}</a></td>
                    <td class="z">{{ $zahl($stadt['wert']) }} €</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>

    <div class="an-card">
        <div class="an-card-head">
            <div>
                <div class="an-card-title">Abschlüsse nach Sparte</div>
                <div class="an-card-sub">Neue Verträge im Zeitraum – Verträge, verschiedene Kunden und Jahresbeitrag</div>
            </div>
        </div>
        @if($daten['sparten_zeitraum'] === [])
            <div class="an-leer">Keine Abschlüsse im gewählten Zeitraum.</div>
        @else
        <div class="scroll-x">
        <table class="an-tabelle">
            <thead><tr><th>Sparte</th><th class="z">Verträge</th><th class="z">Kunden</th><th class="z">Vertragswert</th></tr></thead>
            <tbody>
            @foreach($daten['sparten_zeitraum'] as $sp)
                <tr>
                    <td><span class="an-status-punkt" style="display:inline-block;background:{{ $sp['farbe'] }};margin-right:6px;"></span>{{ $sp['label'] }}</td>
                    <td class="z"><a class="an-zahl-link" href="{{ $listeUrl('vertraege_neu', ['sparte' => $sp['schluessel']]) }}">{{ $zahl($sp['anzahl']) }}</a></td>
                    <td class="z"><a class="an-zahl-link" href="{{ $listeUrl('kunden_abschluss', ['sparte' => $sp['schluessel']]) }}">{{ $zahl($sp['kunden']) }}</a></td>
                    <td class="z">{{ $zahl($sp['wert']) }} €</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>
</div>

{{-- ============ 3c. Mitarbeiter/Vermittler + Gesellschaften ============ --}}
<div class="an-grid-zwei">
    <div class="an-card">
        <div class="an-card-head">
            <div>
                <div class="an-card-title">Mitarbeiter &amp; Vermittler</div>
                <div class="an-card-sub">Nach gespeichertem Werber des Kunden – dieselbe Zuordnung wie bei den Provisionen</div>
            </div>
        </div>
        @if($daten['werber'] === [])
            <div class="an-leer">Keine Abschlüsse und keine Neukunden im gewählten Zeitraum.</div>
        @else
        <div class="scroll-x">
        <table class="an-tabelle">
            <thead><tr><th>Name</th><th class="z">Neukunden</th><th class="z">Kunden mit Abschluss</th><th class="z">Neue Verträge</th><th class="z">Vertragswert</th></tr></thead>
            <tbody>
            @foreach($daten['werber'] as $w)
                <tr>
                    <td><a href="{{ route('admin.reports', $f->alsQuery(['werber' => $w['schluessel']])) }}" title="Ganze Seite auf {{ $w['name'] }} filtern" style="font-weight:600;">{{ $w['name'] }}</a>@if($w['art'])<div class="an-quelle">{{ $w['art'] }}</div>@endif</td>
                    <td class="z"><a class="an-zahl-link" href="{{ $listeUrl('neukunden', ['werber' => $w['schluessel']]) }}">{{ $zahl($w['neukunden']) }}</a></td>
                    <td class="z"><a class="an-zahl-link" href="{{ $listeUrl('kunden_abschluss', ['werber' => $w['schluessel']]) }}">{{ $zahl($w['kunden_abschluss']) }}</a></td>
                    <td class="z"><a class="an-zahl-link" href="{{ $listeUrl('vertraege_neu', ['werber' => $w['schluessel']]) }}">{{ $zahl($w['neue_vertraege']) }}</a></td>
                    <td class="z">{{ $zahl($w['wert']) }} €</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>

    <div class="an-card">
        <div class="an-card-head">
            <div>
                <div class="an-card-title">Gesellschaften</div>
                <div class="an-card-sub">Neue Verträge im Zeitraum je Versicherer/Versorger</div>
            </div>
        </div>
        @if($daten['gesellschaften'] === [])
            <div class="an-leer">Keine Abschlüsse im gewählten Zeitraum.</div>
        @else
        <div class="scroll-x">
        <table class="an-tabelle">
            <thead><tr><th>Gesellschaft</th><th class="z">Verträge</th><th class="z">Kunden</th><th class="z">Vertragswert</th></tr></thead>
            <tbody>
            @foreach(array_slice($daten['gesellschaften'], 0, 15) as $g)
                <tr>
                    <td>
                        @if($g['name'] !== '')
                            <a href="{{ route('admin.reports', $f->alsQuery(['gesellschaft' => $g['name']])) }}" style="font-weight:600;">{{ $g['name'] }}</a>
                        @else
                            <span class="muted-xs">Ohne Gesellschaft</span>
                        @endif
                    </td>
                    <td class="z">
                        @if($g['name'] !== '')
                            <a class="an-zahl-link" href="{{ $listeUrl('vertraege_neu', ['gesellschaft' => $g['name']]) }}">{{ $zahl($g['anzahl']) }}</a>
                        @else
                            {{ $zahl($g['anzahl']) }}
                        @endif
                    </td>
                    <td class="z">{{ $zahl($g['kunden']) }}</td>
                    <td class="z">{{ $zahl($g['wert']) }} €</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @if(count($daten['gesellschaften']) > 15)
            <div class="muted-xs" style="margin-top:10px;">{{ count($daten['gesellschaften']) - 15 }} weitere Gesellschaften – über den Filter „Gesellschaft“ einzeln auswertbar.</div>
        @endif
        @endif
    </div>
</div>

{{-- ============ 4. Kundenstruktur / Vertragsstatus / Vorgänge ============ --}}
<div class="an-grid-drei">
    <div class="an-card">
        <div class="an-card-head">
            <div>
                <div class="an-card-title">Kundenstruktur</div>
                <div class="an-card-sub">{{ $zahl($daten['kunden']['gesamt']) }} Kunden im Zugriff – Anteile beziehen sich auf diese Zahl</div>
            </div>
            {{-- Der Weg zum Neukunden-Bericht war auf der Vorgaengerseite eine
                 Kachel; er bleibt erhalten, nur an der passenden Karte. --}}
            <a class="card-link" href="{{ route('admin.reports.neukunden', ['from' => $f->von->format('Y-m-d'), 'to' => $f->bis->format('Y-m-d')]) }}">Neukunden-Bericht →</a>
        </div>
        <div class="an-bars">
            @include('admin.partials.analytics.bar_list', [
                'eintraege' => collect($daten['kunden']['gruppen'])->map(fn ($g) => [
                    'label' => $g['label'],
                    'wert' => $g['wert'],
                    'anteil' => $g['basis'] > 0 ? round($g['wert'] / $g['basis'] * 100, 1) : 0,
                    'farbe' => $g['schluessel'] === 'firma' ? 'var(--status-info)' : ($g['schluessel'] === 'neu' ? 'var(--gold)' : 'var(--emerald)'),
                    'link' => $listeUrl($g['liste'], $g['filter']),
                ])->all(),
                'basis' => max(1, $daten['kunden']['gesamt']),
                'leer' => 'Keine Kunden im gewählten Filter.',
            ])
        </div>
        @if($daten['kunden']['ohne_typ'] > 0)
            <div class="muted-xs" style="margin-top:12px;">
                Bei {{ $zahl($daten['kunden']['ohne_typ']) }} Kunden ist kein Kundentyp erfasst – sie zählen weder zu privat noch zu Firma.
            </div>
        @endif
    </div>

    <div class="an-card">
        <div class="an-card-head">
            <div>
                <div class="an-card-title">Vertragsstatus</div>
                <div class="an-card-sub">Bestand und Bewegung im Zeitraum</div>
            </div>
        </div>
        @foreach($daten['status']['zeilen'] as $zeile)
            <a class="an-status-zeile" href="{{ $listeUrl($zeile['liste']) }}" title="Liste öffnen: {{ $zeile['label'] }}">
                <span class="an-status-name"><span class="an-status-punkt an-punkt-{{ $zeile['ton'] }}"></span>{{ $zeile['label'] }}</span>
                <span class="an-status-wert">{{ $zahl($zeile['wert']) }}</span>
                <span class="an-status-hint">{{ $zeile['hinweis'] }}</span>
            </a>
        @endforeach
        <div class="muted-xs" style="margin-top:10px;">{{ $daten['status']['erklaerung'] }}</div>
    </div>

    <div class="an-card">
        <div class="an-card-head">
            <div>
                <div class="an-card-title">Vorgänge</div>
                <div class="an-card-sub">Tickets im gewählten Zeitraum</div>
            </div>
        </div>
        <div class="an-bars">
            @include('admin.partials.analytics.bar_list', [
                'eintraege' => $tickets['zeilen'],
                'basis' => max(1, $tickets['gesamt']),
                'leer' => 'Keine Vorgänge im gewählten Zeitraum.',
            ])
        </div>
        <div class="muted-xs" style="margin-top:12px;">{{ $zahl($tickets['gesamt']) }} Vorgänge insgesamt im Zeitraum.</div>
    </div>
</div>

{{-- ============ 5. Insights ============ --}}
@if($daten['insights'] !== [])
<div class="an-card">
    <div class="an-card-head">
        <div>
            <div class="an-card-title">Insights</div>
            <div class="an-card-sub">Beobachtungen aus den Zahlen dieser Seite – jede lässt sich oben nachzählen</div>
        </div>
    </div>
    <div class="an-insights">
        @foreach($daten['insights'] as $insight)
            @if(! empty($insight['liste']))
                <a class="an-insight" href="{{ $listeUrl($insight['liste'], $insight['filter'] ?? []) }}" title="Liste dazu öffnen">
                    <span class="an-insight-strich an-strich-{{ $insight['ton'] }}"></span>
                    <span>{{ $insight['text'] }}</span>
                </a>
            @else
                <div class="an-insight">
                    <span class="an-insight-strich an-strich-{{ $insight['ton'] }}"></span>
                    <span>{{ $insight['text'] }}</span>
                </div>
            @endif
        @endforeach
    </div>
</div>
@endif

{{-- ============ 6. Auslaufende Verträge (Handlungsliste) ============ --}}
<div class="an-card" id="auslaufend" style="padding:0;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 22px;border-bottom:1px solid var(--line);flex-wrap:wrap;">
        <div>
            <div class="an-card-title">Auslaufende Verträge</div>
            <div class="an-card-sub">Aktive Verträge mit Ablauf in den nächsten 30 Tagen</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            {{-- F2 (Befund 25.11.2026): "8 überfällig" stand da, die Tabelle
                 zeigte aber nur die zwei auslaufenden. Die ueberfaelligen
                 haben jetzt eine eigene Liste. --}}
            @if($warnings > 0)
                <a class="badge badge-danger" href="{{ route('admin.reports.liste', ['liste' => 'ueberfaellig']) }}" title="Überfällige Verträge anzeigen">{{ $zahl($warnings) }} überfällig →</a>
            @endif
            <a class="card-link" href="{{ route('admin.reports.liste', ['liste' => 'auslaufend']) }}">Alle auslaufenden →</a>
        </div>
    </div>
    <div class="scroll-x">
    <table>
        <thead><tr style="background:var(--surface-soft);">
            <th style="padding:12px 22px;">Kunde</th>
            <th>Gesellschaft</th>
            <th>Sparte</th>
            <th>Ablaufdatum</th>
            <th>Verbleibend</th>
            <th></th>
        </tr></thead>
        <tbody>
        @if($expiringTotal > $expiring->count())
            <tr><td colspan="6" class="muted-xs" style="padding:10px 22px;background:var(--surface-soft);">
                {{ $zahl($expiringTotal) }} Verträge laufen in den nächsten 30 Tagen ab –
                gezeigt werden die {{ $expiring->count() }} mit dem frühesten Ablauf.
            </td></tr>
        @endif
        @forelse($expiring as $c)
            @php $days = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($c->end_date), false); @endphp
            <tr class="row-link" data-row-nav="{{ route('admin.contract.edit', $c->id) }}" title="Vertrag öffnen">
                <td style="padding:13px 22px;font-weight:600;">{{ $c->customer?->user?->name ?? '—' }}</td>
                <td>{{ $c->insurer }}</td>
                <td>{{ \App\Models\Contract::TYPES[$c->type]['label'] ?? \App\Models\Contract::LEGACY_TYPES[$c->type]['label'] ?? $c->type }}</td>
                <td>{{ \Carbon\Carbon::parse($c->end_date)->format('d.m.Y') }}</td>
                <td>
                    <span class="badge {{ $days <= 7 ? 'badge-danger' : ($days <= 14 ? 'badge-pending' : 'badge-open') }}">
                        {{ $days }} Tage
                    </span>
                </td>
                <td style="padding-right:22px;">
                    <a href="{{ route('admin.customer', $c->customer_id) }}" class="btn btn-ghost btn-sm">Kunde</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="an-leer" style="text-align:center;">Keine ablaufenden Verträge in den nächsten 30 Tagen.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<style>
/* Reiter der Berichtsseite - eine Seite, deshalb hier statt in components.css. */
.rep-tab { padding:8px 16px; border-radius:999px; border:1px solid var(--line); background:var(--tiefe-hell);box-shadow:var(--tiefe-schatten);transition:transform .08s,box-shadow .12s,border-color .15s; font-size:13px; font-weight:600; color:var(--ink); text-decoration:none; white-space:nowrap; }
.rep-tab:hover{transform:translateY(-1px);box-shadow:var(--tiefe-schatten-hover);border-color:var(--ink-soft);}
.rep-tab:active{transform:translateY(1px);box-shadow:var(--tiefe-gedrueckt);}
.rep-tab-active{background:var(--tiefe-graphit);color:#fff;border-color:transparent;box-shadow:var(--tiefe-schatten-aktiv);}
</style>

<script @cspNonce>
/*
 * Diagramme, Karte und die Sofort-Wirkung der Filter.
 *
 * ALLE DATEN KOMMEN AUS DEM SERVER-ERGEBNIS - dieses Skript rechnet
 * nichts nach und kennt keine Zahl, die nicht oben auch im HTML steht.
 * Es zeichnet nur.
 */
(function () {
    const verlauf = @json($daten['verlauf']);
    const karte = @json($daten['karte']['laender']);
    const karteMax = @json($daten['karte']['max']);
    const karteLinks = @json($karteLinks);

    // OHNE Ersatz-Hex: die Markenfarbe steht seit UX-1 an genau EINER Stelle
    // (resources/css/brand.css). Ein zweites Argument mit demselben Wert waere
    // eine Kopie, die beim naechsten Markenwechsel stehen bleibt - und ein
    // Ersatzwert greift ohnehin nur, wenn das Stylesheet fehlt; dann ist die
    // Seite nicht "fast richtig gefaerbt", sondern ungestaltet.
    const linie = brandColor('line');
    const tinte = brandColor('ink-soft');
    const farben = {
        neu: brandColor('emerald'),
        verlaengert: brandColor('status-info'),
        gekuendigt: brandColor('status-danger'),
    };

    // ---------- Vertragsentwicklung ----------
    const flaeche = document.getElementById('verlaufChart');
    if (flaeche && window.Chart) {
        const saeulen = verlauf.reihen.map(function (r) {
            return {
                label: r.label, data: r.werte, backgroundColor: farben[r.schluessel],
                borderRadius: 4, borderWidth: 0, borderSkipped: false, maxBarThickness: 26, order: 2,
            };
        });
        new Chart(flaeche, {
            data: {
                labels: verlauf.labels,
                datasets: saeulen.map(function (s) { return Object.assign({ type: 'bar' }, s); }).concat([{
                    type: 'line',
                    label: verlauf.vergleich.label,
                    data: verlauf.vergleich.werte,
                    borderColor: tinte, borderDash: [5, 4], borderWidth: 1.6,
                    pointRadius: 0, pointHoverRadius: 4, tension: .3, fill: false, order: 1,
                }]),
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 12 } } },
                    tooltip: { backgroundColor: brandColor('graphite'), padding: 10, cornerRadius: 8, boxPadding: 4 },
                },
                scales: {
                    x: { stacked: false, grid: { display: false }, ticks: { font: { size: 11.5 }, color: tinte } },
                    y: { beginAtZero: true, border: { display: false }, grid: { color: linie }, ticks: { precision: 0, font: { size: 11.5 }, color: tinte } },
                },
            },
        });
    }

    // ---------- Deutschlandkarte ----------
    // Einfaerbung als Anteil am Hoechstwert. Ein Land OHNE Kunden bleibt
    // bewusst hell und wird nicht eingefaerbt: eine schwache Toenung waere
    // von "wenige Kunden" nicht zu unterscheiden.
    const feld = document.getElementById('karteFeld');
    if (feld) {
        const smaragd = brandColor('emerald');
        const flaechen = feld.querySelectorAll('.de-land');
        const skala = document.getElementById('karteSkala');
        if (skala) {
            skala.style.background = 'linear-gradient(90deg, ' + mischen(smaragd, 0.06) + ', ' + smaragd + ')';
        }

        let tip = null;
        flaechen.forEach(function (pfad) {
            const daten = karte[pfad.dataset.land];
            if (!daten) { return; }
            if (daten.kunden > 0) {
                pfad.style.fill = mischen(smaragd, 0.14 + 0.86 * (daten.kunden / karteMax));
            }
            pfad.addEventListener('mouseenter', function () { zeigen(pfad, daten); });
            pfad.addEventListener('focus', function () { zeigen(pfad, daten); });
            pfad.addEventListener('mouseleave', verstecken);
            pfad.addEventListener('blur', verstecken);
            const ziel = karteLinks[pfad.dataset.land];
            if (ziel) {
                pfad.addEventListener('click', function () { window.location.href = ziel; });
                pfad.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); window.location.href = ziel; }
                });
            }
        });

        function zeigen(pfad, daten) {
            verstecken();
            const kasten = pfad.getBoundingClientRect();
            const rahmen = feld.getBoundingClientRect();
            tip = document.createElement('div');
            tip.className = 'an-tip';
            tip.appendChild(zeile('an-tip-name', daten.name, null));
            tip.appendChild(zeile('an-tip-zeile', 'Kunden', zahl(daten.kunden)));
                        // "im Zeitraum" gehoert an die Beschriftung: die drei unteren
            // Werte folgen der Zeitraumauswahl, die Kundenzahl nicht. Ohne
            // den Zusatz liest man eine 0 als "hier laeuft nichts".
            tip.appendChild(zeile('an-tip-zeile', 'Neue Verträge (Zeitraum)', zahl(daten.neue_vertraege)));
            tip.appendChild(zeile('an-tip-zeile', 'Verlängerungen (Zeitraum)', zahl(daten.verlaengerungen)));
            tip.appendChild(zeile('an-tip-zeile', 'Vertragswert (Zeitraum)', zahl(Math.round(daten.wert)) + ' €'));
            tip.style.left = (kasten.left - rahmen.left + kasten.width / 2) + 'px';
            tip.style.top = (kasten.top - rahmen.top) + 'px';
            feld.appendChild(tip);
            pfad.classList.add('aktiv');
        }

        function verstecken() {
            if (tip) { tip.remove(); tip = null; }
            flaechen.forEach(function (p) { p.classList.remove('aktiv'); });
        }

        // Per textContent gebaut, nie per HTML-Zeichenkette: die Namen
        // stammen aus dem Server-Ergebnis, aber die Regel gilt hier wie
        // ueberall - Werte werden gesetzt, nicht zusammengeklebt.
        function zeile(klasse, name, wert) {
            const el = document.createElement('div');
            el.className = klasse;
            if (wert === null) { el.textContent = name; return el; }
            const links = document.createElement('span');
            links.textContent = name;
            const rechts = document.createElement('b');
            rechts.textContent = wert;
            el.appendChild(links); el.appendChild(rechts);
            return el;
        }
    }

    // ---------- Sachfilter wirken sofort ----------
    const sachfilter = document.getElementById('anSachfilter');
    if (sachfilter) {
        sachfilter.querySelectorAll('select').forEach(function (s) {
            s.addEventListener('change', function () { sachfilter.submit(); });
        });
    }

    function zahl(w) { return new Intl.NumberFormat('de-DE').format(w || 0); }

    /** Markenfarbe mit dem Kartenuntergrund mischen (0 = weiss, 1 = voll). */
    function mischen(hex, anteil) {
        const h = hex.replace('#', '');
        const r = parseInt(h.substring(0, 2), 16);
        const g = parseInt(h.substring(2, 4), 16);
        const b = parseInt(h.substring(4, 6), 16);
        const m = function (c) { return Math.round(255 - (255 - c) * Math.min(1, Math.max(0, anteil))); };
        return 'rgb(' + m(r) + ',' + m(g) + ',' + m(b) + ')';
    }
})();
</script>
@endsection
