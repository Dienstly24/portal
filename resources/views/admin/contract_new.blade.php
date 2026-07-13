@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.contracts') }}">Verträge</a><span class="breadcrumb-sep">›</span><span>Neu anlegen</span></div>
    <div class="page-title">Vertrag anlegen</div>
</div>

@if($errors->any())
<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:16px;margin-bottom:20px;max-width:800px;">
    <div style="font-weight:700;color:#A32D2D;margin-bottom:8px;">Bitte korrigieren Sie folgende Fehler:</div>
    @foreach($errors->all() as $error)<div style="font-size:13px;color:#A32D2D;">• {{ $error }}</div>@endforeach
</div>
@endif

<div class="card" style="max-width:800px;">
    <form method="POST" id="contract-form" action="">
    @csrf

    <div class="field">
        <label style="font-weight:700;font-size:15px;">Versicherungsnehmer *</label>
        <div style="position:relative;">
            <input type="text" id="customer-search" placeholder="Kundendaten durchsuchen" oninput="searchCustomer(this.value)" autocomplete="off"
                style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;background:#fff;">
            <div id="customer-dropdown" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--line);border-radius:8px;margin-top:4px;max-height:200px;overflow-y:auto;z-index:50;display:none;box-shadow:0 4px 12px rgba(0,0,0,.1);"></div>
        </div>
        <input type="hidden" name="customer_id_selected" id="customer_id_selected">
        <div id="selected-display" style="display:none;margin-top:8px;padding:10px 14px;background:#E4F0E7;border-radius:8px;font-size:13px;font-weight:600;color:#3B7A57;">
            ✓ <span id="selected-name"></span> ausgewählt
        </div>
        <div id="customer-error" style="display:none;color:#A32D2D;font-size:13px;margin-top:6px;">Bitte Versicherungsnehmer auswählen</div>
    </div>

    <div style="border-top:1px solid var(--line);margin:24px 0;"></div>

    @include('admin.partials.contract_form_fields')

    <div style="border-top:1px solid var(--line);padding-top:20px;display:flex;justify-content:space-between;margin-top:8px;">
        <a href="{{ route('admin.contracts') }}" class="btn btn-ghost">Abbrechen</a>
        <button type="submit" class="btn btn-primary">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Vertrag speichern
        </button>
    </div>
    </form>
</div>

<script>
const customers = @json($customers->map(fn($c) => ['id' => $c->id, 'name' => $c->user?->name, 'email' => $c->user?->email]));

function searchCustomer(q) {
    const dd = document.getElementById('customer-dropdown');
    if(!q) { dd.style.display='none'; return; }
    const results = customers.filter(c => c.name && c.name.toLowerCase().includes(q.toLowerCase()));
    if(!results.length) {
        dd.innerHTML = '<div style="padding:12px 16px;color:var(--ink-soft);font-size:13px;">Keine Einträge vorhanden</div>';
    } else {
        dd.innerHTML = results.map(c => `<div onclick="selectCustomer('${c.id}', this.dataset.name)" data-name="${(c.name||'').replace(/"/g,'&quot;')}"
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

// Kunden-Pflichtprüfung erst NACH der nativen HTML5-Validierung der Pflichtfelder.
document.getElementById('contract-form').addEventListener('submit', function(e) {
    if(!document.getElementById('customer_id_selected').value) {
        e.preventDefault();
        document.getElementById('customer-error').style.display = 'block';
        document.getElementById('customer-search').focus();
    }
});

document.addEventListener('click', e => {
    if(!e.target.closest('#customer-search') && !e.target.closest('#customer-dropdown')) {
        document.getElementById('customer-dropdown').style.display = 'none';
    }
});
</script>
@endsection
