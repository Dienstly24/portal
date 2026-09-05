@extends('layouts.admin')
@section('content')
@include('admin.provisionsmanagement._layout', ['active' => 'dashboard', 'titel' => 'Dashboard',
    'untertitel' => 'Was wurde verdient, was wurde importiert, was fehlt, was muss geprüft werden?'])

@php
    $geld = fn ($w) => number_format((float) $w, 2, ',', '.') . ' €';
@endphp

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:22px;max-width:1250px;">
    @foreach([
        ['Provision aktueller Monat', $kpi['monat']],
        ['Provision letzter Monat', $kpi['vormonat']],
        ['Provision aktuelles Jahr', $kpi['jahr']],
        ['Provision Vorjahr', $kpi['vorjahr']],
    ] as [$titel, $zahlen])
    <div class="card" style="padding:14px 16px;">
        <div class="muted-2xs">{{ $titel }}</div>
        <div style="font-size:22px;font-weight:700;">{{ $geld($zahlen['netto']) }}</div>
        <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">
            {{ $zahlen['anzahl'] }} Buchungen · Storno {{ $geld($zahlen['storno']) }}
        </div>
    </div>
    @endforeach
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;max-width:1250px;">

    <div class="card">
        <h3 style="margin-top:0;">Verträge</h3>
        <table class="table" style="font-size:13px;">
            <tr><td>Neue Verträge (Monat)</td><td class="num-strong">{{ $kpi['vertraege']['neu_im_monat'] }}</td></tr>
            <tr><td>Vergütete Verträge</td><td class="num-strong">{{ $kpi['vertraege']['verguetet'] }}</td></tr>
            <tr><td>Nicht vergütet</td><td class="num-strong">{{ $kpi['vertraege']['nicht_verguetet'] }}</td></tr>
            <tr><td>Überfällig</td><td style="text-align:right;font-weight:700;color:#B5651D;">{{ $kpi['vertraege']['ueberfaellig'] }}</td></tr>
            <tr><td>Provision fehlt</td><td style="text-align:right;font-weight:700;color:#A32D2D;">{{ $kpi['vertraege']['fehlt'] }}</td></tr>
            <tr><td>Stornos</td><td class="num">{{ $kpi['vertraege']['storniert'] }}</td></tr>
            <tr><td>Korrekturen</td><td class="num">{{ $kpi['vertraege']['korrektur'] }}</td></tr>
        </table>
        <a href="{{ route('admin.provisionsmanagement.contracts') }}">Alle Verträge →</a>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Probleme</h3>
        <table class="table" style="font-size:13px;">
            <tr><td>Fehlende Provisionen</td><td style="text-align:right;font-weight:700;color:{{ $kpi['probleme']['fehlende'] > 0 ? '#A32D2D' : 'inherit' }};">{{ $kpi['probleme']['fehlende'] }}</td></tr>
            <tr><td>Provisionen ohne Vertrag</td><td class="num-strong">{{ $kpi['probleme']['unklare'] }}</td></tr>
            <tr><td>Unklarer Status</td><td class="num">{{ $kpi['probleme']['unklare_status'] }}</td></tr>
            <tr><td>Import-Entwürfe offen</td><td class="num">{{ $kpi['probleme']['entwuerfe'] }}</td></tr>
            <tr><td>Fehlerhafte Zeilen (gesamt)</td><td class="num">{{ $kpi['probleme']['importfehler'] }}</td></tr>
            <tr><td>Automatisch angelegte Kunden</td><td class="num">{{ $kpi['probleme']['neue_kunden'] }}</td></tr>
            <tr><td>Automatisch angelegte Verträge</td><td class="num">{{ $kpi['probleme']['neue_vertraege'] }}</td></tr>
        </table>
        <a href="{{ route('admin.provisionsmanagement.missing') }}">Fehlende Provisionen →</a>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Provision je Pool</h3>
        @if($pools === [])
            <p class="muted-sm">Noch keine Provision gebucht.</p>
        @else
        <table class="table" style="font-size:13px;">
            <tr><th>Pool</th><th class="num">Buchungen</th><th class="num">Netto</th></tr>
            @foreach($pools as $zeile)
            <tr>
                <td>{{ $zeile['label'] }}</td>
                <td class="num">{{ $zeile['anzahl'] }}</td>
                <td class="num-strong">{{ $geld($zeile['netto']) }}</td>
            </tr>
            @endforeach
        </table>
        @endif
    </div>
</div>

<div class="card" style="max-width:1250px;margin-top:16px;">
    <h3 style="margin-top:0;">Abgeschlossene Verträge gegen erhaltene Provisionen</h3>
    <p style="font-size:12.5px;color:var(--ink-soft);margin-top:0;">
        Je Abschlussmonat: wie viele Verträge sind vergütet, wie viele noch innerhalb der Frist des Pools,
        wie viele überfällig. Die Fristen stehen unter <a href="{{ route('admin.provisionsmanagement.settings') }}">Einstellungen</a>.
    </p>
    <table class="table" style="font-size:13px;">
        <tr><th>Monat</th><th class="num">Abgeschlossen</th><th class="num">Vergütet</th>
            <th class="num">In Frist</th><th class="num">Überfällig</th><th class="num">Fehlt</th></tr>
        @forelse($abgleich as $zeile)
        <tr>
            <td>{{ $zeile['monat'] }}</td>
            <td class="num">{{ $zeile['abgeschlossen'] }}</td>
            <td style="text-align:right;color:#1F7A4D;">{{ $zeile['verguetet'] }}</td>
            <td class="num">{{ $zeile['in_frist'] }}</td>
            <td style="text-align:right;color:#B5651D;">{{ $zeile['ueberfaellig'] }}</td>
            <td style="text-align:right;color:#A32D2D;">{{ $zeile['fehlt'] }}</td>
        </tr>
        @empty
        <tr><td colspan="6" class="muted">Noch keine Verträge mit Pool-Zuordnung.</td></tr>
        @endforelse
    </table>
</div>

<div class="card" style="max-width:1250px;margin-top:16px;">
    <h3 style="margin-top:0;">Letzte Importe</h3>
    <table class="table" style="font-size:13px;">
        <tr><th>Datei</th><th>Pool</th><th>Stand</th><th class="num">Zeilen</th><th>Datum</th><th></th></tr>
        @forelse($letzteImporte as $import)
        <tr>
            <td>{{ $import->filename }}</td>
            <td>{{ $import->poolLabel() }}</td>
            <td>{{ ucfirst($import->status) }}</td>
            <td class="num">{{ $import->rows_total }}</td>
            <td>{{ $import->created_at?->lokal()->format('d.m.Y H:i') }}</td>
            <td><a href="{{ route('admin.commissions_internal.preview', $import->id) }}">öffnen →</a></td>
        </tr>
        @empty
        <tr><td colspan="6" class="muted">Noch nichts importiert.</td></tr>
        @endforelse
    </table>
</div>
@endsection
