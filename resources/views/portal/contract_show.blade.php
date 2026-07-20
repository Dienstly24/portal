@extends('layouts.portal')
@section('content')
@php
$typeIcons = ['kfz'=>'🚗','strom'=>'⚡','gas'=>'🔥','strom_gas'=>'⚡','internet'=>'📶','haftpflicht'=>'🛡️','hausrat'=>'🏠','rechtsschutz'=>'⚖️','krankenversicherung'=>'🏥','leben'=>'❤️','unfall'=>'🚑','andere'=>'📋'];
$typeLabels = ['kfz'=>'KFZ','strom'=>'Strom','gas'=>'Gas','strom_gas'=>'Strom/Gas','internet'=>'Internet','haftpflicht'=>'Haftpflicht','hausrat'=>'Hausrat','rechtsschutz'=>'Rechtsschutz','krankenversicherung'=>'Krankenversicherung','leben'=>'Leben','unfall'=>'Unfall','andere'=>'Andere'];
$intervalLabels = ['monatlich'=>__('Monatlich'),'vierteljaehrlich'=>__('Vierteljährlich'),'halbjaehrlich'=>__('Halbjährlich'),'jaehrlich'=>__('Jährlich')];
$d = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d.m.Y') : '—';
@endphp

<a href="{{ route('portal.contracts') }}" class="btn btn-ghost" style="margin-bottom:16px;">← {{ __('Alle Verträge') }}</a>

<div class="card">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px;">
        <span style="font-size:40px;line-height:1;">{{ $contract->typeIcon() }}</span>
        <div>
            <div class="page-title" style="margin-bottom:2px;">{{ $contract->insurer }}</div>
            <div class="page-sub" style="margin-bottom:0;">{{ __($contract->typeLabel()) }}</div>
        </div>
        <span class="badge badge-{{ $contract->status === 'active' ? 'active' : 'pending' }}" style="margin-left:auto;">{{ $contract->status === 'active' ? __('Aktiv') : ucfirst($contract->status) }}</span>
    </div>
</div>

{{-- Allgemeine Vertragsdaten --}}
<div class="card">
    <div class="card-title">{{ __('Vertragsdaten') }}</div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Vertragsnummer') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $contract->contract_number ?? '—' }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Vertragstyp') }}</span><span style="font-weight:600;font-size:13.5px;">{{ __($contract->typeLabel()) }}</span></div>
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
    @if($v->vehicleTypeLabel())<div class="item-row"><span style="{{ $rowL }}">{{ __('Fahrzeugtyp') }}</span><span style="{{ $rowV }}">{{ __($v->vehicleTypeLabel()) }}</span></div>@endif
    @if($v->vin)<div class="item-row"><span style="{{ $rowL }}">FIN</span><span style="{{ $rowV }}">{{ $v->vin }}</span></div>@endif
    @if($v->hsn || $v->tsn)<div class="item-row"><span style="{{ $rowL }}">HSN / TSN</span><span style="{{ $rowV }}">{{ $v->hsn ?? '—' }} / {{ $v->tsn ?? '—' }}</span></div>@endif
    @if($v->first_registration)<div class="item-row"><span style="{{ $rowL }}">{{ __('Erstzulassung') }}</span><span style="{{ $rowV }}">{{ $d($v->first_registration) }}</span></div>@endif
    @if($v->power_kw)<div class="item-row"><span style="{{ $rowL }}">{{ __('Leistung') }}</span><span style="{{ $rowV }}">{{ $v->power_kw }} kW</span></div>@endif
    @if($v->fuelLabel())<div class="item-row"><span style="{{ $rowL }}">{{ __('Kraftstoff') }}</span><span style="{{ $rowV }}">{{ __($v->fuelLabel()) }}</span></div>@endif
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
            <span style="display:inline-flex;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:600;background:#E7F6EE;color:#0E7A41;">✓ {{ __($label) }}</span>
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
    <div class="card-title">{{ $contract->typeIcon() }} {{ __($contract->typeLabel()) }}{{ __('vertrag') }}</div>
    @if($e->tariff)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Tarif') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $e->tariff }}</span></div>@endif
    @if($e->customer_number)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Kundennummer') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $e->customer_number }}</span></div>@endif
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Zählernummer') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $e->meter_number ?? '—' }}</span></div>
    @if($e->malo_id)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">MaLo-ID</span><span style="font-weight:600;font-size:13.5px;">{{ $e->malo_id }}</span></div>@endif
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Abschlag') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $e->payment_amount ? number_format($e->payment_amount, 2, ',', '.') . ' €' : '—' }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Zahlungsintervall') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $intervalLabels[$e->payment_interval] ?? '—' }}</span></div>
    @if($e->consumption_kwh)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Verbrauch') }}</span><span style="font-weight:600;font-size:13.5px;">{{ number_format($e->consumption_kwh, 0, ',', '.') }} kWh/Jahr</span></div>@endif
</div>
@endif

{{-- Sparte Internet --}}
@if($i = $contract->internetDetail)
<div class="card">
    <div class="card-title">📶 {{ __('Internetvertrag') }}</div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Anbieter') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $contract->insurer }}</span></div>
    @if($i->tariff)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Tarif') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $i->tariff }}</span></div>@endif
    @if($i->speed)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">{{ __('Geschwindigkeit') }}</span><span style="font-weight:600;font-size:13.5px;">{{ $i->speed }}</span></div>@endif
</div>
@endif

@if($contract->pdf_path)
<a href="{{ route('portal.documents') }}" class="btn btn-ghost">📎 {{ __('Zugehörige Dokumente') }}</a>
@endif

{{-- Aenderung beantragen (Self-Service, Vier-Augen-Prinzip) --}}
@if(!empty($pendingChange))
<div class="notice" style="margin-top:16px;">⏳ {{ __('Für diesen Vertrag liegt bereits eine Änderungsanfrage in Prüfung (eingereicht am :date). Sie können nach der Bearbeitung eine weitere Änderung einreichen.', ['date' => $pendingChange->created_at->format('d.m.Y H:i')]) }}</div>
@endif

<details class="card" style="margin-top:16px;" {{ ($errors->any() || !empty($pendingChange)) ? 'open' : '' }}>
    <summary style="cursor:pointer;font-weight:600;font-size:14px;list-style:none;display:flex;align-items:center;gap:8px;">
        ✏️ {{ __('Änderung an diesem Vertrag beantragen') }}
    </summary>
    <p style="font-size:12.5px;color:var(--ink-soft);margin:10px 0 14px;">
        {{ __('Passen Sie die Vertragsdaten an oder beschreiben Sie im Feld „Anmerkung" gewünschte Änderungen bzw. Ergänzungen.') }}
        {{ __('Ihre Anfrage wird erst nach Freigabe durch unser Team wirksam.') }}
    </p>
    <form method="POST" action="{{ route('portal.contracts.change', $contract->id) }}">
        @csrf
        <div class="field">
            <label>{{ __('Vertragstyp') }} *</label>
            <select name="type" required style="width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                @foreach(\App\Models\Contract::TYPES as $key => $cfg)
                <option value="{{ $key }}" {{ old('type', $contract->type) === $key ? 'selected' : '' }}>{{ $cfg['icon'] }} {{ $cfg['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label>{{ __('Gesellschaft / Anbieter') }} *</label>
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
        <p style="font-size:12px;color:var(--ink-soft);margin-top:10px;">🔒 {{ __('Die Änderung wird erst nach Freigabe durch unser Team übernommen.') }}</p>
    </form>
</details>
@endsection
