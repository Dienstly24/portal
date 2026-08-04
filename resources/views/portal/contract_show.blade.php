@extends('layouts.portal')
@section('content')
@php
$typeIcons = ['kfz'=>'🚗','schutzbrief'=>'🆘','strom'=>'⚡','gas'=>'🔥','strom_gas'=>'⚡','internet'=>'📶','haftpflicht'=>'🛡️','hausrat'=>'🏠','rechtsschutz'=>'⚖️','krankenversicherung'=>'🏥','leben'=>'❤️','unfall'=>'🚑','andere'=>'📋'];
$typeLabels = ['kfz'=>'KFZ','schutzbrief'=>'Schutzbrief / Mobilclub','strom'=>'Strom','gas'=>'Gas','strom_gas'=>'Strom/Gas','internet'=>'Internet','haftpflicht'=>'Haftpflicht','hausrat'=>'Hausrat','rechtsschutz'=>'Rechtsschutz','krankenversicherung'=>'Krankenversicherung','leben'=>'Leben','unfall'=>'Unfall','andere'=>'Andere'];
$intervalLabels = ['monatlich'=>'Monatlich','vierteljaehrlich'=>'Vierteljährlich','halbjaehrlich'=>'Halbjährlich','jaehrlich'=>'Jährlich'];
$d = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d.m.Y') : '—';
@endphp

<a href="{{ route('portal.contracts') }}" class="btn btn-ghost" style="margin-bottom:16px;">{{ __('← Alle Verträge') }}</a>

<div class="card">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px;">
        <span style="font-size:40px;line-height:1;">{{ $contract->typeIcon() }}</span>
        <div>
            <div class="page-title" style="margin-bottom:2px;">{{ $contract->insurer }}</div>
            <div class="page-sub" style="margin-bottom:0;">{{ __($contract->typeLabel()) }}</div>
        </div>
        @php $st = $contract->displayStatus(); @endphp
        <span class="badge badge-{{ $st['badge'] }}" style="margin-left:auto;white-space:nowrap;">{{ __($st['label_key'], $st['params']) }}</span>
    </div>
</div>

{{-- Allgemeine Vertragsdaten --}}
<div class="card">
    <div class="card-title">{{ __('Vertragsdaten') }}</div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Vertragsnummer') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $contract->contract_number ?? '—' }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Vertragstyp') }}</span><span style="font-weight:600;font-size:13.5px;">{{ __($contract->typeLabel()) }}</span></div>
    {{-- Untergruppe (z.B. ADAC Basis-/Plus-/Premium-Mitgliedschaft, GKV/PKV) --}}
    @if($contract->subtypeLabel())
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ $contract->type === 'schutzbrief' ? __('Mitgliedschaft') : __('Art') }}</span><span style="font-weight:600;font-size:13.5px;">{{ __($contract->subtypeLabel()) }}</span></div>
    @endif
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Startdatum') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $d($contract->start_date) }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Enddatum') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $d($contract->end_date) }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Kündigungsdatum') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $d($contract->cancellation_date) }}</span></div>
    @if($contract->hasPremium())
    @php $eur = fn($v) => number_format((float) $v, 2, ',', '.') . ' €'; @endphp
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ $contract->isOneTime() ? __('Einmaliger Beitrag') : __('Beitrag') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $eur($contract->premium_amount) }}{{ $contract->isOneTime() ? '' : ' / ' . __(\App\Models\Contract::PREMIUM_INTERVALS[$contract->premium_interval]['label'] ?? 'Monatlich') }}</span></div>
    @if(!$contract->isOneTime() && $contract->premium_interval !== 'monthly')
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Beitrag pro Monat') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $eur($contract->monthlyPremium()) }}</span></div>
    @endif
    @endif
</div>

@php $v = $contract->vehicleDetail; $rowL = 'color:var(--ink-soft);font-size:13px;'; $rowV = 'font-weight:600;font-size:13.5px;'; @endphp

{{-- Sparte E-Scooter: schlanke Ansicht (Kennzeichen, Fahrzeug, FIN, Deckung) --}}
@if($v && $contract->type === 'escooter')
<div class="card">
    <div class="card-title">🛴 {{ __('Ihr E-Scooter') }}</div>
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Kennzeichen') }}</span><span style="{{ $rowV }}">{{ $v->license_plate ?? '—' }}</span></div>
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Hersteller/Modellbezeichnung') }}</span><span style="{{ $rowV }}">{{ trim(($v->manufacturer ?? '') . ' ' . ($v->model ?? '')) ?: '—' }}</span></div>
    @if($v->vin)<div class="item-row"><span style="{{ $rowL }}">{{ __('Fahrgestellnummer') }}</span><span style="{{ $rowV }}">{{ $v->vin }}</span></div>@endif
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Tarifname') }}</span><span style="{{ $rowV }}">{{ $v->has_teilkasko ? __('Teilkasko') : __('Haftpflicht') }}</span></div>
</div>

{{-- Sparte KFZ (Redesign 17.07.2026: Deckung, Zusatzleistungen, Kilometerstand) --}}
@elseif($v)
<div class="card">
    <div class="card-title">{{ $v->vehicleTypeIcon() }} {{ __('Fahrzeugdaten') }}</div>
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Kennzeichen') }}</span><span style="{{ $rowV }}">{{ $v->license_plate ?? '—' }}</span></div>
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Fahrzeug') }}</span><span style="{{ $rowV }}">{{ trim(($v->manufacturer ?? '') . ' ' . ($v->model ?? '')) ?: '—' }}</span></div>
    @if($v->vehicleTypeLabel())<div class="item-row"><span style="{{ $rowL }}">{{ __('Fahrzeugtyp') }}</span><span style="{{ $rowV }}">{{ $v->vehicleTypeLabel() }}</span></div>@endif
    @if($v->vin)<div class="item-row"><span style="{{ $rowL }}">FIN</span><span style="{{ $rowV }}">{{ $v->vin }}</span></div>@endif
    @if($v->hsn || $v->tsn)<div class="item-row"><span style="{{ $rowL }}">{{ __('HSN / TSN') }}</span><span style="{{ $rowV }}">{{ $v->hsn ?? '—' }} / {{ $v->tsn ?? '—' }}</span></div>@endif
    @if($v->first_registration)<div class="item-row"><span style="{{ $rowL }}">{{ __('Erstzulassung') }}</span><span style="{{ $rowV }}">{{ $d($v->first_registration) }}</span></div>@endif
    @if($v->power_kw)<div class="item-row"><span style="{{ $rowL }}">{{ __('Leistung') }}</span><span style="{{ $rowV }}">{{ $v->power_kw }} kW</span></div>@endif
    @if($v->fuelLabel())<div class="item-row"><span style="{{ $rowL }}">{{ __('Kraftstoff') }}</span><span style="{{ $rowV }}">{{ $v->fuelLabel() }}</span></div>@endif
    @if($v->color)<div class="item-row"><span style="{{ $rowL }}">{{ __('Farbe') }}</span><span style="{{ $rowV }}">{{ $v->color }}</span></div>@endif
</div>

{{-- Versicherungsschutz + Zusatzleistungen --}}
<div class="card">
    <div class="card-title">🛡️ {{ __('Versicherungsschutz') }}</div>
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Haftpflicht') }}</span><span style="{{ $rowV }}color:#0E7A41;">✓ {{ __('enthalten') }}</span></div>
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Teilkasko') }}</span><span style="{{ $rowV }}{{ $v->has_teilkasko ? 'color:#0E7A41;' : 'color:var(--ink-soft);' }}">{{ $v->has_teilkasko ? '✓ ' . \App\Models\ContractVehicleDetail::deductibleLabel((int) $v->teilkasko_deductible) : '—' }}</span></div>
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Vollkasko') }}</span><span style="{{ $rowV }}{{ $v->has_vollkasko ? 'color:#0E7A41;' : 'color:var(--ink-soft);' }}">{{ $v->has_vollkasko ? '✓ ' . \App\Models\ContractVehicleDetail::deductibleLabel((int) $v->vollkasko_deductible) : '—' }}</span></div>
    @if($v->extrasLabels())
    <div style="margin-top:12px;">
        <div style="{{ $rowL }}margin-bottom:8px;">{{ __('Zusatzleistungen') }}</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            @foreach($v->extrasLabels() as $label)
            <span style="display:inline-flex;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:600;background:#E7F6EE;color:#0E7A41;">✓ {{ $label }}</span>
            @endforeach
        </div>
    </div>
    @endif
</div>

{{-- Kilometerstand melden (jede Meldung bleibt gespeichert) --}}
<div class="card">
    <div class="card-title">🧭 {{ __('Kilometerstand') }}</div>
    @php $latestReading = $v->latestMileageReading(); @endphp
    @if($latestReading)
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Letzte Meldung') }}</span><span style="{{ $rowV }}">{{ number_format($latestReading->mileage, 0, ',', '.') }} km ({{ $latestReading->reading_date->format('d.m.Y') }})</span></div>
    @endif
    @if($v->annual_mileage)
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Vereinbarte Fahrleistung') }}</span><span style="{{ $rowV }}">{{ number_format($v->annual_mileage, 0, ',', '.') }} km / {{ __('Jahr') }}</span></div>
    @endif
    <form method="POST" action="{{ route('portal.contracts.mileage', $contract->id) }}" style="margin-top:12px;">
        @csrf
        <div class="field">
            <label>{{ __('Aktuellen Kilometerstand melden') }}</label>
            <div style="display:flex;gap:8px;">
                <input type="number" name="mileage" required min="0" max="5000000" inputmode="numeric"
                    value="{{ old('mileage') }}" placeholder="{{ __('z. B.') }} 52300"
                    style="flex:1;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <button type="submit" class="btn btn-primary" style="white-space:nowrap;">{{ __('Melden') }}</button>
            </div>
            @error('mileage')<div style="color:#A32D2D;font-size:12.5px;margin-top:6px;">{{ $message }}</div>@enderror
            <p style="font-size:12px;color:var(--ink-soft);margin-top:8px;">{{ __('Ihre Meldung wird direkt in Ihrer Vertragsakte gespeichert. Alle früheren Stände bleiben erhalten.') }}</p>
        </div>
    </form>
    @if($v->mileageReadings->count() > 1)
    <details>
        <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--ink-soft);">{{ __('Frühere Meldungen anzeigen') }}</summary>
        @foreach($v->mileageReadings as $reading)
        <div class="item-row"><span style="{{ $rowL }}">{{ $reading->reading_date->format('d.m.Y') }}</span><span style="{{ $rowV }}">{{ number_format($reading->mileage, 0, ',', '.') }} km</span></div>
        @endforeach
    </details>
    @endif
</div>

{{-- SF-Klassen (Information fuer den Kunden) --}}
@if($v->sf_liability_class)
<div class="card">
    <div class="card-title">📊 {{ __('Schadenfreiheitsklasse') }}</div>
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Haftpflicht') }}</span><span style="{{ $rowV }}">{{ \App\Models\ContractVehicleDetail::sfLabel($v->sf_liability_class) }}@if($v->sf_liability_valid_from) ({{ __('ab') }} {{ $v->sf_liability_valid_from->format('d.m.Y') }})@endif</span></div>
    @if($v->has_vollkasko && $v->sf_comprehensive_class)
    <div class="item-row"><span style="{{ $rowL }}">{{ __('Vollkasko') }}</span><span style="{{ $rowV }}">{{ \App\Models\ContractVehicleDetail::sfLabel($v->sf_comprehensive_class) }}@if($v->sf_comprehensive_valid_from) ({{ __('ab') }} {{ $v->sf_comprehensive_valid_from->format('d.m.Y') }})@endif</span></div>
    @endif
</div>
@endif
@endif

{{-- Sparte Strom / Gas --}}
@if($e = $contract->energyDetail)
<div class="card">
    <div class="card-title">{{ $contract->typeIcon() }} {{ __($contract->typeLabel()) }}</div>
    @if($e->tariff)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Tarif') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $e->tariff }}</span></div>@endif
    @if($e->customer_number)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Kundennummer') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $e->customer_number }}</span></div>@endif
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Zählernummer') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $e->meter_number ?? '—' }}</span></div>
    @if($e->malo_id)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">MaLo-ID</span><span style="font-weight:600;font-size:13.5px;">{{ $e->malo_id }}</span></div>@endif
    @if($e->working_price !== null)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Arbeitspreis') }}</span><span style="font-weight:600;font-size:13.5px;">{{ rtrim(rtrim(number_format((float) $e->working_price, 3, ',', '.'), '0'), ',') }} ct/kWh</span></div>@endif
    @if($e->base_price !== null)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Grundpreis') }}</span><span style="font-weight:600;font-size:13.5px;">{{ number_format((float) $e->base_price, 2, ',', '.') }} €/Monat</span></div>@endif
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Abschlag') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $e->payment_amount ? number_format($e->payment_amount, 2, ',', '.') . ' €' : '—' }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Zahlungsintervall') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $intervalLabels[$e->payment_interval] ?? '—' }}</span></div>
    @if($e->consumption_kwh)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Verbrauch') }}</span><span style="font-weight:600;font-size:13.5px;">{{ number_format($e->consumption_kwh, 0, ',', '.') }} kWh/Jahr</span></div>@endif
</div>

{{-- Zaehlerstand melden + Verbrauchshistorie (Betreiber-Vorgabe 29.07.2026).
     Der Kunde meldet seinen Stand als Zahl oder als Foto des Zaehlers; aus
     dem Abstand zweier Staende ergibt sich sein tatsaechlicher Verbrauch. --}}
@php
    $meterRegister = \App\Models\MeterReading::REGISTER_DEFAULT;
    $meterLatest = $e->latestMeterReading();
    $meterStatus = $e->consumptionStatus();
    $meterHistory = $e->consumptionHistory();
    $meterUnit = $e->readingUnit();
    $hasFeedIn = in_array('2.8.0', $e->registersWithReadings(), true);
    // Groesster Verbrauch als Massstab der Balken (nur echte Zeitraeume).
    $meterMax = collect($meterHistory)->pluck('consumption')->filter()->max() ?: 0;
@endphp
<div class="card">
    <div class="card-title">📊 {{ __('Zählerstand & Verbrauch') }}</div>

    @if($meterLatest)
    <div class="item-row">
        <span style="{{ $rowL }}">{{ __('Letzter Stand') }}</span>
        <span style="{{ $rowV }}">{{ $meterLatest->formatted() }} ({{ $meterLatest->reading_date->format('d.m.Y') }})</span>
    </div>
    @endif

    @if($meterStatus)
    <div style="background:#F1EEE5;border-radius:10px;padding:12px 14px;margin:12px 0;">
        <div style="font-size:12px;color:var(--ink-soft);margin-bottom:4px;">
            {{ __('Verbrauch seit :datum', ['datum' => $meterStatus['previous']->reading_date->format('d.m.Y')]) }}
            ({{ trans_choice('{1} 1 Tag|[2,*] :count Tage', $meterStatus['days'], ['count' => $meterStatus['days']]) }})
        </div>
        <div style="font-size:22px;font-weight:700;color:#0E7A41;">
            {{ \App\Models\MeterReading::formatValue($meterStatus['consumption'], $meterUnit) }}
        </div>
        @if($meterStatus['per_day'])
        <div style="font-size:12.5px;color:var(--ink-soft);margin-top:2px;">
            ⌀ {{ \App\Models\MeterReading::formatValue((float) $meterStatus['per_day'], $meterUnit) }} {{ __('pro Tag') }}
            @php $cost = $e->estimatedCost($meterStatus['consumption']); @endphp
            @if($cost !== null)
            · {{ __('rund') }} {{ number_format($cost, 2, ',', '.') }} € {{ __('Energiekosten') }}
            @endif
        </div>
        @endif
        @if($meterStatus['projected'])
        <div style="font-size:12.5px;margin-top:8px;padding-top:8px;border-top:1px solid #E0DCD0;">
            {{ __('Hochgerechnet') }}: <b>{{ number_format($meterStatus['projected'], 0, ',', '.') }} {{ $meterUnit }}/{{ __('Jahr') }}</b>
            @if($meterStatus['expected'])
                @if($meterStatus['exceeded'])
                <span style="color:#A32D2D;font-weight:600;">
                    · {{ __('über Ihrem vereinbarten Verbrauch') }} ({{ number_format($meterStatus['expected'], 0, ',', '.') }} {{ $meterUnit }}, +{{ $meterStatus['deviation_percent'] }} %)
                </span>
                @else
                <span style="color:#0E7A41;font-weight:600;">
                    · {{ __('im Rahmen Ihres vereinbarten Verbrauchs') }} ({{ number_format($meterStatus['expected'], 0, ',', '.') }} {{ $meterUnit }})
                </span>
                @endif
            @endif
        </div>
        @endif
    </div>
    @elseif($meterLatest)
    <p style="font-size:12.5px;color:var(--ink-soft);margin:10px 0;">
        {{ __('Sobald Sie einen zweiten Stand melden, sehen Sie hier Ihren Verbrauch für den Zeitraum dazwischen.') }}
    </p>
    @endif

    {{-- Hochgeladene Fotos, aus denen noch kein Stand entstanden ist --}}
    @foreach($openMeterPhotos ?? [] as $photo)
    <div style="background:#FBF6E8;border:1px solid #E0DCD0;border-radius:10px;padding:10px 12px;margin-top:12px;font-size:12.5px;">
        @if($photo->aiInProgress())
            ⏳ {{ __('Ihr Zählerfoto vom :datum wird gerade ausgewertet.', ['datum' => $photo->created_at->format('d.m.Y H:i')]) }}
        @else
            📷 {{ __('Ihr Zählerfoto vom :datum konnten wir nicht automatisch auslesen – unser Team schaut es sich an. Sie können den Stand auch direkt eintragen.', ['datum' => $photo->created_at->format('d.m.Y H:i')]) }}
        @endif
    </div>
    @endforeach

    {{-- Meldung: Zahl und/oder Foto des Zaehlers --}}
    <form method="POST" action="{{ route('portal.contracts.meter', $contract->id) }}" enctype="multipart/form-data"
          id="meter-form" style="margin-top:12px;">
        @csrf
        <div class="field">
            <label>{{ __('Aktuellen Zählerstand melden') }}</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input type="number" name="reading" step="0.001" min="0" max="99999999" inputmode="decimal"
                    value="{{ old('reading') }}" placeholder="{{ __('z. B.') }} 4680"
                    style="flex:1 1 150px;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <input type="date" name="reading_date" max="{{ now()->format('Y-m-d') }}"
                    value="{{ old('reading_date', now()->format('Y-m-d')) }}"
                    style="flex:0 1 160px;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
            </div>
            @error('reading')<div style="color:#A32D2D;font-size:12.5px;margin-top:6px;">{{ $message }}</div>@enderror
        </div>

        @if($hasFeedIn)
        <div class="field">
            <label>{{ __('Zählwerk') }}</label>
            <select name="register" style="width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                @foreach(\App\Models\MeterReading::REGISTERS as $key => $label)
                <option value="{{ $key }}" {{ old('register', $meterRegister) === $key ? 'selected' : '' }}>{{ $key }} – {{ __($label) }}</option>
                @endforeach
            </select>
        </div>
        @endif

        <div class="field">
            <label>{{ __('Oder Foto des Zählers hochladen') }}</label>
            <input type="file" name="photo" accept="image/*,application/pdf" capture="environment"
                style="width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;background:#fff;">
            @error('photo')<div style="color:#A32D2D;font-size:12.5px;margin-top:6px;">{{ $message }}</div>@enderror
            <p style="font-size:12px;color:var(--ink-soft);margin-top:8px;">
                {{ __('Fotografieren Sie das Display so, dass Zählernummer und Stand gut lesbar sind. Wir lesen den Stand aus und tragen ihn mit dem Datum Ihres Uploads ein.') }}
            </p>
        </div>

        <div id="meter-photo-hint" style="display:none;font-size:12px;color:var(--ink-soft);margin-bottom:10px;"></div>
        <button type="submit" class="btn btn-primary" id="meter-submit">{{ __('Zählerstand melden') }}</button>
    </form>

{{--
    Handy-Fotos sind schnell 10 MB gross und wuerden am Server-Limit mit
    einer rohen "413"-Seite scheitern. Das Foto wird daher - wie im
    Dokumenten-Scanner - VOR dem Absenden im Browser auf max. 2000px
    verkleinert und als JPEG kodiert (fuer die Zaehlerablesung mehr als
    ausreichend, spart dem Kunden ausserdem mobiles Datenvolumen).
    Kann der Browser das Bild nicht dekodieren (z.B. HEIC ohne native
    Unterstuetzung), wird die Originaldatei gesendet - und nur, wenn sie zu
    gross ist, mit einer verstaendlichen Meldung abgefangen.
--}}
<script>
(function() {
    var form = document.getElementById('meter-form');
    if (!form || !window.DataTransfer) return;
    var input = form.querySelector('input[name="photo"]');
    var button = document.getElementById('meter-submit');
    var hint = document.getElementById('meter-photo-hint');
    var MAX_BYTES = 10 * 1024 * 1024;   // Server-Limit der Anwendung
    var ready = false;

    function say(text, isError) {
        hint.textContent = text;
        hint.style.color = isError ? '#A32D2D' : 'var(--ink-soft)';
        hint.style.display = text ? '' : 'none';
    }

    function shrink(file) {
        return new Promise(function(resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function() {
                try {
                    var max = 2000;
                    var scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
                    var canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
                    canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(function(blob) {
                        URL.revokeObjectURL(url);
                        blob ? resolve(blob) : reject(new Error('encode'));
                    }, 'image/jpeg', 0.85);
                } catch (e) { URL.revokeObjectURL(url); reject(e); }
            };
            img.onerror = function() { URL.revokeObjectURL(url); reject(new Error('decode')); };
            img.src = url;
        });
    }

    input.addEventListener('change', function() { ready = false; say(''); });

    form.addEventListener('submit', function(event) {
        var file = input.files && input.files[0];
        if (!file || ready) return;                       // nichts zu tun
        if (file.type === 'application/pdf') {            // PDF bleibt unveraendert
            if (file.size > MAX_BYTES) {
                event.preventDefault();
                say('{{ __('Diese Datei ist zu groß (max. 10 MB). Bitte laden Sie ein Foto statt einer großen Datei hoch.') }}', true);
            }
            return;
        }

        event.preventDefault();
        button.disabled = true;
        say('{{ __('Foto wird vorbereitet …') }}');

        shrink(file).then(function(blob) {
            var name = (file.name || 'zaehler.jpg').replace(/\.[^.]+$/, '') + '.jpg';
            var transfer = new DataTransfer();
            transfer.items.add(new File([blob], name, { type: 'image/jpeg' }));
            input.files = transfer.files;
        }).catch(function() {
            // Browser konnte das Bild nicht verkleinern - Original senden.
        }).then(function() {
            var current = input.files && input.files[0];
            if (current && current.size > MAX_BYTES) {
                button.disabled = false;
                say('{{ __('Das Foto ist zu groß (max. 10 MB). Bitte machen Sie ein neues Foto mit geringerer Auflösung.') }}', true);
                return;
            }
            ready = true;
            say('');
            form.submit();
        });
    });
})();
</script>

    {{-- Verbrauchshistorie: je Ablesung der Verbrauch seit der vorherigen --}}
    @if(count($meterHistory) > 0)
    <details style="margin-top:16px;" {{ count($meterHistory) > 1 ? 'open' : '' }}>
        <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--ink-soft);">{{ __('Verbrauchshistorie') }}</summary>
        <div style="margin-top:10px;">
            @foreach($meterHistory as $row)
            @php $r = $row['reading']; @endphp
            <div style="padding:10px 0;border-bottom:1px solid var(--line);">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;">
                    <span style="{{ $rowL }}">
                        {{ $r->reading_date->format('d.m.Y') }}
                        @if($r->captured_at)<span style="font-size:11.5px;"> · {{ $r->captured_at->format('H:i') }} {{ __('Uhr') }}</span>@endif
                        @if($r->isFeedIn())<span style="font-size:11.5px;"> · {{ __('Einspeisung') }}</span>@endif
                    </span>
                    <span style="{{ $rowV }}">{{ $r->formatted() }}</span>
                </div>
                @if($row['implausible'])
                <div style="font-size:12px;color:#A32D2D;margin-top:4px;">{{ __('Niedriger als der vorherige Stand – wir prüfen das für Sie.') }}</div>
                @elseif($row['consumption'] !== null)
                <div style="display:flex;align-items:center;gap:8px;margin-top:6px;">
                    <div style="flex:1;height:8px;background:#E0DCD0;border-radius:999px;overflow:hidden;">
                        <div style="height:100%;width:{{ $meterMax > 0 ? max(3, round($row['consumption'] / $meterMax * 100)) : 0 }}%;background:linear-gradient(90deg,#19b463,#128a4b);"></div>
                    </div>
                    <span style="font-size:12px;color:var(--ink-soft);white-space:nowrap;">
                        +{{ \App\Models\MeterReading::formatValue((float) $row['consumption'], $meterUnit) }}
                        @if($row['days'])({{ $row['days'] }} {{ __('Tage') }})@endif
                    </span>
                </div>
                @else
                <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">{{ __('Erster erfasster Stand') }}</div>
                @endif
            </div>
            @endforeach
        </div>
    </details>
    @endif
</div>
@endif

{{-- Sparte Internet --}}
@if($i = $contract->internetDetail)
<div class="card">
    <div class="card-title">{{ __('📶 Internetvertrag') }}</div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Anbieter') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $contract->insurer }}</span></div>
    @if($i->tariff)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Tarif') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $i->tariff }}</span></div>@endif
    @if($i->speed)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Geschwindigkeit') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $i->speed }}{{ $i->upload_speed ? ' / ' . $i->upload_speed . ' Upload' : '' }}</span></div>@endif
    @if($i->price_initial !== null)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Aktionspreis') }}</span><span style="font-weight:600;font-size:13.5px;">{{ number_format((float) $i->price_initial, 2, ',', '.') }} €/Monat{{ $i->price_initial_months ? ' (erste ' . $i->price_initial_months . ' Monate)' : '' }}</span></div>@endif
    @if($i->price_regular !== null)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Preis danach') }}</span><span style="font-weight:600;font-size:13.5px;">{{ number_format((float) $i->price_regular, 2, ',', '.') }} €/Monat</span></div>@endif
    @if($i->has_router)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Router</span><span style="font-weight:600;font-size:13.5px;">{{ $i->router_name ?: 'inklusive' }}{{ $i->router_price !== null ? ((float) $i->router_price > 0 ? ' · ' . number_format((float) $i->router_price, 2, ',', '.') . ' €/Monat' : ' · inklusive') : '' }}</span></div>@endif
    @if($i->bonus_amount !== null)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Bonus / Cashback') }}</span><span style="font-weight:600;font-size:13.5px;">{{ number_format((float) $i->bonus_amount, 2, ',', '.') }} €</span></div>@endif
    @if($i->voucher_amount !== null)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Gutschein') }}</span><span style="font-weight:600;font-size:13.5px;">{{ number_format((float) $i->voucher_amount, 2, ',', '.') }} €</span></div>@endif
</div>
@endif

@if($contract->pdf_path)
<a href="{{ route('portal.documents') }}" class="btn btn-ghost">{{ __('📎 Zugehörige Dokumente') }}</a>
@endif

{{-- Aenderung beantragen (Self-Service, Vier-Augen-Prinzip) --}}
@if(!empty($pendingChange))
<div class="notice" style="margin-top:16px;">{{ __('⏳ Für diesen Vertrag liegt bereits eine Änderungsanfrage in Prüfung (eingereicht am :date). Sie können nach der Bearbeitung eine weitere Änderung einreichen.', ['date' => $pendingChange->created_at->format('d.m.Y H:i')]) }}</div>
@endif

<details class="card" style="margin-top:16px;" {{ ($errors->any() || !empty($pendingChange)) ? 'open' : '' }}>
    <summary style="cursor:pointer;font-weight:600;font-size:14px;list-style:none;display:flex;align-items:center;gap:8px;">
        {{ __('✏️ Änderung an diesem Vertrag beantragen') }}
    </summary>
    <p style="font-size:12.5px;color:var(--ink-soft);margin:10px 0 14px;">{{ __('Passen Sie die Vertragsdaten an oder beschreiben Sie im Feld „Anmerkung" gewünschte Änderungen bzw. Ergänzungen. Ihre Anfrage wird erst nach Freigabe durch unser Team wirksam.') }}</p>
    <form method="POST" action="{{ route('portal.contracts.change', $contract->id) }}">
        @csrf
        <div class="field">
            <label>{{ __('Vertragstyp *') }}</label>
            <select name="type" required style="width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                @foreach(\App\Models\Contract::TYPES as $key => $cfg)
                <option value="{{ $key }}" {{ old('type', $contract->type) === $key ? 'selected' : '' }}>{{ $cfg['icon'] }} {{ __($cfg['label']) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label>{{ __('Gesellschaft / Anbieter *') }}</label>
            <input type="text" name="insurer" required maxlength="255" value="{{ old('insurer', $contract->insurer) }}">
        </div>
        <div class="field">
            <label>{{ __('Vertragsnummer') }}</label>
            <input type="text" name="contract_number" maxlength="100" value="{{ old('contract_number', $contract->contract_number) }}">
        </div>
        <div class="field">
            <label>{{ __('Startdatum') }}</label>
            <input type="date" name="start_date" value="{{ old('start_date', optional($contract->start_date ? \Carbon\Carbon::parse($contract->start_date) : null)->format('Y-m-d')) }}">
        </div>
        <div class="field">
            <label>{{ __('Enddatum') }}</label>
            <input type="date" name="end_date" value="{{ old('end_date', optional($contract->end_date ? \Carbon\Carbon::parse($contract->end_date) : null)->format('Y-m-d')) }}">
        </div>
        <div class="field">
            <label>{{ __('Kündigungsdatum') }}</label>
            <input type="date" name="cancellation_date" value="{{ old('cancellation_date', optional($contract->cancellation_date ? \Carbon\Carbon::parse($contract->cancellation_date) : null)->format('Y-m-d')) }}">
        </div>
        <div class="field">
            <label>{{ __('Anmerkung / gewünschte Änderung') }}</label>
            <textarea name="notes" maxlength="1000" placeholder="{{ __('z. B. Tarifwechsel gewünscht, neue Vertragsunterlagen, Fragen zum Vertrag …') }}" style="width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;min-height:70px;font-family:inherit;resize:vertical;">{{ old('notes') }}</textarea>
        </div>
        @if($errors->any())<div class="alert-error">{{ __('Bitte prüfen Sie Ihre Eingaben.') }}</div>@endif
        <button type="submit" class="btn btn-primary">{{ __('Änderung einreichen') }}</button>
        <p style="font-size:12px;color:var(--ink-soft);margin-top:10px;">{{ __('🔒 Die Änderung wird erst nach Freigabe durch unser Team übernommen.') }}</p>
    </form>
</details>
@endsection
