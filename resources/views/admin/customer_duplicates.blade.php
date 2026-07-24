@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span><span>Dubletten</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div class="page-title">Mögliche Dubletten</div>
        <a href="{{ route('admin.customers.relationships') }}" class="btn btn-ghost">🔗 Verwandte Kunden @if(($relationCount ?? 0) > 0)({{ $relationCount }})@endif</a>
    </div>
    <div class="page-sub">Automatischer Abgleich nach Name, Telefon, E-Mail, Anschrift, Geburtsdatum, IBAN und Vertragsnummer. Jede einzelne Übereinstimmung wird angezeigt – bitte jedes Paar prüfen, bevor Sie es zusammenführen. Kein Duplikat? Mit „✕ Kein Duplikat" wandert das Paar zu „Verwandte Kunden".</div>
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
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        @if($strongCount > 0)
        <form method="POST" action="{{ route('admin.customers.duplicates.merge_all') }}" style="margin:0;"
              onsubmit="return confirm('Alle sicheren Treffer (>= {{ $autoMin }} % Übereinstimmung) automatisch zusammenführen?\n\nNur eindeutige Dubletten (gleiche E-Mail, Telefon, IBAN, Vertragsnummer oder Name + Geburtsdatum). Schwächere Treffer (nur gleicher Name) bleiben zur manuellen Prüfung. Alle Daten bleiben erhalten.');">
            @csrf
            <button type="submit" class="btn btn-primary" style="background:#128a4b;">✓ Alle sicheren zusammenführen ({{ $strongCount }})</button>
        </form>
        @endif
        <label style="font-size:13px;color:var(--ink);display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="checkAllPairs" style="width:16px;height:16px;cursor:pointer;accent-color:#17A65B;"> Alle auswählen
        </label>
    </div>
    @endif
</div>

@php
$chipDefs = [
    'all'       => ['Alle', count($pairs)],
    'name'      => ['👤 Namen', $catCounts['name'] ?? 0],
    'address'   => ['📍 Adressen', $catCounts['address'] ?? 0],
    'email'     => ['✉ E-Mails', $catCounts['email'] ?? 0],
    'phone'     => ['📞 Telefon', $catCounts['phone'] ?? 0],
    'iban'      => ['🏦 Bankkonto', $catCounts['iban'] ?? 0],
    'contract'  => ['📄 Vertragsnr.', $catCounts['contract'] ?? 0],
    'birthdate' => ['🎂 Geburtsdatum', $catCounts['birthdate'] ?? 0],
];
@endphp
<style>
.catChip{border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 15px;font-size:12.5px;cursor:pointer;color:var(--ink);transition:.12s;white-space:nowrap;}
.catChip:hover{border-color:#131A17;}
.catChip.active{background:#131A17;color:#fff;border-color:#131A17;}
.catChip.active .chipCount{color:rgba(255,255,255,.7);}
.chipCount{color:var(--ink-soft);}
</style>
<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
    <span style="font-size:12.5px;color:var(--ink-soft);align-self:center;margin-right:2px;">Schnellfilter:</span>
    @foreach($chipDefs as $key => [$label, $cnt])
        @if($key === 'all' || $cnt > 0)
        <button type="button" class="catChip {{ $key === 'all' ? 'active' : '' }}" data-cat="{{ $key }}" onclick="filterCat('{{ $key }}', this)">{{ $label }} <span class="chipCount">({{ $cnt }})</span></button>
        @endif
    @endforeach
</div>

@if($canBulk)
<div class="card" style="background:#EEF6F1;padding:11px 16px;margin-bottom:14px;font-size:12.5px;color:#131A17;line-height:1.5;">
    💡 <strong>Sichere Treffer (≥ {{ $autoMin }} %)</strong> – gleiche E-Mail, Telefon, IBAN, Vertragsnummer oder Name + Geburtsdatum – können mit einem Klick automatisch zusammengeführt werden. <strong>Schwächere Treffer (nur gleicher Name)</strong> bitte einzeln prüfen und per Auswahl zusammenführen.
</div>
@endif

@if($canBulk)
{{-- Eigenstaendiges Formular fuer die Sammel-Zusammenfuehrung. Die Checkboxen
     unten gehoeren per form="bulkMergeForm" dazu; der Button uebertraegt die
     Auswahl per JS und verlangt eine bewusste Bestaetigung. --}}
<form method="POST" action="{{ route('admin.customers.duplicates.merge') }}" id="bulkMergeForm" onsubmit="return confirmBulkMerge(this);">@csrf</form>
<form method="POST" action="{{ route('admin.customers.duplicates.dismiss_bulk') }}" id="bulkDismissForm">@csrf<input type="hidden" name="type" id="bulkDismissType" value="not_duplicate"></form>

<div id="mergeBar" style="display:none;position:sticky;top:0;z-index:10;background:#131A17;color:#fff;border-radius:10px;padding:12px 20px;margin-bottom:14px;align-items:center;gap:14px;flex-wrap:wrap;">
    <span style="font-size:13.5px;font-weight:600;"><span id="mergeCount">0</span> Paar(e) ausgewählt</span>
    <div style="margin-left:auto;display:flex;gap:10px;flex-wrap:wrap;">
        <button type="button" onclick="submitBulkDismiss('spouse')" class="btn btn-ghost" style="padding:8px 16px;font-size:13px;background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);" title="Ausgewählte als Ehepaar verknüpfen – beide Akten bleiben erhalten, nichts wird zusammengeführt">💍 Ehepaar</button>
        <button type="button" onclick="submitBulkDismiss('not_duplicate')" class="btn btn-ghost" style="padding:8px 16px;font-size:13px;background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);" title="Ausgewählte als „kein Duplikat" markieren – wandern zu Verwandte Kunden">✕ Kein Duplikat</button>
        <button type="submit" form="bulkMergeForm" class="btn btn-primary" style="padding:8px 18px;font-size:13px;">Zusammenführen</button>
    </div>
</div>
@endif

@foreach($pairs as $pair)
@php
    $primary = $pair['primary']; $duplicate = $pair['duplicate'];
    $score = $pair['score'];
    $badgeColor = $score >= 90 ? '#A32D2D' : ($score >= 80 ? '#B45309' : '#185FA5');
    $tierLabel = $pair['tier'] === 'auto' ? 'Sehr wahrscheinlich' : 'Wahrscheinlich';
@endphp
<div class="card dupCard" data-cats="{{ implode(' ', $pair['categories'] ?? []) }}" style="margin-bottom:16px;padding:0;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:12px;">
            @if($canBulk)
            <input type="checkbox" class="pairCheck" name="pairs[]" value="{{ $primary->id }}|{{ $duplicate->id }}" form="bulkMergeForm" style="width:17px;height:17px;cursor:pointer;accent-color:#17A65B;" title="Für Sammel-Zusammenführung auswählen">
            @endif
            <span style="background:{{ $badgeColor }};color:#fff;border-radius:999px;padding:4px 12px;font-size:12.5px;font-weight:700;">{{ $score }}% · {{ $tierLabel }}</span>
            @if($score >= $autoMin)
            <span style="background:#D9F4E6;color:#128a4b;border-radius:999px;padding:3px 10px;font-size:11.5px;font-weight:600;">✓ sicher</span>
            @else
            <span style="background:#FEF3C7;color:#92400E;border-radius:999px;padding:3px 10px;font-size:11.5px;font-weight:600;">manuell prüfen</span>
            @endif
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            {{-- Ehepaar/Partner: KEINE Zusammenfuehrung. Beide Akten bleiben mit
                 allen Vertraegen erhalten, das Paar wird nur als Ehepaar
                 gekennzeichnet und wandert zu „Verwandte Kunden". --}}
            <form method="POST" action="{{ route('admin.customers.duplicates.dismiss') }}" style="margin:0;"
                  onsubmit="return confirm('Als Ehepaar verknüpfen? BEIDE Kunden bleiben mit allen Verträgen und Dokumenten erhalten – es wird nichts gelöscht und nichts zusammengeführt. Das Paar erscheint unter „Verwandte Kunden".');">
                @csrf
                <input type="hidden" name="customer_a" value="{{ $primary->id }}">
                <input type="hidden" name="customer_b" value="{{ $duplicate->id }}">
                <input type="hidden" name="type" value="spouse">
                <button type="submit" class="btn btn-ghost" style="padding:8px 14px;" title="Kein Duplikat – die beiden sind ein Ehepaar. Beide Akten und Verträge bleiben erhalten.">💍 Ehepaar</button>
            </form>
            <form method="POST" action="{{ route('admin.customers.duplicates.dismiss') }}" style="margin:0;"
                  onsubmit="return confirm('Als „kein Duplikat" markieren? Das Paar verschwindet aus dieser Liste und erscheint unter „Verwandte Kunden".');">
                @csrf
                <input type="hidden" name="customer_a" value="{{ $primary->id }}">
                <input type="hidden" name="customer_b" value="{{ $duplicate->id }}">
                <button type="submit" class="btn btn-ghost" style="padding:8px 14px;" title="Kein Duplikat, sondern verwandt (z. B. Familie)">✕ Kein Duplikat</button>
            </form>
            <a href="{{ route('admin.customer.merge', $primary->id) }}?duplicate={{ $duplicate->id }}" class="btn btn-primary" style="padding:8px 16px;">Prüfen &amp; zusammenführen →</a>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
        @foreach([['c'=>$primary,'label'=>'Hauptkunde (bleibt bestehen)','bg'=>'#D9F4E6'],['c'=>$duplicate,'label'=>'Duplikat (wird übernommen)','bg'=>'#FEF3C7']] as $col)
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

<script>
// Schnellfilter: zeigt nur Paare der gewaehlten Kategorie (Name/Adresse/...).
// Versteckte Paare werden abgewaehlt, damit Sammel-Aktionen nur das Sichtbare
// betreffen.
function filterCat(cat, btn) {
    document.querySelectorAll('.dupCard').forEach(function (card) {
        var cats = (card.dataset.cats || '').split(' ');
        var show = (cat === 'all' || cats.indexOf(cat) !== -1);
        card.style.display = show ? '' : 'none';
        if (!show) {
            var cb = card.querySelector('.pairCheck');
            if (cb) cb.checked = false;
        }
    });
    document.querySelectorAll('.catChip').forEach(function (c) { c.classList.remove('active'); });
    if (btn) btn.classList.add('active');
    if (window.refreshMergeBar) refreshMergeBar();
}

@if($canBulk)
// Sichtbare (nicht ausgefilterte) Paar-Checkboxen.
function visiblePairChecks() {
    return Array.prototype.filter.call(document.querySelectorAll('.pairCheck'), function (cb) {
        var card = cb.closest('.dupCard');
        return card && card.style.display !== 'none';
    });
}

// Zaehlt die aktuelle Auswahl und blendet die Aktionsleiste ein/aus.
function refreshMergeBar() {
    var checked = document.querySelectorAll('.pairCheck:checked');
    document.getElementById('mergeCount').textContent = checked.length;
    document.getElementById('mergeBar').style.display = checked.length > 0 ? 'flex' : 'none';
    var vis = visiblePairChecks();
    var checkedVis = vis.filter(function (cb) { return cb.checked; });
    var master = document.getElementById('checkAllPairs');
    if (master) master.checked = vis.length > 0 && checkedVis.length === vis.length;
}
document.querySelectorAll('.pairCheck').forEach(function (cb) { cb.addEventListener('change', refreshMergeBar); });
var master = document.getElementById('checkAllPairs');
if (master) master.addEventListener('change', function () {
    // Nur die aktuell sichtbaren Paare auswaehlen (respektiert den Filter).
    visiblePairChecks().forEach(function (cb) { cb.checked = master.checked; });
    refreshMergeBar();
});

// Sammel-Zusammenfuehrung: Checkboxen gehoeren per form="bulkMergeForm" bereits
// zum Formular und werden automatisch als pairs[] uebertragen.
function confirmBulkMerge(form) {
    var checked = document.querySelectorAll('.pairCheck:checked');
    if (checked.length === 0) { alert('Bitte zuerst mindestens ein Paar auswählen.'); return false; }
    return confirm(checked.length + ' ausgewählte Dubletten-Paar(e) jetzt zusammenführen?\n\nZusammengehörige Datensätze werden zu einem Kunden vereint. Alle Verträge, Dokumente und Daten bleiben erhalten – nur die leeren Duplikat-Akten werden entfernt. Diese Aktion kann nicht rückgängig gemacht werden.');
}

// Sammel-"Kein Duplikat"/"Ehepaar": ausgewaehlte Paare ins Dismiss-Formular
// kopieren. Der Typ ('spouse' = Ehepaar, sonst 'not_duplicate') steuert nur
// die Kennzeichnung - in beiden Faellen wird NICHTS zusammengefuehrt.
function submitBulkDismiss(type) {
    type = type || 'not_duplicate';
    var checked = document.querySelectorAll('.pairCheck:checked');
    if (checked.length === 0) { alert('Bitte zuerst mindestens ein Paar auswählen.'); return; }
    var frage = type === 'spouse'
        ? checked.length + ' ausgewählte Paar(e) als Ehepaar verknüpfen?\n\nBeide Kunden bleiben mit allen Verträgen erhalten – nichts wird gelöscht oder zusammengeführt. Sie erscheinen unter „Verwandte Kunden". Reversibel.'
        : checked.length + ' ausgewählte Paar(e) als „kein Duplikat" markieren?\n\nSie verschwinden aus dieser Liste und erscheinen unter „Verwandte Kunden". Reversibel.';
    if (!confirm(frage)) return;
    var form = document.getElementById('bulkDismissForm');
    document.getElementById('bulkDismissType').value = type;
    form.querySelectorAll('input[name="pairs[]"]').forEach(function (i) { i.remove(); });
    checked.forEach(function (cb) {
        var input = document.createElement('input');
        input.type = 'hidden'; input.name = 'pairs[]'; input.value = cb.value;
        form.appendChild(input);
    });
    form.submit();
}
@endif
</script>
@endif
@endsection
