@extends('layouts.admin')
@section('content')
@include('admin.provisionsmanagement._layout', ['active' => 'auswertungen', 'titel' => 'Auswertungen',
    'untertitel' => 'Provision nach Zeitraum, Pool, Produkt, Art und Kunde.'])

@php $geld = fn ($w) => number_format((float) $w, 2, ',', '.') . ' €'; @endphp

<div class="card" style="max-width:1250px;margin-bottom:16px;">
    <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">
        <div class="field" style="margin:0;"><label>Von</label><input type="date" name="von" value="{{ $filters['von'] ?? '' }}"></div>
        <div class="field" style="margin:0;"><label>Bis</label><input type="date" name="bis" value="{{ $filters['bis'] ?? '' }}"></div>
        <div class="field" style="margin:0;">
            <label>Pool</label>
            <select name="pool"><option value="">Alle</option>
                @foreach($poolListe as $key => $pool)
                <option value="{{ $key }}" @selected(($filters['pool'] ?? '') === $key)>{{ $pool->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn" type="submit">Auswerten</button>
        <a class="btn" href="{{ route('admin.provisionsmanagement.export', request()->query()) }}">Export (CSV)</a>
    </form>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:20px;max-width:1250px;">
    <div class="card" style="padding:14px 16px;"><div class="muted-2xs">Buchungen</div><div style="font-size:22px;font-weight:700;">{{ $summen['anzahl'] }}</div></div>
    <div class="card" style="padding:14px 16px;"><div class="muted-2xs">Brutto</div><div style="font-size:22px;font-weight:700;">{{ $geld($summen['brutto']) }}</div></div>
    <div class="card" style="padding:14px 16px;"><div class="muted-2xs">Storno</div><div style="font-size:22px;font-weight:700;color:#A32D2D;">{{ $geld($summen['storno']) }}</div></div>
    <div class="card" style="padding:14px 16px;"><div class="muted-2xs">Korrekturen</div><div style="font-size:22px;font-weight:700;">{{ $geld($summen['korrektur']) }}</div></div>
    <div class="card" style="padding:14px 16px;"><div class="muted-2xs">Netto</div><div style="font-size:22px;font-weight:700;color:#1F7A4D;">{{ $geld($summen['netto']) }}</div></div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:16px;max-width:1250px;">
    @foreach([['Nach Pool', $nachPool], ['Nach Produkt', $nachProdukt], ['Nach Provisionsart', $nachArt], ['Nach Gesellschaft', $nachGesellschaft]] as [$titel, $daten])
    <div class="card">
        <h3 style="margin-top:0;">{{ $titel }}</h3>
        <table class="table" style="font-size:13px;">
            <tr><th>{{ $titel }}</th><th class="num">Buchungen</th><th class="num">Netto</th></tr>
            @forelse($daten as $zeile)
            <tr><td>{{ $zeile['label'] }}</td><td class="num">{{ $zeile['anzahl'] }}</td>
                <td class="num-strong">{{ $geld($zeile['netto']) }}</td></tr>
            @empty
            <tr><td colspan="3" class="muted">Keine Daten im Zeitraum.</td></tr>
            @endforelse
        </table>
    </div>
    @endforeach
</div>

<div class="card" style="max-width:1250px;margin-top:16px;">
    <h3 style="margin-top:0;">Verlauf (12 Monate)</h3>
    <table class="table" style="font-size:13px;">
        <tr><th>Monat</th><th class="num">Buchungen</th><th class="num">Brutto</th><th class="num">Storno</th><th class="num">Netto</th></tr>
        @forelse($verlauf as $zeile)
        <tr><td>{{ $zeile['monat'] }}</td><td class="num">{{ $zeile['anzahl'] }}</td>
            <td class="num">{{ $geld($zeile['brutto']) }}</td>
            <td style="text-align:right;color:#A32D2D;">{{ $geld($zeile['storno']) }}</td>
            <td class="num-strong">{{ $geld($zeile['netto']) }}</td></tr>
        @empty
        <tr><td colspan="5" class="muted">Noch keine Buchungen.</td></tr>
        @endforelse
    </table>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:16px;max-width:1250px;margin-top:16px;">
    <div class="card">
        <h3 style="margin-top:0;">Wirtschaftlich stärkste Kunden</h3>
        <p style="font-size:12px;color:var(--ink-soft);margin-top:0;">Nur hier sichtbar – nie in der Kundenakte, nie im Portal.</p>
        <table class="table" style="font-size:13px;">
            <tr><th>Kunde</th><th class="num">Verträge</th><th class="num">Netto</th><th class="num">Ø/Vertrag</th><th class="num">Storno</th></tr>
            @forelse($kundenTop as $zeile)
            <tr>
                <td><a href="{{ route('admin.provisionsmanagement.customer', $zeile['customer_id']) }}">{{ $zeile['name'] }}</a></td>
                <td class="num">{{ $zeile['vertraege'] }}</td>
                <td class="num-strong">{{ $geld($zeile['netto']) }}</td>
                <td class="num">{{ $geld($zeile['schnitt']) }}</td>
                <td class="num">{{ $zeile['stornoquote'] }} %</td>
            </tr>
            @empty
            <tr><td colspan="5" class="muted">Keine Daten.</td></tr>
            @endforelse
        </table>
    </div>
    <div class="card">
        <h3 style="margin-top:0;">Wirtschaftlich schwächste Kunden</h3>
        <table class="table" style="font-size:13px;">
            <tr><th>Kunde</th><th class="num">Verträge</th><th class="num">Netto</th><th class="num">Storno</th></tr>
            @forelse($kundenFlop as $zeile)
            <tr>
                <td><a href="{{ route('admin.provisionsmanagement.customer', $zeile['customer_id']) }}">{{ $zeile['name'] }}</a></td>
                <td class="num">{{ $zeile['vertraege'] }}</td>
                <td class="num-strong">{{ $geld($zeile['netto']) }}</td>
                <td class="num">{{ $zeile['stornoquote'] }} %</td>
            </tr>
            @empty
            <tr><td colspan="4" class="muted">Keine Daten.</td></tr>
            @endforelse
        </table>
    </div>
</div>

<div class="card" style="max-width:1250px;margin-top:16px;">
    <h3 style="margin-top:0;">Abgeschlossen gegen vergütet</h3>
    <table class="table" style="font-size:13px;">
        <tr><th>Monat</th><th class="num">Abgeschlossen</th><th class="num">Vergütet</th>
            <th class="num">In Frist</th><th class="num">Überfällig</th><th class="num">Fehlt</th></tr>
        @forelse($abgleich as $zeile)
        <tr><td>{{ $zeile['monat'] }}</td><td class="num">{{ $zeile['abgeschlossen'] }}</td>
            <td style="text-align:right;color:#1F7A4D;">{{ $zeile['verguetet'] }}</td>
            <td class="num">{{ $zeile['in_frist'] }}</td>
            <td style="text-align:right;color:#B5651D;">{{ $zeile['ueberfaellig'] }}</td>
            <td style="text-align:right;color:#A32D2D;">{{ $zeile['fehlt'] }}</td></tr>
        @empty
        <tr><td colspan="6" class="muted">Keine Verträge mit Pool-Zuordnung.</td></tr>
        @endforelse
    </table>
</div>
@endsection
