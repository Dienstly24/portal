{{--
    KFZ-Cockpit: kompakter Ueberblick ueber den gespeicherten Vertrag, oben auf
    der Bearbeiten-Seite. Ziel (Betreiber-Auftrag 17.07.2026): jeder Mitarbeiter
    sieht in Sekunden Deckung, Zusatzleistungen (z.B. Schutzbrief bei einem
    Pannen-Anruf), SF-Einstufung inkl. Uebertragbarkeit, Kilometer und Schaeden.
    Erwartet $contract (mit geladenem vehicleDetail inkl. claims/mileageReadings/sfHistory).
--}}
@php
    use App\Models\ContractVehicleDetail as VD;
    $veh = $contract->vehicleDetail;
@endphp
@if($contract->type === 'kfz' && $veh)
@php
    $mileageStatus = $veh->mileageStatus();
    $latestReading = $veh->latestMileageReading();
    $eur = fn($v) => number_format((float) $v, 2, ',', '.') . ' €';
@endphp
<div class="card" style="max-width:980px;background:linear-gradient(135deg,#17191d,#101216);border-color:#101216;color:#fff;">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <span style="font-size:34px;line-height:1;">{{ $veh->vehicleTypeIcon() }}</span>
        <div style="min-width:200px;">
            <div style="font-size:17px;font-weight:800;letter-spacing:.02em;">
                {{ $veh->license_plate ?: 'Kennzeichen —' }}
            </div>
            <div style="font-size:12.5px;color:#B9BFC9;">
                {{ trim(($veh->manufacturer ?? '') . ' ' . ($veh->model ?? '')) ?: 'Fahrzeug —' }}
                @if($veh->vehicleTypeLabel()) · {{ $veh->vehicleTypeLabel() }}@endif
                @if($veh->first_registration) · EZ {{ $veh->first_registration->format('m/Y') }}@endif
                @if($veh->power_kw) · {{ $veh->power_kw }} kW @endif
                @if($veh->fuelLabel()) · {{ $veh->fuelLabel() }}@endif
            </div>
        </div>
        <div style="margin-left:auto;text-align:right;">
            <div style="font-size:12px;color:#B9BFC9;">{{ $contract->insurer }}@if($contract->contract_number) · VSNR {{ $contract->contract_number }}@endif</div>
            @if($contract->hasPremium())
            <div style="font-size:15px;font-weight:800;color:#2BC777;">{{ $eur($contract->premium_amount) }} <span style="font-size:11.5px;color:#B9BFC9;font-weight:600;">/ {{ $contract->premiumIntervalLabel() }}</span></div>
            @endif
        </div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;">
        @php $chip = 'display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;'; @endphp
        <span style="{{ $chip }}background:#17A65B;color:#fff;">✓ Haftpflicht</span>
        @if($veh->has_teilkasko)<span style="{{ $chip }}background:#17A65B;color:#fff;">✓ Teilkasko · {{ VD::deductibleLabel($veh->teilkasko_deductible !== null ? (int) $veh->teilkasko_deductible : null) }}</span>
        @else<span style="{{ $chip }}background:#2A2E36;color:#8A919E;">✗ Teilkasko</span>@endif
        @if($veh->has_vollkasko)<span style="{{ $chip }}background:#17A65B;color:#fff;">✓ Vollkasko · {{ VD::deductibleLabel($veh->vollkasko_deductible !== null ? (int) $veh->vollkasko_deductible : null) }}</span>
        @else<span style="{{ $chip }}background:#2A2E36;color:#8A919E;">✗ Vollkasko</span>@endif
    </div>

    {{-- Zusatzleistungen: sofort sichtbar, ob z.B. ein Schutzbrief besteht --}}
    <div style="margin-top:12px;">
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;margin-bottom:6px;">Zusatzleistungen ({{ count($veh->extrasLabels()) }})</div>
        @if($veh->extrasLabels())
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            @foreach($veh->extrasLabels() as $label)
            <span style="{{ $chip }}background:#1E2b24;color:#5BD79A;border:1px solid #24513A;">{{ $label }}</span>
            @endforeach
        </div>
        @else
        <div style="font-size:12.5px;color:#8A919E;">Keine Zusatzleistungen hinterlegt.</div>
        @endif
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(215px,1fr));gap:10px;margin-top:14px;">
        {{-- SF-Einstufung je Sparte --}}
        @foreach([['haftpflicht', 'Haftpflicht', 'sf_liability'], ['vollkasko', 'Vollkasko', 'sf_comprehensive']] as [$branch, $title, $prefix])
        @php
            $class = $veh->{$prefix . '_class'};
            $type = $veh->{$prefix . '_type'};
            $transferable = $veh->sfTransferable($branch);
        @endphp
        @if($branch === 'haftpflicht' || $veh->has_vollkasko)
        <div style="background:#1B1E24;border:1px solid #2A2E36;border-radius:10px;padding:10px 12px;">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;">SF {{ $title }}</div>
            @if($class)
            <div style="font-size:14px;font-weight:800;margin-top:3px;">{{ VD::sfLabel($class) }}
                @if($veh->{$prefix . '_valid_from'})<span style="font-size:11px;color:#8A919E;font-weight:600;"> ab {{ $veh->{$prefix . '_valid_from'}->format('d.m.Y') }}</span>@endif
            </div>
            @if($type === 'sondereinstufung')
            <div style="font-size:11.5px;color:#E8B25A;margin-top:3px;">⭐ {{ VD::SF_SPECIAL_REASONS[$veh->{$prefix . '_special_reason'}] ?? 'Sondereinstufung' }}@if($veh->{$prefix . '_real_class'}) · Tatsächlich: <b>{{ VD::sfLabel($veh->{$prefix . '_real_class'}) }}</b>@endif</div>
            @endif
            <div style="font-size:11.5px;margin-top:4px;font-weight:700;color:{{ $transferable ? '#5BD79A' : '#F08A8A' }};">
                {{ $transferable ? '🟢 Übertragbar zur anderen Versicherung' : '🔴 Nicht übertragbar (Sondereinstufung)' }}
            </div>
            @else
            <div style="font-size:13px;color:#8A919E;margin-top:3px;">—</div>
            @endif
        </div>
        @endif
        @endforeach

        {{-- Kilometer --}}
        <div style="background:#1B1E24;border:1px solid #2A2E36;border-radius:10px;padding:10px 12px;">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;">Kilometer</div>
            <div style="font-size:14px;font-weight:800;margin-top:3px;">
                {{ $latestReading ? number_format($latestReading->mileage, 0, ',', '.') . ' km' : '—' }}
                @if($latestReading)<span style="font-size:11px;color:#8A919E;font-weight:600;">({{ $latestReading->reading_date->format('d.m.Y') }})</span>@endif
            </div>
            <div style="font-size:11.5px;color:#8A919E;margin-top:3px;">
                @if($veh->annual_mileage)Vereinbart: {{ number_format($veh->annual_mileage, 0, ',', '.') }} km/Jahr @else Keine Fahrleistung vereinbart @endif
            </div>
            @if($mileageStatus && $mileageStatus['exceeded'])
            <div style="font-size:11.5px;font-weight:700;color:#F08A8A;margin-top:4px;">⚠️ Hochgerechnet {{ number_format($mileageStatus['projected'], 0, ',', '.') }} km/Jahr – Limit überschritten!</div>
            @elseif($mileageStatus)
            <div style="font-size:11.5px;font-weight:700;color:#5BD79A;margin-top:4px;">✓ Hochgerechnet {{ number_format($mileageStatus['projected'], 0, ',', '.') }} km/Jahr</div>
            @endif
        </div>

        {{-- Fahrer + Halter --}}
        <div style="background:#1B1E24;border:1px solid #2A2E36;border-radius:10px;padding:10px 12px;">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;">Fahrer / Halter</div>
            <div style="font-size:12px;margin-top:3px;color:#D6DAE0;">
                {{ $veh->driverGroupLabels() ? implode(', ', $veh->driverGroupLabels()) : '—' }}
                @foreach($veh->additional_drivers ?? [] as $drv)
                <div style="color:#8A919E;">→ {{ $drv['name'] ?? '' }}@if(!empty($drv['birth_date'])) ({{ \Carbon\Carbon::parse($drv['birth_date'])->format('d.m.Y') }})@endif</div>
                @endforeach
            </div>
            <div style="font-size:11.5px;color:#8A919E;margin-top:4px;">
                @if($veh->holderLabel())Halter: {{ $veh->holder_type === 'abweichender_halter' && $veh->holder_name ? $veh->holder_name : $veh->holderLabel() }}@endif
                @if($veh->ownershipLabel()) · Eigentum: {{ $veh->ownershipLabel() }}@endif
            </div>
        </div>

        {{-- Schaeden --}}
        <div style="background:#1B1E24;border:1px solid #2A2E36;border-radius:10px;padding:10px 12px;">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;">Schäden</div>
            @if($veh->claims->isEmpty())
            <div style="font-size:14px;font-weight:800;margin-top:3px;color:#5BD79A;">Schadenfrei</div>
            @else
            <div style="font-size:14px;font-weight:800;margin-top:3px;color:#E8B25A;">{{ $veh->claims->count() }} Schaden{{ $veh->claims->count() > 1 ? 'fälle' : 'fall' }}</div>
            @foreach($veh->claims->take(3) as $claim)
            <div style="font-size:11.5px;color:#8A919E;margin-top:2px;">
                {{ $claim->claim_date?->format('d.m.Y') ?? '—' }} · {{ $claim->typeLabel() }}@if($claim->damage_amount) · {{ $eur($claim->damage_amount) }}@endif · {{ $claim->statusLabel() }}
            </div>
            @endforeach
            @endif
        </div>

        {{-- Vorversicherung: wo war der Kunde vorher versichert (Wechsel-Info
             aus dem Beratungsprotokoll). Nur zeigen, wenn hinterlegt. --}}
        @if($veh->previous_insurer)
        <div style="background:#1B1E24;border:1px solid #2A2E36;border-radius:10px;padding:10px 12px;">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;">Vorversicherung</div>
            <div style="font-size:14px;font-weight:800;margin-top:3px;">{{ $veh->previous_insurer }}</div>
            @if($veh->previous_insurance_since)
            <div style="font-size:11.5px;color:#8A919E;margin-top:3px;">Dort versichert: {{ $veh->previous_insurance_since }}</div>
            @endif
            @if($veh->previous_insurance_terminated_by_insurer !== null)
            <div style="font-size:11.5px;margin-top:4px;font-weight:700;color:{{ $veh->previous_insurance_terminated_by_insurer ? '#F08A8A' : '#5BD79A' }};">
                {{ $veh->previous_insurance_terminated_by_insurer ? '⚠ Kündigung durch Vorversicherer' : '✓ Keine Kündigung durch Vorversicherer' }}
            </div>
            @endif
        </div>
        @endif
    </div>
</div>
@endif
