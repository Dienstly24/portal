@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Partner</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">Partnerverwaltung</div>
            <div class="page-sub">Makler- und Vertriebspartner mit automatischer Erkennung eingehender Provisions-Mails.</div>
        </div>
        <button onclick="document.getElementById('add-partner-modal').style.display='flex'" class="btn btn-gold">+ Partner anlegen</button>
    </div>
</div>

@if(session('success'))<div style="background:#D9F4E6;color:#17A65B;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

<div class="card" style="padding:0;overflow:hidden;">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 20px;">Partner</th>
            <th>Partner-Nr.</th>
            <th>Erkennungs-Domains</th>
            <th>Provisionen</th>
            <th>Status</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse($partners as $p)
        <tr>
            <td style="padding:14px 20px;">
                <div style="font-weight:600;font-size:14px;">{{ $p->name }}</div>
                @if($p->contact_email)<div style="font-size:12px;color:var(--ink-soft);">{{ $p->contact_email }}</div>@endif
            </td>
            <td style="font-size:13px;">{{ $p->partner_number ?? '—' }}</td>
            <td style="font-size:12px;color:var(--ink-soft);">{{ implode(', ', $p->email_domains ?? []) ?: '—' }}</td>
            <td style="font-size:13px;">{{ $p->commissions_count }}</td>
            <td><span class="badge {{ $p->is_active ? 'badge-active' : 'badge-pending' }}">{{ $p->is_active ? 'Aktiv' : 'Inaktiv' }}</span></td>
            <td style="padding-right:20px;white-space:nowrap;">
                <a href="{{ route('admin.partners.show', $p->id) }}" class="btn btn-ghost btn-sm">Details</a>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--ink-soft);">Noch keine Partner angelegt. Legen Sie Partner mit ihren Absender-Domains an, damit eingehende Provisions-Mails automatisch zugeordnet werden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Modal: Partner anlegen --}}
<div id="add-partner-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:480px;max-width:92vw;max-height:90vh;overflow-y:auto;">
        <div style="font-size:17px;font-weight:700;margin-bottom:16px;">Partner anlegen</div>
        <form method="POST" action="{{ route('admin.partners.store') }}">
            @csrf
            @include('admin._partner_fields', ['partner' => null])
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('add-partner-modal').style.display='none'">Abbrechen</button>
                <button type="submit" class="btn btn-gold">Anlegen</button>
            </div>
        </form>
    </div>
</div>
@endsection
