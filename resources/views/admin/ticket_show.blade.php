@extends('layouts.admin')
@section('content')
@php $attachments = \App\Models\TicketAttachment::where('ticket_id', $ticket->id)->get(); @endphp
<div class="toolbar">
    <div>
        <div class="page-title">{{ $ticket->subject }}</div>
        <div class="page-sub">
            @if($ticket->customer){{ $ticket->customer?->user?->name }}@else 👤 {{ $ticket->guest_name ?? 'Gast' }} ({{ $ticket->source === 'website' ? 'Website' : 'E-Mail' }})@endif
            · {{ $ticket->created_at->format('d.m.Y') }}
            · {{ ['niedrig'=>'🟢 Niedrig','mittel'=>'🟡 Mittel','hoch'=>'🔴 Hoch'][$ticket->priority] ?? '🟡 Mittel' }}
        </div>
    </div>
    <span class="badge badge-{{ $ticket->status === 'open' ? 'open' : 'closed' }}">{{ ['open'=>'Offen','in_progress'=>'In Bearbeitung','waiting'=>'Wartend','closed'=>'Geschlossen'][$ticket->status] ?? $ticket->status }}</span>
</div>
@if(!$ticket->customer && ($ticket->guest_email || $ticket->guest_phone))
<div class="card" style="background:#EFF6FF;">
    <div class="card-title">Kontaktdaten (Lead)</div>
    <p style="font-size:14px;">
        @if($ticket->guest_email)📧 <a href="mailto:{{ $ticket->guest_email }}">{{ $ticket->guest_email }}</a><br>@endif
        @if($ticket->guest_phone)📞 {{ $ticket->guest_phone }}@endif
    </p>
</div>
@endif
<div class="card">
    <div class="card-title">Beschreibung</div>
    <p style="font-size:14px;color:var(--ink-soft);line-height:1.7;white-space:pre-line;">{{ $ticket->description }}</p>
</div>
@if($attachments->count())
<div class="card">
    <div class="card-title">📎 Anhänge ({{ $attachments->count() }})</div>
    @foreach($attachments as $a)
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line);">
        <span style="font-size:13.5px;">{{ $a->file_name }} <span style="color:var(--ink-soft);font-size:11.5px;">· {{ $a->created_at->format('d.m.Y H:i') }}</span></span>
        <span style="display:flex;gap:8px;"><a href="{{ asset('storage/' . $a->file_path) }}" target="_blank" class="btn btn-ghost btn-sm">Öffnen</a><a href="{{ route('admin.attachment.download', $a->id) }}" class="btn btn-ghost btn-sm">⬇ Herunterladen</a></span>
    </div>
    @endforeach
</div>
@endif
@php
    $customerMessages = $ticket->messages->where('is_internal', false);
    $internalMessages = $ticket->messages->where('is_internal', true);
@endphp
{{-- Kundenkommunikation: sichtbar im Kundenportal --}}
<div class="card">
    <div class="card-title">💬 Kundenkommunikation <span style="font-size:11.5px;font-weight:400;color:var(--ink-soft);">(für den Kunden sichtbar)</span></div>
    @forelse($customerMessages as $m)
    <div style="margin-bottom:16px;padding:12px 16px;border-radius:8px;background:var(--canvas);border:1px solid var(--line);">
        <div style="font-size:12px;color:var(--ink-soft);margin-bottom:6px;">{{ $m->sender?->name }} · {{ $m->created_at->format('d.m.Y H:i') }}</div>
        <div style="font-size:14px;line-height:1.6;">{{ $m->body }}</div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Nachrichten an den Kunden.</p>
    @endforelse
</div>
{{-- Interner Bereich: NUR Mitarbeiter, wird niemals ans Kundenportal ausgeliefert --}}
<div class="card" style="background:#FFFDF7;border-color:#F7E7D6;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div class="card-title" style="margin-bottom:0;">🔒 Interner Bereich</div>
        <span style="font-size:11.5px;background:#F7E7D6;color:#B5651D;padding:3px 10px;border-radius:999px;">Nur für Mitarbeiter sichtbar</span>
    </div>
    @forelse($internalMessages as $m)
    <div style="margin-bottom:14px;padding:12px 16px;border-radius:8px;background:#FFF8E6;border:1px solid #F7E7D6;">
        <div style="font-size:12px;color:var(--ink-soft);margin-bottom:6px;">{{ $m->sender?->name }} · {{ $m->created_at->format('d.m.Y H:i') }}</div>
        <div style="font-size:14px;line-height:1.6;">{{ $m->body }}</div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine internen Nachrichten zu diesem Ticket.</p>
    @endforelse
</div>
<div class="card">
    <div class="card-title">Antworten</div>
    <form method="POST" action="{{ route('admin.ticket.reply', $ticket->id) }}" enctype="multipart/form-data">
        @csrf
        <div class="field"><label>Nachricht</label><textarea name="body" required placeholder="Ihre Antwort..."></textarea></div>
        <div class="field">
            <label>Datei anhängen (optional)</label>
            <input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png">
            <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">PDF, JPG oder PNG · für den Kunden im Portal sichtbar</div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Status aktualisieren</label>
                <select name="status">
                    <option value="open" {{ $ticket->status === 'open' ? 'selected' : '' }}>Offen</option>
                    <option value="in_progress" {{ $ticket->status === 'in_progress' ? 'selected' : '' }}>In Bearbeitung</option>
                    <option value="waiting" {{ $ticket->status === 'waiting' ? 'selected' : '' }}>Wartend</option>
                    <option value="closed" {{ $ticket->status === 'closed' ? 'selected' : '' }}>Geschlossen</option>
                </select>
            </div>
            <div class="field" style="display:flex;align-items:flex-end;padding-bottom:18px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_internal" style="width:auto;"> Interne Notiz (nicht für Kunden sichtbar)
                </label>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Senden</button>
    </form>
</div>
<a href="{{ $ticket->customer ? route('admin.tickets') : route('admin.inquiries') }}" class="btn btn-ghost" style="margin-top:8px;">← Zurück</a>
@endsection
