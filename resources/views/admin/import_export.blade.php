@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Import / Export</span></div>
    <div class="page-title">Import / Export</div>
    <div class="page-sub">Kundendaten importieren oder exportieren.</div>
</div>

@if(session('import_result'))
@php $r = session('import_result'); @endphp
@if(!empty($r['queued']))
<div class="alert alert-success" style="flex-direction:column;align-items:flex-start;gap:6px;">
    <div style="font-weight:700;">
        ✓ Import gestartet — die Kunden werden im Hintergrund angelegt.
    </div>
    <div style="font-size:13px;opacity:.8;">Bei großen Dateien dauert das ein paar Minuten. Du bekommst eine Benachrichtigung, sobald der Import fertig ist.</div>
</div>
@else
<div class="alert {{ ($r['imported'] ?? 0) > 0 ? 'alert-success' : 'alert-error' }}" style="flex-direction:column;align-items:flex-start;gap:6px;">
    <div style="font-weight:700;">
        ✓ {{ $r['imported'] ?? 0 }} Kunden importiert — {{ $r['skipped'] ?? 0 }} übersprungen
    </div>
    @foreach(($r['errors'] ?? []) as $err)
    <div style="font-size:13px;opacity:.8;">⚠ {{ $err }}</div>
    @endforeach
</div>
@endif
@endif

<div class="grid-2" style="max-width:900px;">

<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">📥 Kunden importieren</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-top:4px;">CSV-Datei mit Kundendaten hochladen</div>
        </div>
    </div>

    <div style="background:#F4F5F7;border-radius:10px;padding:16px;margin-bottom:20px;font-size:13px;">
        <div style="font-weight:600;margin-bottom:8px;">Unterstützte Spalten:</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
            @foreach(['Vorname','Nachname','E-Mail','Telefon','Mobil','Straße','Nr','PLZ','Ort','IBAN','Geburtsdatum','Familienstand','Sprache','Firma','Rechtsform'] as $col)
            <span style="background:#fff;border:1px solid var(--line);border-radius:6px;padding:3px 10px;font-size:12px;font-family:monospace;">{{ $col }}</span>
            @endforeach
        </div>
        <div style="margin-top:10px;">
            <a href="{{ route('admin.import.template') }}" style="color:var(--gold);font-size:13px;font-weight:600;">⬇ Vorlage herunterladen</a>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.import') }}" enctype="multipart/form-data">
        @csrf
        {{-- Tastaturbedienbar (Audit A11Y-2) --}}
        <div role="button" tabindex="0" aria-label="CSV-Datei auswaehlen oder hierher ziehen"
            style="border:2px dashed var(--line);border-radius:10px;padding:32px;text-align:center;margin-bottom:16px;cursor:pointer;"
            onclick="document.getElementById('csv-input').click()"
            onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('csv-input').click();}"
            ondragover="event.preventDefault();this.style.borderColor='var(--gold)'"
            ondragleave="this.style.borderColor='var(--line)'"
            ondrop="event.preventDefault();this.style.borderColor='var(--line)';document.getElementById('csv-input').files=event.dataTransfer.files;updateFileName(event.dataTransfer.files[0].name)">
            <div style="font-size:32px;margin-bottom:8px;" aria-hidden="true">📂</div>
            <div style="font-weight:600;font-size:14px;" id="file-name-display">CSV-Datei auswählen oder hierher ziehen</div>
            <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">Maximal 10 MB</div>
        </div>
        <input type="file" name="csv_file" id="csv-input" accept=".csv,.txt" style="display:none;" onchange="updateFileName(this.files[0]?.name)">

        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div style="font-size:13px;color:var(--ink-soft);">
                Duplikate werden automatisch erkannt und übersprungen.
            </div>
            <button type="submit" class="btn btn-primary">
                👁 Vorschau anzeigen
            </button>
        </div>
        <div style="font-size:12px;color:var(--ink-soft);margin-top:8px;">
            Nach dem Hochladen siehst du zuerst eine Vorschau. Erst nach deiner Bestätigung werden Kunden angelegt.
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">📤 Kunden exportieren</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-top:4px;">Alle Kundendaten als CSV herunterladen</div>
        </div>
    </div>

    @php $total = \App\Models\Customer::count(); @endphp
    <div style="background:#D9F4E6;border-radius:10px;padding:20px;text-align:center;margin-bottom:20px;">
        <div style="font-size:36px;font-weight:700;color:#17A65B;">{{ $total }}</div>
        <div style="font-size:14px;color:#17A65B;margin-top:4px;">Kunden im System</div>
    </div>

    <div style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">
        Die Export-Datei enthält alle Kundendaten inklusive Kontaktdaten, Adressen, IBAN und Vertragsinformationen.
    </div>

    <div style="display:flex;flex-direction:column;gap:10px;">
        <a href="{{ route('admin.export') }}" class="btn btn-primary" style="justify-content:center;">
            📤 Alle Kunden exportieren (CSV)
        </a>
        <a href="{{ route('admin.import.template') }}" class="btn btn-ghost" style="justify-content:center;">
            📋 Import-Vorlage herunterladen
        </a>
    </div>

    <div style="border-top:1px solid var(--line);margin-top:20px;padding-top:16px;">
        <div style="font-size:13px;font-weight:600;margin-bottom:10px;">Export-Felder:</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
            @foreach(['Kundennummer','Vorname','Nachname','E-Mail','Telefon','Mobil','Adresse','IBAN','Geburtsdatum','Familienstand','Sprache','Firmenname','Rechtsform','Erstellt am'] as $f)
            <span style="background:#F4F5F7;border-radius:6px;padding:3px 10px;font-size:12px;">{{ $f }}</span>
            @endforeach
        </div>
    </div>
</div>

</div>

<script>
function updateFileName(name) {
    if(name) document.getElementById('file-name-display').textContent = '✓ ' + name;
}
</script>
@endsection
