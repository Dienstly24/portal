@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>lexoffice</span><span class="breadcrumb-sep">›</span><span>Kontakte</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">lexoffice Kontakte</div>
            <div class="page-sub">{{ number_format($total) }} Kontakte in lexoffice</div>
        </div>
        <a href="{{ route('admin.lexoffice.invoices') }}" class="btn btn-ghost">📄 Rechnungen →</a>
    </div>
</div>

<form method="GET" action="{{ route('admin.lexoffice.contacts') }}" style="display:flex;gap:12px;margin-bottom:20px;">
    <input type="text" name="search" value="{{ $search }}" placeholder="Kontakt suchen..."
        style="flex:1;padding:10px 14px;border:1px solid var(--line);border-radius:8px;font-size:14px;max-width:400px;">
    <button type="submit" class="btn btn-primary">Suchen</button>
</form>

<div class="card" style="padding:0;overflow:hidden;">
    <table>
        <thead><tr style="background:#FAFAF8;">
            <th style="padding:12px 20px;">Name</th>
            <th>Typ</th>
            <th>E-Mail</th>
            <th>Telefon</th>
            <th>Stadt</th>
            <th></th>
        </tr></thead>
        <tbody>
        @forelse($contacts as $c)
        @php
            $name = isset($c['company']) ? $c['company']['name'] : trim(($c['person']['firstName'] ?? '') . ' ' . ($c['person']['lastName'] ?? ''));
            $email = $c['emailAddresses']['business'][0] ?? $c['emailAddresses']['private'][0] ?? null;
            $phone = $c['phoneNumbers']['business'][0] ?? $c['phoneNumbers']['mobile'][0] ?? null;
            $city = $c['addresses']['billing'][0]['city'] ?? $c['addresses']['shipping'][0]['city'] ?? null;
            $isImported = $email && \App\Models\User::where('email',$email)->exists();
        @endphp
        <tr>
            <td style="padding:13px 20px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:34px;height:34px;border-radius:50%;background:{{ isset($c['company']) ? '#FEF3C7' : 'var(--petrol)' }};color:{{ isset($c['company']) ? '#92400E' : '#fff' }};display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex:none;">
                        {{ strtoupper(substr($name,0,2)) }}
                    </div>
                    <div style="font-weight:600;font-size:14px;">{{ $name }}</div>
                </div>
            </td>
            <td><span class="badge {{ isset($c['company']) ? 'badge-pending' : 'badge-open' }}">{{ isset($c['company']) ? 'Firma' : 'Privat' }}</span></td>
            <td style="font-size:13px;color:var(--ink-soft);">{{ $email ?? '—' }}</td>
            <td style="font-size:13px;color:var(--ink-soft);">{{ $phone ?? '—' }}</td>
            <td style="font-size:13px;color:var(--ink-soft);">{{ $city ?? '—' }}</td>
            <td style="padding-right:20px;">
                @if($isImported)
                    <span class="badge badge-active">✓ Importiert</span>
                @elseif($email)
                    <form method="POST" action="{{ route('admin.lexoffice.import') }}" style="display:inline;">
                        @csrf
                        <input type="hidden" name="lexoffice_id" value="{{ $c['id'] }}">
                        <button type="submit" class="btn btn-ghost btn-sm">+ Importieren</button>
                    </form>
                @else
                    <span style="font-size:12px;color:var(--ink-soft);">Keine E-Mail</span>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--ink-soft);">Keine Kontakte gefunden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Pagination --}}
<div style="display:flex;gap:8px;margin-top:16px;align-items:center;justify-content:center;">
    @if($page > 0)
    <a href="{{ route('admin.lexoffice.contacts', ['page'=>$page-1,'search'=>$search]) }}" class="btn btn-ghost btn-sm">← Zurück</a>
    @endif
    <span style="font-size:13px;color:var(--ink-soft);">Seite {{ $page+1 }} / {{ $pages }}</span>
    @if($page < $pages-1)
    <a href="{{ route('admin.lexoffice.contacts', ['page'=>$page+1,'search'=>$search]) }}" class="btn btn-ghost btn-sm">Weiter →</a>
    @endif
</div>
@endsection
