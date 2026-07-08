@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div><div class="page-title">Meine Verträge</div><div class="page-sub">Alle Ihre Verträge im Überblick.</div></div>
</div>
<div class="card">
    @forelse($contracts as $c)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $c->insurer }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">{{ $c->contract_number }} · {{ ucfirst(str_replace('_',' ',$c->type)) }}</div>
            @if($c->start_date)<div style="font-size:12px;color:var(--ink-soft);">Seit {{ \Carbon\Carbon::parse($c->start_date)->format('d.m.Y') }}</div>@endif
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="badge badge-{{ $c->status === 'active' ? 'active' : 'pending' }}">{{ $c->status === 'active' ? 'Aktiv' : ucfirst($c->status) }}</span>
            @if($c->pdf_path)<a href="{{ Storage::url($c->pdf_path) }}" class="btn btn-ghost" style="padding:6px 12px;font-size:13px;" target="_blank">PDF</a>@endif
        </div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;padding:12px 0;">Noch keine Verträge vorhanden.</p>
    @endforelse
</div>
@endsection
