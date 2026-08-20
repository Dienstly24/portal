@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="page-title">🤝 Vermittler-Abrechnung</div>
    <div class="page-sub">Abrechnungsdatei des Vermittlers einlesen und mit den erfassten Verträgen abgleichen.</div>
</div>

@include('admin.partials.vermittler_tabs', ['active' => 'import'])

@if(session('error'))
<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:14px 16px;margin-bottom:20px;max-width:900px;font-size:13px;color:#A32D2D;">{{ session('error') }}</div>
@endif
@if($errors->any())
<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:14px 16px;margin-bottom:20px;max-width:900px;font-size:13px;color:#A32D2D;">
    @foreach($errors->all() as $error)<div>• {{ $error }}</div>@endforeach
</div>
@endif

{{-- Kennzahlen: was haben wir eingereicht, was kam zurueck? --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px;max-width:980px;">
    @foreach([
        ['Eingereicht', $performance['eingereicht'], '#131A17'],
        ['Abgerechnet', $performance['abgerechnet'], '#128a4b'],
        ['Storniert', $performance['storniert'], '#A32D2D'],
        ['Nicht gefunden', $performance['nicht_gefunden'], '#B5651D'],
        ['Prüfung', $performance['pruefung'], '#A32D2D'],
    ] as [$label, $value, $color])
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11.5px;color:var(--ink-soft);">{{ $label }}</div>
        <div style="font-size:22px;font-weight:700;color:{{ $color }};">{{ $value }}</div>
    </div>
    @endforeach
</div>

<div class="card" style="max-width:980px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:6px;">Abrechnungsdatei (CSV) einlesen</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:16px;">
        Erwartet werden die Spalten des Vermittler-Exports (<code>Datum; Produkt; Id; Status; Provision; Tracking-Id; Stornogrund; Referenz-Nr.</code>).
        Die Spalte <b>Referenz-Nr. darf fehlen</b> – dann findet das System den Vertrag über die bereits gespeicherte Zuordnung
        Referenz-Nr. ↔ Vermittler-ID. Pflicht ist allein die Spalte <b>Id</b>.
        Dieselbe Datei erneut einzulesen erzeugt keine Doppelbuchungen.
    </div>

    <form method="POST" action="{{ route('admin.vermittler.import') }}" enctype="multipart/form-data">
        @csrf
        <div class="field" style="max-width:520px;">
            <label>CSV-Datei *</label>
            <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required>
        </div>
        <label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;margin:14px 0 18px;max-width:640px;">
            <input type="checkbox" name="reconcile" value="1" checked style="margin-top:2px;">
            <span>
                <b>Abgleich in beide Richtungen.</b> Verträge mit einer Referenz-Nr. im Format dieser Datei, die darin nicht
                vorkommen, werden als „Nicht in Abrechnung gefunden" gekennzeichnet. Es wird dabei <b>nichts gelöscht und
                nichts storniert</b> – nur der Abrechnungsstand vermerkt.
            </span>
        </label>
        <button type="submit" class="btn btn-primary">Datei einlesen und abgleichen</button>
    </form>
</div>

@if($openCount > 0)
<div style="background:#FEF3C7;border:1px solid #E8C36A;border-radius:10px;padding:14px 16px;margin:20px 0;max-width:980px;font-size:13px;">
    <b>⚠ {{ $openCount }} Datensätze warten auf eine Entscheidung.</b>
    Sie konnten keinem Vertrag eindeutig zugeordnet werden.
    <a href="{{ route('admin.vermittler.review') }}">Zur Prüfliste →</a>
</div>
@endif

<div class="card" style="max-width:980px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:14px;">Bisherige Importe</div>
    @if($imports->isEmpty())
        <div style="font-size:13px;color:var(--ink-soft);">Noch keine Abrechnung eingelesen.</div>
    @else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Datei</th><th style="padding:8px;">Datum</th>
                <th style="padding:8px;">Gesamt</th><th style="padding:8px;">Zugeordnet</th>
                <th style="padding:8px;">Nicht gefunden</th><th style="padding:8px;">Prüfung</th>
                <th style="padding:8px;">Storniert</th><th style="padding:8px;">Von</th>
            </tr></thead>
            <tbody>
            @foreach($imports as $import)
                <tr style="border-top:1px solid var(--line);">
                    <td style="padding:8px;"><a href="{{ route('admin.vermittler.show', $import->id) }}">{{ $import->filename }}</a></td>
                    <td style="padding:8px;">{{ $import->created_at?->format('d.m.Y H:i') }}</td>
                    <td style="padding:8px;">{{ $import->rows_total }}</td>
                    <td style="padding:8px;">{{ $import->rows_matched + $import->rows_new_link }}</td>
                    <td style="padding:8px;">{{ $import->rows_unmatched }}</td>
                    <td style="padding:8px;">{{ $import->rows_review }}</td>
                    <td style="padding:8px;">{{ $import->rows_storno }}</td>
                    <td style="padding:8px;color:var(--ink-soft);">{{ $import->importer?->name ?? 'System' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
