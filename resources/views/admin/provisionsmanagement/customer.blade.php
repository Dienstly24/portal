@extends('layouts.admin')
@section('content')
@include('admin.provisionsmanagement._layout', ['active' => 'auswertungen', 'titel' => 'Kunden-Wirtschaftlichkeit',
    'untertitel' => 'Ausschließlich intern – diese Zahlen erscheinen weder in der Kundenakte noch im Portal.'])

@php $geld = fn ($w) => number_format((float) $w, 2, ',', '.') . ' €'; @endphp

<div class="card" style="max-width:1250px;">
    <h3 style="margin-top:0;">{{ $customer->user?->name ?? $customer->customer_number }}
        <a style="font-size:12px;font-weight:400;" href="{{ route('admin.customer', $customer->id) }}">Kundenakte →</a></h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:12px 0;">
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Verträge</div><div style="font-size:20px;font-weight:700;">{{ $zahlen['vertraege'] }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Gesamtprovision</div><div style="font-size:20px;font-weight:700;">{{ $geld($zahlen['brutto']) }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Storno</div><div style="font-size:20px;font-weight:700;color:#A32D2D;">{{ $geld($zahlen['storno']) }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Netto</div><div style="font-size:20px;font-weight:700;color:#1F7A4D;">{{ $geld($zahlen['netto']) }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Ø je Vertrag</div><div style="font-size:20px;font-weight:700;">{{ $geld($zahlen['schnitt']) }}</div></div>
        <div><div style="font-size:11.5px;color:var(--ink-soft);">Stornoquote</div><div style="font-size:20px;font-weight:700;">{{ $zahlen['stornoquote'] }} %</div></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;max-width:1250px;margin-top:16px;">
    @foreach([['Nach Monat', $zahlen['nach_monat'], 'monat'], ['Nach Produkt', $zahlen['nach_produkt'], 'label'], ['Nach Pool', $zahlen['nach_pool'], 'label']] as [$titel, $daten, $spalte])
    <div class="card">
        <h3 style="margin-top:0;">{{ $titel }}</h3>
        <table class="table" style="font-size:13px;">
            @forelse($daten as $zeile)
            <tr><td>{{ $zeile[$spalte] }}</td><td style="text-align:right;font-weight:700;">{{ $geld($zeile['netto']) }}</td></tr>
            @empty
            <tr><td style="color:var(--ink-soft);">Keine Daten.</td></tr>
            @endforelse
        </table>
    </div>
    @endforeach
</div>

<div class="card" style="max-width:1250px;margin-top:16px;">
    <h3 style="margin-top:0;">Buchungen</h3>
    <table class="table" style="font-size:13px;">
        <tr><th>Datum</th><th>Art</th><th>Pool</th><th>Vertrag</th><th style="text-align:right;">Betrag</th><th>Status</th></tr>
        @forelse($buchungen as $b)
        <tr>
            <td>{{ $b->commission_date?->format('d.m.Y') ?? '—' }}</td>
            <td>{{ $b->kindLabel() }}</td>
            <td>{{ $b->poolLabel() }}</td>
            <td>{{ $b->contract_label ?: '—' }}</td>
            <td style="text-align:right;font-weight:700;">{{ $b->amountLabel() }}</td>
            <td><span class="badge badge-{{ $b->statusBadge() }}">{{ $b->statusLabel() }}</span></td>
        </tr>
        @empty
        <tr><td colspan="6" style="color:var(--ink-soft);">Keine Provision zu diesem Kunden.</td></tr>
        @endforelse
    </table>
</div>
@endsection
