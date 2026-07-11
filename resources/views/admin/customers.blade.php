@extends('layouts.admin')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">Kunden</div>
        <div class="page-sub">Alle Kundenakten verwalten.</div>
    </div>
    <a href="{{ route('admin.customers.create') }}" class="btn btn-primary">+ Neuer Kunde</a>
</div>

@if(in_array(auth()->user()->role, ['admin','manager']))
<div class="card" style="padding:14px 20px;margin-bottom:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
    <form method="GET" action="{{ route('admin.customers') }}" style="display:flex;gap:10px;align-items:center;margin:0;">
        <label style="font-size:13px;color:var(--ink-soft);">Nach Betreuer filtern:</label>
        <select name="betreuer" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
            <option value="">Alle Kunden</option>
            @foreach($employees as $e)
            <option value="{{ $e->id }}" {{ request('betreuer') == $e->id ? 'selected' : '' }}>{{ $e->name }}</option>
            @endforeach
        </select>
        @if(request('betreuer'))<a href="{{ route('admin.customers') }}" style="font-size:12.5px;color:#A32D2D;">✕ Filter entfernen</a>@endif
    </form>
</div>

{{-- Massen-Aktionen: zwei EIGENSTÄNDIGE Formulare (NICHT verschachtelt – sonst
     verwirft der Browser das innere Formular und der Löschen-Button reagiert
     nicht). Die Zeilen-Checkboxen weiter unten gehören per form="bulkForm" zum
     Zuweisungs-Formular; der Löschen-Button überträgt die aktuelle Auswahl per
     JS in bulkDeleteForm. --}}
<form method="POST" action="{{ route('admin.customers.bulk-assign') }}" id="bulkForm">@csrf</form>
@if(auth()->user()->role === 'admin')
<form method="POST" action="{{ route('admin.customers.bulk-delete') }}" id="bulkDeleteForm" onsubmit="return confirmBulkDelete(this);">@csrf</form>
@endif

<div id="bulkBar" style="display:none;position:sticky;top:0;z-index:10;background:#1F3A33;color:#fff;border-radius:10px;padding:12px 20px;margin-bottom:12px;align-items:center;gap:14px;flex-wrap:wrap;">
    <span style="font-size:13.5px;font-weight:600;"><span id="bulkCount">0</span> Kunden ausgewaehlt</span>
    <select name="employee_id" form="bulkForm" required style="padding:8px 12px;border-radius:8px;border:none;font-size:13px;">
        <option value="">— Mitarbeiter waehlen —</option>
        @foreach($employees as $e)
        <option value="{{ $e->id }}">{{ $e->name }}</option>
        @endforeach
    </select>
    <input type="text" name="reason" form="bulkForm" required placeholder="Grund der Zuweisung (Pflicht)" style="padding:8px 12px;border-radius:8px;border:none;font-size:13px;flex:1;min-width:200px;">
    <label style="font-size:12.5px;display:flex;align-items:center;gap:6px;cursor:pointer;">
        <input type="checkbox" name="replace_existing" value="1" form="bulkForm" style="width:auto;"> Bisherige Betreuer ersetzen
    </label>
    <button type="submit" form="bulkForm" class="btn btn-primary" style="padding:8px 18px;font-size:13px;">Zuweisen</button>
    @if(auth()->user()->role === 'admin')
    <button type="submit" form="bulkDeleteForm" id="bulkDeleteBtn"
        style="padding:8px 18px;font-size:13px;background:#A32D2D;color:#fff;border:none;border-radius:8px;cursor:pointer;">
        🗑 Ausgewählte löschen
    </button>
    @endif
</div>

@if(auth()->user()->role === 'admin')
<script>
// Überträgt die aktuell angehakten Zeilen-Checkboxen in das Lösch-Formular und
// verlangt eine bewusste Bestätigung mit Anzahl, bevor endgültig gelöscht wird.
function confirmBulkDelete(form) {
    var checked = document.querySelectorAll('.rowCheck:checked');
    if (checked.length === 0) { alert('Bitte zuerst Kunden auswählen.'); return false; }
    if (!confirm('⚠️ ' + checked.length + ' Kunde(n) ENDGÜLTIG löschen?\n\nAlle zugehörigen Daten (Verträge, Tickets, Dokumente, E-Mails, Portal-Zugang) werden unwiderruflich entfernt.')) {
        return false;
    }
    // Alte Übernahmen entfernen, dann die aktuelle Auswahl als hidden inputs anhängen.
    form.querySelectorAll('input[name="customer_ids[]"]').forEach(function (i) { i.remove(); });
    checked.forEach(function (cb) {
        var input = document.createElement('input');
        input.type = 'hidden'; input.name = 'customer_ids[]'; input.value = cb.value;
        form.appendChild(input);
    });
    return true;
}
</script>
@endif
@endif

<div class="card">
    <table>
        <thead><tr>
            @if(in_array(auth()->user()->role, ['admin','manager']))<th style="width:36px;"><input type="checkbox" id="checkAll" style="width:17px;height:17px;cursor:pointer;accent-color:#1F3A33;"></th>@endif
            <th>Kunde</th><th>Kundennr.</th><th>E-Mail</th><th>Portal</th><th>1. Login</th><th>Letzter Login</th><th>Betreuer</th>
        </tr></thead>
        <tbody>
        @forelse($customers as $c)
        <tr class="rowLink" data-href="{{ route('admin.customer', $c->id) }}" style="cursor:pointer;">
            @if(in_array(auth()->user()->role, ['admin','manager']))
            <td class="noNav"><input type="checkbox" class="rowCheck" name="customer_ids[]" value="{{ $c->id }}" form="bulkForm" style="width:17px;height:17px;cursor:pointer;accent-color:#1F3A33;"></td>
            @endif
            <td style="font-weight:600;">{{ $c->user?->name }}</td>
            <td style="color:var(--ink-soft);font-size:13px;">{{ $c->customer_number }}</td>
            <td style="color:var(--ink-soft);font-size:13px;">{{ str_contains($c->user?->email ?? '', '@dienstly24.internal') ? '—' : $c->user?->email }}</td>
            @php $ps = $c->portalStatus(); @endphp
            <td title="Einladung: {{ $c->user?->invitation_sent_at?->format('d.m.Y') ?? '—' }} · Passwort gesetzt: {{ $c->user?->portal_password_set_at ? 'Ja' : 'Nein' }}">
                <span style="background:{{ $ps['bg'] }};color:{{ $ps['color'] }};border-radius:12px;padding:2px 10px;font-size:11.5px;white-space:nowrap;">{{ $ps['label'] }}</span>
            </td>
            <td style="color:var(--ink-soft);font-size:12.5px;white-space:nowrap;">{{ $c->user?->first_login_at?->format('d.m.Y') ?? '—' }}</td>
            <td style="color:var(--ink-soft);font-size:12.5px;white-space:nowrap;">{{ $c->user?->last_login_at?->format('d.m.Y') ?? '—' }}</td>
            <td style="font-size:12.5px;">
                @forelse($c->betreuer as $b)
                <span style="background:#E4F0E7;color:#3B7A57;border-radius:12px;padding:2px 10px;display:inline-block;margin:1px 0;">{{ $b->name }}</span>
                @empty
                <span style="color:#B5651D;">— offen —</span>
                @endforelse
            </td>
        </tr>
        @empty
        <tr><td colspan="9" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Kunden gefunden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if(in_array(auth()->user()->role, ['admin','manager']))
<script>
(function () {
    var bar = document.getElementById('bulkBar');
    var count = document.getElementById('bulkCount');
    var all = document.getElementById('checkAll');
    function refresh() {
        var checked = document.querySelectorAll('.rowCheck:checked').length;
        count.textContent = checked;
        bar.style.display = checked > 0 ? 'flex' : 'none';
    }
    document.querySelectorAll('.rowCheck').forEach(function (cb) { cb.addEventListener('change', refresh); });
    if (all) all.addEventListener('change', function () {
        document.querySelectorAll('.rowCheck').forEach(function (cb) { cb.checked = all.checked; });
        refresh();
    });
})();
</script>
@endif
<style>
.rowLink:hover td { background: #F4F7F5; }
</style>
<script id="rowLinkScript">
document.querySelectorAll('tr.rowLink').forEach(function (row) {
    row.addEventListener('click', function (e) {
        if (e.target.closest('.noNav') || e.target.closest('input') || e.target.closest('a') || e.target.closest('button')) return;
        window.location = row.dataset.href;
    });
});
</script>
@endsection
