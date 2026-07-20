@extends('layouts.portal')
@section('content')
@php $attachments = \App\Models\TicketAttachment::where('ticket_id', $ticket->id)->get(); @endphp
<div class="toolbar">
    <div>
        <div class="page-title">{{ $ticket->subject }}</div>
        <div class="page-sub">{{ $ticket->ticket_number }} · {{ $ticket->created_at->format('d.m.Y') }} · {{ __($ticket->typeLabel()) }} · {{ __($ticket->priorityLabel()) }}</div>
    </div>
    <span class="badge badge-{{ $ticket->statusBadge() }}">{{ __($ticket->portalStatusLabel()) }}</span>
</div>

@if($ticket->status === 'resolved')
{{-- Geloest: Kunde bestaetigt oder oeffnet mit einer Antwort wieder --}}
<div class="card" style="background:#D9F4E6;border-color:#B7D9C2;">
    <div class="card-title" style="margin-bottom:8px;">✅ {{ __('Unser Team hat Ihre Anfrage als gelöst markiert.') }}</div>
    <p style="font-size:13.5px;color:var(--ink-soft);margin-bottom:14px;">{{ __('Ist Ihr Anliegen erledigt? Dann können Sie die Anfrage schließen. Falls nicht, antworten Sie einfach unten – die Anfrage wird dann automatisch wieder geöffnet.') }}</p>
    <form method="POST" action="{{ route('portal.tickets.close', $ticket->id) }}">
        @csrf
        <button type="submit" class="btn btn-primary">✔ {{ __('Ja, Anliegen erledigt – Anfrage schließen') }}</button>
    </form>
</div>
@elseif($ticket->status === 'waiting')
<div class="card" style="background:#EEE9F7;border-color:#D8CDEE;">
    <div style="font-size:14px;">💬 {{ __('Unser Team wartet auf Ihre Rückmeldung. Bitte antworten Sie unten.') }}</div>
</div>
@elseif($ticket->status === 'closed')
<div class="card" style="background:var(--canvas);">
    <div style="font-size:14px;color:var(--ink-soft);">{{ __('Diese Anfrage ist geschlossen. Bei einem neuen Anliegen stellen Sie bitte eine neue Anfrage.') }}</div>
</div>
@endif

@if($ticket->isFinished())
<div class="card">
    @if($ticket->rating === null)
    {{-- Zufriedenheits-Bewertung (einmalig nach Loesung/Abschluss) --}}
    <div class="card-title">⭐ {{ __('Wie zufrieden waren Sie mit der Bearbeitung?') }}</div>
    <form method="POST" action="{{ route('portal.tickets.rate', $ticket->id) }}">
        @csrf
        <div class="rate-row" style="display:flex;gap:8px;margin-bottom:14px;flex-direction:row-reverse;justify-content:flex-end;">
            @for($i = 5; $i >= 1; $i--)
            <input type="radio" id="rate{{ $i }}" name="rating" value="{{ $i }}" required style="position:absolute;opacity:0;width:1px;height:1px;">
            <label for="rate{{ $i }}" class="rate-star" style="font-size:30px;cursor:pointer;color:#D8D5CC;" title="{{ $i }}/5">★</label>
            @endfor
        </div>
        <div class="field">
            <textarea name="rating_comment" placeholder="{{ __('Kommentar (optional)') }}" style="min-height:60px;"></textarea>
        </div>
        <button type="submit" class="btn btn-gold">{{ __('Bewertung absenden') }}</button>
    </form>
    <style>
        .rate-row .rate-star:hover, .rate-row .rate-star:hover ~ .rate-star,
        .rate-row input:checked ~ .rate-star { color: #C9A24B; }
    </style>
    @else
    <div style="font-size:14px;">{{ __('Ihre Bewertung') }}: <span style="color:#C9A24B;font-size:18px;">{{ str_repeat('★', $ticket->rating) }}</span>{{ str_repeat('☆', 5 - $ticket->rating) }} · {{ __('Vielen Dank für Ihre Bewertung!') }}</div>
    @endif
</div>
@endif

<div class="card">
    <div class="card-title">{{ __('Beschreibung') }}</div>
    <p style="font-size:14px;color:var(--ink-soft);line-height:1.7;white-space:pre-line;">{{ $ticket->description }}</p>
</div>
@if($attachments->count())
<div class="card">
    <div class="card-title">📎 {{ __('Anhänge') }} ({{ $attachments->count() }})</div>
    @foreach($attachments as $a)
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line);">
        <span style="font-size:13.5px;">{{ $a->file_name }}@if($a->uploaded_by !== auth()->id()) <span style="font-size:11px;background:#D9F4E6;color:#17A65B;padding:2px 8px;border-radius:4px;">{{ __('von') }} Dienstly24</span>@endif</span>
        <span style="display:flex;gap:8px;"><a href="{{ route('portal.attachment.download', $a->id) }}" class="btn btn-ghost btn-sm">⬇ {{ __('Herunterladen') }}</a></span>
    </div>
    @endforeach
</div>
@endif
<div class="card">
    <div class="card-title">{{ __('Nachrichten') }}</div>
    @forelse($messages as $m)
    <div style="margin-bottom:16px;padding:12px 16px;border-radius:8px;background:{{ $m->sender_id === auth()->id() ? 'var(--gold-soft)' : 'var(--canvas)' }};border:1px solid var(--line);">
        <div style="font-size:12px;color:var(--ink-soft);margin-bottom:6px;">{{ $m->sender?->name ?? 'Dienstly24 Team' }} · {{ $m->created_at->format('d.m.Y H:i') }}</div>
        <div style="font-size:14px;line-height:1.6;white-space:pre-line;">{{ $m->body }}</div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">{{ __('Noch keine Nachrichten.') }}</p>
    @endforelse
</div>
@if($ticket->status !== 'closed')
<div class="card">
    <div class="card-title">{{ __('Antworten') }}</div>
    @if($ticket->status === 'resolved')
    <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:10px;">ℹ️ {{ __('Eine neue Nachricht öffnet die Anfrage automatisch wieder.') }}</p>
    @endif
    <form method="POST" action="{{ route('portal.tickets.reply', $ticket->id) }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
            <textarea name="body" required placeholder="{{ __('Ihre Nachricht...') }}"></textarea>
        </div>
        <div class="field">
            <label>📎 {{ __('Anhänge (optional, max. 5 · PDF/JPG/PNG/WEBP, je max. 10 MB)') }}</label>
            <input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp">
        </div>
        <button type="submit" class="btn btn-primary">{{ __('Senden') }}</button>
    </form>
</div>
@endif
<a href="{{ route('portal.tickets') }}" class="btn btn-ghost" style="margin-top:8px;">← {{ __('Zurück') }}</a>
@endsection
