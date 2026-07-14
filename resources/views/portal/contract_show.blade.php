@extends('layouts.portal')
@section('content')
@php
$typeIcons = ['kfz'=>'🚗','strom'=>'⚡','gas'=>'🔥','strom_gas'=>'⚡','internet'=>'📶','haftpflicht'=>'🛡️','hausrat'=>'🏠','rechtsschutz'=>'⚖️','krankenversicherung'=>'🏥','leben'=>'❤️','unfall'=>'🚑','andere'=>'📋'];
$typeLabels = ['kfz'=>'KFZ','strom'=>'Strom','gas'=>'Gas','strom_gas'=>'Strom/Gas','internet'=>'Internet','haftpflicht'=>'Haftpflicht','hausrat'=>'Hausrat','rechtsschutz'=>'Rechtsschutz','krankenversicherung'=>'Krankenversicherung','leben'=>'Leben','unfall'=>'Unfall','andere'=>'Andere'];
$intervalLabels = ['monatlich'=>'Monatlich','vierteljaehrlich'=>'Vierteljährlich','halbjaehrlich'=>'Halbjährlich','jaehrlich'=>'Jährlich'];
$d = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d.m.Y') : '—';
@endphp

<a href="{{ route('portal.contracts') }}" class="btn btn-ghost" style="margin-bottom:16px;">← Alle Verträge</a>

<div class="card">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px;">
        <span style="font-size:40px;line-height:1;">{{ $contract->typeIcon() }}</span>
        <div>
            <div class="page-title" style="margin-bottom:2px;">{{ $contract->insurer }}</div>
            <div class="page-sub" style="margin-bottom:0;">{{ $contract->typeLabel() }}</div>
        </div>
        <span class="badge badge-{{ $contract->status === 'active' ? 'active' : 'pending' }}" style="margin-left:auto;">{{ $contract->status === 'active' ? 'Aktiv' : ucfirst($contract->status) }}</span>
    </div>
</div>

{{-- Allgemeine Vertragsdaten --}}
<div class="card">
    <div class="card-title">Vertragsdaten</div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Vertragsnummer</span><span style="font-weight:600;font-size:13.5px;">{{ $contract->contract_number ?? '—' }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Vertragstyp</span><span style="font-weight:600;font-size:13.5px;">{{ $contract->typeLabel() }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Startdatum</span><span style="font-weight:600;font-size:13.5px;">{{ $d($contract->start_date) }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Enddatum</span><span style="font-weight:600;font-size:13.5px;">{{ $d($contract->end_date) }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Kündigungsdatum</span><span style="font-weight:600;font-size:13.5px;">{{ $d($contract->cancellation_date) }}</span></div>
</div>

{{-- Sparte KFZ --}}
@if($v = $contract->vehicleDetail)
<div class="card">
    <div class="card-title">🚗 Fahrzeugdaten</div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Kennzeichen</span><span style="font-weight:600;font-size:13.5px;">{{ $v->license_plate ?? '—' }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Fahrzeug</span><span style="font-weight:600;font-size:13.5px;">{{ trim(($v->manufacturer ?? '') . ' ' . ($v->model ?? '')) ?: '—' }}</span></div>
    @if($v->vin)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">FIN</span><span style="font-weight:600;font-size:13.5px;">{{ $v->vin }}</span></div>@endif
    @if($v->first_registration)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Erstzulassung</span><span style="font-weight:600;font-size:13.5px;">{{ $d($v->first_registration) }}</span></div>@endif
</div>
@endif

{{-- Sparte Strom / Gas --}}
@if($e = $contract->energyDetail)
<div class="card">
    <div class="card-title">{{ $contract->typeIcon() }} {{ $contract->typeLabel() }}vertrag</div>
    @if($e->tariff)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Tarif</span><span style="font-weight:600;font-size:13.5px;">{{ $e->tariff }}</span></div>@endif
    @if($e->customer_number)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Kundennummer</span><span style="font-weight:600;font-size:13.5px;">{{ $e->customer_number }}</span></div>@endif
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Zählernummer</span><span style="font-weight:600;font-size:13.5px;">{{ $e->meter_number ?? '—' }}</span></div>
    @if($e->malo_id)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">MaLo-ID</span><span style="font-weight:600;font-size:13.5px;">{{ $e->malo_id }}</span></div>@endif
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Abschlag</span><span style="font-weight:600;font-size:13.5px;">{{ $e->payment_amount ? number_format($e->payment_amount, 2, ',', '.') . ' €' : '—' }}</span></div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Zahlungsintervall</span><span style="font-weight:600;font-size:13.5px;">{{ $intervalLabels[$e->payment_interval] ?? '—' }}</span></div>
    @if($e->consumption_kwh)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Verbrauch</span><span style="font-weight:600;font-size:13.5px;">{{ number_format($e->consumption_kwh, 0, ',', '.') }} kWh/Jahr</span></div>@endif
</div>
@endif

{{-- Sparte Internet --}}
@if($i = $contract->internetDetail)
<div class="card">
    <div class="card-title">📶 Internetvertrag</div>
    <div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Anbieter</span><span style="font-weight:600;font-size:13.5px;">{{ $contract->insurer }}</span></div>
    @if($i->tariff)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Tarif</span><span style="font-weight:600;font-size:13.5px;">{{ $i->tariff }}</span></div>@endif
    @if($i->speed)<div class="item-row"><span style="color:var(--ink-soft);font-size:13px;">Geschwindigkeit</span><span style="font-weight:600;font-size:13.5px;">{{ $i->speed }}</span></div>@endif
</div>
@endif

@if($contract->pdf_path)
<a href="{{ route('portal.documents') }}" class="btn btn-ghost">📎 Zugehörige Dokumente</a>
@endif
@endsection
