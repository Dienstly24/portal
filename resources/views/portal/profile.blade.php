@extends('layouts.portal')
@section('content')
<div class="page-title">{{ __('Meine Daten') }}</div>
<div class="page-sub">{{ __('Persönliche Daten, Adresse und Bankverbindung an einem Ort. Änderungen werden von unserem Team geprüft – Sie können mehrere Änderungen gleichzeitig einreichen.') }}</div>

@if($pending > 0)<div class="notice">Sie haben aktuell {{ $pending }} Änderung(en) in Prüfung. <a href="{{ route('portal.change_requests') }}" style="color:var(--graphite);font-weight:600;">{{ __('Status ansehen →') }}</a></div>@endif

<form method="POST" action="{{ route('portal.profile.update') }}" enctype="multipart/form-data">
    @csrf

    {{-- Persönliche Daten --}}
    <div class="card">
        <div class="card-title">👤 {{ __('Persönliche Daten') }}</div>
        @php $__np = explode(' ', trim(auth()->user()->name ?? ''), 2); @endphp
        <div class="grid-2">
            <div class="field"><label>{{ __('Vorname') }} *</label><input type="text" name="first_name" required value="{{ old('first_name', $__np[0] ?? '') }}"></div>
            <div class="field"><label>{{ __('Nachname') }} *</label><input type="text" name="last_name" required value="{{ old('last_name', $__np[1] ?? '') }}"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>{{ __('E-Mail') }} *</label><input type="email" name="email" required value="{{ old('email', auth()->user()->hasRealEmail() ? auth()->user()->email : '') }}" placeholder="name@example.com"></div>
            <div class="field"><label>{{ __('Geburtsdatum') }} *</label><input type="date" name="birth_date" required value="{{ old('birth_date', $customer?->birth_date) }}"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>{{ __('Geschlecht') }}</label>
                <select name="gender">
                    <option value="">{{ __('— Bitte wählen —') }}</option>
                    @foreach(\App\Models\Customer::GENDERS as $key => $label)
                    <option value="{{ $key }}" {{ $customer?->gender === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>{{ __('Familienstand') }}</label>
                <select name="marital_status">
                    <option value="">{{ __('— Bitte wählen —') }}</option>
                    @foreach(['ledig'=>'Ledig','verheiratet'=>'Verheiratet','geschieden'=>'Geschieden','verwitwet'=>'Verwitwet'] as $k=>$v)
                    <option value="{{ $k }}" {{ $customer?->marital_status === $k ? 'selected' : '' }}>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="grid-2">
            <div class="field"><label>{{ __('Geburtsort') }} *</label><input type="text" name="birth_place" required value="{{ $customer?->birth_place }}"></div>
            <div class="field"><label>{{ __('Nationalität') }} *</label><input type="text" name="nationality" required value="{{ $customer?->nationality }}" placeholder="{{ __('z.B. Deutsch, Syrisch') }}"></div>
        </div>
        <div class="grid-2">
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
            <div class="field"><label>{{ __('Straße') }} *</label><input type="text" name="address_street" required value="{{ $customer?->address_street }}"></div>
            <div class="field"><label>{{ __('Hausnummer') }} *</label><input type="text" name="address_house_number" required value="{{ $customer?->address_house_number }}"></div>
            <div class="field"><label>{{ __('Zusatz') }}</label><input type="text" name="address_house_suffix" value="{{ $customer?->address_house_suffix }}" placeholder="A, 1a"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>{{ __('Postleitzahl') }} *</label><input type="text" name="address_zip" required value="{{ $customer?->address_zip }}" maxlength="10"></div>
            <div class="field"><label>{{ __('Ort') }} *</label><input type="text" name="address_city" required value="{{ $customer?->address_city }}"></div>
        </div>
        @if($customer?->address && !$customer?->address_street)
        <p class="muted-xs">Bisher hinterlegt: {{ $customer->address }}</p>
        @endif
    </div>

    {{-- Bankverbindung --}}
    <div class="card">
        <div class="card-title">🏦 {{ __('Bankverbindung') }}</div>
        <div class="grid-2">
            <div class="field"><label>{{ __('IBAN') }}</label><input type="text" name="iban" value="" placeholder="{{ $customer?->iban ? '••••' . substr($customer->iban, -4) : 'DE…' }}" data-h-input="1020851b2f"></div>
            <div class="field"><label>{{ __('Kontoinhaber') }}</label><input type="text" name="account_holder" value="{{ $customer?->account_holder }}"></div>
        </div>
        <div class="field"><label>{{ __('Kontonachweis') }} ({{ __('bei neuer IBAN erforderlich') }})</label><input type="file" name="bank_proof" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
        <p class="muted-xs">🔒 {{ __('Foto der Bankkarte oder Kontoauszug – IBAN und Name müssen lesbar sein. Bank- und Steuerdaten werden verschlüsselt gespeichert und erst nach Freigabe übernommen.') }}</p>
    </div>

    {{-- Nachweis fuer Name/Geburtsdatum/Anschrift: ohne Beleg nehmen wir
         diese Aenderungen nicht an (Schutz vor Identitaetsmissbrauch). --}}
    <div class="card">
        <div class="card-title">📎 {{ __('Nachweis') }}</div>
        <p style="font-size:13px;color:var(--ink-soft);margin-bottom:14px;">
            {{ __('Ändern Sie Name, Geburtsdatum oder Anschrift? Dann laden Sie bitte einen Nachweis hoch – Ausweis (Vorder- und Rückseite) oder Meldebescheinigung. Ohne Nachweis können wir diese Änderungen nicht übernehmen.') }}
        </p>
        <div class="grid-2">
            <div class="field">
                <label>{{ __('Art des Nachweises') }}</label>
                <select name="proof_kind">
                    <option value="id_front">{{ __('Ausweis (Vorderseite)') }}</option>
                    <option value="meldebescheinigung">{{ __('Meldebescheinigung') }}</option>
                    <option value="other">{{ __('Anderer Nachweis') }}</option>
                </select>
            </div>
            <div class="field"><label>{{ __('Gültig ab') }}</label><input type="date" name="effective_from" value="{{ old('effective_from') }}"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>{{ __('Nachweis / Vorderseite') }}</label><input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
            <div class="field"><label>{{ __('Rückseite (optional)') }}</label><input type="file" name="proof_back" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
        </div>
        <p class="muted-2xs">{{ __('Erlaubt: PDF oder Foto (JPG, PNG, WEBP), max. 10 MB je Datei.') }}</p>
    </div>

    <button type="submit" class="btn btn-primary">{{ __('Änderungen einreichen') }}</button>
</form>

{{-- Passwort ändern (wirkt sofort, kein Freigabe-Workflow nötig).
     Ohne nutzbares Passwort (Magic-Login, Set-Link nie benutzt) wird
     KEIN aktuelles Passwort abgefragt - der Kunde kennt keines. --}}
@php $hatPasswort = auth()->user()->portal_password_set_at !== null; @endphp
<div class="card" style="margin-top:20px;">
    <div class="card-title">🔑 {{ $hatPasswort ? __('Passwort ändern') : __('Passwort festlegen') }}</div>
    <div style="font-size:13px;color:var(--ink-soft);margin-bottom:14px;">
        @if($hatPasswort)
            {{ __('Sie können Ihr Passwort jederzeit ändern – z. B. nach dem ersten Login mit Ihrem Startpasswort.') }}
        @else
            {{ __('Sie haben noch kein eigenes Passwort. Legen Sie es jetzt fest, um sich künftig direkt anmelden zu können.') }}
        @endif
    </div>
    <form method="POST" action="{{ route('portal.profile.password') }}" style="display:grid;gap:12px;max-width:420px;">
        @csrf
        @if($hatPasswort)
        <div class="field">
            <label>{{ __('Aktuelles Passwort') }}</label>
            <input type="password" name="current_password" required autocomplete="current-password">
        </div>
        @endif
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

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["1020851b2f"] = function (event) { this.value=this.value.toUpperCase().replace(/\s/g,'') };
</script>
@endPushOnce
