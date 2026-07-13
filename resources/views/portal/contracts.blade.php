@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div><div class="page-title">{{ __('Meine Verträge') }}</div><div class="page-sub">{{ __('Alle Ihre Verträge im Überblick.') }}</div></div>
    <button onclick="document.getElementById('report-contract-modal').style.display='flex'" class="btn btn-gold">+ Neuen Vertrag melden</button>
</div>
@php
$typeIcons = [
    'kfz' => '🚗', 'strom_gas' => '⚡', 'internet' => '📶', 'haftpflicht' => '🛡️',
    'hausrat' => '🏠', 'rechtsschutz' => '⚖️', 'krankenversicherung' => '🏥',
    'leben' => '❤️', 'unfall' => '🚑', 'andere' => '📋',
];
$typeLabels = [
    'kfz' => 'KFZ', 'strom_gas' => 'Strom/Gas', 'internet' => 'Internet', 'haftpflicht' => 'Haftpflicht',
    'hausrat' => 'Hausrat', 'rechtsschutz' => 'Rechtsschutz', 'krankenversicherung' => 'Krankenversicherung',
    'leben' => 'Leben', 'unfall' => 'Unfall', 'andere' => 'Andere',
];
@endphp

@if($contracts->isEmpty())
<div class="card"><p style="color:var(--ink-soft);font-size:14px;padding:12px 0;">Noch keine Verträge vorhanden. Melden Sie Ihren ersten Vertrag über den Button oben.</p></div>
@else
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-bottom:24px;">
    @foreach($contracts as $c)
    <a href="{{ route('portal.contracts.show', $c->id) }}" class="card metric-link" style="margin-bottom:0;text-decoration:none;color:var(--ink);">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px;">
            <span style="font-size:34px;line-height:1;">{{ $c->typeIcon() }}</span>
            <span class="badge badge-{{ $c->status === 'active' ? 'active' : 'pending' }}">{{ $c->status === 'active' ? 'Aktiv' : ucfirst($c->status) }}</span>
        </div>
        <div style="font-weight:700;font-size:15px;margin-bottom:2px;">{{ $c->insurer }}</div>
        <div style="font-size:12.5px;color:var(--ink-soft);">{{ $c->typeLabel() }}@if($c->contract_number) · {{ $c->contract_number }}@endif</div>
        @if($c->start_date)<div style="font-size:12px;color:var(--ink-soft);margin-top:6px;">Seit {{ \Carbon\Carbon::parse($c->start_date)->format('d.m.Y') }}</div>@endif
        <div class="metric-cta" style="margin-top:12px;">{{ __('Details ansehen') }} →</div>
    </a>
    @endforeach
</div>
@endif

{{-- Modal: Neuen Vertrag melden (erzeugt nur einen Change Request) --}}
<div id="report-contract-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:480px;position:relative;">
        <button onclick="document.getElementById('report-contract-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">Neuen Vertrag melden</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">Unser Team prüft Ihre Meldung und nimmt den Vertrag anschließend auf.</p>
        <form method="POST" action="{{ route('portal.contracts.report') }}" enctype="multipart/form-data">
            @csrf
            <div class="grid-2">
                <div class="field"><label>Versicherungsart *</label>
                    <select name="type" required>
                        <option value="kfz">🚗 KFZ</option>
                        <option value="krankenversicherung">🏥 Krankenversicherung</option>
                        <option value="haftpflicht">🛡️ Haftpflicht</option>
                        <option value="rechtsschutz">⚖️ Rechtsschutz</option>
                        <option value="hausrat">🏠 Hausrat</option>
                        <option value="leben">❤️ Leben</option>
                        <option value="unfall">🚑 Unfall</option>
                        <option value="internet">📶 Internet</option>
                        <option value="strom_gas">⚡ Strom/Gas</option>
                        <option value="andere">📋 Andere</option>
                    </select>
                </div>
                <div class="field"><label>Gesellschaft *</label><input type="text" name="insurer" required maxlength="255" placeholder="z.B. Allianz"></div>
            </div>
            <div class="field"><label>Vertragsnummer</label><input type="text" name="contract_number" maxlength="100" placeholder="Optional"></div>
            <div class="field"><label>Dokument (PDF/JPG/PNG, max. 10 MB)</label><input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png"></div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Vertrag melden</button>
        </form>
    </div>
</div>
@endsection
