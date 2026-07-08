@extends('layouts.portal')
@section('content')
@php $attachments = \App\Models\TicketAttachment::where('ticket_id', $ticket->id)->get(); @endphp
<div class="toolbar">
    <div>
        <div class="page-title">{{ $ticket->subject }}</div>
        <div class="page-sub">{{ $ticket->created_at->format('d.m.Y') }} · {{ ucfirst(str_replace('_',' ',$ticket->type)) }} · {{ ['niedrig'=>'🟢 Niedrig','mittel'=>'🟡 Mittel','hoch'=>'🔴 Hoch'][$ticket->priority] ?? '🟡 Mittel' }}</div>
    </div>
    <span class="badge badge-{{ $ticket->status === 'open' ? 'open' : 'closed' }}">{{ ['open'=>'Offen','in_progress'=>'In Bearbeitung','waiting'=>'Wartend','closed'=>'Geschlossen'][$ticket->status] }}</span>
</div>
<div class="card">
    <div class="card-title">Beschreibung</div>
    <p style="font-size:14px;color:var(--ink-soft);line-height:1.7;white-space:pre-line;">{{ $ticket->description }}</p>
</div>
@if($attachments->count())
<div class="card">
    <div class="card-title">📎 Anhänge ({{ $attachments->count() }})</div>
    @foreach($attachments as $a)
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line);">
        <span style="font-size:13.5px;">{{ $a->file_name }}@if($a->uploaded_by !== auth()->id()) <span style="font-size:11px;background:#E4F0E7;color:#3B7A57;padding:2px 8px;border-radius:4px;">von Dienstly24</span>@endif</span>
        <span style="display:flex;gap:8px;"><a href="{{ asset('storage/' . $a->file_path) }}" target="_blank" class="btn btn-ghost btn-sm">Öffnen</a><a href="{{ route('portal.attachment.download', $a->id) }}" class="btn btn-ghost btn-sm">⬇</a></span>
    </div>
    @endforeach
</div>
@endif
<div class="card">
    <div class="card-title">Nachrichten</div>
    @forelse($messages as $m)
    <div style="margin-bottom:16px;padding:12px 16px;border-radius:8px;background:{{ $m->sender_id === auth()->id() ? 'var(--gold-soft)' : 'var(--canvas)' }};border:1px solid var(--line);">
        <div style="font-size:12px;color:var(--ink-soft);margin-bottom:6px;">{{ $m->sender->name }} · {{ $m->created_at->format('d.m.Y H:i') }}</div>
        <div style="font-size:14px;line-height:1.6;">{{ $m->body }}</div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Nachrichten.</p>
    @endforelse
</div>
@if($ticket->status !== 'closed')
<div class="card">
    <div class="card-title">Antworten</div>
    <form method="POST" action="{{ route('portal.tickets.reply', $ticket->id) }}">
        @csrf
        <div class="field">
            <textarea name="body" required placeholder="Ihre Nachricht..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Senden</button>
    </form>
</div>
@endif
<a href="{{ route('portal.tickets') }}" class="btn btn-ghost" style="margin-top:8px;">← Zurück</a>
@endsection
