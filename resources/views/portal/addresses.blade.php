@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">🏠 {{ __('Meine Adressen') }}</div>
        <div class="page-sub" style="margin-bottom:0;">{{ __('Adressen hinzufügen oder Änderungen beantragen – Änderungen werden geprüft.') }}</div>
    </div>
    <button onclick="document.getElementById('add-address-modal').style.display='flex'" class="btn btn-gold">+ {{ __('Adresse hinzufügen') }}</button>
</div>

@php
$typeIcons = ['main'=>'🏠','billing'=>'🧾','postal'=>'📮','other'=>'📍'];
$pendingCreates = $requests->where('status','pending')->filter(fn($r)=>empty($r->new_data['id']));
$pendingChangeIds = $requests->where('status','pending')->pluck('new_data.id')->filter()->all();
@endphp

@if($customer->address)
<div class="card">
    <div class="card-title">{{ __('Aktuelle Stammadresse') }}</div>
    <p style="font-size:14px;">🏠 {{ $customer->address }} <span style="font-size:12px;color:var(--ink-soft);">{{ __('(im Profil hinterlegt – Änderung über „Meine Daten")') }}</span></p>
</div>
@endif

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:24px;">
    @foreach($addresses as $a)
    <div class="card" style="margin-bottom:0;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
            <span style="font-size:26px;">{{ $typeIcons[$a->type] ?? '📍' }}</span>
            <div>
                <div style="font-weight:700;font-size:14px;">{{ __($a->typeLabel()) }}</div>
                @if(in_array($a->id, $pendingChangeIds))
                <span class="badge badge-pending" style="font-size:11px;">{{ __('Änderung in Prüfung') }}</span>
                @else
                <span class="badge badge-active" style="font-size:11px;">{{ __('Aktiv') }}</span>
                @endif
            </div>
        </div>
        <p style="font-size:13.5px;line-height:1.6;color:var(--ink-soft);">{{ $a->street }}<br>{{ $a->zip }} {{ $a->city }}<br>{{ $a->country }}</p>
        @php $addressPayload = $a->only(['id','type','street','zip','city','country']); @endphp
        <button onclick='openAddressChange(@json($addressPayload))' class="btn btn-ghost" style="margin-top:12px;font-size:12.5px;padding:7px 14px;">✏️ {{ __('Änderung beantragen') }}</button>
    </div>
    @endforeach

    @foreach($pendingCreates as $r)
    <div class="card" style="margin-bottom:0;border-style:dashed;background:#FFFDF7;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
            <span style="font-size:26px;">{{ $typeIcons[$r->new_data['type'] ?? 'other'] ?? '📍' }}</span>
            <div>
                <div style="font-weight:700;font-size:14px;">{{ \App\Models\CustomerAddress::TYPES[$r->new_data['type'] ?? 'other'] ?? __('Adresse') }}</div>
                <span class="badge badge-pending" style="font-size:11px;">{{ __('Prüfung ausstehend') }}</span>
            </div>
        </div>
        <p style="font-size:13.5px;line-height:1.6;color:var(--ink-soft);">{{ $r->new_data['street'] ?? '' }}<br>{{ $r->new_data['zip'] ?? '' }} {{ $r->new_data['city'] ?? '' }}</p>
    </div>
    @endforeach
</div>

@if($addresses->isEmpty() && $pendingCreates->isEmpty())
<div class="card"><p style="color:var(--ink-soft);font-size:14px;">{{ __('Noch keine zusätzlichen Adressen hinterlegt.') }}</p></div>
@endif

<div id="add-address-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:460px;position:relative;">
        <button onclick="document.getElementById('add-address-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">{{ __('Adresse hinzufügen') }}</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">{{ __('Wird nach Prüfung durch unser Team übernommen.') }}</p>
        <form method="POST" action="{{ route('portal.addresses.store') }}">
            @csrf
            @include('portal._address_fields')
            <button type="submit" class="btn btn-primary" style="width:100%;">{{ __('Zur Prüfung einreichen') }}</button>
        </form>
    </div>
</div>

<div id="change-address-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:460px;position:relative;">
        <button onclick="document.getElementById('change-address-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">{{ __('Adressänderung beantragen') }}</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">{{ __('Die Änderung wird erst nach Prüfung wirksam.') }}</p>
        <form method="POST" id="change-address-form" action="">
            @csrf
            @include('portal._address_fields', ['prefix' => 'ca-'])
            <button type="submit" class="btn btn-primary" style="width:100%;">{{ __('Änderung einreichen') }}</button>
        </form>
    </div>
</div>

<script>
function openAddressChange(a) {
    document.getElementById('change-address-form').action = '{{ url('portal/addresses') }}/' + a.id + '/change';
    document.getElementById('ca-type').value = a.type;
    document.getElementById('ca-street').value = a.street || '';
    document.getElementById('ca-zip').value = a.zip || '';
    document.getElementById('ca-city').value = a.city || '';
    document.getElementById('ca-country').value = a.country || 'Deutschland';
    document.getElementById('change-address-modal').style.display = 'flex';
}
</script>
@endsection
