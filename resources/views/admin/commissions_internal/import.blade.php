@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="page-title">📥 Provisionen aus CSV / Excel importieren</div>
    <div class="page-sub">Schritt 1 von 5 – Datei hochladen. Es wird noch nichts übernommen.</div>
</div>

@include('admin.commissions_internal._tabs', ['active' => 'import'])
@include('admin.commissions_internal._flash')

{{-- Der Ablauf steht sichtbar auf der Seite: der Admin soll VOR dem Upload
     wissen, dass er die Vorschau noch abbrechen kann. Ohne diesen Hinweis
     traut sich niemand, eine unbekannte Datei zu probieren. --}}
<div class="card" style="max-width:1000px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;font-size:12.5px;margin-bottom:4px;">
        @foreach([
            '1. Hochladen', '2. Datei erkennen', '3. Spalten zuordnen', '4. Prüfen', '5. Bestätigen',
        ] as $i => $step)
        <span style="padding:6px 10px;border-radius:20px;border:1px solid var(--line);{{ $i === 0 ? 'background:#131A17;color:#fff;border-color:#131A17;' : 'color:var(--ink-soft);' }}">{{ $step }}</span>
        @endforeach
    </div>
    <div style="font-size:12.5px;color:var(--ink-soft);">
        Geschrieben wird erst in Schritt 5. Bis dahin lässt sich jeder Entwurf verwerfen.
    </div>
</div>

<div class="card" style="max-width:1000px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:6px;">Datei auswählen</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:16px;">
        Unterstützt werden <b>{{ implode(', ', array_map(fn ($e) => '.' . $e, $extensions)) }}</b> (max. 20 MB).
        Das Format wird am <b>Inhalt</b> der Datei erkannt, nicht an der Endung – eine als „.csv“ gespeicherte
        Excel-Datei wird also trotzdem richtig gelesen.
        Trennzeichen (<code>;</code> <code>,</code> Tabulator) und Kodierung (UTF-8, UTF-8 mit BOM, ISO-8859-1/Windows-1252)
        erkennt das System selbst; beides lässt sich in der Vorschau überschreiben.
        Bei Excel-Dateien mit mehreren Tabellenblättern wird das gewählte Blatt in der Vorschau angezeigt und kann gewechselt werden.
        <br><br>
        <b>Zwei Arten von Dateien werden gelesen.</b> Eine <b>Abrechnung</b> enthält Provisionsbeträge – daraus entstehen Provisionen.
        Eine <b>Auftrags-/Kundenliste</b> aus einem Vertriebsportal enthält gar keine Beträge, dafür Name, Anschrift,
        Geburtsdatum, Telefon und Zählernummer – daraus entstehen nur Kunden und Verträge. Welche der beiden vorliegt,
        erkennt das System an der Betragsspalte; in der Vorschau lässt es sich umstellen.
    </div>

    <form method="POST" action="{{ route('admin.commissions_internal.upload') }}" enctype="multipart/form-data">
        @csrf
        <div class="field" style="max-width:560px;">
            <label>CSV- oder Excel-Datei *</label>
            <input type="file" name="datei" required
                   accept=".csv,.txt,.xlsx,.xlsm,.xls,text/csv,text/plain,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream">
        </div>

        <details style="margin:14px 0 18px;max-width:640px;font-size:12.5px;">
            <summary style="cursor:pointer;">Erkennung überschreiben (nur nötig, wenn die Vorschau falsch aussieht)</summary>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:12px;">
                <div class="field" style="margin:0;">
                    <label>Trennzeichen (CSV)</label>
                    <select name="delimiter">
                        <option value="">Automatisch erkennen</option>
                        <option value=";">Semikolon ( ; )</option>
                        <option value=",">Komma ( , )</option>
                        <option value="&#9;">Tabulator</option>
                        <option value="|">Pipe ( | )</option>
                    </select>
                </div>
                <div class="field" style="margin:0;">
                    <label>Kodierung (CSV)</label>
                    <select name="encoding">
                        <option value="">Automatisch erkennen</option>
                        <option value="UTF-8">UTF-8</option>
                        <option value="Windows-1252">Windows-1252 / ISO-8859-1</option>
                        <option value="UTF-16LE">UTF-16</option>
                    </select>
                </div>
                <div class="field" style="margin:0;">
                    <label>Tabellenblatt (Excel)</label>
                    <input type="text" name="sheet" placeholder="leer = erstes Blatt">
                </div>
                <div class="field" style="margin:0;">
                    <label>Betriebsart</label>
                    <select name="modus">
                        <option value="">Automatisch erkennen</option>
                        @foreach(\App\Services\CommissionImport\ColumnMap::MODES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </details>

        <button type="submit" class="btn btn-primary">Datei lesen und Vorschau anzeigen</button>
    </form>
</div>

<div class="card" style="max-width:1000px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:12px;">Bisherige Importe</div>
    @if($imports->isEmpty())
        <div style="font-size:13px;color:var(--ink-soft);">Noch keine Datei eingelesen.</div>
    @else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Datei</th><th style="padding:8px;">Format</th>
                <th style="padding:8px;">Zeilen</th><th style="padding:8px;">Ergebnis</th>
                <th style="padding:8px;">Zustand</th><th style="padding:8px;">Von</th><th style="padding:8px;">Wann</th>
            </tr></thead>
            <tbody>
            @foreach($imports as $import)
            <tr style="border-top:1px solid var(--line);">
                <td style="padding:8px;"><a href="{{ route('admin.commissions_internal.preview', $import->id) }}">{{ $import->filename }}</a></td>
                <td style="padding:8px;">
                    {{ strtoupper($import->format) }}@if($import->sheet_name) <span style="color:var(--ink-soft);">· {{ $import->sheet_name }}</span>@endif
                    @unless($import->isAbrechnung())<div style="color:var(--ink-soft);font-size:11.5px;">Auftragsliste</div>@endunless
                </td>
                <td style="padding:8px;">{{ $import->rows_total }}</td>
                <td style="padding:8px;">
                    <span style="color:#128a4b;">{{ $import->rows_new }} neu</span> ·
                    {{ $import->rows_updated }} akt. · {{ $import->rows_duplicate }} dupl. ·
                    <span style="color:#B5651D;">{{ $import->rows_unmatched }} ohne Vertrag</span> ·
                    <span style="color:#A32D2D;">{{ $import->rows_invalid }} fehlerhaft</span>
                    @if($import->contracts_created > 0)
                    <div style="color:#128a4b;">
                        + {{ $import->contracts_created }} Verträge
                        @if($import->customers_created > 0), {{ $import->customers_created }} Kunden @endif
                        angelegt
                    </div>
                    @endif
                </td>
                <td style="padding:8px;">
                    <span class="badge badge-{{ $import->isDraft() ? 'pending' : ($import->status === 'importiert' ? 'active' : 'closed') }}">{{ $import->statusLabel() }}</span>
                </td>
                <td style="padding:8px;">{{ $import->importer?->name ?? 'System' }}</td>
                <td style="padding:8px;white-space:nowrap;">{{ $import->created_at?->lokal()->format('d.m.Y H:i') }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
