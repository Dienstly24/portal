@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customer', $customer->id) }}">{{ $customer->user?->name }}</a><span class="breadcrumb-sep">›</span>
        <span>Bearbeiten</span>
    </div>
    <div class="page-title">{{ $customer->user?->name }} bearbeiten</div>
</div>

<form method="POST" action="{{ route('admin.customer.update', $customer->id) }}">
@csrf @method('PUT')
{{-- Tabs --}}
<div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:24px;">
    <button type="button" onclick="showTab('basis')" id="tab-basis" style="padding:11px 18px;border:none;background:none;cursor:pointer;font-size:13.5px;font-weight:700;color:var(--petrol);border-bottom:2px solid var(--petrol);margin-bottom:-2px;">Basisdaten</button>
    <button type="button" onclick="showTab('familie')" id="tab-familie" style="padding:11px 18px;border:none;background:none;cursor:pointer;font-size:13.5px;font-weight:500;color:var(--ink-soft);border-bottom:2px solid transparent;margin-bottom:-2px;">Familie</button>
    <button type="button" onclick="showTab('portal')" id="tab-portal" style="padding:11px 18px;border:none;background:none;cursor:pointer;font-size:13.5px;font-weight:500;color:var(--ink-soft);border-bottom:2px solid transparent;margin-bottom:-2px;">Portal-Zugang</button>
</div>

{{-- Tab: Basisdaten --}}
<div id="section-basis" class="card" style="max-width:760px;">
    <div class="card-title" style="margin-bottom:20px;">Persönliche Daten</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        @php $__np = explode(' ', trim($customer->user?->name ?? ''), 2); @endphp
        <div class="field"><label>Vorname *</label><input type="text" name="first_name" required value="{{ $__np[0] ?? '' }}"></div>
        <div class="field"><label>Nachname *</label><input type="text" name="last_name" required value="{{ $__np[1] ?? '' }}"></div>
        <div class="field"><label>Geburtsdatum</label><input type="date" name="birth_date" value="{{ $customer->birth_date }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Nationalität</label><input type="text" name="nationality" value="{{ $customer->nationality }}" placeholder="z.B. Deutsch, Syrisch"></div>
        <div class="field"><label>Beruf</label><input type="text" name="occupation" value="{{ $customer->occupation }}" placeholder="z.B. Ingenieur"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Geschlecht</label>
            <select name="gender" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="">— Nicht angegeben —</option>
                @foreach(\App\Models\Customer::GENDERS as $gkey => $glabel)
                <option value="{{ $gkey }}" {{ $customer->gender === $gkey ? 'selected' : '' }}>{{ $glabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="field"><label>Familienstand</label>
            <select name="marital_status" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="">—</option>
                @foreach(['ledig','verheiratet','geschieden','verwitwet'] as $ms)
                <option value="{{ $ms }}" {{ $customer->marital_status === $ms ? 'selected' : '' }}>{{ ucfirst($ms) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field"><label>Sprache</label>
            <select name="preferred_lang" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="de" {{ $customer->preferred_lang === 'de' ? 'selected' : '' }}>Deutsch</option>
                <option value="ar" {{ $customer->preferred_lang === 'ar' ? 'selected' : '' }}>Arabisch</option>
            </select>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Kundentyp</label>
            <select name="customer_type" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="privat" {{ $customer->customer_type === 'privat' ? 'selected' : '' }}>👤 Privatkunde</option>
                <option value="firma" {{ $customer->customer_type === 'firma' ? 'selected' : '' }}>🏢 Firmenkunde</option>
            </select>
        </div>
        <div class="field"><label>Vertriebspartner (Partnerportal)</label>
            <select name="partner_id" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="">— Kein Partner —</option>
                @foreach($partners as $p)
                <option value="{{ $p->id }}" {{ $customer->partner_id === $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div style="border-top:1px solid var(--line);padding-top:20px;margin-top:4px;">
        <div class="card-title" style="margin-bottom:16px;">Bankverbindung</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>IBAN (Haupt)</label><input type="text" name="iban" value="{{ $customer->iban }}" placeholder="DE89 3704 0044 ..."></div>
            <div class="field"><label>IBAN 2 (Alternativ)</label><input type="text" name="iban2" value="{{ $customer->iban2 }}" placeholder="Optional"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Kontoinhaber (falls abweichend)</label><input type="text" name="account_holder" value="{{ $customer->account_holder }}" placeholder="Optional"></div>
            <div></div>
        </div>
    </div>
<div style="border-top:1px solid var(--line);margin-top:20px;"></div>
    <div class="card-title" style="margin-bottom:20px;">E-Mail & Telefon</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field">
            <label>E-Mail (Haupt) *</label>
            <input type="email" name="email" required value="{{ $customer->user?->email }}"
                placeholder="hauptemail@beispiel.de">
            <div style="font-size:11px;color:var(--ink-soft);margin-top:4px;">⚠ Login-E-Mail — Änderung betrifft den Portal-Zugang</div>
        </div>
        <div class="field"><label>E-Mail 2 (Alternativ)</label><input type="email" name="email2" value="{{ $customer->email2 }}" placeholder="alternativ@beispiel.de"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Telefon</label><input type="tel" name="phone" value="{{ $customer->phone }}" placeholder="+49 40 ..."></div>
        <div class="field"><label>Mobil</label><input type="tel" name="mobile" value="{{ $customer->mobile }}" placeholder="+49 176 ..."></div>
    </div>
    <div style="border-top:1px solid var(--line);padding-top:20px;margin-top:4px;">
        <div class="card-title" style="margin-bottom:16px;">Adressen</div>
        <div style="display:grid;grid-template-columns:3fr 1fr;gap:16px;">
            <div class="field"><label>Straße</label><input type="text" name="street" value="{{ $addr['street'] ?? '' }}" placeholder="Musterstraße"></div>
            <div class="field"><label>Nr.</label><input type="text" name="street_nr" value="{{ $addr['street_nr'] ?? '' }}" placeholder="12"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:16px;">
            <div class="field"><label>PLZ</label><input type="text" name="plz" value="{{ $addr['plz'] ?? '' }}" placeholder="20095"></div>
            <div class="field"><label>Ort</label><input type="text" name="city" value="{{ $addr['city'] ?? '' }}" placeholder="Hamburg"></div>
            <div class="field"><label>Land</label><input type="text" name="country" value="{{ $addr['country'] ?? '' }}" placeholder="Deutschland"></div>
        </div>
        <div class="field"><label>Adresse 2 (Zweitwohnsitz / Postadresse)</label><input type="text" name="address2" value="{{ $customer->address2 }}" placeholder="Optional"></div>
    </div>
    <div style="border-top:1px solid var(--line);padding-top:20px;margin-top:4px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <div class="card-title" style="margin-bottom:0;">🏥 Kranken-, Renten- & Steuerdaten</div>
            <span style="font-size:11.5px;background:#EAF2FB;color:#185FA5;border:1px solid #CFE2F5;padding:3px 10px;border-radius:999px;">🔐 Verschlüsselt gespeichert</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Versicherungsart</label>
                <select name="health_insurance_type" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    <option value="">— Nicht angegeben —</option>
                    <option value="gesetzlich" {{ $customer->health_insurance_type === 'gesetzlich' ? 'selected' : '' }}>Gesetzlich</option>
                    <option value="privat" {{ $customer->health_insurance_type === 'privat' ? 'selected' : '' }}>Privat</option>
                </select>
            </div>
            <div class="field"><label>Krankenkasse</label><input type="text" name="health_insurance_company" value="{{ $customer->health_insurance_company }}" placeholder="z.B. TK, AOK"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Krankenversicherungsnummer</label><input type="text" name="health_insurance_number" value="{{ $customer->health_insurance_number }}" placeholder="KV-Nummer"></div>
            <div class="field"><label>Rentenversicherungsnummer</label><input type="text" name="pension_insurance_number" value="{{ $customer->pension_insurance_number }}" placeholder="z.B. 65 170439 K 001"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Steuer-ID (optional)</label><input type="text" name="tax_id" value="{{ $customer->tax_id }}" placeholder="11-stellige Steuer-ID"></div>
            <div></div>
        </div>
    </div>

</div>

{{-- Tab: Familie & Fahrzeuge --}}
<div id="section-familie" class="card" style="max-width:760px;display:none;">
    <div class="card-title" style="margin-bottom:20px;">Familie & Kinder</div>
    @php $family = $customer->family ?? collect(); @endphp
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">
    @forelse($family as $f)
    @php
        $age = $f->birth_date ? \Carbon\Carbon::parse($f->birth_date)->age : null;
        $g = strtolower($f->geschlecht ?? '');
        $isKind = $f->relation === 'Kind';
        $icon = $isKind ? ($g === 'w' ? '👧' : ($g === 'm' ? '👦' : '🧒')) : ($g === 'w' ? '👩' : ($g === 'm' ? '👨' : '👤'));
    @endphp
    <div style="border:1px solid var(--line);border-radius:12px;padding:14px;text-align:center;position:relative;background:#FAFBFC;">
        <a href="{{ route('admin.customer.family.delete', $f->id) }}" onclick="return confirm('Familienmitglied wirklich entfernen?')" style="position:absolute;top:6px;right:10px;color:#A32D2D;text-decoration:none;font-size:13px;">✕</a>
        <div style="font-size:34px;">{{ $icon }}</div>
        <div style="font-size:13px;font-weight:700;margin-top:6px;">{{ $f->name }}</div>
        <div style="font-size:11.5px;color:var(--ink-soft);margin-top:2px;">{{ $f->relation }}{{ $age !== null ? ' · '.$age.' J.' : '' }}{{ $g ? ' · '.$g : '' }}</div>
    </div>
    @empty
    <div style="grid-column:1/-1;text-align:center;padding:12px;color:var(--ink-soft);font-size:13px;">Keine Familienmitglieder vorhanden</div>
    @endforelse
</div>

    <div style="border:1px dashed var(--line);border-radius:10px;padding:16px;margin-top:16px;">
        <div style="font-size:13px;font-weight:600;margin-bottom:12px;">+ Neues Familienmitglied hinzufügen</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Name *</label><input type="text" name="family_name[]" placeholder="Vor- und Nachname"></div>
            <div class="field"><label>Verwandtschaft</label>
                <select name="family_relation[]" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    <option>Kind</option><option>Ehepartner</option><option>Elternteil</option><option>Geschwister</option><option>Sonstige</option>
                </select>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Geburtsdatum</label><input type="date" name="family_birth[]"></div>
            <div class="field"><label>Krankenversicherungsnr.</label><input type="text" name="family_kv_nr[]" placeholder="z.B. A123456789"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Krankenkasse</label><input type="text" name="family_kv_company[]" placeholder="z.B. TK, AOK"></div>
            <div class="field"><label>KV-Status</label>
                <select name="family_kv_status[]" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    <option value="">—</option>
                    <option value="mitglied">Mitglied</option>
                    <option value="familienversichert">Familienversichert</option>
                </select>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Versicherungsbeginn</label><input type="date" name="family_kv_start[]"></div>
            <div class="field"><label>Steuernummer / Steuer-ID</label><input type="text" name="family_steuer[]" placeholder="z.B. 12 345 678 901"></div>
            <div class="field"><label>Geschlecht</label>
                <select name="family_geschlecht[]" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    <option value="">—</option><option value="m">Männlich (m)</option><option value="w">Weiblich (w)</option>
                </select>
            </div>
        </div>
    </div>
    <div style="font-size:12px;color:var(--ink-soft);margin-top:10px;">💡 Neue Einträge werden beim Klick auf „Speichern“ übernommen.</div>
</div>

{{-- Tab: Firma --}}
<div id="section-firma" class="card" style="max-width:760px;display:none;">
    <div class="card-title" style="margin-bottom:20px;">Firmendaten</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Firmenname</label><input type="text" name="company_name" value="{{ $customer->company_name }}" placeholder="Firmenname GmbH"></div>
        <div class="field"><label>Rechtsform</label>
            <select name="company_type" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="">—</option>
                @foreach(['Einzelunternehmen','GmbH','UG (haftungsbeschränkt)','AG','GbR','OHG','KG','GmbH & Co. KG','e.K.','e.V.'] as $t)
                <option value="{{ $t }}" {{ $customer->company_type === $t ? 'selected' : '' }}>{{ $t }}</option>
                @endforeach
            </select>
        </div>
    </div>
</div>

{{-- Tab: Portal --}}
<div id="section-portal" class="card" style="max-width:760px;display:none;">
    <div class="card-title" style="margin-bottom:20px;">Portal-Zugang</div>
    @php
        $hasPortalAccess = !str_contains($customer->user?->email ?? '', '@dienstly24.internal');
        $psEdit = $customer->portalStatus();
    @endphp
    <div style="padding:16px;border-radius:10px;background:{{ $psEdit['bg'] }};margin-bottom:20px;">
        <div style="font-weight:600;font-size:14px;color:{{ $psEdit['color'] }};">{{ $psEdit['label'] }}</div>
        <div style="font-size:12.5px;margin-top:6px;color:var(--ink-soft);">
            Einladung: {{ $customer->user?->invitation_sent_at?->format('d.m.Y') ?? '—' }}
            · Passwort gesetzt: {{ $customer->user?->portal_password_set_at ? 'Ja' : 'Nein' }}
            · Erster Login: {{ $customer->user?->first_login_at?->format('d.m.Y') ?? '—' }}
            · Letzter Login: {{ $customer->user?->last_login_at?->format('d.m.Y') ?? '—' }}
        </div>
        <div style="font-size:12px;margin-top:6px;color:var(--ink-soft);">Aktionen (Einladung, Reset, Deaktivieren) finden Sie in der Kundenakte.</div>
    </div>
    @if(!$hasPortalAccess)
    <div class="field">
        <label>Echte E-Mail-Adresse eingeben (aktiviert Portal-Zugang)</label>
        <input type="email" name="portal_email" placeholder="kunde@beispiel.de">
        <div style="font-size:11px;color:var(--ink-soft);margin-top:4px;">Nach dem Speichern kann der Kunde eine Einladungs-E-Mail erhalten.</div>
    </div>
    @endif
    <div class="field">
        <label>Neues Passwort setzen (optional)</label>
        <input type="password" name="new_password" placeholder="Leer lassen = kein Wechsel">
    </div>
</div>

<div style="display:flex;gap:12px;max-width:760px;margin-top:8px;">
    <button type="submit" class="btn btn-primary">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        Speichern
    </button>
    <a href="{{ route('admin.customer', $customer->id) }}" class="btn btn-ghost">Abbrechen</a>
</div>
</form>

<script>
window.IS_FIRMA = {{ ($customer->customer_type ?? 'privat') === 'firma' ? 'true' : 'false' }};
function showTab(tab) {
    ['basis','familie','portal'].forEach(t => {
        document.getElementById('section-' + t).style.display = t === tab ? 'block' : 'none';
        const btn = document.getElementById('tab-' + t);
        btn.style.fontWeight = t === tab ? '700' : '500';
        btn.style.color = t === tab ? 'var(--petrol)' : 'var(--ink-soft)';
        btn.style.borderBottomColor = t === tab ? 'var(--petrol)' : 'transparent';
    });
    var f = document.getElementById('section-firma');
    if (f) { f.style.display = (tab === 'basis' && window.IS_FIRMA) ? 'block' : 'none'; }
}
showTab('basis');
</script>
@endsection
