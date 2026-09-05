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
            <label data-h-click="fe8f1e7f34" id="lbl-full"
                style="border:2px solid {{ $employee->can_see_all_customers ? 'var(--graphite)' : 'var(--line)' }};border-radius:10px;padding:16px;cursor:pointer;background:{{ $employee->can_see_all_customers ? 'var(--emerald-soft)' : '#fff' }};">
                <div style="font-weight:700;margin-bottom:4px;">Alle Kunden</div>
                <div class="muted-xs">Mitarbeiter sieht alle Kunden</div>
            </label>
            <label data-h-click="99f92ffd1f" id="lbl-limited"
                style="border:2px solid {{ !$employee->can_see_all_customers ? 'var(--graphite)' : 'var(--line)' }};border-radius:10px;padding:16px;cursor:pointer;background:{{ !$employee->can_see_all_customers ? 'var(--emerald-soft)' : '#fff' }};">
                <div style="font-weight:700;margin-bottom:4px;">Begrenzte Kunden</div>
                <div class="muted-xs">Nur zugewiesene Kunden</div>
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
<script type="application/json" id="preselectedData" @cspNonce>@json($preselectedCustomers)</script>
<input type="hidden" name="assigned_customers_present" value="1">
<div id="assignBox">
    <div style="font-size:13px;color:var(--ink-soft);margin-bottom:10px;">Aktuell zugewiesen: <strong id="assignCount">{{ count($assignedIds) }}</strong> Kunden</div>
    <input type="text" id="assignSearch" placeholder="Suche: Name, Nummer, E-Mail, Telefon, Anschrift, Kennzeichen, Zaehler ..." autocomplete="off"
        style="width:100%;padding:11px 14px;border:1px solid var(--line);border-radius:10px;font-size:14px;margin-bottom:8px;">
    <div id="assignResults" style="display:none;border:1px solid var(--line);border-radius:10px;background:#fff;max-height:220px;overflow-y:auto;margin-bottom:12px;"></div>
    <div id="assignSelected" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
</div>
<script @cspNonce>
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
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:var(--emerald-soft);border:1px solid var(--emerald);border-radius:20px;padding:5px 12px;font-size:12.5px;';
            chip.innerHTML = '👤 ' + c.name + ' <span class="muted">' + (c.number || '') + '</span>';
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
                        row.innerHTML = '<strong>' + c.name + '</strong> · ' + (c.number || '') + ' <span class="muted">' + (c.email || '') + '</span>' + (selected[c.id] ? ' ✅' : '');
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
                <button type="button" data-h-click="a067bc2e64" class="btn btn-ghost btn-sm">✓ Alle auswählen</button>
                <button type="button" data-h-click="54084ca47e" class="btn btn-ghost btn-sm">✗ Alle abwählen</button>
            </div>
            @foreach([
                ['can_manage_contracts','Verträge verwalten','Verträge hinzufügen und bearbeiten','📄'],
                ['can_manage_tickets','Tickets bearbeiten','Kundenanfragen beantworten','💬'],
                ['can_approve_changes','Änderungen genehmigen','Kundendaten-Änderungen genehmigen','✅'],
                ['can_send_emails','E-Mails senden','E-Mail Marketing nutzen','📧'],
                ['can_import_export','Import / Export','Kunden importieren und exportieren','📊'],
                // Provisionsdaten sind intern und vertraulich - dieses Recht
                // oeffnet Betraege, Empfaenger und Abrechnungen. Es wird
                // einzeln vergeben, nie ueber die Rolle mitgeliefert.
                ['can_manage_commissions','Provisionen verwalten','Interne Provisionen sehen, importieren und abrechnen','💶'],
            ] as $perm)
            <div class="perm-card" id="card-{{ $perm[0] }}"
                data-h-click="df369be7fe" data-a0="{{ $perm[0] }}"
                style="border:2px solid {{ $employee->{$perm[0]} ? 'var(--graphite)' : 'var(--line)' }};border-radius:10px;padding:14px;cursor:pointer;background:{{ $employee->{$perm[0]} ? 'var(--emerald-soft)' : '#fff' }};transition:.15s;user-select:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="font-size:24px;">{{ $perm[3] }}</span>
                    <span id="check-{{ $perm[0] }}" style="width:24px;height:24px;border-radius:50%;background:{{ $employee->{$perm[0]} ? 'var(--graphite)' : '#ccc' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;">{{ $employee->{$perm[0]} ? '✓' : '' }}</span>
                </div>
                <div style="font-weight:700;font-size:13px;">{{ $perm[1] }}</div>
                <div style="font-size:11px;color:var(--ink-soft);margin-top:2px;">{{ $perm[2] }}</div>
                <input type="checkbox" name="{{ $perm[0] }}" id="{{ $perm[0] }}" {{ $employee->{$perm[0]} ? 'checked' : '' }} style="display:none;">
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Provisions-Saetze fuer den Neukunden-Bericht: Vorschlag = fix je
     Neuvertrag + Prozent vom Jahresbeitrag. Beide optional. --}}
<div class="card" style="max-width:700px;">
    <div class="card-title" style="margin-bottom:6px;">Provision (Werber-Vergütung)</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:16px;">
        Grundlage für den Provisions-Vorschlag im Neukunden-Bericht, wenn dieser Mitarbeiter als Werber eingetragen ist. Leer = keine Provision.
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field" style="margin:0;">
            <label>Fester Betrag je Neuvertrag (EUR)</label>
            <input type="number" name="provision_fixed" step="0.01" min="0" max="99999" value="{{ old('provision_fixed', $employee->provision_fixed) }}" placeholder="z. B. 25,00">
        </div>
        <div class="field" style="margin:0;">
            <label>Prozent vom Jahresbeitrag (%)</label>
            <input type="number" name="provision_percent" step="0.01" min="0" max="100" value="{{ old('provision_percent', $employee->provision_percent) }}" placeholder="z. B. 10">
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

{{--
    Zugang: seit der Passwort-Haertung vergibt die Verwaltung KEIN Passwort
    mehr. Der einzige Weg, einem Mitarbeiter den Zugang wiederherzustellen,
    ist eine neue Einladung an seine hinterlegte Adresse.
--}}
<div style="max-width:700px;margin-top:32px;border:1px solid var(--line);border-radius:12px;overflow:hidden;">
    <div style="background:#F6F3EA;padding:12px 20px;font-size:13px;font-weight:700;color:#16211C;">&#128273; Zugang</div>
    <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div style="font-size:13px;color:var(--ink-soft);line-height:1.5;">
            <strong>Einladung erneut senden</strong><br>
            Der Mitarbeiter erhaelt einen Link (14 Tage gueltig) und legt sein Passwort selbst fest.
            Passwoerter werden grundsaetzlich nicht von der Verwaltung vergeben und nie per E-Mail verschickt.
        </div>
        <form method="POST" action="{{ route('admin.employees.resend_invitation', $employee->id) }}" style="margin:0;">
            @csrf
            <button type="submit" class="btn btn-ghost nowrap">&#9993; Einladung senden</button>
        </form>
    </div>
    @if(auth()->user()->role === 'admin')
    <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;border-top:1px solid var(--line);">
        <div style="font-size:13px;color:var(--ink-soft);line-height:1.5;">
            <strong>Zwei-Faktor-Anmeldung</strong>
            @if($employee->hasTwoFactor())
                <span style="color:var(--emerald);">&#10003; aktiv seit {{ $employee->two_factor_confirmed_at?->lokal()->format('d.m.Y') }}</span><br>
                Nur zuruecksetzen, wenn das Telefon verloren ist UND keine Ersatzcodes mehr vorliegen.
                Der Mitarbeiter richtet sie beim naechsten Login neu ein.
            @else
                <span style="color:#B5651D;">noch nicht eingerichtet</span><br>
                Der Mitarbeiter wird beim naechsten Login automatisch durch die Einrichtung gefuehrt.
            @endif
        </div>
        @if($employee->hasTwoFactor())
        <form method="POST" action="{{ route('admin.employees.reset_two_factor', $employee->id) }}"
              data-confirm="Zwei-Faktor-Anmeldung von {{ $employee->name }} wirklich zuruecksetzen?" style="margin:0;">
            @csrf
            <button type="submit" class="btn btn-ghost" style="white-space:nowrap;color:#B5651D;border-color:#B5651D;">&#128260; Zuruecksetzen</button>
        </form>
        @endif
    </div>
    @endif
</div>

<div style="max-width:700px;margin-top:24px;border:1px solid #F0D5D5;border-radius:12px;overflow:hidden;">
    <div style="background:#FBF3F3;padding:12px 20px;font-size:13px;font-weight:700;color:#A32D2D;">Gefahrenzone</div>
    <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:1px solid #F0D5D5;">
        <div style="font-size:13px;color:var(--ink-soft);line-height:1.5;"><strong>{{ $employee->is_active ? 'Konto deaktivieren' : 'Konto aktivieren' }}</strong><br>{{ $employee->is_active ? 'Login wird gesperrt, alle Daten und Zuweisungen bleiben erhalten. Empfohlen statt Loeschen.' : 'Konto ist derzeit deaktiviert. Login wieder freigeben.' }}</div>
        <form method="POST" action="{{ route('admin.employees.toggle', $employee->id) }}" style="margin:0;">
            @csrf @method('PUT')
            <button type="submit" class="btn btn-ghost" style="white-space:nowrap;{{ $employee->is_active ? 'color:#B5651D;border-color:#B5651D;' : 'color:var(--emerald);border-color:var(--emerald);' }}">{{ $employee->is_active ? '&#9208; Deaktivieren' : '&#9654; Aktivieren' }}</button>
        </form>
    </div>
    @if(auth()->user()->role === 'admin')
    <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div style="font-size:13px;color:var(--ink-soft);line-height:1.5;">Mitarbeiter dauerhaft entfernen.<br>Kundenzuweisungen werden aufgehoben, Kundendaten bleiben erhalten.</div>
        <form method="POST" action="{{ route('admin.employees.destroy', $employee->id) }}" data-confirm="Mitarbeiter {{ $employee->name }} wirklich ENDGUELTIG loeschen?" data-confirm-2="Sicher? Diese Aktion kann nicht rueckgaengig gemacht werden." style="margin:0;">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-ghost" style="color:#A32D2D;border-color:#A32D2D;white-space:nowrap;">&#128465; Mitarbeiter loeschen</button>
        </form>
    </div>
    @endif
</div>

<script @cspNonce>
function togglePerm(name) {
    var cb = document.getElementById(name);
    var card = document.getElementById('card-' + name);
    var check = document.getElementById('check-' + name);
    cb.checked = !cb.checked;
    card.style.borderColor = cb.checked ? 'var(--graphite)' : 'var(--line)';
    card.style.background = cb.checked ? 'var(--emerald-soft)' : '#fff';
    check.style.background = cb.checked ? 'var(--graphite)' : '#ccc';
    check.textContent = cb.checked ? '\u2713' : '';
}
function selectAllPerms(state) {
    ['can_manage_contracts','can_manage_tickets','can_approve_changes','can_send_emails','can_import_export','can_manage_commissions'].forEach(function (name) {
        var cb = document.getElementById(name);
        if (cb && cb.checked !== state) togglePerm(name);
    });
}
function toggleLimited(limited) {
    document.getElementById('access_level').value = limited ? 'limited' : 'full';
    document.getElementById('lbl-full').style.borderColor = limited ? 'var(--line)' : 'var(--graphite)';
    document.getElementById('lbl-full').style.background = limited ? '#fff' : 'var(--emerald-soft)';
    document.getElementById('lbl-limited').style.borderColor = limited ? 'var(--graphite)' : 'var(--line)';
    document.getElementById('lbl-limited').style.background = limited ? 'var(--emerald-soft)' : '#fff';
    document.getElementById('assign-customers').style.display = limited ? 'block' : 'none';
    const el = document.getElementById('can_see_all');
    if (limited) { el.removeAttribute('name'); }
    else { el.name = 'can_see_all_customers'; }
}
</script>
@endsection

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["fe8f1e7f34"] = function (event) { toggleLimited(false) };
window.__h["99f92ffd1f"] = function (event) { toggleLimited(true) };
window.__h["a067bc2e64"] = function (event) { selectAllPerms(true) };
window.__h["54084ca47e"] = function (event) { selectAllPerms(false) };
</script>
@endPushOnce

{{-- Ereignis-Handler mit veraenderlichem Wert (Audit SEC-4):
     der Wert steht in data-a0, der Code ist fuer alle Zeilen
     derselbe. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["df369be7fe"] = function (event) { togglePerm(this.dataset.a0) };
</script>
@endPushOnce
