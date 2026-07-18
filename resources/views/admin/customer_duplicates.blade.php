@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span><span>Dubletten</span></div>
    <div class="page-title">Mögliche Dubletten</div>
    <div class="page-sub">Automatischer Abgleich nach Name, Telefon, E-Mail, Anschrift, Geburtsdatum, IBAN und Vertragsnummer. Jede einzelne Übereinstimmung wird angezeigt – bitte jedes Paar prüfen, bevor Sie es zusammenführen.</div>
</div>

@if($capped)
<div class="card" style="background:#FEF3C7;color:#92400E;padding:12px 18px;margin-bottom:16px;font-size:13px;">
    ⚠ Es wurden aus Leistungsgründen nur die neuesten Kunden geprüft. Bereinigen Sie die angezeigten Dubletten und laden Sie die Seite erneut, um weitere zu finden.
</div>
@endif

@if(count($pairs) === 0)
<div class="card" style="padding:40px;text-align:center;color:var(--ink-soft);">
    <div style="font-size:38px;margin-bottom:10px;">✅</div>
    <div style="font-size:15px;font-weight:600;color:var(--ink);">Keine Dubletten gefunden</div>
    <div style="font-size:13px;margin-top:6px;">Der Kundenbestand ({{ $scanned }} geprüft) enthält aktuell keine offensichtlichen Doppelanlagen.</div>
</div>
@else
@php $canBulk = in_array(auth()->user()->role, ['admin','manager']); @endphp
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
    <div style="font-size:13px;color:var(--ink-soft);">{{ count($pairs) }} Verdachtsfall(e) · {{ $scanned }} Kunden geprüft</div>
    @if($canBulk)
    <label style="font-size:13px;color:var(--ink);display:flex;align-items:center;gap:8px;cursor:pointer;">
        <input type="checkbox" id="checkAllPairs" style="width:16px;height:16px;cursor:pointer;accent-color:#17A65B;"> Alle auswählen
    </label>
    @endif
</div>

@if($canBulk)
{{-- Eigenstaendiges Formular fuer die Sammel-Zusammenfuehrung. Die Checkboxen
     unten gehoeren per form="bulkMergeForm" dazu; der Button uebertraegt die
     Auswahl per JS und verlangt eine bewusste Bestaetigung. --}}
<form method="POST" action="{{ route('admin.customers.duplicates.merge') }}" id="bulkMergeForm" onsubmit="return confirmBulkMerge(this);">@csrf</form>

<div id="mergeBar" style="display:none;position:sticky;top:0;z-index:10;background:#1F3A33;color:#fff;border-radius:10px;padding:12px 20px;margin-bottom:14px;align-items:center;gap:14px;flex-wrap:wrap;">
    <span style="font-size:13.5px;font-weight:600;"><span id="mergeCount">0</span> Paar(e) ausgewählt</span>
    <span style="font-size:12px;opacity:.8;">Zusammengehörige Datensätze werden automatisch zu einem Kunden vereint – nichts wird gelöscht außer den leeren Duplikat-Akten.</span>
    <button type="submit" form="bulkMergeForm" class="btn btn-primary" style="padding:8px 18px;font-size:13px;margin-left:auto;">Ausgewählte zusammenführen</button>
</div>
@endif

@foreach($pairs as $pair)
@php
    $primary = $pair['primary']; $duplicate = $pair['duplicate'];
    $score = $pair['score'];
    $badgeColor = $score >= 90 ? '#A32D2D' : ($score >= 80 ? '#B45309' : '#185FA5');
    $tierLabel = $pair['tier'] === 'auto' ? 'Sehr wahrscheinlich' : 'Wahrscheinlich';
@endphp
<div class="card" style="margin-bottom:16px;padding:0;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:12px;">
            @if($canBulk)
            <input type="checkbox" class="pairCheck" name="pairs[]" value="{{ $primary->id }}|{{ $duplicate->id }}" form="bulkMergeForm" style="width:17px;height:17px;cursor:pointer;accent-color:#17A65B;" title="Für Sammel-Zusammenführung auswählen">
            @endif
            <span style="background:{{ $badgeColor }};color:#fff;border-radius:999px;padding:4px 12px;font-size:12.5px;font-weight:700;">{{ $score }}% · {{ $tierLabel }}</span>
        </div>
        <a href="{{ route('admin.customer.merge', $primary->id) }}?duplicate={{ $duplicate->id }}" class="btn btn-primary" style="padding:8px 16px;">Prüfen &amp; zusammenführen →</a>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
        @foreach([['c'=>$primary,'label'=>'Hauptkunde (bleibt bestehen)','bg'=>'#E4F0E7'],['c'=>$duplicate,'label'=>'Duplikat (wird übernommen)','bg'=>'#FEF3C7']] as $col)
        @php $c = $col['c']; @endphp
        <div style="padding:16px 20px;{{ $loop->first ? 'border-right:1px solid var(--line);' : '' }}">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);margin-bottom:8px;">{{ $col['label'] }}</div>
            <a href="{{ route('admin.customer', $c->id) }}" style="font-size:15px;font-weight:700;color:var(--ink);text-decoration:none;">{{ $c->user?->name ?? 'Unbekannt' }}</a>
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:6px;line-height:1.7;">
                <div>🔢 {{ $c->customer_number }}</div>
                @if($c->user?->hasRealEmail())<div>✉ {{ $c->user->email }}</div>@endif
                @if($c->phone || $c->mobile)<div>📞 {{ $c->phone ?: $c->mobile }}</div>@endif
                @if($c->birth_date)<div>🎂 {{ \Illuminate\Support\Carbon::parse($c->birth_date)->format('d.m.Y') }}</div>@endif
                @if($c->fullAddress())<div>📍 {{ $c->fullAddress() }}</div>@endif
            </div>
        </div>
        @endforeach
    </div>
    @if(!empty($pair['signals']))
    <div style="padding:12px 20px;background:var(--surface);border-top:1px solid var(--line);">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);margin-bottom:6px;">Übereinstimmende Merkmale</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
            @foreach($pair['signals'] as $signal)
            <span style="background:#fff;border:1px solid var(--line);border-radius:6px;padding:4px 10px;font-size:12px;color:var(--ink);">✓ {{ $signal }}</span>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endforeach

@if($canBulk)
<script>
// Zaehlt die aktuelle Auswahl und blendet die Aktionsleiste ein/aus.
function refreshMergeBar() {
    var checked = document.querySelectorAll('.pairCheck:checked');
    document.getElementById('mergeCount').textContent = checked.length;
    document.getElementById('mergeBar').style.display = checked.length > 0 ? 'flex' : 'none';
    var all = document.querySelectorAll('.pairCheck');
    var master = document.getElementById('checkAllPairs');
    if (master) master.checked = checked.length > 0 && checked.length === all.length;
}
document.querySelectorAll('.pairCheck').forEach(function (cb) { cb.addEventListener('change', refreshMergeBar); });
var master = document.getElementById('checkAllPairs');
if (master) master.addEventListener('change', function () {
    document.querySelectorAll('.pairCheck').forEach(function (cb) { cb.checked = master.checked; });
    refreshMergeBar();
});

// Bestaetigung vor der Sammel-Zusammenfuehrung. Die ausgewaehlten Checkboxen
// gehoeren per form="bulkMergeForm" bereits zum Formular und werden vom Browser
// automatisch als pairs[] uebertragen - kein manuelles Kopieren noetig.
function confirmBulkMerge(form) {
    var checked = document.querySelectorAll('.pairCheck:checked');
    if (checked.length === 0) { alert('Bitte zuerst mindestens ein Paar auswählen.'); return false; }
    return confirm(checked.length + ' ausgewählte Dubletten-Paar(e) jetzt zusammenführen?\n\nZusammengehörige Datensätze werden zu einem Kunden vereint. Alle Verträge, Dokumente und Daten bleiben erhalten – nur die leeren Duplikat-Akten werden entfernt. Diese Aktion kann nicht rückgängig gemacht werden.');
}
</script>
@endif
@endif
@endsection
