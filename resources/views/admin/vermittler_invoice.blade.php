@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
@php
    $positions = $invoice->result['positions'] ?? [];
    $rest = $invoice->result['rest'] ?? null;
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.').' €';
@endphp
<div class="page-header">
    <h1 class="page-title">🧾 Rechnung prüfen</h1>
    <div class="page-sub">{{ $invoice->filename }}</div>
</div>

@include('admin.partials.vermittler_tabs', ['active' => 'import'])

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald-deep);padding:12px 16px;border-radius:10px;margin-bottom:16px;max-width:1000px;font-size:13px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:12px 16px;margin-bottom:16px;max-width:1000px;font-size:13px;color:#A32D2D;">{{ session('error') }}</div>@endif

<div class="card" style="max-width:1000px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;font-size:13px;">
        <div><div class="muted-2xs">Rechnungs-Nr.</div><div style="font-weight:600;">{{ $invoice->invoice_number ?: 'nicht erkannt' }}</div></div>
        <div><div class="muted-2xs">Datum</div><div style="font-weight:600;">{{ $invoice->invoice_date?->format('d.m.Y') ?: 'nicht erkannt' }}</div></div>
        <div><div class="muted-2xs">Gesamtbetrag laut Rechnung</div><div style="font-weight:600;">{{ $money($invoice->total_amount) }}</div></div>
        <div><div class="muted-2xs">Zustand</div>
            <div style="font-weight:600;">
                @if($invoice->isDraft())
                    <span class="badge badge-pending">Entwurf – noch nichts übernommen</span>
                @else
                    <span class="badge badge-active">Übernommen</span>
                    <div class="muted-xs" style="margin-top:4px;">{{ $invoice->confirmed_at?->lokal()->format('d.m.Y H:i') }} · {{ $invoice->confirmer?->name ?? 'System' }}</div>
                @endif
            </div>
        </div>
    </div>
    @if($invoice->file_path)
    <div style="margin-top:12px;font-size:12.5px;"><a href="{{ route('admin.vermittler.invoice_file', $invoice->id) }}">📄 Original-Rechnung öffnen</a></div>
    @endif
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:20px;max-width:1000px;">
    @foreach([
        ['Gefunden', $invoice->rows_found, 'var(--graphite)'],
        ['Betrag stimmt', $invoice->rows_confirmed, 'var(--emerald-deep)'],
        ['Abweichung', $invoice->rows_deviation, '#A32D2D'],
        ['Nicht eindeutig', $invoice->rows_open, '#B5651D'],
    ] as [$label, $value, $color])
    <div class="card" style="padding:14px 16px;margin:0;">
        <div class="muted-2xs">{{ $label }}</div>
        <div style="font-size:22px;font-weight:700;color:{{ $color }};">{{ $value }}</div>
    </div>
    @endforeach
</div>

@if($rest !== null && abs($rest) >= 0.01)
<div style="background:#FEF3C7;border:1px solid #E8C36A;border-radius:10px;padding:12px 16px;margin-bottom:20px;max-width:1000px;font-size:13px;">
    <b>⚠ {{ $money($rest) }} der Rechnung konnten keinem bekannten Vorgang zugeordnet werden.</b>
    Die Rechnung nennt {{ $money($invoice->total_amount) }}, zugeordnet sind {{ $money($invoice->result['sum_matched'] ?? 0) }}.
    Meist fehlt dafür noch die monatliche CSV oder die Referenz-Nr. am Vertrag. Es wurde nichts geraten.
</div>
@endif

<div class="card" style="max-width:1000px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:6px;">Positionen</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">
        Geprüft wird nur gegen Vorgänge, die aus der CSV bekannt sind (Id oder Referenz-Nr.). Als bezahlt gilt ein Vertrag
        erst, wenn auf seiner Zeile <b>genau die erwartete Provision</b> steht.
    </div>
    @if(empty($positions))
        <div class="muted-sm">Keine bekannte Id oder Referenz-Nr. in dieser Rechnung gefunden.</div>
    @else
    <div class="scroll-x">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Id / Referenz-Nr.</th><th style="padding:8px;">Kunde / Vertrag</th>
                <th style="padding:8px;">CSV-Status</th>
                <th style="padding:8px;text-align:right;">Erwartet (CSV)</th><th style="padding:8px;text-align:right;">Laut Rechnung</th>
                <th style="padding:8px;">Ergebnis</th>
            </tr></thead>
            <tbody>
            @foreach($positions as $p)
            <tr style="border-top:1px solid var(--line);">
                <td style="padding:8px;white-space:nowrap;">{{ $p['vermittler_id'] }}<div class="muted-xs">{{ $p['reference_number'] ?: '—' }}</div></td>
                <td style="padding:8px;">
                    @if($p['contract_id'])
                        <a href="{{ route('admin.contract.edit', $p['contract_id']) }}">{{ $p['customer_label'] ?: '—' }}</a>
                    @else
                        {{ $p['customer_label'] ?: '—' }} <span class="muted-xs">(kein Vertrag zugeordnet)</span>
                    @endif
                    <div class="muted-xs">{{ $p['produkt'] ?: $p['contract_label'] }}</div>
                </td>
                <td style="padding:8px;">{{ \App\Services\Vermittler\VermittlerStatusMap::codeLabel($p['status_code']) }}</td>
                <td style="padding:8px;text-align:right;white-space:nowrap;">{{ $money($p['expected_amount']) }}</td>
                <td style="padding:8px;text-align:right;white-space:nowrap;font-weight:600;">
                    {{ $money($p['invoice_amount']) }}
                    @if($p['invoice_amount'] === null && count($p['amounts_seen'] ?? []) > 1)
                        <div class="muted-xs">gelesen: {{ collect($p['amounts_seen'])->map($money)->implode(' / ') }}</div>
                    @endif
                </td>
                <td style="padding:8px;"><span class="badge badge-{{ \App\Models\VermittlerInvoice::outcomeBadge($p['outcome']) }}">{{ \App\Models\VermittlerInvoice::outcomeIcon($p['outcome']) }} {{ \App\Models\VermittlerInvoice::outcomeLabel($p['outcome']) }}</span></td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if($invoice->isDraft() && !empty($positions))
    <form method="POST" action="{{ route('admin.vermittler.invoice_confirm', $invoice->id) }}" style="margin-top:18px;">
        @csrf
        <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:10px;">
            Beim Übernehmen werden <b>{{ $invoice->rows_confirmed }}</b> Verträge als „Bezahlt – auch durch Rechnung belegt" markiert.
            @if($invoice->rows_deviation > 0)
                <b>{{ $invoice->rows_deviation }}</b> Abweichungen kommen in die Prüfliste.
            @endif
            Nicht eindeutige Positionen bleiben unverändert. Vertragsdaten werden nicht geändert.
        </div>
        <button type="submit" class="btn btn-primary">Ergebnis übernehmen</button>
        <a href="{{ route('admin.vermittler.index') }}" class="btn btn-ghost">Abbrechen</a>
    </form>
    @endif
</div>
@endsection
