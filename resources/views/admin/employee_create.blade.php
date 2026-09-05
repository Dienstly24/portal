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
    {{--
        Kein Passwort-Feld mehr (Betreiber-Vorgabe 18.08.2026): Der neue
        Mitarbeiter bekommt eine Einladung und legt sein Passwort selbst
        fest. So kennt es ausser ihm niemand - auch die Verwaltung nicht.
    --}}
    <div style="background:#F6F3EA;border:1px solid #E0DCD0;border-left:3px solid var(--emerald);border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13.5px;color:#4A564E;line-height:1.6;">
        <strong>&#128273; Passwort:</strong> Sie vergeben kein Passwort.
        Der Mitarbeiter erhaelt eine Einladung per E-Mail und legt sein
        eigenes Passwort fest (Link 14 Tage gueltig).
    </div>
</div>

<div class="card" style="max-width:700px;">
    <div class="card-title" style="margin-bottom:20px;">Zugriffsrechte</div>
    <div style="margin-bottom:20px;">
        <label style="font-size:13px;color:var(--ink-soft);font-weight:600;display:block;margin-bottom:10px;">Kundenzugriff</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div data-h-click="3eadc9b4dd" id="lbl-full"
                style="border:2px solid var(--line);border-radius:10px;padding:16px;cursor:pointer;background:#fff;transition:.15s;">
                <div style="font-weight:700;margin-bottom:4px;">👥 Alle Kunden</div>
                <div class="muted-xs">Mitarbeiter sieht alle Kunden</div>
            </div>
            <div data-h-click="bdf352c9c0" id="lbl-limited"
                style="border:2px solid var(--emerald);border-radius:10px;padding:16px;cursor:pointer;background:var(--emerald-soft);transition:.15s;">
                <div style="font-weight:700;margin-bottom:4px;">🔒 Begrenzte Kunden</div>
                <div class="muted-xs">Nur zugewiesene Kunden</div>
            </div>
        </div>
        {{-- Least-privilege-Default (Audit UX-14): "Begrenzte Kunden" ist
             vorausgewaehlt; Voll-Zugriff muss der Admin bewusst waehlen. --}}
        <input type="hidden" name="access_level" id="access_level" value="limited">
        <input type="hidden" id="can_see_all" value="0">
    </div>

    <div style="border-top:1px solid var(--line);padding-top:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <label style="font-size:13px;color:var(--ink-soft);font-weight:600;">Berechtigungen</label>
            <div style="display:flex;gap:8px;">
                <button type="button" data-h-click="8802664bc1" class="btn btn-ghost btn-sm">✓ Alle auswählen</button>
                <button type="button" data-h-click="392bf09bc6" class="btn btn-ghost btn-sm">✗ Alle abwählen</button>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            @foreach([
                ['can_manage_contracts','Verträge verwalten','Verträge hinzufügen und bearbeiten','📄'],
                ['can_manage_tickets','Tickets bearbeiten','Kundenanfragen beantworten','💬'],
                ['can_approve_changes','Änderungen genehmigen','Kundendaten-Änderungen genehmigen','✅'],
                ['can_send_emails','E-Mails senden','E-Mail Marketing nutzen','📧'],
                ['can_import_export','Import / Export','Kunden importieren und exportieren','📊'],
            ] as $perm)
            {{-- Least-privilege-Default (Audit UX-14): neue Mitarbeiter starten
                 OHNE Rechte, der Admin vergibt bewusst. Frueher waren alle
                 Rechte + Voll-Zugriff vorausgewaehlt. --}}
            <div class="perm-card" id="card-{{ $perm[0] }}"
                data-h-click="741d401b2c" data-a0="{{ $perm[0] }}"
                style="border:2px solid var(--line);border-radius:10px;padding:14px;cursor:pointer;background:#fff;transition:.15s;user-select:none;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <span style="font-size:24px;">{{ $perm[3] }}</span>
                    <span id="check-{{ $perm[0] }}" style="width:24px;height:24px;border-radius:50%;background:#ccc;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;"></span>
                </div>
                <div style="font-weight:700;font-size:13px;">{{ $perm[1] }}</div>
                <div style="font-size:11px;color:var(--ink-soft);margin-top:2px;">{{ $perm[2] }}</div>
                <input type="checkbox" name="{{ $perm[0] }}" id="{{ $perm[0] }}" style="display:none;">
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

<script @cspNonce>
const permIds = ['can_manage_contracts','can_manage_tickets','can_approve_changes','can_send_emails','can_import_export'];

function selectAccess(type) {
    const isLimited = type === 'limited';
    document.getElementById('access_level').value = type;
    document.getElementById('lbl-full').style.borderColor = isLimited ? 'var(--line)' : 'var(--graphite)';
    document.getElementById('lbl-full').style.background = isLimited ? '#fff' : 'var(--emerald-soft)';
    document.getElementById('lbl-limited').style.borderColor = isLimited ? 'var(--graphite)' : 'var(--line)';
    document.getElementById('lbl-limited').style.background = isLimited ? 'var(--emerald-soft)' : '#fff';
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
    card.style.borderColor = checked ? 'var(--graphite)' : 'var(--line)';
    card.style.background = checked ? 'var(--emerald-soft)' : '#fff';
    check.style.background = checked ? 'var(--graphite)' : '#ccc';
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

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["3eadc9b4dd"] = function (event) { selectAccess('full') };
window.__h["bdf352c9c0"] = function (event) { selectAccess('limited') };
window.__h["8802664bc1"] = function (event) { selectAllPerms(true) };
window.__h["392bf09bc6"] = function (event) { selectAllPerms(false) };
</script>
@endPushOnce

{{-- Ereignis-Handler mit veraenderlichem Wert (Audit SEC-4):
     der Wert steht in data-a0, der Code ist fuer alle Zeilen
     derselbe. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["741d401b2c"] = function (event) { togglePerm(this.dataset.a0) };
</script>
@endPushOnce
