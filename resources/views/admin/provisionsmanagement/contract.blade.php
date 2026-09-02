@extends('layouts.admin')
@section('content')
@include('admin.provisionsmanagement._layout', ['active' => 'vertraege', 'titel' => 'Vertrag',
    'untertitel' => 'Provisionserwartung, Buchungen und Bearbeitungsstand dieses Vertrags.'])

@php
    $geld = fn ($w) => number_format((float) $w, 2, ',', '.') . ' €';
    $netto = $contract->commissions->sum(fn ($c) => (float) $c->amount);
@endphp

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;max-width:1250px;">
    <div class="card">
        <h3 style="margin-top:0;">{{ $contract->contract_number ?: '—' }}</h3>
        <table class="table" style="font-size:13px;">
            <tr><td>Kunde</td><td><a href="{{ route('admin.customer', $contract->customer_id) }}">{{ $contract->customer?->user?->name ?? '—' }}</a></td></tr>
            <tr><td>Gesellschaft / Produkt</td><td>{{ $contract->insurer }}</td></tr>
            <tr><td>Pool</td><td>{{ $pool?->name ?? '— kein Pool —' }}</td></tr>
            <tr><td>Referenz-Nr.</td><td>{{ $contract->reference_number ?: '—' }}</td></tr>
            <tr><td>Pool-Id (Vermittler-Id)</td><td>{{ $contract->vermittler_id ?: '—' }}</td></tr>
            <tr><td>Interne Vertragsnr.</td><td>{{ $contract->internal_contract_number ?: '—' }}</td></tr>
            <tr><td>Abschluss</td><td>{{ $abschluss?->format('d.m.Y') ?? '—' }} @if($monate !== null)<span style="color:var(--ink-soft);">({{ $monate }} Monate her)</span>@endif</td></tr>
            <tr><td>Provisionserwartung</td><td>{{ $contract->expected_commission_date?->format('d.m.Y') ?? '—' }}</td></tr>
            <tr><td>Prüffrist</td><td>{{ $contract->commission_check_date?->format('d.m.Y') ?? '—' }}</td></tr>
            <tr><td>Zustand</td><td><span class="badge badge-{{ $contract->commissionStatusBadge() }}">{{ $contract->commissionStatusLabel() }}</span></td></tr>
        </table>
        <form method="POST" action="{{ route('admin.provisionsmanagement.recalculate') }}">@csrf
            <input type="hidden" name="contract_id" value="{{ $contract->id }}">
            <button class="btn" type="submit">Zustand neu berechnen</button>
        </form>
    </div>

    <div class="card">
        <h3 style="margin-top:0;">Bearbeitungsstand</h3>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-top:0;">
            Was haben wir unternommen? „Geklärt“ nimmt den Vertrag dauerhaft aus der Mahnliste –
            bis eine Provision eingeht, dann rechnet das System ihn von selbst wieder normal.
        </p>
        <form method="POST" action="{{ route('admin.provisionsmanagement.followup', $contract->id) }}">
            @csrf
            @php $f = $contract->commissionFollowup; @endphp
            <div class="field"><label>Stand</label>
                <select name="status">
                    @foreach(\App\Models\CommissionFollowup::STATUSES as $k => $v)
                    <option value="{{ $k }}" @selected(($f->status ?? 'offen') === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Kontakt am</label>
                <input type="date" name="contacted_on" value="{{ $f?->contacted_on?->format('Y-m-d') }}"></div>
            <div class="field"><label>Ansprechpartner beim Pool</label>
                <input type="text" name="contact_person" value="{{ $f->contact_person ?? ($pool->contact ?? '') }}"></div>
            <div class="field"><label>Antwort des Pools</label>
                <textarea name="response" rows="2">{{ $f->response ?? '' }}</textarea></div>
            <div class="field"><label>Interne Bemerkung</label>
                <textarea name="note" rows="2">{{ $f->note ?? '' }}</textarea></div>
            <button class="btn btn-primary" type="submit">Speichern</button>
            @if($f?->updated_at)
            <div style="font-size:11.5px;color:var(--ink-soft);margin-top:8px;">
                Zuletzt {{ $f->updated_at->lokal()->format('d.m.Y H:i') }} · {{ $f->editor?->name ?? 'System' }}
            </div>
            @endif
        </form>
    </div>
</div>

<div class="card" style="max-width:1250px;margin-top:16px;">
    <h3 style="margin-top:0;">Provisionshistorie <span style="font-weight:400;color:var(--ink-soft);">· Gesamt {{ $geld($netto) }}</span></h3>
    <table class="table" style="font-size:13px;">
        <tr><th>Datum</th><th>Art</th><th>Bezeichnung der Quelle</th><th>Pool / Datei</th>
            <th style="text-align:right;">Betrag</th><th>Status</th><th></th></tr>
        @forelse($contract->commissions as $buchung)
        <tr>
            <td>{{ $buchung->commission_date?->format('d.m.Y') ?? $buchung->booking_date?->format('d.m.Y') ?? '—' }}</td>
            <td>{{ $buchung->kindLabel() }}</td>
            <td>{{ $buchung->commission_type ?: '—' }}</td>
            <td>{{ $buchung->poolLabel() }}<div style="font-size:11px;color:var(--ink-soft);">{{ $buchung->source_file }}@if($buchung->source_row), Zeile {{ $buchung->source_row }}@endif</div></td>
            <td style="text-align:right;font-weight:700;color:{{ (float) $buchung->amount < 0 ? '#A32D2D' : 'inherit' }};">{{ $buchung->amountLabel() }}</td>
            <td><span class="badge badge-{{ $buchung->statusBadge() }}">{{ $buchung->statusLabel() }}</span></td>
            <td><a href="{{ route('admin.commissions_internal.show', $buchung->id) }}">öffnen →</a></td>
        </tr>
        @empty
        <tr><td colspan="7" style="color:var(--ink-soft);">Noch keine Provision zu diesem Vertrag.</td></tr>
        @endforelse
    </table>
</div>

@if($links->isNotEmpty())
<div class="card" style="max-width:1250px;margin-top:16px;">
    <h3 style="margin-top:0;">Gespeicherte Kennungs-Paare</h3>
    <p style="font-size:12.5px;color:var(--ink-soft);margin-top:0;">
        Damit findet auch eine Abrechnung den Vertrag, die nur noch die Id des Pools führt.
    </p>
    <table class="table" style="font-size:13px;">
        <tr><th>Pool</th><th>Referenz-Nr.</th><th>Pool-Id</th><th>Herkunft</th><th>Seit</th></tr>
        @foreach($links as $link)
        <tr>
            <td>{{ $poolListe[$link->pool]->name ?? $link->pool }}</td>
            <td>{{ $link->reference_number }}</td>
            <td>{{ $link->external_id }}</td>
            <td>{{ $link->source === 'import' ? 'Aus einer Datei' : 'Von Hand' }}</td>
            <td>{{ $link->created_at?->lokal()->format('d.m.Y') }}</td>
        </tr>
        @endforeach
    </table>
</div>
@endif
@endsection
