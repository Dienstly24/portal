@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.contracts') }}">Verträge</a><span class="breadcrumb-sep">›</span><span>Neu anlegen</span></div>
    <div class="page-title">Vertrag anlegen</div>
</div>

<div class="card" style="max-width:800px;">
    <div style="display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:28px;">
        <div style="padding:14px;background:var(--petrol);color:#fff;text-align:center;font-weight:600;font-size:14px;">Manuell anlegen</div>
        <div style="padding:14px;background:#fff;text-align:center;font-size:14px;color:var(--ink-soft);display:flex;align-items:center;justify-content:center;gap:8px;">✦ Dokument per KI auslesen <span style="font-size:11px;background:#EEF0F3;padding:2px 8px;border-radius:4px;">Bald verfügbar</span></div>
    </div>

    <form method="POST" id="contract-form" action="">
    @csrf

    <div class="field">
        <label style="font-weight:700;font-size:15px;">Versicherungsnehmer *</label>
        <div style="position:relative;">
            <input type="text" id="customer-search" placeholder="Kundendaten durchsuchen" oninput="searchCustomer(this.value)"
                style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:#fff;">
            <div id="customer-dropdown" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--line);border-radius:8px;margin-top:4px;max-height:200px;overflow-y:auto;z-index:50;display:none;box-shadow:0 4px 12px rgba(0,0,0,.1);"></div>
        </div>
        <input type="hidden" name="customer_id_selected" id="customer_id_selected">
        <div id="selected-display" style="display:none;margin-top:8px;padding:10px 14px;background:#E4F0E7;border-radius:8px;font-size:13px;font-weight:600;color:#3B7A57;">
            ✓ <span id="selected-name"></span> ausgewählt
        </div>
        <div id="customer-error" style="display:none;color:#A32D2D;font-size:13px;margin-top:6px;">Versicherungsnehmer auswählen</div>
    </div>

    <div class="field" style="margin-top:20px;">
        <label style="font-weight:700;font-size:15px;">Sparte *</label>
        <select name="type" id="sparte" required onchange="updateSubtype(this.value)"
            style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            <option value="">Bitte auswählen</option>
            <option value="krankenversicherung">Kranken</option>
            <option value="kfz">KFZ</option>
            <option value="leben">Leben</option>
            <option value="sach">Sach</option>
            <option value="internet">Internet & Mobilfunk</option>
            <option value="strom_gas">Strom & Gas</option>
            <option value="andere">Andere</option>
        </select>
    </div>

    <div id="subtype-wrap" style="display:none;" class="field">
        <label style="font-weight:700;font-size:15px;">Produktart</label>
        <select name="subtype" id="subtype"
            style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;"></select>
    </div>

    <div style="border-top:1px solid var(--line);margin:24px 0;"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Versicherer / Anbieter *</label><input type="text" name="insurer" required placeholder="z.B. Allianz, HUK-Coburg..."></div>
        <div class="field"><label>VSNR / Vertragsnummer</label><input type="text" name="vsnr" placeholder="Wird automatisch generiert"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
        <div class="field"><label>Status *</label>
            <select name="status" required style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="active">Aktiv</option>
                <option value="pending">In Bearbeitung</option>
                <option value="cancelled">Gekündigt</option>
                <option value="expired">Abgelaufen</option>
            </select>
        </div>
        <div class="field"><label>Beginn</label><input type="date" name="start_date"></div>
        <div class="field"><label>Ablauf</label><input type="date" name="end_date"></div>
    </div>
    <div class="field"><label>Notizen</label><textarea name="notes" placeholder="Interne Notizen..." style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;min-height:80px;resize:vertical;"></textarea></div>

    <div style="border-top:1px solid var(--line);padding-top:20px;display:flex;justify-content:space-between;">
        <a href="{{ route('admin.contracts') }}" class="btn btn-ghost">Abbrechen</a>
        <button type="button" onclick="submitContract()" class="btn btn-primary">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Vertrag speichern
        </button>
    </div>
    </form>
</div>

<script>
const customers = @json($customers->map(fn($c) => ['id' => $c->id, 'name' => $c->user?->name, 'email' => $c->user?->email]));
const subtypes = {
    krankenversicherung: ['Betriebliche Krankenversicherung','Gesetzliche Krankenversicherung','Krankentagegeld','Krankenzusatz','Pflege-Versicherung','Private Krankenvollversicherung'],
    kfz: ['Haftpflicht','Haftpflicht + Teilkasko','Haftpflicht + Vollkasko','Kfz-Schutzbrief'],
    leben: ['Risikolebensversicherung','Kapitallebensversicherung','Berufsunfähigkeit','Rentenversicherung','Sterbegeldversicherung'],
    sach: ['Hausrat','Wohngebäude','Haftpflicht','Rechtsschutz','Unfallversicherung'],
    internet: ['DSL / Glasfaser','Mobilfunk','Kombi-Tarif'],
    strom_gas: ['Strom','Gas','Strom + Gas'],
    andere: ['Sonstige']
};

function searchCustomer(q) {
    const dd = document.getElementById('customer-dropdown');
    if(!q) { dd.style.display='none'; return; }
    const results = customers.filter(c => c.name && c.name.toLowerCase().includes(q.toLowerCase()));
    if(!results.length) {
        dd.innerHTML = '<div style="padding:12px 16px;color:var(--ink-soft);font-size:13px;">Keine Einträge vorhanden</div>';
    } else {
        dd.innerHTML = results.map(c => `<div onclick="selectCustomer('${c.id}','${c.name}')"
            style="padding:12px 16px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--line);"
            onmouseover="this.style.background='#F8F9FA'" onmouseout="this.style.background='#fff'">
            <div style="font-weight:600;">${c.name}</div>
            <div style="font-size:12px;color:var(--ink-soft);">${c.email||''}</div>
        </div>`).join('');
    }
    dd.style.display='block';
}

function selectCustomer(id, name) {
    document.getElementById('customer_id_selected').value = id;
    document.getElementById('customer-search').value = name;
    document.getElementById('selected-name').textContent = name;
    document.getElementById('selected-display').style.display = 'block';
    document.getElementById('customer-dropdown').style.display = 'none';
    document.getElementById('customer-error').style.display = 'none';
    document.getElementById('contract-form').action = `/admin/contracts/${id}`;
}

function updateSubtype(val) {
    const wrap = document.getElementById('subtype-wrap');
    const sel = document.getElementById('subtype');
    if(subtypes[val]) {
        sel.innerHTML = subtypes[val].map(s => `<option value="${s}">${s}</option>`).join('');
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
    }
}

function submitContract() {
    if(!document.getElementById('customer_id_selected').value) {
        document.getElementById('customer-error').style.display = 'block';
        return;
    }
    document.getElementById('contract-form').submit();
}

document.addEventListener('click', e => {
    if(!e.target.closest('#customer-search') && !e.target.closest('#customer-dropdown')) {
        document.getElementById('customer-dropdown').style.display = 'none';
    }
});
</script>
@endsection
