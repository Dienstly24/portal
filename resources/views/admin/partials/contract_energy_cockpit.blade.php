{{--
    Energie-Cockpit (Betreiber-Vorgabe 29.07.2026): Zaehlerstand, Verbrauch
    und die vollstaendige Ablese-Historie eines Strom-/Gasvertrags - inkl.
    Erfassung eines neuen Standes von Hand. Die Historie entsteht sonst
    automatisch aus hochgeladenen Zaehlerfotos (Zuordnung ueber die
    Zaehlernummer). Erwartet $contract (mit geladenem energyDetail).
--}}
@php
    $en = $contract->energyDetail;
@endphp
@if(in_array($contract->type, \App\Models\Contract::ENERGY_TYPES, true) && $en)
@php
    $meterLatest = $en->latestMeterReading();
    $meterStatus = $en->consumptionStatus();
    $meterHistory = $en->consumptionHistory();
    $meterUnit = $en->readingUnit();
    $hasFeedIn = in_array('2.8.0', $en->registersWithReadings(), true);
    $feedInHistory = $hasFeedIn ? $en->consumptionHistory('2.8.0') : [];
    $meterMax = collect($meterHistory)->pluck('consumption')->filter()->max() ?: 0;
    $canManage = in_array(auth()->user()->role, ['admin', 'manager'], true);
@endphp
<div class="card" style="max-width:980px;background:linear-gradient(135deg,#131A17,#0F1512);border-color:#0F1512;color:#fff;">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <span style="font-size:34px;line-height:1;">{{ $contract->typeIcon() }}</span>
        <div style="min-width:200px;">
            <div style="font-size:17px;font-weight:800;letter-spacing:.02em;">
                {{ $meterLatest ? $meterLatest->formatted() : 'Kein Zählerstand erfasst' }}
            </div>
            <div style="font-size:12.5px;color:#B9BFC9;">
                @if($meterLatest)
                    Stand vom {{ $meterLatest->reading_date->format('d.m.Y') }}
                    @if($meterLatest->captured_at) · erfasst {{ $meterLatest->captured_at->lokal()->format('d.m.Y H:i') }} Uhr @endif
                    · {{ $meterLatest->sourceLabel() }}
                @else
                    Sobald ein Zählerfoto hochgeladen wird, erscheint der Stand hier automatisch.
                @endif
            </div>
        </div>
        <div style="margin-left:auto;text-align:right;">
            <div style="font-size:12px;color:#B9BFC9;">
                {{ $contract->insurer }}@if($en->meter_number) · Zähler {{ $en->meter_number }}@endif
            </div>
            @if($en->consumption_kwh)
            <div style="font-size:12.5px;color:#B9BFC9;">Vereinbart: {{ number_format($en->consumption_kwh, 0, ',', '.') }} {{ $meterUnit }}/Jahr</div>
            @endif
        </div>
    </div>

    @if($meterStatus)
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:16px;">
        @php $box = 'background:#0B1310;border:1px solid #1E2A24;border-radius:10px;padding:12px 14px;'; @endphp
        <div style="{{ $box }}">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;">Verbrauch</div>
            <div style="font-size:16px;font-weight:800;margin-top:4px;">{{ \App\Models\MeterReading::formatValue($meterStatus['consumption'], $meterUnit) }}</div>
            <div style="font-size:11.5px;color:#B9BFC9;margin-top:2px;">seit {{ $meterStatus['previous']->reading_date->format('d.m.Y') }} ({{ $meterStatus['days'] }} Tage)</div>
        </div>
        <div style="{{ $box }}">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;">Pro Tag</div>
            <div style="font-size:16px;font-weight:800;margin-top:4px;">
                {{ $meterStatus['per_day'] !== null ? \App\Models\MeterReading::formatValue((float) $meterStatus['per_day'], $meterUnit) : '—' }}
            </div>
            @php $cost = $en->estimatedCost($meterStatus['consumption']); @endphp
            <div style="font-size:11.5px;color:#B9BFC9;margin-top:2px;">
                {{ $cost !== null ? 'rund ' . number_format($cost, 2, ',', '.') . ' € Arbeitspreis' : 'Arbeitspreis nicht gepflegt' }}
            </div>
        </div>
        <div style="{{ $box }}">
            <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;">Hochrechnung</div>
            <div style="font-size:16px;font-weight:800;margin-top:4px;">
                {{ $meterStatus['projected'] ? number_format($meterStatus['projected'], 0, ',', '.') . ' ' . $meterUnit : '—' }}
            </div>
            @if($meterStatus['projected'] && $meterStatus['expected'])
                @if($meterStatus['exceeded'])
                <div style="font-size:11.5px;font-weight:700;color:#F08A8A;margin-top:2px;">⚠️ {{ $meterStatus['deviation_percent'] }} % über dem vereinbarten Verbrauch</div>
                @else
                <div style="font-size:11.5px;font-weight:700;color:#5BD79A;margin-top:2px;">✓ {{ abs($meterStatus['deviation_percent']) }} % unter dem vereinbarten Verbrauch</div>
                @endif
            @elseif(!$meterStatus['projected'])
            <div style="font-size:11.5px;color:#B9BFC9;margin-top:2px;">Zeitraum unter 14 Tagen – zu kurz</div>
            @endif
        </div>
    </div>
    @endif

    {{-- Ablese-Historie: je Eintrag der Verbrauch seit der vorherigen Ablesung --}}
    @if(count($meterHistory) > 0)
    <div style="margin-top:16px;background:#0B1310;border:1px solid #1E2A24;border-radius:10px;padding:12px 14px;">
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;margin-bottom:8px;">
            Verbrauchshistorie · {{ \App\Models\MeterReading::REGISTERS[\App\Models\MeterReading::REGISTER_DEFAULT] }}
        </div>
        @foreach($meterHistory as $row)
        @php $r = $row['reading']; @endphp
        <div style="padding:8px 0;{{ !$loop->last ? 'border-bottom:1px solid #1E2A24;' : '' }}">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;">
                <span style="font-size:12.5px;color:#B9BFC9;">
                    {{ $r->reading_date->format('d.m.Y') }}
                    @if($r->captured_at) · {{ $r->captured_at->lokal()->format('H:i') }} Uhr @endif
                    · {{ $r->sourceLabel() }}@if($r->created_by) ({{ $r->created_by }})@endif
                    @if($r->document_id)
                    · <a href="{{ route('admin.documents.download', $r->document_id) }}" target="_blank" style="color:#5BD79A;">📎 Foto</a>
                    @endif
                </span>
                <span style="font-size:13px;font-weight:700;white-space:nowrap;">
                    {{ $r->formatted() }}
                    @if($canManage)
                    <form method="POST" action="{{ route('admin.contract.meter_reading.destroy', [$contract->id, $r->id]) }}"
                          style="display:inline;" onsubmit="return confirm('Diese Ablesung wirklich löschen?');">
                        @csrf @method('DELETE')
                        <button type="submit" title="Ablesung löschen"
                                style="background:none;border:none;color:#8A919E;cursor:pointer;font-size:12px;padding:0 0 0 6px;">✕</button>
                    </form>
                    @endif
                </span>
            </div>
            @if($row['implausible'])
            <div style="font-size:11.5px;color:#F08A8A;margin-top:4px;">⚠️ Niedriger als der vorherige Stand – Zählerwechsel oder Lesefehler prüfen.</div>
            @elseif($row['consumption'] !== null)
            <div style="display:flex;align-items:center;gap:8px;margin-top:6px;">
                <div style="flex:1;height:6px;background:#1E2A24;border-radius:999px;overflow:hidden;">
                    <div style="height:100%;width:{{ $meterMax > 0 ? max(3, round($row['consumption'] / $meterMax * 100)) : 0 }}%;background:linear-gradient(90deg,#19b463,#128a4b);"></div>
                </div>
                <span style="font-size:11.5px;color:#B9BFC9;white-space:nowrap;">
                    +{{ \App\Models\MeterReading::formatValue((float) $row['consumption'], $meterUnit) }}@if($row['days']) · {{ $row['days'] }} Tage @endif
                </span>
            </div>
            @else
            <div style="font-size:11.5px;color:#8A919E;margin-top:4px;">Erster erfasster Stand</div>
            @endif
            @if($r->note)
            <div style="font-size:11.5px;color:#8A919E;margin-top:4px;">{{ $r->note }}</div>
            @endif
        </div>
        @endforeach
    </div>
    @endif

    {{-- Einspeisung getrennt (Zweirichtungszaehler mit PV-Anlage) --}}
    @if($hasFeedIn && count($feedInHistory) > 0)
    <div style="margin-top:12px;background:#0B1310;border:1px solid #1E2A24;border-radius:10px;padding:12px 14px;">
        <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:#8A919E;font-weight:700;margin-bottom:8px;">
            Einspeisung (2.8.0)
        </div>
        @foreach($feedInHistory as $row)
        <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;color:#B9BFC9;padding:4px 0;">
            <span>{{ $row['reading']->reading_date->format('d.m.Y') }}</span>
            <span style="font-weight:700;color:#fff;">
                {{ $row['reading']->formatted() }}@if($row['consumption'] !== null) <span style="color:#5BD79A;">(+{{ \App\Models\MeterReading::formatValue((float) $row['consumption'], $meterUnit) }})</span>@endif
            </span>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Stand von Hand erfassen (z.B. telefonische Meldung des Kunden) --}}
    <form method="POST" action="{{ route('admin.contract.meter_reading.store', $contract->id) }}"
          style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
        @csrf
        <div style="flex:1 1 150px;">
            <label style="display:block;font-size:11px;color:#8A919E;font-weight:700;margin-bottom:4px;">Zählerstand ({{ $meterUnit }})</label>
            <input type="number" name="reading" step="0.001" min="0" max="99999999" required placeholder="z. B. 4680"
                   style="width:100%;padding:9px 10px;border:1px solid #1E2A24;border-radius:8px;font-size:14px;background:#0B1310;color:#fff;">
        </div>
        <div style="flex:0 1 150px;">
            <label style="display:block;font-size:11px;color:#8A919E;font-weight:700;margin-bottom:4px;">Abgelesen am</label>
            <input type="date" name="reading_date" max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}"
                   style="width:100%;padding:9px 10px;border:1px solid #1E2A24;border-radius:8px;font-size:14px;background:#0B1310;color:#fff;">
        </div>
        <div style="flex:0 1 190px;">
            <label style="display:block;font-size:11px;color:#8A919E;font-weight:700;margin-bottom:4px;">Zählwerk</label>
            <select name="register" style="width:100%;padding:9px 10px;border:1px solid #1E2A24;border-radius:8px;font-size:14px;background:#0B1310;color:#fff;">
                @foreach(\App\Models\MeterReading::REGISTERS as $key => $label)
                <option value="{{ $key }}">{{ $key }} – {{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="white-space:nowrap;">Ablesung erfassen</button>
    </form>
</div>
@endif
