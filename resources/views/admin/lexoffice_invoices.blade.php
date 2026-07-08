@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>lexoffice</span><span class="breadcrumb-sep">›</span><span>Rechnungen</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">lexoffice Rechnungen</div>
            <div class="page-sub">{{ number_format($total) }} Rechnungen in lexoffice</div>
        </div>
        <a href="{{ route('admin.lexoffice.contacts') }}" class="btn btn-ghost">👥 Kontakte →</a>
    </div>
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <table>
        <thead><tr style="background:#FAFAF8;">
            <th style="padding:12px 20px;">Rechnungsnr.</th>
            <th>Empfänger</th>
            <th>Betrag</th>
            <th>Datum</th>
            <th>Status</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse($invoices as $inv)
        @php
            $status = $inv['voucherStatus'] ?? 'draft';
            $statusMap = ['draft'=>['Entwurf','badge-closed'],'open'=>['Offen','badge-open'],'paid'=>['Bezahlt','badge-active'],'overdue'=>['Überfällig','badge-rejected'],'cancelled'=>['Storniert','badge-rejected']];
            $s = $statusMap[$status] ?? [$status,'badge-closed'];
            $name = $inv['address']['name'] ?? ($inv['address']['contactId'] ?? '—');
            $total = number_format(($inv['totalPrice']['totalNetAmount'] ?? 0) * (1 + ($inv['totalPrice']['taxRatePercentage'] ?? 19)/100), 2, ',', '.') . ' €';
        @endphp
        <tr>
            <td style="padding:13px 20px;font-weight:700;">{{ $inv['voucherNumber'] ?? 'Entwurf' }}</td>
            <td style="font-size:14px;">{{ $name }}</td>
            <td style="font-weight:600;">{{ $total }}</td>
            <td style="font-size:13px;color:var(--ink-soft);">{{ isset($inv['voucherDate']) ? \Carbon\Carbon::parse($inv['voucherDate'])->format('d.m.Y') : '—' }}</td>
            <td><span class="badge {{ $s[1] }}">{{ $s[0] }}</span></td>
            <td style="padding-right:20px;">
                <div style="display:flex;gap:6px;align-items:center;">
                    <a href="{{ route('admin.lexoffice.invoice.download', $inv['id']) }}" class="btn btn-ghost btn-sm">PDF</a>
                    @if(isset($inv['address']['supplement']) || true)
                    <button onclick="document.getElementById('send-{{ $inv['id'] }}').style.display='block'" class="btn btn-primary btn-sm">📧 Senden</button>
                    @endif
                </div>
                <div id="send-{{ $inv['id'] }}" style="display:none;margin-top:8px;">
                    <form method="POST" action="{{ route('admin.lexoffice.invoice.send', $inv['id']) }}" style="display:flex;gap:6px;">
                        @csrf
                        <input type="email" name="email" placeholder="E-Mail eingeben..." required style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;font-size:13px;width:200px;">
                        <button type="submit" class="btn btn-primary btn-sm">Senden</button>
                    </form>
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--ink-soft);">Keine Rechnungen gefunden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div style="display:flex;gap:8px;margin-top:16px;align-items:center;justify-content:center;">
    @if($page > 0)
    <a href="{{ route('admin.lexoffice.invoices', ['page'=>$page-1]) }}" class="btn btn-ghost btn-sm">← Zurück</a>
    @endif
    <span style="font-size:13px;color:var(--ink-soft);">Seite {{ $page+1 }} / {{ max(1,$pages) }}</span>
    @if($page < $pages-1)
    <a href="{{ route('admin.lexoffice.invoices', ['page'=>$page+1]) }}" class="btn btn-ghost btn-sm">Weiter →</a>
    @endif
</div>
@endsection
