@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span><span>Neu</span></div>
    <div class="page-title">Neuen Kunden erstellen</div>
</div>

<form method="POST" action="{{ route('admin.customers.store') }}">
@csrf

@if($errors->any())
<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:16px;margin-bottom:20px;max-width:800px;">
    <div style="font-weight:700;color:#A32D2D;margin-bottom:8px;">Bitte korrigieren Sie folgende Fehler:</div>
    @foreach($errors->all() as $error)
    <div style="font-size:13px;color:#A32D2D;">• {{ $error }}</div>
    @endforeach
</div>
@endif

<div class="card" style="max-width:800px;">
    <div class="card-title" style="margin-bottom:20px;">Persönliche Daten</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Vorname *</label><input type="text" name="first_name" required value="{{ old('first_name') }}" placeholder="Max"></div>
        <div class="field"><label>Nachname *</label><input type="text" name="last_name" required value="{{ old('last_name') }}" placeholder="Mustermann"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Geburtsdatum</label><input type="date" name="birth_date" value="{{ old('birth_date') }}"></div>
        <div class="field"><label>Telefon</label><input type="tel" name="phone" value="{{ old('phone') }}" placeholder="+49 40 ..."></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Mobil</label><input type="tel" name="mobile" value="{{ old('mobile') }}" placeholder="+49 176 ..."></div>
                <div class="field"><label>Geschlecht</label>
            <select name="gender" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="">— Nicht angegeben —</option>
                @foreach(\App\Models\Customer::GENDERS as $gkey => $glabel)
                <option value="{{ $gkey }}" >{{ $glabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="field"><label>Familienstand</label>
            <select name="marital_status" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="">—</option>
                <option value="ledig">Ledig</option>
                <option value="verheiratet">Verheiratet</option>
                <option value="geschieden">Geschieden</option>
                <option value="verwitwet">Verwitwet</option>
            </select>
        </div>
    </div>
</div>

<div class="card" style="max-width:800px;">
    <div class="card-title" style="margin-bottom:20px;">Kontakt & Adresse</div>
    <div class="field"><label>E-Mail *</label><input type="email" name="email" required value="{{ old('email') }}" placeholder="max@beispiel.de"></div>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;">
        <div class="field"><label>Straße</label><input type="text" name="street" value="{{ old('street') }}" placeholder="Musterstraße"></div>
        <div class="field"><label>Hausnummer</label><input type="text" name="street_nr" value="{{ old('street_nr') }}" placeholder="12"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:16px;">
        <div class="field"><label>PLZ</label><input type="text" name="plz" value="{{ old('plz') }}" placeholder="20095"></div>
        <div class="field"><label>Ort</label><input type="text" name="city" value="{{ old('city') }}" placeholder="Hamburg"></div>
        <div class="field"><label>Land</label>
            <select name="country" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="Deutschland" selected>Deutschland</option>
                <option value="Österreich">Österreich</option>
                <option value="Schweiz">Schweiz</option>
            </select>
        </div>
    </div>
</div>

<div class="card" style="max-width:800px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
        <div class="card-title" style="margin-bottom:0;">Bankverbindung (optional)</div>
        <span style="font-size:11.5px;background:#EAF2FB;color:#185FA5;border:1px solid #CFE2F5;padding:3px 10px;border-radius:999px;">🔐 Verschlüsselt gespeichert</span>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;">
        <div class="field"><label>IBAN</label><input type="text" name="iban" value="{{ old('iban') }}" placeholder="DE89 3704 0044 0532 0130 00"></div>
        <div class="field"><label>Kontoinhaber</label><input type="text" name="account_holder" value="{{ old('account_holder') }}" placeholder="Abweichend vom Kunden?"></div>
    </div>
</div>

<div class="card" style="max-width:800px;">
    <div class="card-title" style="margin-bottom:20px;">Portal-Zugang</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field">
            <label>Passwort (optional)</label>
            <input type="password" name="password" placeholder="Leer lassen = Startpasswort-Flow">
            <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">
                Leer lassen: Der Kunde erhält sein <strong>Geburtsdatum (TT.MM.JJJJ)</strong> als Startpasswort
                bzw. ohne Geburtsdatum einen Link zum Selbst-Festlegen – inkl. Einladungs-Mail mit Anleitung.
            </div>
        </div>
        <div class="field"><label>Sprache</label>
            <select name="preferred_lang" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="de">Deutsch</option>
                <option value="ar">Arabisch</option>
            </select>
        </div>
    </div>
    <div class="field"><label>Kundentyp *</label>
        <select name="customer_type" onchange="document.getElementById('firma-fields').style.display = this.value === 'firma' ? 'block' : 'none';" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            <option value="privat">👤 Privatkunde</option>
            <option value="firma">🏢 Gewerbe / Firma</option>
        </select>
    </div>
    <div id="firma-fields" style="display:none;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Firmenname</label><input type="text" name="company_name" placeholder="Firmenname GmbH"></div>
            <div class="field"><label>Rechtsform</label>
                <select name="company_type" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    <option value="">—</option>
                    <option>Einzelunternehmen</option><option>GmbH</option><option>UG (haftungsbeschränkt)</option><option>AG</option><option>GbR</option><option>OHG</option><option>KG</option><option>GmbH &amp; Co. KG</option><option>e.K.</option><option>e.V.</option>
                </select>
            </div>
        </div>
    </div>
</div>

<div style="display:flex;gap:12px;max-width:800px;">
    <button type="submit" class="btn btn-primary">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        Kunde anlegen
    </button>
    <a href="{{ route('admin.customers') }}" class="btn btn-ghost">Abbrechen</a>
</div>
</form>
@endsection
