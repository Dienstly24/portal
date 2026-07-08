@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.employees') }}">Mitarbeiter</a><span class="breadcrumb-sep">›</span><span>Neu</span></div>
    <div class="page-title">Neuer Mitarbeiter</div>
</div>

<form method="POST" action="{{ route('admin.employees.store') }}">
@csrf
<div class="card" style="max-width:700px;">
    <div class="card-title" style="margin-bottom:20px;">Persönliche Daten</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Name *</label><input type="text" name="name" required placeholder="Max Mustermann"></div>
        <div class="field"><label>E-Mail *</label><input type="email" name="email" required placeholder="max@dienstly24.de"></div>
    </div>
    <div class="field"><label>Passwort *</label><input type="password" name="password" required placeholder="Mindestens 8 Zeichen"></div>
</div>

<div class="card" style="max-width:700px;">
    <div class="card-title" style="margin-bottom:20px;">Zugriffsrechte</div>
    <div style="margin-bottom:20px;">
        <label style="font-size:13px;color:var(--ink-soft);font-weight:600;display:block;margin-bottom:10px;">Kundenzugriff</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div onclick="selectAccess('full')" id="lbl-full"
                style="border:2px solid var(--petrol);border-radius:10px;padding:16px;cursor:pointer;background:#E4F0E7;transition:.15s;">
                <div style="font-weight:700;margin-bottom:4px;">👥 Alle Kunden</div>
                <div style="font-size:12px;color:var(--ink-soft);">Mitarbeiter sieht alle Kunden</div>
            </div>
            <div onclick="selectAccess('limited')" id="lbl-limited"
                style="border:2px solid var(--line);border-radius:10px;padding:16px;cursor:pointer;background:#fff;transition:.15s;">
                <div style="font-weight:700;margin-bottom:4px;">🔒 Begrenzte Kunden</div>
                <div style="font-size:12px;color:var(--ink-soft);">Nur zugewiesene Kunden</div>
            </div>
        </div>
        <input type="hidden" name="access_level" id="access_level" value="full">
        <input type="hidden" name="can_see_all_customers" id="can_see_all" value="1">
    </div>

    <div style="border-top:1px solid var(--line);padding-top:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <label style="font-size:13px;color:var(--ink-soft);font-weight:600;">Berechtigungen</label>
            <div style="display:flex;gap:8px;">
                <button type="button" onclick="selectAllPerms(true)" class="btn btn-ghost btn-sm">✓ Alle auswählen</button>
                <button type="button" onclick="selectAllPerms(false)" class="btn btn-ghost btn-sm">✗ Alle abwählen</button>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            @foreach([
                ['can_manage_contracts','Verträge verwalten','Verträge hinzufügen und bearbeiten','📄'],
                ['can_manage_tickets','Tickets bearbeiten','Kundenanfragen beantworten','💬'],
                ['can_approve_changes','Änderungen genehmigen','Kundendaten-Änderungen genehmigen','✅'],
                ['can_send_emails','E-Mails senden','E-Mail Marketing nutzen','📧'],
            ] as $perm)
            <div class="perm-card" id="card-{{ $perm[0] }}"
                onclick="togglePerm('{{ $perm[0] }}')"
                style="border:2px solid var(--petrol);border-radius:10px;padding:14px;cursor:pointer;background:#E4F0E7;transition:.15s;user-select:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="font-size:24px;">{{ $perm[3] }}</span>
                    <span id="check-{{ $perm[0] }}" style="width:24px;height:24px;border-radius:50%;background:var(--petrol);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;">✓</span>
                </div>
                <div style="font-weight:700;font-size:13px;">{{ $perm[1] }}</div>
                <div style="font-size:11px;color:var(--ink-soft);margin-top:2px;">{{ $perm[2] }}</div>
                <input type="checkbox" name="{{ $perm[0] }}" id="{{ $perm[0] }}" checked style="display:none;">
            </div>
            @endforeach
        </div>
    </div>
</div>

<div style="display:flex;gap:12px;max-width:700px;">
    <button type="submit" class="btn btn-primary">Mitarbeiter erstellen</button>
    <a href="{{ route('admin.employees') }}" class="btn btn-ghost">Abbrechen</a>
</div>
</form>

<script>
const permIds = ['can_manage_contracts','can_manage_tickets','can_approve_changes','can_send_emails','can_import_export'];

function selectAccess(type) {
    const isLimited = type === 'limited';
    document.getElementById('access_level').value = type;
    document.getElementById('lbl-full').style.borderColor = isLimited ? 'var(--line)' : 'var(--petrol)';
    document.getElementById('lbl-full').style.background = isLimited ? '#fff' : '#E4F0E7';
    document.getElementById('lbl-limited').style.borderColor = isLimited ? 'var(--petrol)' : 'var(--line)';
    document.getElementById('lbl-limited').style.background = isLimited ? '#E4F0E7' : '#fff';
    const el = document.getElementById('can_see_all');
    if (isLimited) { el.removeAttribute('name'); }
    else { el.name = 'can_see_all_customers'; el.value = '1'; }
}

function togglePerm(id) {
    const cb = document.getElementById(id);
    cb.checked = !cb.checked;
    updateCard(id, cb.checked);
}

function updateCard(id, checked) {
    const card = document.getElementById('card-' + id);
    const check = document.getElementById('check-' + id);
    card.style.borderColor = checked ? 'var(--petrol)' : 'var(--line)';
    card.style.background = checked ? '#E4F0E7' : '#fff';
    check.style.background = checked ? 'var(--petrol)' : '#ccc';
    check.textContent = checked ? '✓' : '';
}

function selectAllPerms(select) {
    permIds.forEach(id => {
        document.getElementById(id).checked = select;
        updateCard(id, select);
    });
}
</script>
@endsection
