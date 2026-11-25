@extends('layouts.admin')

@section('content')
{{--
  DETAILLISTE hinter einer Zahl der Berichtsseite (Berichte 2.0, 25.11.2026).

  "Neue Kunden: 71" -> genau diese 71 Kunden. Die Zeilen kommen aus
  derselben Abfrage, mit der die Kachel zaehlt (DashboardAnalyticsService::
  liste), die Spalten aus App\Services\Reporting\BerichtsListe - dieselben
  Werte gehen in den Excel-/CSV-Export. Die Vorlage rechnet nichts.
--}}
@php
    $zahl = fn ($w, $n = 0) => number_format((float) $w, $n, ',', '.');
    $extra = ['liste' => $liste];
    $sortLink = function (string $spalte, string $label) use ($f, $liste, $sortierung, $richtung, $suche) {
        $aktiv = $sortierung === $spalte;
        $neu = $aktiv && $richtung === 'desc' ? 'asc' : 'desc';
        $url = route('admin.reports.liste', $f->listeQuery($liste) + array_filter(['q' => $suche, 'sort' => $spalte, 'dir' => $neu]));

        return '<a href="'.e($url).'">'.e($label).($aktiv ? ($richtung === 'desc' ? ' ↓' : ' ↑') : '').'</a>';
    };
    $exportUrl = fn (string $format) => route('admin.reports.liste', $f->listeQuery($liste) + array_filter(['q' => $suche, 'sort' => $sortierung ?: null, 'dir' => $richtung, 'format' => $format]));
@endphp

<div class="page-header an-head">
    <div>
        <div class="breadcrumb">
            <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
            <a href="{{ route('admin.reports', $f->alsQuery()) }}">Berichte &amp; Analysen</a><span class="breadcrumb-sep">›</span>
            <span>{{ $info['titel'] }}</span>
        </div>
        <h1 class="page-title">{{ $info['titel'] }}: {{ $zahl($gesamt) }}</h1>
        <div class="page-sub">{{ $f->zeitraumText() }} · {{ $info['erklaerung'] }}</div>
    </div>
    <div class="an-liste-aktionen" style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="{{ route('admin.reports', $f->alsQuery()) }}" class="btn btn-ghost btn-sm">← Zur Übersicht</a>
        @if($darfExport)
            <a href="{{ $exportUrl('xlsx') }}" class="btn btn-primary btn-sm">Excel</a>
            <a href="{{ $exportUrl('csv') }}" class="btn btn-ghost btn-sm">CSV</a>
        @endif
        <button type="button" class="btn btn-ghost btn-sm" data-h-click="berichtDrucken">Drucken</button>
    </div>
</div>

@include('admin.partials.analytics.filter_bar', ['f' => $f, 'ziel' => 'admin.reports.liste', 'extra' => $extra, 'auswahl' => $auswahl])

<div class="an-card" style="padding:0;overflow:hidden;">
    <div class="an-liste-kopf">
        <form method="GET" action="{{ route('admin.reports.liste') }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            @foreach($f->listeQuery($liste) as $k => $v)
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach
            <input type="search" name="q" value="{{ $suche }}" class="an-date" style="width:260px;"
                   placeholder="{{ $bericht->art() === 'vertrag' ? 'Name, Kundennr., Vertragsnr., Gesellschaft' : 'Name, Kundennr., Ort, E-Mail' }}" aria-label="Liste durchsuchen">
            <button type="submit" class="btn btn-ghost btn-sm">Suchen</button>
            @if($suche)
                <a class="an-reset" href="{{ route('admin.reports.liste', $f->listeQuery($liste)) }}">Suche löschen</a>
            @endif
        </form>
        <div class="muted-xs">
            @if($suche)
                {{ $zahl($seite->total()) }} von {{ $zahl($gesamt) }} Einträgen passen zur Suche.
            @else
                {{ $zahl($gesamt) }} Einträge – entspricht der Zahl auf der Übersicht.
            @endif
        </div>
    </div>

    <div class="scroll-x">
    @if($bericht->art() === 'vertrag')
        <table class="an-tabelle">
            <thead><tr>
                <th style="padding-left:18px;">Kunde</th>
                <th>{!! $sortLink('sparte', 'Sparte') !!}</th>
                <th>{!! $sortLink('gesellschaft', 'Gesellschaft') !!}</th>
                <th>Vertrags-/Ref.-Nr.</th>
                <th>{!! $sortLink('ort', 'Ort') !!}</th>
                <th>{!! $sortLink('abschluss', 'Abschluss') !!}</th>
                <th>{!! $sortLink('beginn', 'Beginn') !!}</th>
                <th>{!! $sortLink('ablauf', 'Ablauf') !!}</th>
                <th>Status</th>
                <th class="z">{!! $sortLink('beitrag', 'Jahresbeitrag') !!}</th>
                <th style="padding-right:18px;">Mitarbeiter/Vermittler</th>
            </tr></thead>
            <tbody>
            @forelse($seite as $z)
                <tr>
                    <td style="padding-left:18px;">
                        <a href="{{ route('admin.customer', $z['kunde_id']) }}" style="font-weight:600;">{{ $z['kunde'] ?: '—' }}</a>
                        <div class="an-quelle">{{ $z['kundennummer'] }}@if($z['kundentyp']) · {{ $z['kundentyp'] }}@endif</div>
                    </td>
                    <td>{{ $z['sparte'] }}</td>
                    <td>{{ $z['gesellschaft'] ?: '—' }}</td>
                    <td>
                        <a href="{{ route('admin.contract.edit', $z['id']) }}">{{ $z['vertragsnummer'] ?: 'Vertrag öffnen' }}</a>
                        @if($z['referenz'])<div class="an-quelle">Ref. {{ $z['referenz'] }}</div>@endif
                    </td>
                    <td>{{ trim($z['plz'].' '.$z['ort']) ?: '—' }}@if($z['bundesland'])<div class="an-quelle">{{ $z['bundesland'] }}</div>@endif</td>
                    <td>{{ $z['abschluss'] }}<div class="an-quelle">{{ $z['datumsquelle'] }}</div></td>
                    <td>{{ $z['beginn'] ?: '—' }}</td>
                    <td>{{ $z['ablauf'] ?: '—' }}</td>
                    <td>{{ $z['status'] }}</td>
                    <td class="z">{{ $z['jahresbeitrag'] > 0 ? $zahl($z['jahresbeitrag'], 2).' €' : '—' }}@if($z['zahlweise'])<div class="an-quelle">{{ $zahl($z['beitrag'], 2) }} € {{ $z['zahlweise'] }}</div>@endif</td>
                    <td style="padding-right:18px;">{{ $z['werber'] ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="an-leer">Keine Verträge für diese Auswahl.</td></tr>
            @endforelse
            </tbody>
        </table>
    @else
        <table class="an-tabelle">
            <thead><tr>
                <th style="padding-left:18px;">{!! $sortLink('nummer', 'Kundennr.') !!}</th>
                <th>Kunde</th>
                <th>{!! $sortLink('plz', 'PLZ') !!}</th>
                <th>{!! $sortLink('ort', 'Ort') !!}</th>
                <th>Bundesland</th>
                <th>{!! $sortLink('angelegt', 'Angelegt am') !!}</th>
                <th class="z">Verträge gesamt</th>
                <th class="z">Abschlüsse im Zeitraum</th>
                <th>Sparten im Zeitraum</th>
                <th class="z">Jahresbeitrag im Zeitraum</th>
                <th style="padding-right:18px;">Mitarbeiter/Vermittler</th>
            </tr></thead>
            <tbody>
            @forelse($seite as $z)
                <tr>
                    <td style="padding-left:18px;">{{ $z['kundennummer'] ?: '—' }}</td>
                    <td><a href="{{ route('admin.customer', $z['kunde_id']) }}" style="font-weight:600;">{{ $z['kunde'] ?: '—' }}</a>@if($z['kundentyp'])<div class="an-quelle">{{ $z['kundentyp'] }}</div>@endif</td>
                    <td>{{ $z['plz'] ?: '—' }}</td>
                    <td>{{ $z['ort'] ?: '—' }}</td>
                    <td>{{ $z['bundesland'] ?: '—' }}</td>
                    <td>{{ $z['angelegt'] }}</td>
                    <td class="z">{{ $zahl($z['vertraege_gesamt']) }}</td>
                    <td class="z">{{ $zahl($z['abschluesse']) }}</td>
                    <td>{{ $z['sparten'] ?: '—' }}</td>
                    <td class="z">{{ $z['wert'] > 0 ? $zahl($z['wert'], 2).' €' : '—' }}</td>
                    <td style="padding-right:18px;">{{ $z['werber'] ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="an-leer">Keine Kunden für diese Auswahl.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif
    </div>
</div>
<div style="margin-top:16px;">{{ $seite->links() }}</div>

<script @cspNonce>
(function () {
    window.__h = window.__h || {};
    window.__h["berichtDrucken"] = function () { window.print(); };
    const sachfilter = document.getElementById('anSachfilter');
    if (sachfilter) {
        sachfilter.querySelectorAll('select').forEach(function (s) {
            s.addEventListener('change', function () { sachfilter.submit(); });
        });
    }
})();
</script>
@endsection
