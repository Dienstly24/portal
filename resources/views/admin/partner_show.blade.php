@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.partners') }}">Partner</a><span class="breadcrumb-sep">›</span><span>{{ $partner->name }}</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">{{ $partner->name }}</div>
            <div class="page-sub">
                {{ $partner->partner_number ? 'Partner-Nr. ' . $partner->partner_number . ' · ' : '' }}
                <span class="badge {{ $partner->is_active ? 'badge-active' : 'badge-pending' }}">{{ $partner->is_active ? 'Aktiv' : 'Inaktiv' }}</span>
            </div>
        </div>
        <button onclick="document.getElementById('edit-partner-modal').style.display='flex'" class="btn btn-ghost">Bearbeiten</button>
    </div>
</div>

@if(session('success'))<div style="background:#E4F0E7;color:#3B7A57;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

<div style="display:grid;grid-template-columns:1fr 2fr;gap:20px;align-items:start;">
    <div class="card" style="padding:20px;">
        <div style="font-weight:700;margin-bottom:12px;">Stammdaten</div>
        <dl style="font-size:13px;display:grid;gap:8px;">
            <div><dt style="color:var(--ink-soft);">Kontakt-E-Mail</dt><dd>{{ $partner->contact_email ?? '—' }}</dd></div>
            <div><dt style="color:var(--ink-soft);">Erkennungs-Domains</dt><dd>{{ implode(', ', $partner->email_domains ?? []) ?: '—' }}</dd></div>
            <div><dt style="color:var(--ink-soft);">IBAN</dt><dd>{{ $partner->iban ?? '—' }}</dd></div>
            @if($partner->notes)<div><dt style="color:var(--ink-soft);">Notizen</dt><dd>{{ $partner->notes }}</dd></div>@endif
        </dl>

        @if($partner->externalReferences->isNotEmpty())
        <div style="font-weight:700;margin:16px 0 8px;">Externe Referenzen</div>
        <ul style="font-size:13px;color:var(--ink-soft);">
            @foreach($partner->externalReferences as $ref)
            <li>{{ $ref->typeLabel() }}: {{ $ref->value }}</li>
            @endforeach
        </ul>
        @endif

        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line);font-size:13px;">
            <div style="color:var(--ink-soft);">Gebuchte Provisionen gesamt</div>
            <div style="font-size:20px;font-weight:700;">{{ number_format($partner->bookedTotal(), 2, ',', '.') }} €</div>
        </div>
    </div>

    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);">Provisionshistorie</div>
        <table>
            <thead><tr style="background:#F8F9FA;">
                <th style="padding:10px 20px;">Gutschrift-Nr.</th>
                <th>Datum</th>
                <th>Betrag</th>
                <th>Status</th>
                <th>Geprüft von</th>
            </tr></thead>
            <tbody>
            @forelse($partner->commissions as $c)
            <tr>
                <td style="padding:12px 20px;font-size:13px;">{{ $c->credit_note_number ?? '—' }}</td>
                <td style="font-size:13px;">{{ $c->statement_date?->format('d.m.Y') ?? '—' }}</td>
                <td style="font-size:13px;font-weight:600;">{{ $c->amount !== null ? number_format($c->amount, 2, ',', '.') . ' €' : '—' }}</td>
                <td><span class="badge {{ $c->status === 'booked' ? 'badge-active' : ($c->status === 'rejected' ? 'badge-danger' : 'badge-pending') }}">{{ $c->statusLabel() }}</span></td>
                <td style="font-size:13px;color:var(--ink-soft);">{{ $c->reviewer?->name ?? '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" style="text-align:center;padding:28px;color:var(--ink-soft);">Noch keine Provisionen erfasst.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Modal: Partner bearbeiten --}}
<div id="edit-partner-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:480px;max-width:92vw;max-height:90vh;overflow-y:auto;">
        <div style="font-size:17px;font-weight:700;margin-bottom:16px;">Partner bearbeiten</div>
        <form method="POST" action="{{ route('admin.partners.update', $partner->id) }}">
            @csrf @method('PUT')
            @include('admin._partner_fields', ['partner' => $partner])
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('edit-partner-modal').style.display='none'">Abbrechen</button>
                <button type="submit" class="btn btn-gold">Speichern</button>
            </div>
        </form>
    </div>
</div>
@endsection
