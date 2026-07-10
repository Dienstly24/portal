@extends('layouts.portal')
@section('content')
<div class="page-title">Meine Daten</div>
<div class="page-sub">Persönliche Daten, Adresse und Bankverbindung an einem Ort. Änderungen werden von unserem Team geprüft – Sie können mehrere Änderungen gleichzeitig einreichen.</div>

@if($pending > 0)<div class="notice">Sie haben aktuell {{ $pending }} Änderung(en) in Prüfung. <a href="{{ route('portal.change_requests') }}" style="color:var(--petrol);font-weight:600;">Status ansehen →</a></div>@endif

<form method="POST" action="{{ route('portal.profile.update') }}">
    @csrf

    {{-- Persönliche Daten --}}
    <div class="card">
        <div class="card-title">👤 Persönliche Daten</div>
        <div class="grid-2">
            <div class="field"><label>Geschlecht</label>
                <select name="gender">
                    <option value="">— Bitte wählen —</option>
                    @foreach(\App\Models\Customer::GENDERS as $key => $label)
                    <option value="{{ $key }}" {{ $customer?->gender === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Familienstand</label>
                <select name="marital_status">
                    <option value="">— Bitte wählen —</option>
                    @foreach(['ledig'=>'Ledig','verheiratet'=>'Verheiratet','geschieden'=>'Geschieden','verwitwet'=>'Verwitwet'] as $k=>$v)
                    <option value="{{ $k }}" {{ $customer?->marital_status === $k ? 'selected' : '' }}>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Geburtsort</label><input type="text" name="birth_place" value="{{ $customer?->birth_place }}"></div>
            <div class="field"><label>Telefon</label><input type="text" name="phone" value="{{ $customer?->phone }}" placeholder="+49 …"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Krankenversicherungsnummer</label><input type="text" name="health_insurance_number" value="{{ $customer?->health_insurance_number }}"></div>
            <div class="field"><label>Rentenversicherungsnummer</label><input type="text" name="pension_insurance_number" value="{{ $customer?->pension_insurance_number }}"></div>
        </div>
        <div class="field"><label>Steuer-ID</label><input type="text" name="tax_id" value="{{ $customer?->tax_id }}" placeholder="11-stellig"></div>
    </div>

    {{-- Adresse nach deutschem Standard --}}
    <div class="card">
        <div class="card-title">🏠 Adresse</div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;">
            <div class="field"><label>Straße</label><input type="text" name="address_street" value="{{ $customer?->address_street }}"></div>
            <div class="field"><label>Hausnummer</label><input type="text" name="address_house_number" value="{{ $customer?->address_house_number }}"></div>
            <div class="field"><label>Zusatz</label><input type="text" name="address_house_suffix" value="{{ $customer?->address_house_suffix }}" placeholder="A, 1a"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Postleitzahl</label><input type="text" name="address_zip" value="{{ $customer?->address_zip }}" maxlength="10"></div>
            <div class="field"><label>Ort</label><input type="text" name="address_city" value="{{ $customer?->address_city }}"></div>
        </div>
        @if($customer?->address && !$customer?->address_street)
        <p style="font-size:12px;color:var(--ink-soft);">Bisher hinterlegt: {{ $customer->address }}</p>
        @endif
    </div>

    {{-- Bankverbindung --}}
    <div class="card">
        <div class="card-title">🏦 Bankverbindung</div>
        <div class="grid-2">
            <div class="field"><label>IBAN</label><input type="text" name="iban" value="" placeholder="{{ $customer?->iban ? '••••' . substr($customer->iban, -4) : 'DE…' }}" oninput="this.value=this.value.toUpperCase().replace(/\s/g,'')"></div>
            <div class="field"><label>Kontoinhaber</label><input type="text" name="account_holder" value="{{ $customer?->account_holder }}"></div>
        </div>
        <p style="font-size:12px;color:var(--ink-soft);">🔒 Bank- und Steuerdaten werden verschlüsselt gespeichert und erst nach Freigabe übernommen.</p>
    </div>

    <button type="submit" class="btn btn-primary">Änderungen einreichen</button>
</form>
@endsection
