{{--
    KFZ-Vertragsfelder (Redesign 17.07.2026): Button-Oberflaeche nach dem
    Vorbild deutscher Versicherer. Jede Auswahl ist ein Klick, abhaengige
    Optionen erscheinen erst bei Bedarf (Teilkasko -> SB, Vollkasko nur mit
    Teilkasko, Sondereinstufung -> Grund + tatsaechliche Klasse).
    Erwartet $veh (ContractVehicleDetail|null) aus contract_form_fields.
    Alle Kataloge kommen aus ContractVehicleDetail - dort ergaenzen.
--}}
@php
    use App\Models\ContractVehicleDetail as VD;
    use App\Models\VehicleClaim;

    $dfmt = fn($d) => $d ? \Carbon\Carbon::parse($d)->format('Y-m-d') : '';
    $vd  = fn($field, $default = '') => old("vehicle.$field", $default);

    $curVehicleType = $vd('vehicle_type', $veh->vehicle_type ?? '');
    $curCondition   = $vd('vehicle_condition', $veh->vehicle_condition ?? '');
    $curFuel        = $vd('fuel_type', $veh->fuel_type ?? '');
    $curTransmission= $vd('transmission', $veh->transmission ?? '');
    $curHolder      = $vd('holder_type', $veh->holder_type ?? '');
    $curOwnership   = $vd('ownership_type', $veh->ownership_type ?? '');
    $curAnnual      = (string) $vd('annual_mileage', $veh->annual_mileage ?? '');
    // Bestand mit "krummer" Fahrleistung (z.B. 18.500 km) -> Chip "Eigene
    // Fahrleistung" vorwaehlen und den Wert ins Freifeld uebernehmen.
    $isCustomAnnual = $curAnnual === 'custom'
        || ($curAnnual !== '' && !in_array((int) $curAnnual, VD::ANNUAL_MILEAGE_OPTIONS, true));
    $customAnnual   = (string) $vd('annual_mileage_custom', $isCustomAnnual && $curAnnual !== 'custom' ? $curAnnual : '');

    // Booleans kommen als '0'/'1' aus hidden+checkbox zurueck.
    $hasTk = (string) $vd('has_teilkasko', ($veh->has_teilkasko ?? false) ? '1' : '0') === '1';
    $hasVk = (string) $vd('has_vollkasko', ($veh->has_vollkasko ?? false) ? '1' : '0') === '1';
    $curTkSb = (string) $vd('teilkasko_deductible', $veh->teilkasko_deductible ?? '');
    $curVkSb = (string) $vd('vollkasko_deductible', $veh->vollkasko_deductible ?? '');

    $extrasSel  = (array) old('vehicle.extras', $veh->extras ?? []);
    $driversSel = (array) old('vehicle.driver_groups', $veh->driver_groups ?? []);
    $addDrivers = array_values(array_filter((array) old('vehicle.additional_drivers', $veh->additional_drivers ?? []), 'is_array'));

    // SF je Sparte: aktuelle Klasse, gueltig ab, Art, Grund, tatsaechliche Klasse
    $sfL = [
        'class'  => $vd('sf_liability_class', $veh->sf_liability_class ?? ''),
        'from'   => $vd('sf_liability_valid_from', $dfmt($veh->sf_liability_valid_from ?? null)),
        'type'   => $vd('sf_liability_type', $veh->sf_liability_type ?? 'tatsaechlich') ?: 'tatsaechlich',
        'reason' => $vd('sf_liability_special_reason', $veh->sf_liability_special_reason ?? ''),
        'real'   => $vd('sf_liability_real_class', $veh->sf_liability_real_class ?? ''),
    ];
    $sfV = [
        'class'  => $vd('sf_comprehensive_class', $veh->sf_comprehensive_class ?? ''),
        'from'   => $vd('sf_comprehensive_valid_from', $dfmt($veh->sf_comprehensive_valid_from ?? null)),
        'type'   => $vd('sf_comprehensive_type', $veh->sf_comprehensive_type ?? 'tatsaechlich') ?: 'tatsaechlich',
        'reason' => $vd('sf_comprehensive_special_reason', $veh->sf_comprehensive_special_reason ?? ''),
        'real'   => $vd('sf_comprehensive_real_class', $veh->sf_comprehensive_real_class ?? ''),
    ];

    // Schaeden: nach Validierungsfehler die alten Eingaben, sonst DB-Bestand.
    $claimRows = old('vehicle.claim_rows');
    if ($claimRows === null) {
        $claimRows = $veh
            ? $veh->claims->map(fn($cl) => [
                'claim_date' => $cl->claim_date?->format('Y-m-d'),
                'claim_type' => $cl->claim_type,
                'damage_amount' => $cl->damage_amount !== null ? rtrim(rtrim(number_format((float) $cl->damage_amount, 2, '.', ''), '0'), '.') : '',
                'status' => $cl->status,
                'insurer' => $cl->insurer,
                'notes' => $cl->notes,
            ])->values()->all()
            : [];
    }
    $claimRows = array_values(array_filter((array) $claimRows, 'is_array'));

    $latestReading = $veh?->latestMileageReading();
    $mileageStatus = $veh?->mileageStatus();
    $sfHistory = $veh ? $veh->sfHistory : collect();
    $kfzInputStyle = 'width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;background:var(--surface);';
@endphp

<style>
.kfz-card{border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:14px;background:var(--surface);}
.kfz-card-title{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.kfz-card-sub{font-size:12px;color:var(--ink-soft);margin:2px 0 12px;}
.kfz-chip{position:relative;display:inline-flex;}
.kfz-chip input{position:absolute;inset:0;opacity:0;cursor:pointer;margin:0;}
.kfz-chip span{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 14px;border:1.5px solid var(--line);border-radius:10px;font-size:13px;font-weight:600;background:var(--surface);color:var(--ink);cursor:pointer;transition:.12s;user-select:none;width:100%;text-align:center;}
.kfz-chip input:checked + span{border-color:#17A65B;background:#E7F6EE;color:#0E7A41;box-shadow:inset 0 0 0 1px #17A65B;}
.kfz-chip input:focus-visible + span{outline:2px solid #17A65B;outline-offset:2px;}
.kfz-chip input:disabled + span{opacity:.4;cursor:not-allowed;}
.kfz-chip.big span{padding:13px 16px;font-size:13.5px;}
.kfz-chip-row{display:flex;flex-wrap:wrap;gap:8px;}
.kfz-chip-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;}
.kfz-fixed-chip{display:inline-flex;align-items:center;gap:7px;padding:13px 16px;border-radius:10px;font-size:13.5px;font-weight:700;background:#17A65B;color:#fff;}
.kfz-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px;}
.kfz-sum-box{background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:10px 12px;min-width:0;}
.kfz-sum-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);font-weight:700;}
.kfz-sum-value{font-size:12.5px;font-weight:700;margin-top:3px;overflow:hidden;text-overflow:ellipsis;}
.kfz-subline{font-size:12.5px;font-weight:700;color:var(--ink-soft);margin:14px 0 8px;text-transform:uppercase;letter-spacing:.04em;}
.kfz-warn{background:#FDF2E3;border:1px solid #EBC894;color:#8A5A1B;border-radius:10px;padding:10px 12px;font-size:12.5px;margin-top:10px;}
.kfz-row-btn{border:1px dashed var(--line);background:transparent;border-radius:10px;padding:9px 14px;font-size:12.5px;font-weight:600;color:var(--ink-soft);cursor:pointer;width:100%;}
.kfz-row-btn:hover{border-color:#17A65B;color:#0E7A41;}
.kfz-item-row{display:grid;gap:8px;margin-bottom:8px;align-items:end;}
.kfz-remove{border:none;background:#F9E3E3;color:#A32D2D;border-radius:8px;width:34px;height:38px;cursor:pointer;font-size:14px;flex:none;}
.kfz-transfer{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;padding:6px 12px;border-radius:999px;}
.kfz-transfer.ok{background:#E7F6EE;color:#0E7A41;}
.kfz-transfer.no{background:#F9E3E3;color:#A32D2D;}
.kfz-sf-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px;}
.kfz-sf-table th{text-align:left;padding:6px 8px;color:var(--ink-soft);font-size:11px;text-transform:uppercase;border-bottom:1px solid var(--line);}
.kfz-sf-table td{padding:7px 8px;border-bottom:1px solid var(--line);}
</style>

{{-- ===== Live-Ueberblick (aktualisiert sich beim Klicken) ===== --}}
<div class="kfz-summary" id="kfz-summary">
    <div class="kfz-sum-box"><div class="kfz-sum-label">Fahrzeug</div><div class="kfz-sum-value" id="kfz-sum-vehicle">—</div></div>
    <div class="kfz-sum-box"><div class="kfz-sum-label">Schutz</div><div class="kfz-sum-value" id="kfz-sum-coverage">Haftpflicht</div></div>
    <div class="kfz-sum-box"><div class="kfz-sum-label">Zusatzleistungen</div><div class="kfz-sum-value" id="kfz-sum-extras">0 gewählt</div></div>
    <div class="kfz-sum-box"><div class="kfz-sum-label">Fahrer</div><div class="kfz-sum-value" id="kfz-sum-drivers">—</div></div>
    <div class="kfz-sum-box"><div class="kfz-sum-label">Fahrleistung</div><div class="kfz-sum-value" id="kfz-sum-mileage">—</div></div>
    <div class="kfz-sum-box"><div class="kfz-sum-label">SF-Klassen</div><div class="kfz-sum-value" id="kfz-sum-sf">—</div></div>
</div>

{{-- ===== Fahrzeugtyp ===== --}}
<div class="kfz-card">
    <div class="kfz-card-title">🚗 Fahrzeugtyp</div>
    <div class="kfz-card-sub">Ein Klick genügt.</div>
    <div class="kfz-chip-grid">
        @foreach(VD::VEHICLE_TYPES as $key => $cfg)
        <label class="kfz-chip big">
            <input type="radio" name="vehicle[vehicle_type]" value="{{ $key }}" data-label="{{ $cfg['label'] }}" {{ $curVehicleType === $key ? 'checked' : '' }}>
            <span>{{ $cfg['icon'] }} {{ $cfg['label'] }}</span>
        </label>
        @endforeach
    </div>
</div>

{{-- ===== Fahrzeugdaten ===== --}}
<div class="kfz-card">
    <div class="kfz-card-title">📋 Fahrzeugdaten</div>
    <div class="kfz-card-sub">Kennzeichen, Identifikation und Technik.</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
        <div class="field"><label>Kennzeichen</label><input type="text" id="kfz-plate" name="vehicle[license_plate]" maxlength="20" value="{{ $vd('license_plate', $veh->license_plate ?? '') }}" placeholder="HH-AB 1234" style="{{ $kfzInputStyle }}"></div>
        <div class="field"><label>Hersteller</label><input type="text" id="kfz-manufacturer" name="vehicle[manufacturer]" value="{{ $vd('manufacturer', $veh->manufacturer ?? '') }}" placeholder="VW" style="{{ $kfzInputStyle }}"></div>
        <div class="field"><label>Modell</label><input type="text" id="kfz-model" name="vehicle[model]" value="{{ $vd('model', $veh->model ?? '') }}" placeholder="Golf VIII" style="{{ $kfzInputStyle }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;">
        <div class="field"><label>FIN (VIN)</label><input type="text" name="vehicle[vin]" maxlength="30" value="{{ $vd('vin', $veh->vin ?? '') }}" placeholder="WVWZZZ..." style="{{ $kfzInputStyle }}"></div>
        <div class="field"><label>HSN</label><input type="text" name="vehicle[hsn]" maxlength="4" inputmode="numeric" pattern="[0-9]{4}" value="{{ $vd('hsn', $veh->hsn ?? '') }}" placeholder="0603" style="{{ $kfzInputStyle }}"></div>
        <div class="field"><label>TSN</label><input type="text" name="vehicle[tsn]" maxlength="10" value="{{ $vd('tsn', $veh->tsn ?? '') }}" placeholder="BJM" style="{{ $kfzInputStyle }}"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
        <div class="field"><label>Erstzulassung</label><input type="date" name="vehicle[first_registration]" value="{{ $vd('first_registration', $dfmt($veh->first_registration ?? null)) }}" style="{{ $kfzInputStyle }}"></div>
        <div class="field"><label>Erwerbsdatum</label><input type="date" name="vehicle[acquisition_date]" value="{{ $vd('acquisition_date', $dfmt($veh->acquisition_date ?? null)) }}" style="{{ $kfzInputStyle }}"></div>
        <div class="field"><label>Leistung (kW)</label><input type="number" name="vehicle[power_kw]" min="1" max="2000" value="{{ $vd('power_kw', $veh->power_kw ?? '') }}" placeholder="110" style="{{ $kfzInputStyle }}"></div>
    </div>
    <div class="kfz-subline">Zustand bei Erwerb</div>
    <div class="kfz-chip-row">
        @foreach(VD::CONDITIONS as $key => $label)
        <label class="kfz-chip"><input type="radio" name="vehicle[vehicle_condition]" value="{{ $key }}" {{ $curCondition === $key ? 'checked' : '' }}><span>{{ $key === 'neuwagen' ? '✨' : '🔄' }} {{ $label }}</span></label>
        @endforeach
    </div>
    <div class="kfz-subline">Kraftstoff</div>
    <div class="kfz-chip-row">
        @foreach(VD::FUEL_TYPES as $key => $label)
        <label class="kfz-chip"><input type="radio" name="vehicle[fuel_type]" value="{{ $key }}" {{ $curFuel === $key ? 'checked' : '' }}><span>{{ $label }}</span></label>
        @endforeach
    </div>
    <div class="kfz-subline">Getriebe</div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
        <div class="kfz-chip-row">
            @foreach(VD::TRANSMISSIONS as $key => $label)
            <label class="kfz-chip"><input type="radio" name="vehicle[transmission]" value="{{ $key }}" {{ $curTransmission === $key ? 'checked' : '' }}><span>{{ $label }}</span></label>
            @endforeach
        </div>
        <div class="field" style="min-width:180px;margin:0;"><label>Farbe</label><input type="text" name="vehicle[color]" maxlength="40" value="{{ $vd('color', $veh->color ?? '') }}" placeholder="schwarz" style="{{ $kfzInputStyle }}"></div>
    </div>
</div>

{{-- ===== Versicherungsschutz (hierarchisch) ===== --}}
<div class="kfz-card">
    <div class="kfz-card-title">🛡️ Versicherungsschutz</div>
    <div class="kfz-card-sub">Haftpflicht ist immer enthalten. Vollkasko setzt Teilkasko voraus.</div>
    {{-- hidden 0 + Checkbox 1: so kommt auch "abgewaehlt" explizit im old() an --}}
    <input type="hidden" name="vehicle[has_teilkasko]" value="0">
    <input type="hidden" name="vehicle[has_vollkasko]" value="0">
    <div class="kfz-chip-row" style="margin-bottom:4px;">
        <span class="kfz-fixed-chip">✓ Haftpflicht (Pflicht)</span>
        <label class="kfz-chip big">
            <input type="checkbox" id="kfz-tk" name="vehicle[has_teilkasko]" value="1" {{ $hasTk ? 'checked' : '' }} onchange="kfzSync()">
            <span>Teilkasko</span>
        </label>
        <label class="kfz-chip big">
            <input type="checkbox" id="kfz-vk" name="vehicle[has_vollkasko]" value="1" {{ $hasVk ? 'checked' : '' }} onchange="kfzSync()">
            <span>Vollkasko</span>
        </label>
    </div>
    <div id="kfz-vk-hint" style="font-size:12px;color:var(--ink-soft);margin-bottom:4px;display:none;">Vollkasko kann erst gewählt werden, wenn Teilkasko aktiv ist.</div>

    <div id="kfz-tk-sb" style="display:none;">
        <div class="kfz-subline">Selbstbeteiligung Teilkasko</div>
        <div class="kfz-chip-row">
            @foreach(VD::TK_DEDUCTIBLES as $sb)
            <label class="kfz-chip"><input type="radio" name="vehicle[teilkasko_deductible]" value="{{ $sb }}" {{ $curTkSb !== '' && (int) $curTkSb === $sb ? 'checked' : '' }}><span>{{ $sb === 0 ? 'ohne SB' : $sb . ' €' }}</span></label>
            @endforeach
        </div>
    </div>
    <div id="kfz-vk-sb" style="display:none;">
        <div class="kfz-subline">Selbstbeteiligung Vollkasko</div>
        <div class="kfz-chip-row">
            @foreach(VD::VK_DEDUCTIBLES as $sb)
            <label class="kfz-chip"><input type="radio" name="vehicle[vollkasko_deductible]" value="{{ $sb }}" {{ $curVkSb !== '' && (int) $curVkSb === $sb ? 'checked' : '' }}><span>{{ $sb }} €</span></label>
            @endforeach
        </div>
    </div>
</div>

{{-- ===== Zusatzleistungen ===== --}}
<div class="kfz-card">
    <div class="kfz-card-title">🧩 Zusatzleistungen</div>
    <div class="kfz-card-sub">Alle gewählten Bausteine erscheinen nach dem Speichern deutlich sichtbar im Vertrag – z.&nbsp;B. ob ein Schutzbrief für die Pannenhilfe besteht.</div>
    <div class="kfz-chip-grid">
        @foreach(VD::EXTRAS as $key => $label)
        <label class="kfz-chip"><input type="checkbox" name="vehicle[extras][]" value="{{ $key }}" data-label="{{ $label }}" {{ in_array($key, $extrasSel, true) ? 'checked' : '' }}><span>{{ $label }}</span></label>
        @endforeach
    </div>
</div>

{{-- ===== Fahrer ===== --}}
<div class="kfz-card">
    <div class="kfz-card-title">👥 Fahrer</div>
    <div class="kfz-card-sub">Wer darf das Fahrzeug fahren? Mehrfachauswahl möglich.</div>
    <div class="kfz-chip-row">
        @foreach(VD::DRIVER_GROUPS as $key => $label)
        <label class="kfz-chip">
            <input type="checkbox" name="vehicle[driver_groups][]" value="{{ $key }}" {{ in_array($key, $driversSel, true) ? 'checked' : '' }} {{ $key === 'weitere_fahrer' ? 'id=kfz-more-drivers onchange=kfzSync()' : '' }}>
            <span>{{ $label }}</span>
        </label>
        @endforeach
    </div>
    <div id="kfz-driver-list" style="display:none;margin-top:12px;">
        <div class="kfz-subline">Weitere Fahrer</div>
        <div id="kfz-drivers"></div>
        <button type="button" class="kfz-row-btn" onclick="kfzAddDriver()">+ Fahrer hinzufügen</button>
    </div>
</div>

{{-- ===== Halter & Eigentum ===== --}}
<div class="kfz-card">
    <div class="kfz-card-title">🗂️ Halter &amp; Eigentum</div>
    <div class="kfz-card-sub">Wer ist im Fahrzeugschein eingetragen, wem gehört das Fahrzeug?</div>
    <div class="kfz-subline" style="margin-top:0;">Fahrzeughalter</div>
    <div class="kfz-chip-row">
        @foreach(VD::HOLDER_TYPES as $key => $label)
        <label class="kfz-chip"><input type="radio" name="vehicle[holder_type]" value="{{ $key }}" {{ $curHolder === $key ? 'checked' : '' }} onchange="kfzSync()"><span>{{ $label }}</span></label>
        @endforeach
    </div>
    <div id="kfz-holder-name" class="field" style="display:none;margin-top:10px;max-width:420px;">
        <label>Name des abweichenden Halters</label>
        <input type="text" name="vehicle[holder_name]" maxlength="255" value="{{ $vd('holder_name', $veh->holder_name ?? '') }}" placeholder="Vor- und Nachname" style="{{ $kfzInputStyle }}">
    </div>
    <div class="kfz-subline">Eigentümer</div>
    <div class="kfz-chip-row">
        @foreach(VD::OWNERSHIP_TYPES as $key => $label)
        <label class="kfz-chip"><input type="radio" name="vehicle[ownership_type]" value="{{ $key }}" {{ $curOwnership === $key ? 'checked' : '' }}><span>{{ $key === 'leasing' ? '📄 ' : ($key === 'finanzierung' ? '🏦 ' : '') }}{{ $label }}</span></label>
        @endforeach
    </div>
</div>

{{-- ===== Nutzung & Kilometer ===== --}}
<div class="kfz-card">
    <div class="kfz-card-title">🧭 Nutzung &amp; Kilometer</div>
    <div class="kfz-card-sub">Alle Ablesungen werden dauerhaft gespeichert – der Kunde kann den aktuellen Stand auch selbst im Portal melden.</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
        <div class="field"><label>Kilometerstand bei Vertragsbeginn</label><input type="number" name="vehicle[initial_mileage]" min="0" max="5000000" value="{{ $vd('initial_mileage', $veh->initial_mileage ?? '') }}" placeholder="z. B. 45000" style="{{ $kfzInputStyle }}"></div>
        <div class="field"><label>Aktueller Kilometerstand</label><input type="number" name="vehicle[current_mileage]" min="0" max="5000000" value="{{ $vd('current_mileage', $latestReading->mileage ?? '') }}" placeholder="z. B. 52300" style="{{ $kfzInputStyle }}"></div>
        <div class="field"><label>Stand vom</label><input type="date" name="vehicle[current_mileage_date]" value="{{ $vd('current_mileage_date', $latestReading?->reading_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" style="{{ $kfzInputStyle }}"></div>
    </div>
    @if($latestReading)
    <div style="font-size:12px;color:var(--ink-soft);">Letzte Meldung: <b>{{ number_format($latestReading->mileage, 0, ',', '.') }} km</b> am {{ $latestReading->reading_date->format('d.m.Y') }} ({{ $latestReading->sourceLabel() }}@if($latestReading->created_by), {{ $latestReading->created_by }}@endif)</div>
    @endif
    <div class="kfz-subline">Jährliche Fahrleistung (vereinbart)</div>
    <div class="kfz-chip-row">
        <label class="kfz-chip"><input type="radio" name="vehicle[annual_mileage]" value="" {{ $curAnnual === '' ? 'checked' : '' }} onchange="kfzSync()"><span>keine Angabe</span></label>
        @foreach(VD::ANNUAL_MILEAGE_OPTIONS as $km)
        <label class="kfz-chip"><input type="radio" name="vehicle[annual_mileage]" value="{{ $km }}" {{ !$isCustomAnnual && $curAnnual !== '' && (int) $curAnnual === $km ? 'checked' : '' }} onchange="kfzSync()"><span>{{ number_format($km, 0, ',', '.') }} km</span></label>
        @endforeach
        {{-- Sonderfaelle (8.000, 18.500, 22.500 km ...) per Freifeld --}}
        <label class="kfz-chip"><input type="radio" id="kfz-annual-custom-radio" name="vehicle[annual_mileage]" value="custom" {{ $isCustomAnnual ? 'checked' : '' }} onchange="kfzSync()"><span>✏️ Eigene Fahrleistung</span></label>
    </div>
    <div id="kfz-annual-custom" class="field" style="display:none;margin-top:10px;max-width:280px;">
        <label>Eigene Fahrleistung (km/Jahr)</label>
        <input type="number" name="vehicle[annual_mileage_custom]" min="1000" max="150000" step="100" value="{{ $customAnnual }}" placeholder="z. B. 18500" style="{{ $kfzInputStyle }}">
    </div>
    @if($mileageStatus && $mileageStatus['exceeded'])
    <div class="kfz-warn">⚠️ <b>Fahrleistung überschritten:</b> hochgerechnet {{ number_format($mileageStatus['projected'], 0, ',', '.') }} km/Jahr bei vereinbarten {{ number_format($mileageStatus['allowed'], 0, ',', '.') }} km/Jahr. Bitte Kunden auf eine Anpassung ansprechen (sonst droht Nachzahlung im Schadenfall).</div>
    @elseif($mileageStatus)
    <div style="font-size:12px;color:#0E7A41;margin-top:10px;">✓ Hochgerechnet {{ number_format($mileageStatus['projected'], 0, ',', '.') }} km/Jahr – im Rahmen der vereinbarten {{ number_format($mileageStatus['allowed'], 0, ',', '.') }} km/Jahr.</div>
    @endif
    @if($veh && $veh->mileageReadings->count() > 1)
    <details style="margin-top:10px;">
        <summary style="cursor:pointer;font-size:12.5px;font-weight:600;color:var(--ink-soft);">Alle {{ $veh->mileageReadings->count() }} Ablesungen anzeigen</summary>
        <table class="kfz-sf-table">
            <thead><tr><th>Datum</th><th>Kilometerstand</th><th>Quelle</th><th>Erfasst von</th></tr></thead>
            <tbody>
            @foreach($veh->mileageReadings as $reading)
            <tr><td>{{ $reading->reading_date->format('d.m.Y') }}</td><td>{{ number_format($reading->mileage, 0, ',', '.') }} km</td><td>{{ $reading->sourceLabel() }}</td><td>{{ $reading->created_by ?? '—' }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </details>
    @endif
</div>

{{-- ===== SF-Einstufung ===== --}}
<div class="kfz-card">
    <div class="kfz-card-title">📊 Schadenfreiheitsklasse (SF)</div>
    <div class="kfz-card-sub">Haftpflicht und Vollkasko werden getrennt eingestuft, Teilkasko hat keine SF-Klasse. Sondereinstufungen sind nicht auf andere Versicherer übertragbar.</div>

    @foreach([
        ['prefix' => 'sf_liability', 'short' => 'liability', 'title' => 'Haftpflicht', 'data' => $sfL, 'wrap' => ''],
        ['prefix' => 'sf_comprehensive', 'short' => 'comprehensive', 'title' => 'Vollkasko', 'data' => $sfV, 'wrap' => 'id=kfz-sf-vk style=display:none;'],
    ] as $branch)
    <div {!! $branch['wrap'] !!}>
        <div class="kfz-subline" style="display:flex;align-items:center;gap:10px;">
            {{ $branch['title'] }}
            <span id="kfz-transfer-{{ $branch['short'] }}"></span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:560px;">
            <div class="field"><label>SF-Klasse</label>
                <select name="vehicle[{{ $branch['prefix'] }}_class]" class="kfz-sf-class" data-branch="{{ $branch['short'] }}" onchange="kfzSync()" style="{{ $kfzInputStyle }}">
                    <option value="">— keine Angabe —</option>
                    @foreach(VD::sfClassKeys() as $key)
                    <option value="{{ $key }}" {{ $branch['data']['class'] === $key ? 'selected' : '' }}>{{ VD::sfLabel($key) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Gültig ab</label><input type="date" name="vehicle[{{ $branch['prefix'] }}_valid_from]" value="{{ $branch['data']['from'] }}" style="{{ $kfzInputStyle }}"></div>
        </div>
        <div style="margin:6px 0 4px;font-size:12px;color:var(--ink-soft);">Art der SF-Klasse</div>
        <div class="kfz-chip-row">
            @foreach(VD::SF_TYPES as $key => $label)
            <label class="kfz-chip"><input type="radio" name="vehicle[{{ $branch['prefix'] }}_type]" value="{{ $key }}" {{ $branch['data']['type'] === $key ? 'checked' : '' }} onchange="kfzSync()"><span>{{ $key === 'tatsaechlich' ? '✅' : '⭐' }} {{ $label }}</span></label>
            @endforeach
        </div>
        <div id="kfz-sonder-{{ $branch['short'] }}" style="display:none;margin-top:10px;background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:12px;">
            <div style="font-size:12px;color:var(--ink-soft);margin-bottom:8px;">Grund der Sondereinstufung</div>
            <div class="kfz-chip-row">
                @foreach(VD::SF_SPECIAL_REASONS as $key => $label)
                <label class="kfz-chip"><input type="radio" name="vehicle[{{ $branch['prefix'] }}_special_reason]" value="{{ $key }}" {{ $branch['data']['reason'] === $key ? 'checked' : '' }}><span>{{ $label }}</span></label>
                @endforeach
            </div>
            <div class="field" style="margin-top:10px;max-width:280px;"><label>Tatsächliche SF-Klasse (übertragbar)</label>
                <select name="vehicle[{{ $branch['prefix'] }}_real_class]" style="{{ $kfzInputStyle }}">
                    <option value="">— keine Angabe —</option>
                    @foreach(VD::sfClassKeys() as $key)
                    <option value="{{ $key }}" {{ $branch['data']['real'] === $key ? 'selected' : '' }}>{{ VD::sfLabel($key) }}</option>
                    @endforeach
                </select>
                <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">Diese Klasse gilt beim Wechsel zu einem anderen Versicherer – nicht die gewährte Sondereinstufung.</div>
            </div>
        </div>
    </div>
    @endforeach

    @if($sfHistory->isNotEmpty())
    <div class="kfz-subline">SF-Verlauf</div>
    <table class="kfz-sf-table">
        <thead><tr><th>Sparte</th><th>SF-Klasse</th><th>Gültig ab</th><th>Gültig bis</th></tr></thead>
        <tbody>
        @foreach($sfHistory as $entry)
        <tr>
            <td>{{ $entry->branchLabel() }}</td>
            <td style="font-weight:700;">{{ VD::sfLabel($entry->sf_class) }}</td>
            <td>{{ $entry->valid_from?->format('d.m.Y') ?? '—' }}</td>
            <td>{{ $entry->valid_until?->format('d.m.Y') ?? 'aktuell' }}</td>
        </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>

{{-- ===== Schaeden ===== --}}
<div class="kfz-card">
    <div class="kfz-card-title">⚠️ Schäden</div>
    <div class="kfz-card-sub">Alle Schadenfälle mit Datum, Art, Höhe und Stand der Regulierung.</div>
    <div id="kfz-claims"></div>
    <button type="button" class="kfz-row-btn" onclick="kfzAddClaim()">+ Schaden hinzufügen</button>
</div>

<script>
// ---- Kataloge/Bestand aus PHP (einmalig gerendert) ----
const KFZ_CLAIM_TYPES = @json(VehicleClaim::TYPES);
const KFZ_CLAIM_STATUSES = @json(VehicleClaim::STATUSES);
const KFZ_DRIVERS_INIT = @json($addDrivers);
const KFZ_CLAIMS_INIT = @json($claimRows);
let kfzDriverIdx = 0, kfzClaimIdx = 0;
const KFZ_INPUT = 'width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--surface);';

function kfzEsc(v) {
    return String(v ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function kfzAddDriver(d) {
    d = d || {};
    const i = kfzDriverIdx++;
    const row = document.createElement('div');
    row.className = 'kfz-item-row';
    row.style.gridTemplateColumns = '2fr 1fr 1fr 34px';
    row.innerHTML = `
        <div><label style="font-size:11.5px;color:var(--ink-soft);">Name</label>
            <input type="text" name="vehicle[additional_drivers][${i}][name]" maxlength="120" value="${kfzEsc(d.name)}" placeholder="Vor- und Nachname" style="${KFZ_INPUT}"></div>
        <div><label style="font-size:11.5px;color:var(--ink-soft);">Geburtsdatum</label>
            <input type="date" name="vehicle[additional_drivers][${i}][birth_date]" value="${kfzEsc(d.birth_date)}" style="${KFZ_INPUT}"></div>
        <div><label style="font-size:11.5px;color:var(--ink-soft);">Führerschein seit</label>
            <input type="date" name="vehicle[additional_drivers][${i}][license_date]" value="${kfzEsc(d.license_date)}" style="${KFZ_INPUT}"></div>
        <button type="button" class="kfz-remove" title="Fahrer entfernen" onclick="this.parentElement.remove();kfzSummary()">✕</button>`;
    document.getElementById('kfz-drivers').appendChild(row);
}

function kfzAddClaim(cl) {
    cl = cl || {};
    const i = kfzClaimIdx++;
    const opts = (map, sel, empty) => `<option value="">${empty}</option>` + Object.entries(map)
        .map(([k, l]) => `<option value="${k}" ${sel === k ? 'selected' : ''}>${l}</option>`).join('');
    const row = document.createElement('div');
    row.className = 'kfz-item-row';
    row.style.gridTemplateColumns = '130px 130px 110px 140px 1fr 34px';
    row.innerHTML = `
        <div><label style="font-size:11.5px;color:var(--ink-soft);">Datum</label>
            <input type="date" name="vehicle[claim_rows][${i}][claim_date]" value="${kfzEsc(cl.claim_date)}" style="${KFZ_INPUT}"></div>
        <div><label style="font-size:11.5px;color:var(--ink-soft);">Art</label>
            <select name="vehicle[claim_rows][${i}][claim_type]" style="${KFZ_INPUT}">${opts(KFZ_CLAIM_TYPES, cl.claim_type, '—')}</select></div>
        <div><label style="font-size:11.5px;color:var(--ink-soft);">Schaden (€)</label>
            <input type="number" step="0.01" min="0" name="vehicle[claim_rows][${i}][damage_amount]" value="${kfzEsc(cl.damage_amount)}" placeholder="0,00" style="${KFZ_INPUT}"></div>
        <div><label style="font-size:11.5px;color:var(--ink-soft);">Status</label>
            <select name="vehicle[claim_rows][${i}][status]" style="${KFZ_INPUT}">${opts(KFZ_CLAIM_STATUSES, cl.status, '—')}</select></div>
        <div><label style="font-size:11.5px;color:var(--ink-soft);">Versicherer / Notiz</label>
            <div style="display:flex;gap:6px;">
                <input type="text" name="vehicle[claim_rows][${i}][insurer]" maxlength="255" value="${kfzEsc(cl.insurer)}" placeholder="Versicherer" style="${KFZ_INPUT}">
                <input type="text" name="vehicle[claim_rows][${i}][notes]" maxlength="2000" value="${kfzEsc(cl.notes)}" placeholder="Notizen" style="${KFZ_INPUT}">
            </div></div>
        <button type="button" class="kfz-remove" title="Schaden entfernen" onclick="this.parentElement.remove();kfzSummary()">✕</button>`;
    document.getElementById('kfz-claims').appendChild(row);
}

// Abhaengigkeiten: Teilkasko -> SB, Vollkasko nur mit Teilkasko,
// Sondereinstufung -> Grund/tatsaechliche Klasse, weitere Fahrer -> Liste.
function kfzSync() {
    const tk = document.getElementById('kfz-tk');
    const vk = document.getElementById('kfz-vk');
    if (!tk) return;
    if (!tk.checked && vk.checked) vk.checked = false;
    vk.disabled = !tk.checked;
    document.getElementById('kfz-vk-hint').style.display = tk.checked ? 'none' : 'block';
    document.getElementById('kfz-tk-sb').style.display = tk.checked ? 'block' : 'none';
    document.getElementById('kfz-vk-sb').style.display = vk.checked ? 'block' : 'none';
    document.getElementById('kfz-sf-vk').style.display = vk.checked ? 'block' : 'none';

    const more = document.getElementById('kfz-more-drivers');
    const list = document.getElementById('kfz-driver-list');
    list.style.display = more.checked ? 'block' : 'none';
    if (more.checked && !document.querySelector('#kfz-drivers .kfz-item-row')) kfzAddDriver();

    const holder = document.querySelector('input[name="vehicle[holder_type]"]:checked');
    document.getElementById('kfz-holder-name').style.display = (holder && holder.value === 'abweichender_halter') ? 'block' : 'none';

    // Eigene Fahrleistung: Freifeld nur bei gewaehltem Chip anzeigen.
    const customAnnual = document.getElementById('kfz-annual-custom-radio');
    document.getElementById('kfz-annual-custom').style.display = customAnnual.checked ? 'block' : 'none';

    ['liability', 'comprehensive'].forEach(branch => {
        const prefix = branch === 'liability' ? 'sf_liability' : 'sf_comprehensive';
        const type = document.querySelector(`input[name="vehicle[${prefix}_type]"]:checked`);
        const sonder = type && type.value === 'sondereinstufung';
        document.getElementById('kfz-sonder-' + branch).style.display = sonder ? 'block' : 'none';
        const cls = document.querySelector(`select[name="vehicle[${prefix}_class]"]`).value;
        const badge = document.getElementById('kfz-transfer-' + branch);
        badge.innerHTML = !cls ? '' : (sonder
            ? '<span class="kfz-transfer no">🔴 Nicht übertragbar (Sondereinstufung)</span>'
            : '<span class="kfz-transfer ok">🟢 Übertragbar zur anderen Versicherung</span>');
    });
    kfzSummary();
}

// Live-Ueberblick oben im Formular.
function kfzSummary() {
    const q = sel => document.querySelector(sel);
    const type = q('input[name="vehicle[vehicle_type]"]:checked');
    const plate = q('#kfz-plate').value.trim();
    const car = [q('#kfz-manufacturer').value.trim(), q('#kfz-model').value.trim()].filter(Boolean).join(' ');
    q('#kfz-sum-vehicle').textContent = [type ? type.dataset.label : null, plate || car || null].filter(Boolean).join(' · ') || '—';

    const tk = q('#kfz-tk').checked, vk = q('#kfz-vk').checked;
    const tkSb = q('input[name="vehicle[teilkasko_deductible]"]:checked');
    const vkSb = q('input[name="vehicle[vollkasko_deductible]"]:checked');
    const sbTxt = el => el ? (el.value === '0' ? ' ohne SB' : ' ' + el.value + '€') : '';
    q('#kfz-sum-coverage').textContent = 'Haftpflicht' + (tk ? ' + TK' + sbTxt(tkSb) : '') + (vk ? ' + VK' + sbTxt(vkSb) : '');

    const extras = document.querySelectorAll('input[name="vehicle[extras][]"]:checked').length;
    q('#kfz-sum-extras').textContent = extras + ' gewählt';

    const groups = document.querySelectorAll('input[name="vehicle[driver_groups][]"]:checked').length;
    const addl = document.querySelectorAll('#kfz-drivers .kfz-item-row').length;
    q('#kfz-sum-drivers').textContent = groups ? groups + ' Gruppe(n)' + (addl ? ' + ' + addl + ' namentlich' : '') : '—';

    const annual = q('input[name="vehicle[annual_mileage]"]:checked');
    let annualVal = annual ? annual.value : '';
    if (annualVal === 'custom') annualVal = q('input[name="vehicle[annual_mileage_custom]"]').value;
    q('#kfz-sum-mileage').textContent = annualVal ? Number(annualVal).toLocaleString('de-DE') + ' km/Jahr' : '—';

    const sfl = q('select[name="vehicle[sf_liability_class]"]').value;
    const sfv = q('select[name="vehicle[sf_comprehensive_class]"]').value;
    q('#kfz-sum-sf').textContent = [sfl ? 'HF: SF ' + sfl : null, (vk && sfv) ? 'VK: SF ' + sfv : null].filter(Boolean).join(' · ') || '—';
}

document.addEventListener('DOMContentLoaded', function () {
    KFZ_DRIVERS_INIT.forEach(d => kfzAddDriver(d));
    KFZ_CLAIMS_INIT.forEach(cl => kfzAddClaim(cl));
    kfzSync();
    document.getElementById('section-kfz').addEventListener('change', kfzSync);
    document.getElementById('section-kfz').addEventListener('input', kfzSummary);
});
</script>
