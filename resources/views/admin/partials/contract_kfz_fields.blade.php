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

    // ---- SF-Bezug (01.10.2026): Formularwerte je Sparte ----
    $formCustomer = $customer ?? $c?->customer;
    $sfRefCtx = [
        'customerId' => $formCustomer?->id,
        'contractId' => $c?->id,
        'documents' => $formCustomer ? $formCustomer->documents()->latest()->limit(50)->get(['id', 'file_name', 'created_at']) : collect(),
        'mayVerify' => app(\App\Services\Kfz\SfReferenceService::class)->mayVerify(auth()->user()),
    ];
    $sfRefData = [];
    $sfRefModels = [];
    foreach (['haftpflicht', 'vollkasko'] as $branchKey) {
        $m = $veh?->sfReference($branchKey);
        $o = fn ($field, $default = null) => old("vehicle.sf_ref.$branchKey.$field", $default);
        $refId = (string) $o('reference_contract_id', $m?->reference_contract_id ?? '');
        $refContract = $refId !== '' ? ($m && $m->reference_contract_id === $refId ? $m->referenceContract : \App\Models\Contract::with('vehicleDetail')->find($refId)) : null;
        $card = null;
        if ($refContract) {
            $rv = $refContract->vehicleDetail;
            $card = [
                'label' => \App\Models\VehicleSfReference::labelFor($refContract),
                'meta' => implode(' · ', array_filter([
                    $rv?->license_plate, trim(($rv?->manufacturer ?? '').' '.($rv?->model ?? '')) ?: null,
                    $rv?->sf_liability_class ? 'HP '.VD::sfLabel($rv->sf_liability_class) : null,
                    ($rv?->has_vollkasko && $rv?->sf_comprehensive_class) ? 'VK '.VD::sfLabel($rv->sf_comprehensive_class) : null,
                    $refContract->displayStatus()['label'],
                    $refContract->origin === \App\Models\Contract::ORIGIN_EXTERNAL ? 'Fremdvertrag' : null,
                ])),
                'url' => route('admin.contract.edit', $refContract->id),
                'customer_id' => (string) $refContract->customer_id,
            ];
        } elseif ($m && $m->reference_type === 'internal' && $m->reference_label) {
            $card = ['label' => $m->reference_label.' (gelöscht)', 'meta' => 'Der Erstwagen wurde gelöscht – bitte neu zuordnen.', 'url' => null, 'customer_id' => null];
        }
        $sfRefData[$branchKey] = [
            'reference_type' => (string) $o('reference_type', $m?->reference_type ?? ''),
            'reference_contract_id' => $refId,
            'card' => $card,
            'ext_insurer' => (string) $o('ext_insurer', $m?->ext_insurer ?? ''),
            'ext_contract_number' => (string) $o('ext_contract_number', $m?->ext_contract_number ?? ''),
            'ext_license_plate' => (string) $o('ext_license_plate', $m?->ext_license_plate ?? ''),
            'ext_sf_class' => (string) $o('ext_sf_class', $m?->ext_sf_class ?? ''),
            // Neuanlage: Fremdvertrag anlegen ist voreingestellt
            'create_external' => (bool) $o('create_external', old('vehicle') === null),
            'copy_from_liability' => (bool) $o('copy_from_liability', false),
            'holder_relation' => (string) $o('holder_relation', $m?->holder_relation ?? ''),
            'holder_name' => (string) $o('holder_name', $m?->holder_name ?? ''),
            'proof_document_id' => (string) $o('proof_document_id', $m?->proof_document_id ?? ''),
            'verified' => (bool) $o('verified', $m?->verified ?? false),
            'license_date' => (string) $o('license_date', $m?->license_date?->format('Y-m-d') ?? ''),
            'campaign_name' => (string) $o('campaign_name', $m?->campaign_name ?? ''),
            'note' => (string) $o('note', $m?->note ?? ''),
        ];
        $sfRefModels[$branchKey] = $m;
    }
    // Vorschlaege fuer den Versicherer des Erstwagens: die im Bestand
    // vorkommenden Namen (es gibt keine Versicherer-Stammdaten), Freitext bleibt.
    $insurerSuggestions = \App\Models\Contract::query()->whereNotNull('insurer')->where('insurer', '!=', '')
        ->distinct()->orderBy('insurer')->limit(400)->pluck('insurer');
    $noPrevious = (string) $vd('no_previous_insurance', ($veh->no_previous_insurance ?? false) ? '1' : '0') === '1';
    $sfSuggestRule = (array) config('kfz_rules.rules.SF-VORSCHLAG-FUEHRERSCHEIN.werte', []);
    $kfzSfrefJs = [
    'searchUrlTemplate' => route('admin.contract.sf_reference_search', '__KUNDE__'),
    'customerId' => $sfRefCtx['customerId'],
    'contractId' => $sfRefCtx['contractId'],
    'suggestYears' => (int) ($sfSuggestRule['mindestjahre'] ?? 3),
    'suggestAbove' => (string) ($sfSuggestRule['klasse_ab_mindestjahre'] ?? '1/2'),
    'suggestBelow' => (string) ($sfSuggestRule['klasse_darunter'] ?? '0'),
];

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
.kfz-chip input:checked + span{border-color:var(--emerald);background:#E7F6EE;color:#0E7A41;box-shadow:inset 0 0 0 1px var(--emerald);}
.kfz-chip input:focus-visible + span{outline:2px solid var(--emerald);outline-offset:2px;}
.kfz-chip input:disabled + span{opacity:.4;cursor:not-allowed;}
.kfz-chip.big span{padding:13px 16px;font-size:13.5px;}
.kfz-chip-row{display:flex;flex-wrap:wrap;gap:8px;}
.kfz-chip-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;}
.kfz-fixed-chip{display:inline-flex;align-items:center;gap:7px;padding:13px 16px;border-radius:10px;font-size:13.5px;font-weight:700;background:var(--emerald);color:#fff;}
.kfz-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px;}
.kfz-sum-box{background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:10px 12px;min-width:0;}
.kfz-sum-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);font-weight:700;}
.kfz-sum-value{font-size:12.5px;font-weight:700;margin-top:3px;overflow:hidden;text-overflow:ellipsis;}
.kfz-subline{font-size:12.5px;font-weight:700;color:var(--ink-soft);margin:14px 0 8px;text-transform:uppercase;letter-spacing:.04em;}
.kfz-warn{background:#FDF2E3;border:1px solid #EBC894;color:#8A5A1B;border-radius:10px;padding:10px 12px;font-size:12.5px;margin-top:10px;}
.kfz-row-btn{border:1px dashed var(--line);background:transparent;border-radius:10px;padding:9px 14px;font-size:12.5px;font-weight:600;color:var(--ink-soft);cursor:pointer;width:100%;}
.kfz-row-btn:hover{border-color:var(--emerald);color:#0E7A41;}
.kfz-item-row{display:grid;gap:8px;margin-bottom:8px;align-items:end;}
.kfz-remove{border:none;background:#F9E3E3;color:#A32D2D;border-radius:8px;width:34px;height:38px;cursor:pointer;font-size:14px;flex:none;}
.kfz-transfer{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;padding:6px 12px;border-radius:999px;}
.kfz-transfer.ok{background:#E7F6EE;color:#0E7A41;}
.kfz-transfer.no{background:#F9E3E3;color:#A32D2D;}
.kfz-sf-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px;}
.kfz-sf-table th{text-align:left;padding:6px 8px;color:var(--ink-soft);font-size:11px;text-transform:uppercase;border-bottom:1px solid var(--line);}
.kfz-sf-table td{padding:7px 8px;border-bottom:1px solid var(--line);}
.kfz-ref-title{font-size:13px;font-weight:700;margin-top:14px;}
.kfz-ref-sub{font-size:12px;color:var(--ink-soft);margin:2px 0 10px;}
.kfz-ref-card{display:flex;align-items:center;gap:10px;border:1.5px solid var(--emerald);background:#E7F6EE;border-radius:10px;padding:10px 12px;}
.kfz-ref-card-title{font-weight:700;font-size:13px;}
.kfz-ref-card-meta{font-size:12px;color:var(--ink-soft);}
.kfz-ref-card-link{font-size:12px;font-weight:600;white-space:nowrap;}
.kfz-ref-clear,.kfz-ref-back{border:none;background:transparent;color:var(--ink-soft);cursor:pointer;font-size:12.5px;}
.kfz-ref-results{display:flex;flex-direction:column;gap:6px;margin-top:6px;}
.kfz-ref-hit{display:block;width:100%;text-align:left;border:1px solid var(--line);border-radius:10px;padding:8px 12px;background:var(--surface);cursor:pointer;}
.kfz-ref-hit:hover{border-color:var(--emerald);}
.kfz-ref-hit b{font-size:13px;}
.kfz-ref-hit small{display:block;font-size:11.5px;color:var(--ink-soft);}
.kfz-ref-empty{font-size:12px;color:var(--ink-soft);margin-top:6px;}
.kfz-check{display:flex;align-items:flex-start;gap:8px;font-size:12.5px;margin-top:8px;}
.kfz-hint{font-size:11.5px;color:var(--ink-soft);}
.kfz-suggest{display:inline-flex;align-items:center;gap:8px;font-size:12px;background:#FDF8EC;border:1px dashed var(--gold);border-radius:8px;padding:5px 10px;margin-top:6px;}
.kfz-suggest button{border:none;background:transparent;color:#0E7A41;font-weight:700;cursor:pointer;font-size:12px;}
</style>
<datalist id="kfz-insurer-list">
    @foreach($insurerSuggestions as $insurerName)<option value="{{ $insurerName }}">@endforeach
</datalist>

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
        <div class="field"><label>Kennzeichen</label><input type="text" id="kfz-plate" name="vehicle[license_plate]" maxlength="20" value="{{ $vd('license_plate', $veh->license_plate ?? '') }}" placeholder="HH-AB 1234" style="{{ $kfzInputStyle }}" aria-label="Kennzeichen"></div>
        <div class="field"><label>Hersteller</label><input type="text" id="kfz-manufacturer" name="vehicle[manufacturer]" value="{{ $vd('manufacturer', $veh->manufacturer ?? '') }}" placeholder="VW" style="{{ $kfzInputStyle }}" aria-label="Hersteller"></div>
        <div class="field"><label>Modell</label><input type="text" id="kfz-model" name="vehicle[model]" value="{{ $vd('model', $veh->model ?? '') }}" placeholder="Golf VIII" style="{{ $kfzInputStyle }}" aria-label="Modell"></div>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;">
        <div class="field"><label>FIN (VIN)</label><input type="text" name="vehicle[vin]" maxlength="30" value="{{ $vd('vin', $veh->vin ?? '') }}" placeholder="WVWZZZ..." style="{{ $kfzInputStyle }}" aria-label="FIN (VIN)"></div>
        <div class="field"><label>HSN</label><input type="text" name="vehicle[hsn]" maxlength="4" inputmode="numeric" pattern="[0-9]{4}" value="{{ $vd('hsn', $veh->hsn ?? '') }}" placeholder="0603" style="{{ $kfzInputStyle }}" aria-label="HSN"></div>
        <div class="field"><label>TSN</label><input type="text" name="vehicle[tsn]" maxlength="10" value="{{ $vd('tsn', $veh->tsn ?? '') }}" placeholder="BJM" style="{{ $kfzInputStyle }}" aria-label="TSN"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
        <div class="field"><label>Erstzulassung</label><input type="date" name="vehicle[first_registration]" value="{{ $vd('first_registration', $dfmt($veh->first_registration ?? null)) }}" style="{{ $kfzInputStyle }}" aria-label="Erstzulassung"></div>
        <div class="field"><label>Erwerbsdatum</label><input type="date" name="vehicle[acquisition_date]" value="{{ $vd('acquisition_date', $dfmt($veh->acquisition_date ?? null)) }}" style="{{ $kfzInputStyle }}" aria-label="Erwerbsdatum"></div>
        <div class="field"><label>Leistung (kW)</label><input type="number" name="vehicle[power_kw]" min="1" max="2000" value="{{ $vd('power_kw', $veh->power_kw ?? '') }}" placeholder="110" style="{{ $kfzInputStyle }}" aria-label="Leistung (kW)"></div>
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
        <div class="field" style="min-width:180px;margin:0;"><label>Farbe</label><input type="text" name="vehicle[color]" maxlength="40" value="{{ $vd('color', $veh->color ?? '') }}" placeholder="schwarz" style="{{ $kfzInputStyle }}" aria-label="Farbe"></div>
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
            <input type="checkbox" id="kfz-tk" name="vehicle[has_teilkasko]" value="1" {{ $hasTk ? 'checked' : '' }} data-h-change="78b9583e66">
            <span>Teilkasko</span>
        </label>
        <label class="kfz-chip big">
            <input type="checkbox" id="kfz-vk" name="vehicle[has_vollkasko]" value="1" {{ $hasVk ? 'checked' : '' }} data-h-change="78b9583e66">
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
        <button type="button" class="kfz-row-btn" data-h-click="565b7d45ea">+ Fahrer hinzufügen</button>
    </div>
</div>

{{-- ===== Halter & Eigentum ===== --}}
<div class="kfz-card">
    <div class="kfz-card-title">🗂️ Halter &amp; Eigentum</div>
    <div class="kfz-card-sub">Wer ist im Fahrzeugschein eingetragen, wem gehört das Fahrzeug?</div>
    <div class="kfz-subline" style="margin-top:0;">Fahrzeughalter</div>
    <div class="kfz-chip-row">
        @foreach(VD::HOLDER_TYPES as $key => $label)
        <label class="kfz-chip"><input type="radio" name="vehicle[holder_type]" value="{{ $key }}" {{ $curHolder === $key ? 'checked' : '' }} data-h-change="78b9583e66"><span>{{ $label }}</span></label>
        @endforeach
    </div>
    <div id="kfz-holder-name" class="field" style="display:none;margin-top:10px;max-width:420px;">
        <label>Name des abweichenden Halters</label>
        <input type="text" name="vehicle[holder_name]" maxlength="255" value="{{ $vd('holder_name', $veh->holder_name ?? '') }}" placeholder="Vor- und Nachname" style="{{ $kfzInputStyle }}" aria-label="Name des abweichenden Halters">
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
        <div class="field"><label>Kilometerstand bei Vertragsbeginn</label><input type="number" name="vehicle[initial_mileage]" min="0" max="5000000" value="{{ $vd('initial_mileage', $veh->initial_mileage ?? '') }}" placeholder="z. B. 45000" style="{{ $kfzInputStyle }}" aria-label="Kilometerstand bei Vertragsbeginn"></div>
        <div class="field"><label>Aktueller Kilometerstand</label><input type="number" name="vehicle[current_mileage]" min="0" max="5000000" value="{{ $vd('current_mileage', $latestReading->mileage ?? '') }}" placeholder="z. B. 52300" style="{{ $kfzInputStyle }}" aria-label="Aktueller Kilometerstand"></div>
        <div class="field"><label>Stand vom</label><input type="date" name="vehicle[current_mileage_date]" value="{{ $vd('current_mileage_date', $latestReading?->reading_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" style="{{ $kfzInputStyle }}" aria-label="Stand vom"></div>
    </div>
    @if($latestReading)
    <div class="muted-xs">Letzte Meldung: <b>{{ number_format($latestReading->mileage, 0, ',', '.') }} km</b> am {{ $latestReading->reading_date->format('d.m.Y') }} ({{ $latestReading->sourceLabel() }}@if($latestReading->created_by), {{ $latestReading->created_by }}@endif)</div>
    @endif
    <div class="kfz-subline">Jährliche Fahrleistung (vereinbart)</div>
    <div class="kfz-chip-row">
        <label class="kfz-chip"><input type="radio" name="vehicle[annual_mileage]" value="" {{ $curAnnual === '' ? 'checked' : '' }} data-h-change="78b9583e66"><span>keine Angabe</span></label>
        @foreach(VD::ANNUAL_MILEAGE_OPTIONS as $km)
        <label class="kfz-chip"><input type="radio" name="vehicle[annual_mileage]" value="{{ $km }}" {{ !$isCustomAnnual && $curAnnual !== '' && (int) $curAnnual === $km ? 'checked' : '' }} data-h-change="78b9583e66"><span>{{ number_format($km, 0, ',', '.') }} km</span></label>
        @endforeach
        {{-- Sonderfaelle (8.000, 18.500, 22.500 km ...) per Freifeld --}}
        <label class="kfz-chip"><input type="radio" id="kfz-annual-custom-radio" name="vehicle[annual_mileage]" value="custom" {{ $isCustomAnnual ? 'checked' : '' }} data-h-change="78b9583e66"><span>✏️ Eigene Fahrleistung</span></label>
    </div>
    <div id="kfz-annual-custom" class="field" style="display:none;margin-top:10px;max-width:280px;">
        <label>Eigene Fahrleistung (km/Jahr)</label>
        <input type="number" name="vehicle[annual_mileage_custom]" min="1000" max="150000" step="100" value="{{ $customAnnual }}" placeholder="z. B. 18500" style="{{ $kfzInputStyle }}" aria-label="Eigene Fahrleistung (km/Jahr)">
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

{{-- ===== Vorversicherung ===== --}}
@php
    // null (unbekannt) -> '', true -> '1', false -> '0'
    $prevTerm = $veh->previous_insurance_terminated_by_insurer ?? null;
    $curPrevTerm = (string) old('vehicle.previous_insurance_terminated_by_insurer', $prevTerm === null ? '' : ($prevTerm ? '1' : '0'));
@endphp
<div class="kfz-card">
    <div class="kfz-card-title">↩️ Vorversicherung</div>
    <div class="kfz-card-sub">Wo war <b>dieses Fahrzeug</b> vor diesem Vertrag versichert? Wird beim Wechsel aus dem Beratungsprotokoll übernommen. Der Erstwagen einer Zweitwagenregelung gehört <b>nicht</b> hierher, sondern zur SF-Sondereinstufung.</div>
    <div class="kfz-chip-row" style="margin-bottom:10px;">
        <input type="hidden" name="vehicle[no_previous_insurance]" value="0">
        <label class="kfz-chip"><input type="checkbox" id="kfz-no-prev" name="vehicle[no_previous_insurance]" value="1" {{ $noPrevious ? 'checked' : '' }}><span>🆕 Keine Vorversicherung (Neuzulassung/Ersterwerb)</span></label>
    </div>
    <div id="kfz-prev-fields">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="field"><label>Vorheriger Versicherer</label><input type="text" name="vehicle[previous_insurer]" maxlength="120" value="{{ $vd('previous_insurer', $veh->previous_insurer ?? '') }}" placeholder="z. B. Generali" style="{{ $kfzInputStyle }}" aria-label="Vorheriger Versicherer"></div>
        <div class="field"><label>Vertragsnummer beim Vorversicherer</label><input type="text" name="vehicle[previous_contract_number]" maxlength="60" value="{{ $vd('previous_contract_number', $veh->previous_contract_number ?? '') }}" placeholder="Nummer des alten Vertrags" style="{{ $kfzInputStyle }}" aria-label="Vertragsnummer beim Vorversicherer"></div>
        <div class="field"><label>Dort versichert seit</label><input type="text" name="vehicle[previous_insurance_since]" maxlength="60" value="{{ $vd('previous_insurance_since', $veh->previous_insurance_since ?? '') }}" placeholder="z. B. länger als 3 Jahre" style="{{ $kfzInputStyle }}" aria-label="Dort versichert seit"></div>
    </div>
    <div class="kfz-subline">Kündigung durch Vorversicherer</div>
    <div class="kfz-chip-row">
        <label class="kfz-chip"><input type="radio" name="vehicle[previous_insurance_terminated_by_insurer]" value="" {{ $curPrevTerm === '' ? 'checked' : '' }}><span>unbekannt</span></label>
        <label class="kfz-chip"><input type="radio" name="vehicle[previous_insurance_terminated_by_insurer]" value="0" {{ $curPrevTerm === '0' ? 'checked' : '' }}><span>Nein</span></label>
        <label class="kfz-chip"><input type="radio" name="vehicle[previous_insurance_terminated_by_insurer]" value="1" {{ $curPrevTerm === '1' ? 'checked' : '' }}><span>Ja</span></label>
    </div>
    <div class="kfz-warn" id="kfz-prev-zweitwagen" hidden>💡 Das sieht nach dem <b>Erstwagen</b> aus (Zweitwagenregelung). Die Vorversicherung beschreibt den Vorvertrag <b>dieses</b> Fahrzeugs. Den Erstwagen bitte unter „Schadenfreiheitsklasse → Sondereinstufung → Bezugsfahrzeug“ erfassen – dort wird er mit dem Vertrag verknüpft.</div>
    </div>
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
                <select name="vehicle[{{ $branch['prefix'] }}_class]" class="kfz-sf-class" data-branch="{{ $branch['short'] }}" data-h-change="78b9583e66" style="{{ $kfzInputStyle }}" aria-label="SF-Klasse">
                    <option value="">— keine Angabe —</option>
                    @foreach(VD::sfClassKeys() as $key)
                    <option value="{{ $key }}" {{ $branch['data']['class'] === $key ? 'selected' : '' }}>{{ VD::sfLabel($key) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Gültig ab</label><input type="date" name="vehicle[{{ $branch['prefix'] }}_valid_from]" value="{{ $branch['data']['from'] }}" style="{{ $kfzInputStyle }}" aria-label="Gültig ab"></div>
        </div>
        <div style="margin:6px 0 4px;font-size:12px;color:var(--ink-soft);">Art der SF-Klasse</div>
        <div class="kfz-chip-row">
            @foreach(VD::SF_TYPES as $key => $label)
            <label class="kfz-chip"><input type="radio" name="vehicle[{{ $branch['prefix'] }}_type]" value="{{ $key }}" {{ $branch['data']['type'] === $key ? 'checked' : '' }} data-h-change="78b9583e66"><span>{{ $key === 'tatsaechlich' ? '✅' : '⭐' }} {{ $label }}</span></label>
            @endforeach
        </div>
        <div id="kfz-sonder-{{ $branch['short'] }}" style="display:none;margin-top:10px;background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:12px;">
            <div style="font-size:12px;color:var(--ink-soft);margin-bottom:8px;">Grund der Sondereinstufung</div>
            <div class="kfz-chip-row">
                @foreach(VD::SF_SPECIAL_REASONS as $key => $label)
                <label class="kfz-chip"><input type="radio" name="vehicle[{{ $branch['prefix'] }}_special_reason]" value="{{ $key }}" {{ $branch['data']['reason'] === $key ? 'checked' : '' }}><span>{{ $label }}</span></label>
                @endforeach
            </div>
            @include('admin.partials.contract_kfz_sf_reference', [
                'branchKey' => $branch['short'] === 'liability' ? 'haftpflicht' : 'vollkasko',
                'short' => $branch['short'],
                'ref' => $sfRefData[$branch['short'] === 'liability' ? 'haftpflicht' : 'vollkasko'],
                'refModel' => $sfRefModels[$branch['short'] === 'liability' ? 'haftpflicht' : 'vollkasko'],
            ])
            <div class="field" style="margin-top:10px;max-width:280px;"><label>Tatsächliche SF-Klasse (übertragbar)</label>
                <select name="vehicle[{{ $branch['prefix'] }}_real_class]" id="kfz-real-{{ $branch['short'] }}" style="{{ $kfzInputStyle }}" aria-label="Tatsächliche SF-Klasse (übertragbar)">
                    <option value="">— keine Angabe —</option>
                    @foreach(VD::sfClassKeys() as $key)
                    <option value="{{ $key }}" {{ $branch['data']['real'] === $key ? 'selected' : '' }}>{{ VD::sfLabel($key) }}</option>
                    @endforeach
                </select>
                <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">Diese Klasse gilt beim Wechsel zu einem anderen Versicherer – nicht die gewährte Sondereinstufung.</div>
                <div class="kfz-suggest" id="kfz-suggest-{{ $branch['short'] }}" hidden>
                    <span>Vorschlag: <b class="kfz-suggest-val"></b> <span class="kfz-suggest-why"></span></span>
                    <button type="button" class="kfz-suggest-apply" data-target="kfz-real-{{ $branch['short'] }}">übernehmen</button>
                </div>
            </div>
        </div>
    </div>
    @endforeach

    @if($sfHistory->isNotEmpty())
    <div class="kfz-subline">SF-Verlauf</div>
    <table class="kfz-sf-table">
        <thead><tr><th>Sparte</th><th>SF-Klasse</th><th>Gültig ab</th><th>Gültig bis</th><th>Grund / Bezug</th></tr></thead>
        <tbody>
        @foreach($sfHistory as $entry)
        <tr>
            <td>{{ $entry->branchLabel() }}</td>
            <td style="font-weight:700;">{{ VD::sfLabel($entry->sf_class) }}</td>
            <td>{{ $entry->valid_from?->format('d.m.Y') ?? '—' }}</td>
            <td>{{ $entry->valid_until?->format('d.m.Y') ?? 'aktuell' }}</td>
            <td>@if($entry->reference_contract_id && $entry->referenceContract)<a href="{{ route('admin.contract.edit', $entry->reference_contract_id) }}">{{ $entry->reasonText() }}</a>@else{{ $entry->reasonText() ?? '—' }}@endif</td>
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
    <button type="button" class="kfz-row-btn" data-h-click="90204f6c8a">+ Schaden hinzufügen</button>
</div>

<script @cspNonce>
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
        <div><label class="muted-2xs">Name</label>
            <input type="text" name="vehicle[additional_drivers][${i}][name]" maxlength="120" value="${kfzEsc(d.name)}" placeholder="Vor- und Nachname" style="${KFZ_INPUT}" aria-label="Name"></div>
        <div><label class="muted-2xs">Geburtsdatum</label>
            <input type="date" name="vehicle[additional_drivers][${i}][birth_date]" value="${kfzEsc(d.birth_date)}" style="${KFZ_INPUT}" aria-label="Geburtsdatum"></div>
        <div><label class="muted-2xs">Führerschein seit</label>
            <input type="date" name="vehicle[additional_drivers][${i}][license_date]" value="${kfzEsc(d.license_date)}" style="${KFZ_INPUT}" aria-label="Führerschein seit"></div>
        <button type="button" class="kfz-remove" title="Fahrer entfernen" data-h-click="kfz-entfernen">✕</button>`;
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
        <div><label class="muted-2xs">Datum</label>
            <input type="date" name="vehicle[claim_rows][${i}][claim_date]" value="${kfzEsc(cl.claim_date)}" style="${KFZ_INPUT}" aria-label="Datum"></div>
        <div><label class="muted-2xs">Art</label>
            <select name="vehicle[claim_rows][${i}][claim_type]" style="${KFZ_INPUT}" aria-label="Art">${opts(KFZ_CLAIM_TYPES, cl.claim_type, '—')}</select></div>
        <div><label class="muted-2xs">Schaden (€)</label>
            <input type="number" step="0.01" min="0" name="vehicle[claim_rows][${i}][damage_amount]" value="${kfzEsc(cl.damage_amount)}" placeholder="0,00" style="${KFZ_INPUT}" aria-label="Schaden (€)"></div>
        <div><label class="muted-2xs">Status</label>
            <select name="vehicle[claim_rows][${i}][status]" style="${KFZ_INPUT}" aria-label="Status">${opts(KFZ_CLAIM_STATUSES, cl.status, '—')}</select></div>
        <div><label class="muted-2xs">Versicherer / Notiz</label>
            <div style="display:flex;gap:6px;">
                <input type="text" name="vehicle[claim_rows][${i}][insurer]" maxlength="255" value="${kfzEsc(cl.insurer)}" placeholder="Versicherer" style="${KFZ_INPUT}" aria-label="Versicherer">
                <input type="text" name="vehicle[claim_rows][${i}][notes]" maxlength="2000" value="${kfzEsc(cl.notes)}" placeholder="Notizen" style="${KFZ_INPUT}" aria-label="Notizen">
            </div></div>
        <button type="button" class="kfz-remove" title="Schaden entfernen" data-h-click="kfz-entfernen">✕</button>`;
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
        const reason = document.querySelector(`input[name="vehicle[${prefix}_special_reason]"]:checked`);
        kfzRefVisibility(branch, sonder ? (reason ? reason.value : '') : '');
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

// ---- SF-Bezugsfahrzeug (01.10.2026) ----
const KFZ_SFREF = @json($kfzSfrefJs);
const KFZ_ZWEITWAGEN_RE = /(zweit|dritt|erstwagen|\b[123]\.\s*(wagen|fahrzeug|auto|pkw)\b)/i;

// Bloecke je Grund ein-/ausblenden; verborgene Felder deaktivieren, damit
// nur die Angaben des gewaehlten Grundes gesendet werden.
function kfzRefVisibility(branch, reason) {
    const root = document.querySelector(`.kfz-sfref[data-short="${branch}"]`);
    if (!root) return;
    root.querySelectorAll('.kfz-sfref-block').forEach(block => {
        const show = reason !== '' && block.dataset.reasons.split(',').includes(reason);
        block.hidden = !show;
        block.querySelectorAll('input,select,textarea,button').forEach(el => { el.disabled = !show; });
    });
    // VK "wie Haftpflicht": eigene Auswahl ausblenden
    const copy = root.querySelector('.kfz-ref-copy');
    const own = root.querySelector('.kfz-ref-own');
    if (copy && own && !copy.disabled) {
        own.hidden = copy.checked;
        own.querySelectorAll('input,select,textarea,button').forEach(el => { if (copy.checked) el.disabled = true; });
    }
    kfzRefHolderState(root);
    kfzSuggest(branch, root);
}

function kfzCustomerId() {
    const sel = document.getElementById('customer_id_selected');
    return KFZ_SFREF.customerId || (sel ? sel.value : '');
}

function kfzRefSelect(root, hit) {
    root.querySelector('.kfz-ref-type').value = 'internal';
    root.querySelector('.kfz-ref-id').value = hit.id;
    const card = root.querySelector('.kfz-ref-card');
    card.querySelector('.kfz-ref-card-title').textContent = hit.label;
    card.querySelector('.kfz-ref-card-meta').textContent = [hit.plate, hit.vehicle, hit.sf_hp ? 'HP ' + hit.sf_hp : null, hit.sf_vk ? 'VK ' + hit.sf_vk : null, hit.status, hit.external ? 'Fremdvertrag' : null, hit.own_customer ? null : hit.owner].filter(Boolean).join(' · ');
    const link = card.querySelector('.kfz-ref-card-link');
    link.href = hit.url; link.hidden = false;
    card.dataset.otherCustomer = hit.own_customer ? '' : '1';
    card.hidden = false;
    root.querySelector('.kfz-ref-search').hidden = true;
    root.querySelector('.kfz-ref-ext').hidden = true;
    if (!hit.own_customer && !root.querySelector('.kfz-ref-holder:checked')) {
        const fam = root.querySelector('.kfz-ref-holder[value="familie"]');
        if (fam) fam.checked = true;
    }
    kfzRefHolderState(root);
}

function kfzRefClear(root) {
    root.querySelector('.kfz-ref-type').value = '';
    root.querySelector('.kfz-ref-id').value = '';
    const card = root.querySelector('.kfz-ref-card');
    card.hidden = true; card.dataset.otherCustomer = '';
    root.querySelector('.kfz-ref-search').hidden = false;
    root.querySelector('.kfz-ref-ext').hidden = true;
    kfzRefSearch(root);
}

function kfzRefHolderState(root) {
    const holder = root.querySelector('.kfz-ref-holder:checked');
    const nameBox = root.querySelector('.kfz-ref-holder-name');
    if (nameBox) nameBox.hidden = !(holder && ['partner', 'familie', 'sonstige'].includes(holder.value));
    const warn = root.querySelector('.kfz-ref-holder-warn');
    const card = root.querySelector('.kfz-ref-card');
    if (warn) warn.hidden = !(card && !card.hidden && card.dataset.otherCustomer === '1' && (!holder || holder.value === 'kunde'));
}

let kfzRefTimer = null;
function kfzRefSearch(root) {
    const customerId = kfzCustomerId();
    const box = root.querySelector('.kfz-ref-results');
    const empty = root.querySelector('.kfz-ref-empty');
    if (!customerId) { box.textContent = ''; empty.hidden = false; empty.textContent = 'Bitte zuerst den Kunden wählen.'; return; }
    const url = new URL(KFZ_SFREF.searchUrlTemplate.replace('__KUNDE__', encodeURIComponent(customerId)), window.location.origin);
    url.searchParams.set('q', root.querySelector('.kfz-ref-q').value.trim());
    if (KFZ_SFREF.contractId) url.searchParams.set('exclude', KFZ_SFREF.contractId);
    fetch(url, {headers: {'Accept': 'application/json'}}).then(r => r.ok ? r.json() : {results: []}).then(data => {
        box.textContent = '';
        (data.results || []).forEach(hit => {
            const btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'kfz-ref-hit';
            const title = document.createElement('b'); title.textContent = hit.label + (hit.active ? '' : ' – ' + hit.status);
            const meta = document.createElement('small');
            meta.textContent = [hit.plate, hit.vehicle, hit.sf_hp ? 'HP ' + hit.sf_hp : null, hit.sf_vk ? 'VK ' + hit.sf_vk : null, hit.owner, hit.external ? 'Fremdvertrag' : null].filter(Boolean).join(' · ');
            btn.append(title, meta);
            btn.addEventListener('click', () => kfzRefSelect(root, hit));
            box.appendChild(btn);
        });
        empty.textContent = 'Kein passender KFZ-Vertrag bei Kunde oder Familie.';
        empty.hidden = (data.results || []).length > 0;
    }).catch(() => {});
}

// SF-Vorschlag aus dem Fuehrerscheindatum (Regel SF-VORSCHLAG-FUEHRERSCHEIN).
function kfzSuggest(branch, root) {
    const box = document.getElementById('kfz-suggest-' + branch);
    if (!box) return;
    const input = root.querySelector('.kfz-ref-license');
    const value = input && !input.disabled ? input.value : '';
    if (!value) { box.hidden = true; return; }
    const lic = new Date(value + 'T00:00:00'); const today = new Date(); today.setHours(0, 0, 0, 0);
    if (isNaN(lic) || lic > today) { box.hidden = true; return; }
    const limit = new Date(lic); limit.setFullYear(limit.getFullYear() + KFZ_SFREF.suggestYears);
    const cls = limit <= today ? KFZ_SFREF.suggestAbove : KFZ_SFREF.suggestBelow;
    box.querySelector('.kfz-suggest-val').textContent = 'SF ' + (cls === '1/2' ? '½' : cls);
    box.querySelector('.kfz-suggest-why').textContent = '(Führerschein ' + (limit <= today ? 'seit mind. ' : 'kürzer als ') + KFZ_SFREF.suggestYears + ' Jahre – bitte mit den AKB des Versicherers abgleichen)';
    box.querySelector('.kfz-suggest-apply').dataset.value = cls;
    box.hidden = false;
}

function kfzPrevState() {
    const none = document.getElementById('kfz-no-prev');
    const fields = document.getElementById('kfz-prev-fields');
    if (!none || !fields) return;
    fields.hidden = none.checked;
    fields.querySelectorAll('input').forEach(el => { el.disabled = none.checked; });
    const ins = document.querySelector('input[name="vehicle[previous_insurer]"]').value;
    const nr = document.querySelector('input[name="vehicle[previous_contract_number]"]').value;
    document.getElementById('kfz-prev-zweitwagen').hidden = none.checked || !KFZ_ZWEITWAGEN_RE.test(ins + ' ' + nr);
}

function kfzRefInit() {
    document.querySelectorAll('.kfz-sfref').forEach(root => {
        const q = root.querySelector('.kfz-ref-q');
        if (q) q.addEventListener('input', () => { clearTimeout(kfzRefTimer); kfzRefTimer = setTimeout(() => kfzRefSearch(root), 250); });
        if (q) q.addEventListener('focus', () => { if (!root.querySelector('.kfz-ref-results').children.length) kfzRefSearch(root); });
        root.querySelector('.kfz-ref-clear')?.addEventListener('click', () => kfzRefClear(root));
        root.querySelector('.kfz-ref-ext-btn')?.addEventListener('click', () => {
            root.querySelector('.kfz-ref-type').value = 'external';
            root.querySelector('.kfz-ref-id').value = '';
            root.querySelector('.kfz-ref-search').hidden = true;
            root.querySelector('.kfz-ref-ext').hidden = false;
        });
        root.querySelector('.kfz-ref-back')?.addEventListener('click', () => kfzRefClear(root));
        root.querySelectorAll('.kfz-ref-holder').forEach(r => r.addEventListener('change', () => kfzRefHolderState(root)));
        root.querySelector('.kfz-ref-license')?.addEventListener('input', () => kfzSuggest(root.dataset.short, root));
    });
    document.querySelectorAll('.kfz-suggest-apply').forEach(btn => btn.addEventListener('click', () => {
        const sel = document.getElementById(btn.dataset.target);
        if (sel && btn.dataset.value) { sel.value = btn.dataset.value; sel.dispatchEvent(new Event('change', {bubbles: true})); }
    }));
    document.getElementById('kfz-no-prev')?.addEventListener('change', kfzPrevState);
    ['vehicle[previous_insurer]', 'vehicle[previous_contract_number]'].forEach(name =>
        document.querySelector(`input[name="${name}"]`)?.addEventListener('input', kfzPrevState));
    kfzPrevState();
}

document.addEventListener('DOMContentLoaded', function () {
    kfzRefInit();
    KFZ_DRIVERS_INIT.forEach(d => kfzAddDriver(d));
    KFZ_CLAIMS_INIT.forEach(cl => kfzAddClaim(cl));
    kfzSync();
    document.getElementById('section-kfz').addEventListener('change', kfzSync);
    document.getElementById('section-kfz').addEventListener('input', kfzSummary);
});
</script>

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["78b9583e66"] = function (event) { kfzSync() };
// Entfernen-Knopf: wird von kfzAddDriver()/kfzAddClaim() per JavaScript
// erzeugt und traegt deshalb nur das data-Attribut, keinen Code.
window.__h["kfz-entfernen"] = function (event) { this.parentElement.remove(); kfzSummary(); };
window.__h["565b7d45ea"] = function (event) { kfzAddDriver() };
window.__h["90204f6c8a"] = function (event) { kfzAddClaim() };
</script>
@endPushOnce
