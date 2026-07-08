@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div><div class="page-title">Anfragen</div><div class="page-sub">Stellen Sie eine Anfrage oder verfolgen Sie den Status.</div></div>
    <a href="{{ route('portal.tickets.create') }}" class="btn btn-gold">+ Neue Anfrage</a>
</div>
<div class="card">
    @forelse($tickets as $t)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $t->subject }}</div>
            <div style="font-size:13px;color:var(--ink-soft);">{{ $t->created_at->format('d.m.Y') }} · {{ ucfirst(str_replace('_',' ',$t->type)) }}</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="badge badge-{{ $t->status === 'open' ? 'open' : ($t->status === 'closed' ? 'closed' : 'pending') }}">{{ ['open'=>'Offen','in_progress'=>'In Bearbeitung','waiting'=>'Wartend','closed'=>'Geschlossen'][$t->status] ?? $t->status }}</span>
            <a href="{{ route('portal.tickets.show', $t->id) }}" class="btn btn-ghost" style="padding:6px 12px;font-size:13px;">Details</a>
        </div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;padding:12px 0;">Noch keine Anfragen vorhanden.</p>
    @endforelse
</div>
@endsection
