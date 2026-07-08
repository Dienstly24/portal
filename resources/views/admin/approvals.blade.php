@extends('layouts.admin')
@section('content')
<div class="page-title">Genehmigungen</div>
<div class="page-sub">Ausstehende Änderungsanfragen der Kunden.</div>
<div class="card">
    @forelse($approvals as $a)
    <div class="item-row" style="flex-wrap:wrap;gap:12px;">
        <div style="flex:1;min-width:200px;">
            <div style="font-weight:600;font-size:14px;">{{ $a->customer?->user?->name }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">Feld: <strong>{{ $a->field_name }}</strong></div>
            <div style="font-size:13px;margin-top:4px;">
                <span style="color:var(--ink-soft);">Alt:</span> {{ $a->old_value ?? '—' }}
                <span style="margin:0 8px;color:var(--ink-soft);">→</span>
                <span style="font-weight:600;color:#3B7A57;">{{ $a->new_value }}</span>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <form method="POST" action="{{ route('admin.approval.action', $a->id) }}" style="display:inline;">
                @csrf
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-primary btn-sm">Genehmigen</button>
            </form>
            <form method="POST" action="{{ route('admin.approval.action', $a->id) }}" style="display:inline;">
                @csrf
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="btn btn-danger btn-sm">Ablehnen</button>
            </form>
        </div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;padding:12px 0;">Keine ausstehenden Genehmigungen.</p>
    @endforelse
</div>
@endsection
