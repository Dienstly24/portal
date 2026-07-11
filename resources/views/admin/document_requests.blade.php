@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Dokumentenanfragen</span></div>
    <div>
        <div class="page-title">Dokumentenanfragen</div>
        <div class="page-sub">Von Kunden hochgeladene Dokumente prüfen und offene Anfragen im Blick behalten.</div>
    </div>
</div>

@if(session('success'))<div style="background:#E4F0E7;color:#3B7A57;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

<div class="card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);">Zu prüfen ({{ $awaitingReview->count() }})</div>
    @forelse($awaitingReview as $req)
    <div style="padding:16px 20px;border-bottom:1px solid var(--line);">
        <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start;">
            <div style="min-width:240px;">
                <div style="font-weight:600;font-size:14px;">{{ $req->title }}</div>
                <div style="font-size:13px;color:var(--ink-soft);">
                    <a href="{{ route('admin.customer', $req->customer_id) }}">{{ $req->customer->user?->name ?? 'Kunde' }}</a>
                    @if($req->contract) · Vertrag {{ $req->contract->contract_number }} @endif
                    · hochgeladen {{ $req->uploaded_at?->format('d.m.Y H:i') }}
                </div>
                @if($req->document)
                <div style="font-size:13px;margin-top:6px;">
                    📄 <a href="{{ route('admin.documents.download', $req->document_id) }}">{{ $req->document->file_name }}</a>
                </div>
                @endif
            </div>
            <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">
                <form method="POST" action="{{ route('admin.document_requests.approve', $req->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-gold btn-sm">Freigeben</button>
                </form>
                <form method="POST" action="{{ route('admin.document_requests.reject', $req->id) }}" style="display:flex;gap:8px;">
                    @csrf
                    <input type="text" name="rejection_note" required maxlength="1000" placeholder="Grund für Zurückweisung"
                        style="padding:7px 10px;border:1px solid var(--line);border-radius:8px;width:220px;font-size:13px;">
                    <button type="submit" class="btn btn-ghost btn-sm">Zurückweisen</button>
                </form>
            </div>
        </div>
    </div>
    @empty
    <div style="text-align:center;padding:28px;color:var(--ink-soft);">Keine Uploads zur Prüfung.</div>
    @endforelse
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);">Offen beim Kunden ({{ $open->count() }})</div>
    <table>
        <thead><tr style="background:#FAFAF8;">
            <th style="padding:10px 20px;">Anfrage</th>
            <th>Kunde</th>
            <th>Frist</th>
            <th>Status</th>
        </tr></thead>
        <tbody>
        @forelse($open as $req)
        <tr>
            <td style="padding:12px 20px;font-size:13px;font-weight:600;">{{ $req->title }}</td>
            <td style="font-size:13px;"><a href="{{ route('admin.customer', $req->customer_id) }}">{{ $req->customer->user?->name ?? '—' }}</a></td>
            <td style="font-size:13px;color:{{ $req->deadline?->isPast() ? '#B3261E' : 'var(--ink-soft)' }};">{{ $req->deadline?->format('d.m.Y') ?? '—' }}</td>
            <td><span class="badge {{ $req->status === 'rejected' ? 'badge-danger' : 'badge-pending' }}">{{ $req->statusLabel() }}</span></td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;padding:28px;color:var(--ink-soft);">Keine offenen Anfragen.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
