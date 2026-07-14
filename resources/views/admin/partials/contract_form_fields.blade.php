{{--
    Gemeinsame Vertragsfelder für Anlegen (contract_new / contract_create) und
    Bearbeiten (contract_edit). Erwartet optional $contract (Bestandsvertrag).
    Der umschließende <form>, die Kundenauswahl und der Submit-Button liegen in
    der jeweiligen Seite. Sparten-Liste zentral aus Contract::TYPES.
--}}
@php
    $c   = $contract ?? null;
    $veh = $c?->vehicleDetail;
    $en  = $c?->energyDetail;
    $net = $c?->internetDetail;
    $val = fn($field, $default = '') => old($field, $default);
    $curType   = old('type', $c->type ?? '');
    $curStatus = old('status', $c->status ?? 'active');
    $curSub    = old('subtype', $c->subtype ?? '');
@endphp

<div class="field">
    <label style="font-weight:700;font-size:15px;">Sparte *</label>
    <select name="type" id="sparte" required onchange="contractToggleSections()"
        style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
        <option value="">Bitte auswählen</option>
        @foreach(\App\Models\Contract::TYPES as $key => $cfg)
        <option value="{{ $key }}" {{ $curType === $key ? 'selected' : '' }}>{{ $cfg['icon'] }} {{ $cfg['label'] }}</option>
        @endforeach
    </select>
</div>

{{-- Sonstige: Freitext, was für ein Vertrag das ist (z.B. ADAC Schutzbrief) --}}
<div id="type-other-wrap" class="field" style="display:none;">
    <label>Was für ein Vertrag? *</label>
    <input type="text" name="type_other" maxlength="120" value="{{ $val('type_other', $c->type_other ?? '') }}"
        placeholder="z. B. ADAC Schutzbrief, Mobil-Club (ACV), Reise-Schutz ...">
</div>

{{-- Krankenversicherung: GKV/PKV steuert die Wechsel-Erinnerungen --}}
<div id="subtype-wrap-krankenversicherung" class="field subtype-wrap" style="display:none;">
    <label>Art der Krankenversicherung</label>
    <select name="subtype" data-subtype-for="krankenversicherung" disabled style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
        <option value="">— bitte wählen —</option>
        <option value="gkv" {{ $curSub === 'gkv' ? 'selected' : '' }}>Gesetzlich (GKV) – erhält Wechsel-Erinnerung nach 12 Monaten</option>
        <option value="pkv" {{ $curSub === 'pkv' ? 'selected' : '' }}>Privat (PKV) – keine Wechsel-Erinnerung</option>
    </select>
</div>

{{-- Krankenzusatz: Art der Zusatzversicherung (rein beschreibend, keine Erinnerung).
     Gleiche subtype-Spalte wie oben; nur das aktive Feld wird abgeschickt (disabled). --}}
<div id="subtype-wrap-krankenzusatz" class="field subtype-wrap" style="display:none;">
    <label>Art der Krankenzusatz</label>
    <select name="subtype" data-subtype-for="krankenzusatz" disabled style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
        <option value="">— bitte wählen —</option>
        @foreach(\App\Models\Contract::SUBTYPES['krankenzusatz'] as $ok => $ol)
        <option value="{{ $ok }}" {{ $curSub === $ok ? 'selected' : '' }}>{{ $ol }}</option>
        @endforeach
    </select>
</div>

<div style="border-top:1px solid var(--line);margin:24px 0;"></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div class="field"><label>Versicherer / Anbieter *</label><input type="text" name="insurer" required value="{{ $val('insurer', $c->insurer ?? '') }}" placeholder="z.B. Allianz, HUK-Coburg..."></div>
    <div class="field">
        {{-- Bei Energievertraegen (Strom/Gas) heisst dieses Feld "Vertragsnummer"
             statt "Versicherungsnummer (VSNR)" - per JS umgeschaltet. --}}
        <label id="contract-number-label">Versicherungsnummer (VSNR)</label>
        <input type="text" name="contract_number" maxlength="255" value="{{ $val('contract_number', $c->contract_number ?? '') }}" placeholder="Optional – später nachtragbar">
        <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">Leer lassen, falls die echte Nummer noch nicht vorliegt. Es wird keine automatische Nummer erzeugt.</div>
    </div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
    <div class="field"><label>Status *</label>
        <select name="status" required style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            @foreach(['active'=>'Aktiv','pending'=>'In Bearbeitung','cancelled'=>'Gekündigt','expired'=>'Abgelaufen'] as $sk => $sl)
            <option value="{{ $sk }}" {{ $curStatus === $sk ? 'selected' : '' }}>{{ $sl }}</option>
            @endforeach
        </select>
    </div>
    <div class="field"><label>Beginn</label><input type="date" name="start_date" value="{{ $val('start_date', $c && $c->start_date ? \Carbon\Carbon::parse($c->start_date)->format('Y-m-d') : '') }}"></div>
    <div class="field"><label>Ablauf</label><input type="date" name="end_date" value="{{ $val('end_date', $c && $c->end_date ? \Carbon\Carbon::parse($c->end_date)->format('Y-m-d') : '') }}"></div>
</div>
<div style="display:grid;grid-template-columns:1fr 2fr;gap:16px;">
    <div class="field"><label>Kündigungsdatum</label><input type="date" name="cancellation_date" value="{{ $val('cancellation_date', $c && $c->cancellation_date ? \Carbon\Carbon::parse($c->cancellation_date)->format('Y-m-d') : '') }}"></div>
    <div class="field"><label>Notizen</label><input type="text" name="notes" value="{{ $val('notes', $c->notes ?? '') }}" placeholder="Interne Notizen..."></div>
</div>

{{-- ===== KFZ ===== --}}
<div id="section-kfz" class="branch-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;">
    <div class="card-title" style="font-size:14px;">🚗 Fahrzeug & Einstufung</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Kennzeichen</label><input type="text" name="vehicle[license_plate]" maxlength="20" value="{{ $val('vehicle.license_plate', $veh->license_plate ?? '') }}" placeholder="HH-AB 1234"></div>
        <div class="field"><label>Fahrzeugtyp</label><input type="text" name="vehicle[vehicle_type]" maxlength="50" value="{{ $val('vehicle.vehicle_type', $veh->vehicle_type ?? '') }}" placeholder="PKW"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Hersteller</label><input type="text" name="vehicle[manufacturer]" value="{{ $val('vehicle.manufacturer', $veh->manufacturer ?? '') }}" placeholder="VW"></div>
        <div class="field"><label>Modell</label><input type="text" name="vehicle[model]" value="{{ $val('vehicle.model', $veh->model ?? '') }}" placeholder="Golf VIII"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>FIN / Fahrgestellnummer</label><input type="text" name="vehicle[vin]" maxlength="30" value="{{ $val('vehicle.vin', $veh->vin ?? '') }}"></div>
        <div class="field"><label>Erstzulassung</label><input type="date" name="vehicle[first_registration]" value="{{ $val('vehicle.first_registration', $veh && $veh->first_registration ? \Carbon\Carbon::parse($veh->first_registration)->format('Y-m-d') : '') }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>SF-Klasse Haftpflicht</label><input type="text" name="vehicle[sf_liability_class]" maxlength="10" value="{{ $val('vehicle.sf_liability_class', $veh->sf_liability_class ?? '') }}" placeholder="SF 12"></div>
        <div class="field"><label>SF-Jahr Haftpflicht</label><input type="number" name="vehicle[sf_liability_year]" min="1950" max="2100" value="{{ $val('vehicle.sf_liability_year', $veh->sf_liability_year ?? '') }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>SF-Klasse Vollkasko</label><input type="text" name="vehicle[sf_comprehensive_class]" maxlength="10" value="{{ $val('vehicle.sf_comprehensive_class', $veh->sf_comprehensive_class ?? '') }}" placeholder="SF 10"></div>
        <div class="field"><label>SF-Jahr Vollkasko</label><input type="number" name="vehicle[sf_comprehensive_year]" min="1950" max="2100" value="{{ $val('vehicle.sf_comprehensive_year', $veh->sf_comprehensive_year ?? '') }}"></div>
    </div>
    <div class="field">
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" id="has-claims" {{ ($veh && $veh->has_claims) ? 'checked' : '' }} onchange="document.getElementById('claims-area').style.display=this.checked?'block':'none'"> Schäden vorhanden</label>
    </div>
    <div id="claims-area" style="display:{{ ($veh && $veh->has_claims) ? 'block' : 'none' }};">
        <div id="claims-list"></div>
        <button type="button" class="btn btn-ghost" style="font-size:12.5px;" onclick="addClaimRow()">+ Schaden hinzufügen</button>
    </div>
</div>

{{-- ===== Energie (Strom & Gas) ===== --}}
<div id="section-energy" class="branch-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;">
    <div class="card-title" style="font-size:14px;">⚡ Energievertrag (Strom / Gas)</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Tarif</label><input type="text" name="energy[tariff]" value="{{ $val('energy.tariff', $en->tariff ?? '') }}"></div>
        {{-- Energievertraege haben zusaetzlich eine Kundennummer beim Anbieter
             (getrennt von der Vertragsnummer oben). --}}
        <div class="field"><label>Kundennummer (beim Anbieter)</label><input type="text" name="energy[customer_number]" maxlength="60" value="{{ $val('energy.customer_number', $en->customer_number ?? '') }}" placeholder="Kundennummer des Energieanbieters"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Verbrauch (kWh/Jahr)</label><input type="number" name="energy[consumption_kwh]" min="0" value="{{ $val('energy.consumption_kwh', $en->consumption_kwh ?? '') }}"></div>
        <div class="field"><label>Zählernummer</label><input type="text" name="energy[meter_number]" maxlength="60" value="{{ $val('energy.meter_number', $en->meter_number ?? '') }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Marktlokations-ID (MaLo-ID, 11 Ziffern)</label><input type="text" name="energy[malo_id]" maxlength="11" pattern="[0-9]{11}" value="{{ $val('energy.malo_id', $en->malo_id ?? '') }}" placeholder="Nicht die Zählernummer!"></div>
        <div class="field"><label>Zählerstand (optional)</label><input type="text" name="energy[meter_reading]" maxlength="30" value="{{ $val('energy.meter_reading', $en->meter_reading ?? '') }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Netzbetreiber (optional)</label><input type="text" name="energy[grid_operator]" value="{{ $val('energy.grid_operator', $en->grid_operator ?? '') }}"></div>
        <div class="field"><label>Messstellenbetreiber (optional)</label><input type="text" name="energy[metering_operator]" value="{{ $val('energy.metering_operator', $en->metering_operator ?? '') }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Abschlag (€)</label><input type="number" step="0.01" name="energy[payment_amount]" min="0" value="{{ $val('energy.payment_amount', $en->payment_amount ?? '') }}"></div>
        <div class="field"><label>Zahlungsintervall</label>
            <select name="energy[payment_interval]" style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                @php $curInt = old('energy.payment_interval', $en->payment_interval ?? ''); @endphp
                <option value="">—</option>
                @foreach(['monatlich'=>'Monatlich','vierteljaehrlich'=>'Vierteljährlich','halbjaehrlich'=>'Halbjährlich','jaehrlich'=>'Jährlich'] as $ik => $il)
                <option value="{{ $ik }}" {{ $curInt === $ik ? 'selected' : '' }}>{{ $il }}</option>
                @endforeach
            </select>
        </div>
    </div>
</div>

{{-- ===== Internet ===== --}}
<div id="section-internet" class="branch-section" style="display:none;border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;">
    <div class="card-title" style="font-size:14px;">📶 Internet & Mobilfunk</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="field"><label>Tarif</label><input type="text" name="internet[tariff]" value="{{ $val('internet.tariff', $net->tariff ?? '') }}"></div>
        <div class="field"><label>Geschwindigkeit</label><input type="text" name="internet[speed]" maxlength="30" value="{{ $val('internet.speed', $net->speed ?? '') }}" placeholder="250 Mbit/s"></div>
    </div>
</div>

<script>
function contractToggleSections() {
    const type = document.getElementById('sparte').value;
    // Strom und Gas sind getrennte Sparten, teilen sich aber das Energie-Formular.
    const energyTypes = ['strom', 'gas'];
    document.querySelectorAll('.branch-section').forEach(el => el.style.display = 'none');
    const active = energyTypes.includes(type)
        ? document.getElementById('section-energy')
        : document.getElementById('section-' + type);
    if (active) active.style.display = 'block';
    document.getElementById('type-other-wrap').style.display = (type === 'andere') ? 'block' : 'none';
    // Untergruppe (subtype) je Sparte: nur das passende Feld anzeigen UND aktivieren.
    // disabled steuert, welcher Wert abgeschickt wird - sonst kaemen zwei subtype-Werte an.
    document.querySelectorAll('.subtype-wrap').forEach(w => w.style.display = 'none');
    document.querySelectorAll('select[data-subtype-for]').forEach(s => s.disabled = true);
    const subWrap = document.getElementById('subtype-wrap-' + type);
    if (subWrap) {
        subWrap.style.display = 'block';
        const sel = subWrap.querySelector('select[data-subtype-for]');
        if (sel) sel.disabled = false;
    }
    // Energievertraege haben eine Vertragsnummer statt einer Versicherungsnummer.
    const lbl = document.getElementById('contract-number-label');
    if (lbl) lbl.textContent = energyTypes.includes(type) ? 'Vertragsnummer' : 'Versicherungsnummer (VSNR)';
}

let claimIndex = 0;
function addClaimRow(month, year, ctype) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;';
    const sel = t => `<option value="${t}" ${ctype===t?'selected':''}>${t.charAt(0).toUpperCase()+t.slice(1)}</option>`;
    wrap.innerHTML = `
        <input type="number" name="vehicle[claims][${claimIndex}][month]" min="1" max="12" placeholder="Monat" value="${month??''}" style="width:90px;padding:8px;border:1px solid var(--line);border-radius:8px;">
        <input type="number" name="vehicle[claims][${claimIndex}][year]" min="1990" max="2100" placeholder="Jahr" value="${year??''}" style="width:100px;padding:8px;border:1px solid var(--line);border-radius:8px;">
        <select name="vehicle[claims][${claimIndex}][type]" style="padding:8px;border:1px solid var(--line);border-radius:8px;">
            ${sel('haftpflicht')}${sel('vollkasko')}${sel('teilkasko')}
        </select>
        <button type="button" onclick="this.parentElement.remove()" style="border:none;background:none;cursor:pointer;">✕</button>`;
    document.getElementById('claims-list').appendChild(wrap);
    claimIndex++;
}

document.addEventListener('DOMContentLoaded', function () {
    contractToggleSections();
    @if($veh && $veh->has_claims && is_array($veh->claims))
        @foreach($veh->claims as $claim)
        addClaimRow({{ (int)($claim['month'] ?? 0) ?: 'null' }}, {{ (int)($claim['year'] ?? 0) ?: 'null' }}, @json($claim['type'] ?? 'haftpflicht'));
        @endforeach
    @endif
});
</script>
