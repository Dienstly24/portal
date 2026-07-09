@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">📞 Kontaktinformationen</div>
        <div class="page-sub" style="margin-bottom:0;">Mehrere E-Mail-Adressen und Telefonnummern verwalten – Änderungen werden geprüft.</div>
    </div>
    <button onclick="document.getElementById('add-contact-modal').style.display='flex'" class="btn btn-gold">+ Kontakt hinzufügen</button>
</div>

@php
$pendingCreates = $requests->where('status','pending')->filter(fn($r)=>empty($r->new_data['id']));
$pendingChangeIds = $requests->where('status','pending')->pluck('new_data.id')->filter()->all();
@endphp

<div class="grid-2">
    <div class="card">
        <div class="card-title">📧 E-Mail-Adressen</div>
        <div class="item-row">
            <div>
                <div style="font-size:14px;font-weight:600;">{{ auth()->user()->email }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">Login-E-Mail (nicht änderbar)</div>
            </div>
            <span class="badge badge-active">Primär</span>
        </div>
        @foreach($contacts->where('type','email') as $c)
        <div class="item-row">
            <div>
                <div style="font-size:14px;font-weight:600;">{{ $c->value }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">{{ \App\Models\CustomerContact::LABELS[$c->label] ?? $c->label }}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                @if(in_array($c->id, $pendingChangeIds))<span class="badge badge-pending">In Prüfung</span>@else<span class="badge badge-active">Aktiv</span>@endif
                @php $contactPayload = $c->only(['id','type','label','value']); @endphp<button onclick='openContactChange(@json($contactPayload))' class="btn btn-ghost" style="font-size:12px;padding:6px 10px;">✏️</button>
            </div>
        </div>
        @endforeach
        @foreach($pendingCreates->filter(fn($r)=>$r->type==='email') as $r)
        <div class="item-row">
            <div>
                <div style="font-size:14px;font-weight:600;">{{ $r->new_data['value'] ?? '' }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">{{ \App\Models\CustomerContact::LABELS[$r->new_data['label'] ?? ''] ?? '' }}</div>
            </div>
            <span class="badge badge-pending">Prüfung ausstehend</span>
        </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-title">📱 Telefonnummern</div>
        @if($customer->phone)
        <div class="item-row">
            <div>
                <div style="font-size:14px;font-weight:600;">{{ $customer->phone }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">Stammnummer (Änderung über „Meine Daten")</div>
            </div>
            <span class="badge badge-active">Primär</span>
        </div>
        @endif
        @foreach($contacts->where('type','phone') as $c)
        <div class="item-row">
            <div>
                <div style="font-size:14px;font-weight:600;">{{ $c->value }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">{{ \App\Models\CustomerContact::LABELS[$c->label] ?? $c->label }}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                @if(in_array($c->id, $pendingChangeIds))<span class="badge badge-pending">In Prüfung</span>@else<span class="badge badge-active">Aktiv</span>@endif
                @php $contactPayload = $c->only(['id','type','label','value']); @endphp<button onclick='openContactChange(@json($contactPayload))' class="btn btn-ghost" style="font-size:12px;padding:6px 10px;">✏️</button>
            </div>
        </div>
        @endforeach
        @foreach($pendingCreates->filter(fn($r)=>$r->type==='phone') as $r)
        <div class="item-row">
            <div>
                <div style="font-size:14px;font-weight:600;">{{ $r->new_data['value'] ?? '' }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">{{ \App\Models\CustomerContact::LABELS[$r->new_data['label'] ?? ''] ?? '' }}</div>
            </div>
            <span class="badge badge-pending">Prüfung ausstehend</span>
        </div>
        @endforeach
    </div>
</div>

<div id="add-contact-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:440px;position:relative;">
        <button onclick="document.getElementById('add-contact-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">Kontakt hinzufügen</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">Wird nach Prüfung durch unser Team übernommen.</p>
        <form method="POST" action="{{ route('portal.contacts.store') }}">
            @csrf
            <div class="grid-2">
                <div class="field"><label>Art *</label>
                    <select name="type" required>
                        <option value="email">E-Mail-Adresse</option>
                        <option value="phone">Telefonnummer</option>
                    </select>
                </div>
                <div class="field"><label>Bezeichnung *</label>
                    <select name="label" required>
                        @foreach(\App\Models\CustomerContact::LABELS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="field"><label>E-Mail / Nummer *</label><input type="text" name="value" required maxlength="255" placeholder="z.B. name@firma.de oder +49 40 …"></div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Zur Prüfung einreichen</button>
        </form>
    </div>
</div>

<div id="change-contact-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:440px;position:relative;">
        <button onclick="document.getElementById('change-contact-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">Kontaktänderung beantragen</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">Die Änderung wird erst nach Prüfung wirksam.</p>
        <form method="POST" id="change-contact-form" action="">
            @csrf
            <div class="field"><label>Bezeichnung *</label>
                <select name="label" id="cc-label" required>
                    @foreach(\App\Models\CustomerContact::LABELS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>E-Mail / Nummer *</label><input type="text" name="value" id="cc-value" required maxlength="255"></div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Änderung einreichen</button>
        </form>
    </div>
</div>

<script>
function openContactChange(c) {
    document.getElementById('change-contact-form').action = '{{ url('portal/contacts') }}/' + c.id + '/change';
    document.getElementById('cc-label').value = c.label || 'privat';
    document.getElementById('cc-value').value = c.value || '';
    document.getElementById('change-contact-modal').style.display = 'flex';
}
</script>
@endsection
