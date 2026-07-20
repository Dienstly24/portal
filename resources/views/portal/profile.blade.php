@extends('layouts.portal')
@section('content')
<div class="page-title">{{ __('Meine Daten') }}</div>
<div class="page-sub">{{ __('Persönliche Daten, Adresse und Bankverbindung an einem Ort. Änderungen werden von unserem Team geprüft – Sie können mehrere Änderungen gleichzeitig einreichen.') }}</div>

@if($pending > 0)<div class="notice">{{ __('Sie haben aktuell :count Änderung(en) in Prüfung.', ['count' => $pending]) }} <a href="{{ route('portal.change_requests') }}" style="color:var(--petrol);font-weight:600;">{{ __('Status ansehen') }} →</a></div>@endif

<form method="POST" action="{{ route('portal.profile.update') }}">
    @csrf

    {{-- Persönliche Daten --}}
    <div class="card">
        <div class="card-title">👤 {{ __('Persönliche Daten') }}</div>
        <div class="grid-2">
            <div class="field"><label>{{ __('Geschlecht') }}</label>
                <select name="gender">
                    <option value="">{{ __('— Bitte wählen —') }}</option>
                    @foreach(\App\Models\Customer::GENDERS as $key => $label)
                    <option value="{{ $key }}" {{ $customer?->gender === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>{{ __('Familienstand') }}</label>
                <select name="marital_status">
                    <option value="">{{ __('— Bitte wählen —') }}</option>
                    @foreach(['ledig'=>__('Ledig'),'verheiratet'=>__('Verheiratet'),'geschieden'=>__('Geschieden'),'verwitwet'=>__('Verwitwet')] as $k=>$v)
                    <option value="{{ $k }}" {{ $customer?->marital_status === $k ? 'selected' : '' }}>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="grid-2">
            <div class="field"><label>{{ __('Geburtsort') }}</label><input type="text" name="birth_place" value="{{ $customer?->birth_place }}"></div>
            <div class="field"><label>{{ __('Telefon') }}</label><input type="text" name="phone" value="{{ $customer?->phone }}" placeholder="+49 …"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>{{ __('Krankenversicherungsnummer') }}</label><input type="text" name="health_insurance_number" value="{{ $customer?->health_insurance_number }}"></div>
            <div class="field"><label>{{ __('Rentenversicherungsnummer') }}</label><input type="text" name="pension_insurance_number" value="{{ $customer?->pension_insurance_number }}"></div>
        </div>
        <div class="field"><label>{{ __('Steuer-ID') }}</label><input type="text" name="tax_id" value="{{ $customer?->tax_id }}" placeholder="{{ __('11-stellig') }}"></div>
    </div>

    {{-- Adresse nach deutschem Standard --}}
    <div class="card">
        <div class="card-title">🏠 {{ __('Adresse') }}</div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;">
            <div class="field"><label>{{ __('Straße') }}</label><input type="text" name="address_street" value="{{ $customer?->address_street }}"></div>
            <div class="field"><label>{{ __('Hausnummer') }}</label><input type="text" name="address_house_number" value="{{ $customer?->address_house_number }}"></div>
            <div class="field"><label>{{ __('Zusatz') }}</label><input type="text" name="address_house_suffix" value="{{ $customer?->address_house_suffix }}" placeholder="A, 1a"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>{{ __('Postleitzahl') }}</label><input type="text" name="address_zip" value="{{ $customer?->address_zip }}" maxlength="10"></div>
            <div class="field"><label>{{ __('Ort') }}</label><input type="text" name="address_city" value="{{ $customer?->address_city }}"></div>
        </div>
        @if($customer?->address && !$customer?->address_street)
        <p style="font-size:12px;color:var(--ink-soft);">{{ __('Bisher hinterlegt:') }} {{ $customer->address }}</p>
        @endif
    </div>

    {{-- Bankverbindung --}}
    <div class="card">
        <div class="card-title">🏦 {{ __('Bankverbindung') }}</div>
        <div class="grid-2">
            <div class="field"><label>{{ __('IBAN') }}</label><input type="text" name="iban" value="" placeholder="{{ $customer?->iban ? '••••' . substr($customer->iban, -4) : 'DE…' }}" oninput="this.value=this.value.toUpperCase().replace(/\s/g,'')"></div>
            <div class="field"><label>{{ __('Kontoinhaber') }}</label><input type="text" name="account_holder" value="{{ $customer?->account_holder }}"></div>
        </div>
        <p style="font-size:12px;color:var(--ink-soft);">🔒 {{ __('Bank- und Steuerdaten werden verschlüsselt gespeichert und erst nach Freigabe übernommen.') }}</p>
    </div>

    <button type="submit" class="btn btn-primary">{{ __('Änderungen einreichen') }}</button>
</form>

{{-- Passwort ändern (wirkt sofort, kein Freigabe-Workflow nötig) --}}
<div class="card" style="margin-top:20px;">
    <div class="card-title">🔑 {{ __('Passwort ändern') }}</div>
    <div style="font-size:13px;color:var(--ink-soft);margin-bottom:14px;">
        {{ __('Sie können Ihr Passwort jederzeit ändern – z. B. nach dem ersten Login mit Ihrem Startpasswort.') }}
    </div>
    <form method="POST" action="{{ route('portal.profile.password') }}" style="display:grid;gap:12px;max-width:420px;">
        @csrf
        <div class="field">
            <label>{{ __('Aktuelles Passwort') }}</label>
            <input type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="field">
            <label>{{ __('Neues Passwort (mind. 8 Zeichen)') }}</label>
            <input type="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="field">
            <label>{{ __('Neues Passwort bestätigen') }}</label>
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary" style="justify-self:start;">{{ __('Passwort speichern') }}</button>
    </form>
</div>
@endsection
