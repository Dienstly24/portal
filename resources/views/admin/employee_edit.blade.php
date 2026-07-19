@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.employees') }}">Mitarbeiter</a><span class="breadcrumb-sep">›</span><span>Bearbeiten</span></div>
    <div class="page-title">{{ $employee->name }} bearbeiten</div>
</div>

<form method="POST" action="{{ route('admin.employees.update', $employee->id) }}">
@csrf @method('PUT')

<div class="card" style="max-width:700px;">
    <div class="card-title" style="margin-bottom:20px;">Persönliche Daten</div>
    <div class="field"><label>Name *</label><input type="text" name="name" required value="{{ $employee->name }}"></div>
    <div class="field"><label>E-Mail</label><input type="email" value="{{ $employee->email }}" disabled style="opacity:.6;"></div>
</div>

<div class="card" style="max-width:700px;">
    <div class="card-title" style="margin-bottom:20px;">Zugriffsrechte</div>

    {{-- Rolle - IMMER sichtbar (Audit UX-1): frueher lag der Select im
         display:none-Block "Begrenzte Kunden", sodass fuer Voll-Zugriff-
         Mitarbeiter kein Rollenwechsel moeglich war. --}}
    <div class="field" style="max-width:320px;margin-bottom:20px;">
        <label for="role">Rolle</label>
        <select name="role" id="role" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            <option value="employee" {{ $employee->role === 'employee' ? 'selected' : '' }}>👤 Mitarbeiter</option>
            <option value="manager" {{ $employee->role === 'manager' ? 'selected' : '' }}>⭐ Manager (sieht alle Kunden)</option>
        </select>
    </div>

    <div style="margin-bottom:20px;">
        <label style="font-size:13px;color:var(--ink-soft);font-weight:600;display:block;margin-bottom:10px;">Kundenzugriff</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <label onclick="toggleLimited(false)" id="lbl-full"
                style="border:2px solid {{ $employee->can_see_all_customers ? 'var(--petrol)' : 'var(--line)' }};border-radius:10px;padding:16px;cursor:pointer;background:{{ $employee->can_see_all_customers ? '#E4F0E7' : '#fff' }};">
                <div style="font-weight:700;margin-bottom:4px;">Alle Kunden</div>
                <div style="font-size:12px;color:var(--ink-soft);">Mitarbeiter sieht alle Kunden</div>
            </label>
            <label onclick="toggleLimited(true)" id="lbl-limited"
                style="border:2px solid {{ !$employee->can_see_all_customers ? 'var(--petrol)' : 'var(--line)' }};border-radius:10px;padding:16px;cursor:pointer;background:{{ !$employee->can_see_all_customers ? '#E4F0E7' : '#fff' }};">
                <div style="font-weight:700;margin-bottom:4px;">Begrenzte Kunden</div>
                <div style="font-size:12px;color:var(--ink-soft);">Nur zugewiesene Kunden</div>
            </label>
        </div>
        <input type="hidden" name="access_level" id="access_level" value="{{ $employee->access_level }}">
        @if($employee->can_see_all_customers)
        <input type="hidden" name="can_see_all_customers" id="can_see_all" value="1">
        @else
        <input type="hidden" id="can_see_all" value="0">
        @endif
    </div>

    <div id="assign-customers" style="{{ $employee->can_see_all_customers ? 'display:none' : '' }};border-top:1px solid var(--line);padding-top:20px;margin-bottom:20px;">
        <label style="font-size:13px;color:var(--ink-soft);font-weight:600;display:block;margin-bottom:12px;">Zugewiesene Kunden</label>
@php
    $preselectedCustomers = \App\Models\Customer::with('user')->whereIn('id', $assignedIds)->get()->map(function ($c) {
        return ['id' => $c->id, 'name' => $c->user?->name, 'number' => $c->customer_number];
    })->values();
@endphp
<script type="application/json" id="preselectedData">{!! json_encode($preselectedCustomers) !!}</script>
<input type="hidden" name="assigned_customers_present" value="1">
<div id="assignBox">
    <div style="font-size:13px;color:var(--ink-soft);margin-bottom:10px;">Aktuell zugewiesen: <strong id="assignCount">{{ count($assignedIds) }}</strong> Kunden</div>
    <input type="text" id="assignSearch" placeholder="Suche: Name, Kundennummer, E-Mail oder Telefon..." autocomplete="off"
        style="width:100%;padding:11px 14px;border:1px solid var(--line);border-radius:10px;font-size:14px;margin-bottom:8px;">
    <div id="assignResults" style="display:none;border:1px solid var(--line);border-radius:10px;background:#fff;max-height:220px;overflow-y:auto;margin-bottom:12px;"></div>
    <div id="assignSelected" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
</div>
<script>
(function () {
    var preselected = JSON.parse(document.getElementById('preselectedData').textContent);
    var selected = {};
    var box = document.getElementById('assignSelected');
    var count = document.getElementById('assignCount');
    var input = document.getElementById('assignSearch');
    var results = document.getElementById('assignResults');
    var form = input.closest('form');

    function render() {
        box.innerHTML = '';
        var ids = Object.keys(selected);
        count.textContent = ids.length;
        ids.forEach(function (id) {
            var c = selected[id];
            var chip = document.createElement('span');
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#E4F0E7;border:1px solid #3B7A57;border-radius:20px;padding:5px 12px;font-size:12.5px;';
            chip.innerHTML = '👤 ' + c.name + ' <span style="color:var(--ink-soft);">' + (c.number || '') + '</span>';
            var x = document.createElement('a');
            x.textContent = '✕';
            x.style.cssText = 'cursor:pointer;color:#A32D2D;font-weight:700;';
            x.onclick = function () { delete selected[id]; render(); };
            chip.appendChild(x);
            var hidden = document.createElement('input');
            hidden.type = 'hidden'; hidden.name = 'assigned_customers[]'; hidden.value = id;
            chip.appendChild(hidden);
            box.appendChild(chip);
        });
    }
    preselected.forEach(function (c) { selected[c.id] = c; });
    render();

    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(function () {
            fetch('{{ route('admin.employees.customer-search') }}?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    results.innerHTML = '';
                    if (!list.length) { results.style.display = 'none'; return; }
                    list.forEach(function (c) {
                        var row = document.createElement('div');
                        row.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:13.5px;border-bottom:1px solid var(--line);';
                        row.innerHTML = '<strong>' + c.name + '</strong> · ' + (c.number || '') + ' <span style="color:var(--ink-soft);">' + (c.email || '') + '</span>' + (selected[c.id] ? ' ✅' : '');
                        row.onmouseover = function () { row.style.background = 'var(--canvas)'; };
                        row.onmouseout = function () { row.style.background = '#fff'; };
                        row.onclick = function () {
                            if (selected[c.id]) { delete selected[c.id]; } else { selected[c.id] = c; }
                            render(); results.style.display = 'none'; input.value = ''; input.focus();
                        };
                        results.appendChild(row);
                    });
                    results.style.display = 'block';
                });
        }, 250);
    });
    document.addEventListener('click', function (e) {
        if (!results.contains(e.target) && e.target !== input) results.style.display = 'none';
    });
})();
</script>
        </div>
    </div>

    <div style="border-top:1px solid var(--line);padding-top:20px;">
        <label style="font-size:13px;color:var(--ink-soft);font-weight:600;display:block;margin-bottom:12px;">Berechtigungen</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:12px;">
                <button type="button" onclick="selectAllPerms(true)" class="btn btn-ghost btn-sm">✓ Alle auswählen</button>
                <button type="button" onclick="selectAllPerms(false)" class="btn btn-ghost btn-sm">✗ Alle abwählen</button>
            </div>
            @foreach([
                ['can_manage_contracts','Verträge verwalten','Verträge hinzufügen und bearbeiten','📄'],
                ['can_manage_tickets','Tickets bearbeiten','Kundenanfragen beantworten','💬'],
                ['can_approve_changes','Änderungen genehmigen','Kundendaten-Änderungen genehmigen','✅'],
                ['can_send_emails','E-Mails senden','E-Mail Marketing nutzen','📧'],
                ['can_import_export','Import / Export','Kunden importieren und exportieren','📊'],
            ] as $perm)
            <div class="perm-card" id="card-{{ $perm[0] }}"
                onclick="togglePerm('{{ $perm[0] }}')"
                style="border:2px solid {{ $employee->{$perm[0]} ? 'var(--petrol)' : 'var(--line)' }};border-radius:10px;padding:14px;cursor:pointer;background:{{ $employee->{$perm[0]} ? '#E4F0E7' : '#fff' }};transition:.15s;user-select:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="font-size:24px;">{{ $perm[3] }}</span>
                    <span id="check-{{ $perm[0] }}" style="width:24px;height:24px;border-radius:50%;background:{{ $employee->{$perm[0]} ? 'var(--petrol)' : '#ccc' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;">{{ $employee->{$perm[0]} ? '✓' : '' }}</span>
                </div>
                <div style="font-weight:700;font-size:13px;">{{ $perm[1] }}</div>
                <div style="font-size:11px;color:var(--ink-soft);margin-top:2px;">{{ $perm[2] }}</div>
                <input type="checkbox" name="{{ $perm[0] }}" id="{{ $perm[0] }}" {{ $employee->{$perm[0]} ? 'checked' : '' }} style="display:none;">
            </div>
            @endforeach
        </div>
    </div>
</div>

<div style="display:flex;gap:12px;max-width:700px;justify-content:space-between;">
    <div style="display:flex;gap:12px;">
        <button type="submit" class="btn btn-primary">Speichern</button>
        <a href="{{ route('admin.employees') }}" class="btn btn-ghost">Abbrechen</a>
    </div>
    @if(!$employee->isAdmin())
    @endif
</div>
</form>

<div style="max-width:700px;margin-top:40px;border:1px solid #F0D5D5;border-radius:12px;overflow:hidden;">
    <div style="background:#FBF3F3;padding:12px 20px;font-size:13px;font-weight:700;color:#A32D2D;">Gefahrenzone</div>
    <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:1px solid #F0D5D5;">
        <div style="font-size:13px;color:var(--ink-soft);line-height:1.5;"><strong>{{ $employee->is_active ? 'Konto deaktivieren' : 'Konto aktivieren' }}</strong><br>{{ $employee->is_active ? 'Login wird gesperrt, alle Daten und Zuweisungen bleiben erhalten. Empfohlen statt Loeschen.' : 'Konto ist derzeit deaktiviert. Login wieder freigeben.' }}</div>
        <form method="POST" action="{{ route('admin.employees.toggle', $employee->id) }}" style="margin:0;">
            @csrf @method('PUT')
            <button type="submit" class="btn btn-ghost" style="white-space:nowrap;{{ $employee->is_active ? 'color:#B5651D;border-color:#B5651D;' : 'color:#3B7A57;border-color:#3B7A57;' }}">{{ $employee->is_active ? '&#9208; Deaktivieren' : '&#9654; Aktivieren' }}</button>
        </form>
    </div>
    @if(auth()->user()->role === 'admin')
    <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div style="font-size:13px;color:var(--ink-soft);line-height:1.5;">Mitarbeiter dauerhaft entfernen.<br>Kundenzuweisungen werden aufgehoben, Kundendaten bleiben erhalten.</div>
        <form method="POST" action="{{ route('admin.employees.destroy', $employee->id) }}" onsubmit="return confirm('Mitarbeiter {{ $employee->name }} wirklich ENDGUELTIG loeschen?') && confirm('Sicher? Diese Aktion kann nicht rueckgaengig gemacht werden.');" style="margin:0;">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-ghost" style="color:#A32D2D;border-color:#A32D2D;white-space:nowrap;">&#128465; Mitarbeiter loeschen</button>
        </form>
    </div>
    @endif
</div>

<script>
function togglePerm(name) {
    var cb = document.getElementById(name);
    var card = document.getElementById('card-' + name);
    var check = document.getElementById('check-' + name);
    cb.checked = !cb.checked;
    card.style.borderColor = cb.checked ? 'var(--petrol)' : 'var(--line)';
    card.style.background = cb.checked ? '#E4F0E7' : '#fff';
    check.style.background = cb.checked ? 'var(--petrol)' : '#ccc';
    check.textContent = cb.checked ? '\u2713' : '';
}
function selectAllPerms(state) {
    ['can_manage_contracts','can_manage_tickets','can_approve_changes','can_send_emails','can_import_export'].forEach(function (name) {
        var cb = document.getElementById(name);
        if (cb && cb.checked !== state) togglePerm(name);
    });
}
function toggleLimited(limited) {
    document.getElementById('access_level').value = limited ? 'limited' : 'full';
    document.getElementById('lbl-full').style.borderColor = limited ? 'var(--line)' : 'var(--petrol)';
    document.getElementById('lbl-full').style.background = limited ? '#fff' : '#E4F0E7';
    document.getElementById('lbl-limited').style.borderColor = limited ? 'var(--petrol)' : 'var(--line)';
    document.getElementById('lbl-limited').style.background = limited ? '#E4F0E7' : '#fff';
    document.getElementById('assign-customers').style.display = limited ? 'block' : 'none';
    const el = document.getElementById('can_see_all');
    if (limited) { el.removeAttribute('name'); }
    else { el.name = 'can_see_all_customers'; }
}
</script>
@endsection
