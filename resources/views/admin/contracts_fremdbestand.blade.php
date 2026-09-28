@extends('layouts.admin')
@section('content')
{{--
    Fremdbestand - Uebernahmepotenzial (28.09.2026). Laufende Vertraege, die
    NICHT ueber uns vermittelt wurden: jeder ist ein Anlass fuer eine
    Bestandsuebertragung (Maklervollmacht) oder ein Gegenangebot. Nach Ablauf
    sortiert - wer zuerst wechseln kann, steht oben.
--}}
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.contracts') }}">Verträge</a><span class="breadcrumb-sep">›</span><span>Fremdbestand</span></div>
    <h1 class="page-title">📈 Fremdbestand – Übernahmepotenzial</h1>
    <div class="page-sub">Laufende Verträge unserer Kunden, die nicht über uns vermittelt wurden. Kein Mandat, keine Courtage – aber ein Anlass für ein Gespräch.</div>
</div>

<div class="card card-flush">
    <table>
        <thead>
            <tr style="background:#F8F9FA;">
                <th style="padding:12px 20px;">Kunde</th>
                <th>Vertrag</th>
                <th>Bisher betreut durch</th>
                <th>Beitrag</th>
                <th>Ablauf</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse($contracts as $c)
        <tr>
            <td style="padding:14px 20px;">
                <a href="{{ route('admin.customer', $c->customer_id) }}" style="font-weight:600;">{{ $c->customer?->user?->name ?? '—' }}</a>
                <div class="muted-2xs">{{ $c->customer?->customer_number }}</div>
            </td>
            <td>
                <div style="font-weight:600;">{{ $c->typeIcon() }} {{ $c->typeLabel() }}</div>
                <div class="muted-xs">{{ $c->insurer }}@if($c->contract_number) · {{ $c->contract_number }}@endif</div>
                @if($c->successor)<div class="muted-2xs">↪ bereits ersetzt durch {{ $c->successor->insurer }}</div>@endif
            </td>
            <td class="muted-sm">{{ $c->responsibleParty() }}</td>
            <td class="muted-sm nowrap">@if($c->hasPremium()){{ number_format($c->monthlyPremium(), 2, ',', '.') }} €/Monat @else — @endif</td>
            <td class="muted-sm nowrap">
                @php $st = $c->displayStatus(); @endphp
                @if($c->end_date){{ \Carbon\Carbon::parse($c->end_date)->format('d.m.Y') }}@else — @endif
                <div><span class="badge badge-{{ $st['badge'] }} nowrap">{{ $st['label'] }}</span></div>
            </td>
            <td style="padding-right:20px;white-space:nowrap;">
                <a href="{{ route('admin.contract.edit', $c->id) }}" class="btn btn-ghost btn-sm">Öffnen</a>
                @if(\Illuminate\Support\Facades\Route::has('admin.signatures.create'))
                <a href="{{ route('admin.signatures.create', ['vertrag' => $c->id]) }}" class="btn btn-ghost btn-sm" title="Maklervollmacht zur Unterschrift senden">🔄 Übernahme anbieten</a>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--ink-soft);">Kein laufender Fremdvertrag erfasst.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($contracts->hasPages())
<div style="display:flex;justify-content:space-between;gap:12px;margin:16px 2px;flex-wrap:wrap;" class="muted-sm">
    <span>{{ $contracts->firstItem() }}–{{ $contracts->lastItem() }} von {{ $contracts->total() }}</span>
    <span>
        @if(! $contracts->onFirstPage())<a href="{{ $contracts->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>@endif
        @if($contracts->hasMorePages())<a href="{{ $contracts->nextPageUrl() }}" class="btn btn-ghost btn-sm">Weiter →</a>@endif
    </span>
</div>
@endif
@endsection
