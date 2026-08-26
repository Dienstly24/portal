@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="page-title">🧾 Rechnungsabgleich</div>
    <div class="page-sub">
        Kennung aus einer Rechnung eingeben – das System zeigt Vertrag, Kunde und die erwarteten Provisionen.
    </div>
</div>

@include('admin.commissions_internal._tabs', ['active' => 'rechnung'])
@include('admin.commissions_internal._flash')

<div class="card" style="max-width:1000px;">
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">
        Gesucht wird über <b>Interne Vertragsnummer</b>, <b>Referenz-Nr.</b>, <b>Vermittler-Id</b>,
        <b>Auftr.-Nr.</b> und die Vertragsnummer der Gesellschaft – in dieser Reihenfolge.
        Eine Rechnung, die eine dieser Nummern nennt, findet damit ihren Vertrag von selbst.
    </div>
    <form method="GET" action="{{ route('admin.commissions_internal.invoice') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <div class="field" style="margin:0;min-width:280px;">
            <label>Kennung aus der Rechnung</label>
            <input type="text" name="kennung" value="{{ $identifier }}" placeholder="z. B. V19613073 oder 1477-6741-9200-53">
        </div>
        <button type="submit" class="btn btn-primary">Suchen</button>
    </form>
</div>

@if($result !== null)
<div class="card" style="max-width:1000px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:12px;">Treffer zu „{{ $identifier }}“</div>

    @if($result['contract'])
    <div style="font-size:13px;margin-bottom:14px;">
        <a href="{{ route('admin.contract.edit', $result['contract']->id) }}">
            {{ $result['contract']->typeIcon() }} {{ $result['contract']->typeLabel() }}
            @if($result['contract']->contract_number) · {{ $result['contract']->contract_number }}@endif
        </a>
        <div style="color:var(--ink-soft);margin-top:4px;">
            Kunde: {{ $result['contract']->customer?->user?->name ?? '—' }}
            @if($result['contract']->internal_contract_number) · Interne Vertragsnummer {{ $result['contract']->internal_contract_number }}@endif
        </div>
    </div>
    @else
    <div style="background:#FEF3C7;border:1px solid #E8C36A;border-radius:8px;padding:10px 12px;font-size:12.5px;margin-bottom:14px;">
        ⚠ <b>Kein Vertrag gefunden.</b> {{ $result['note'] }}
        Es wurde bewusst nichts geraten und <b>kein Vertrag angelegt</b>.
    </div>
    @endif

    @if($result['commissions']->isEmpty())
        <div style="font-size:13px;color:var(--ink-soft);">Zu dieser Kennung liegt keine Provision vor.</div>
    @else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Provisionsart</th><th style="padding:8px;text-align:right;">Erwartet</th>
                <th style="padding:8px;">Provisionsdatum</th><th style="padding:8px;">Fällig</th>
                <th style="padding:8px;">Status</th><th style="padding:8px;">Rechnung</th><th style="padding:8px;"></th>
            </tr></thead>
            <tbody>
            @foreach($result['commissions'] as $c)
            <tr style="border-top:1px solid var(--line);">
                <td style="padding:8px;">{{ $c->commission_type ?: '—' }}<div style="color:var(--ink-soft);font-size:11.5px;">{{ $c->product_name }}</div></td>
                <td style="padding:8px;text-align:right;font-weight:600;white-space:nowrap;">{{ $c->amountLabel() }}</td>
                <td style="padding:8px;white-space:nowrap;">{{ $c->commission_date?->format('d.m.Y') ?: '—' }}</td>
                <td style="padding:8px;white-space:nowrap;">{{ $c->due_date?->format('d.m.Y') ?: '—' }}</td>
                <td style="padding:8px;"><span class="badge badge-{{ $c->statusBadge() }}">{{ $c->statusLabel() }}</span></td>
                <td style="padding:8px;">{{ $c->invoice_number ?: '—' }}</td>
                <td style="padding:8px;"><a href="{{ route('admin.commissions_internal.show', $c->id) }}">öffnen →</a></td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div style="font-size:11.5px;color:var(--ink-soft);margin-top:12px;">
        Die Bestätigung von Rechnung und Zahlung erfolgt bewusst je Provision – nicht als Sammelaktion über eine Trefferliste.
    </div>
    @endif
</div>
@endif
@endsection
