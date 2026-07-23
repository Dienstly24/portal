@extends('layouts.portal')
@section('content')
{{-- Direktnachrichten als Vollbild-Chat im Messenger-Stil: Blasen,
     Tagestrenner, Lesehaken, feste Eingabezeile. Der Verlauf wird per
     Feed-Polling aktualisiert, gesendet wird per fetch() (Fallback:
     klassischer POST ohne JS). --}}
<div class="chatpage">
    <div class="chatpage-head">
        <div class="d24c-av">D24</div>
        <div>
            <div class="chatpage-name">{{ __('Ihr Dienstly24 Team') }}</div>
            <div class="chatpage-status">{{ __('Wir antworten schnellstmöglich') }}</div>
        </div>
    </div>
    <div class="d24c-scroll" id="chat-scroll">
        @php $lastDay = null; @endphp
        <div class="d24c-list" id="chat-list" data-last-day="">
            @forelse($messages as $m)
                @php
                    $day = $m->created_at->isToday()
                        ? __('Heute')
                        : ($m->created_at->isYesterday() ? __('Gestern') : $m->created_at->format('d.m.Y'));
                @endphp
                @if($day !== $lastDay)
                    <div class="d24c-day">{{ $day }}</div>
                    @php $lastDay = $day; @endphp
                @endif
                <div class="d24c-bub {{ $m->from_staff ? 'them' : 'me' }}" data-mid="{{ $m->id }}">
                    @if($m->from_staff)<span class="d24c-sender">{{ $m->sender?->name ?? 'Dienstly24 Team' }}</span>@endif
                    <span class="d24c-body">{{ $m->body }}</span>
                    @foreach($m->attachments as $att)
                    <span class="d24c-att">
                        <span class="d24c-att-n">{{ $att->isImage() ? '🖼️' : ($att->isPdf() ? '📄' : '📎') }} {{ $att->file_name }}</span>
                        <span class="d24c-attbtns">
                            @if($att->isViewable())<a href="{{ route('portal.messages.attachment.view', $att->id) }}" target="_blank" rel="noopener">👁 {{ __('Anzeigen') }}</a>@endif
                            <a href="{{ route('portal.messages.attachment', $att->id) }}">⬇ {{ __('Herunterladen') }}</a>
                        </span>
                    </span>
                    @endforeach
                    <span class="d24c-tm">{{ $m->created_at->format('H:i') }}@unless($m->from_staff)<span class="d24c-ticks{{ $m->read_at ? ' read' : '' }}" title="{{ $m->read_at ? __('Gelesen') : __('Gesendet') }}">{{ $m->read_at ? '✓✓' : '✓' }}</span>@endunless</span>
                </div>
            @empty
                <div class="d24c-empty">👋 {{ __('Noch keine Nachrichten – schreiben Sie uns einfach. Wir melden uns schnellstmöglich.') }}</div>
            @endforelse
        </div>
    </div>
    <div class="d24c-chips">
        <a class="d24c-chip" href="{{ route('portal.tickets.create') }}">📝 {{ __('Anfrage stellen') }}</a>
        <a class="d24c-chip" href="{{ route('portal.documents') }}">📤 {{ __('Dokument hochladen') }}</a>
        <a class="d24c-chip" href="{{ route('portal.contracts') }}">📑 {{ __('Meine Verträge') }}</a>
    </div>
    <div class="d24c-files" id="chat-files" hidden></div>
    <form class="d24c-comp" id="chat-form" method="POST" action="{{ route('portal.messages.store') }}" enctype="multipart/form-data">
        @csrf
        <label class="d24c-clip" title="{{ __('Anhang hinzufügen') }}">📎<input id="chat-file" type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" hidden></label>
        <textarea id="chat-input" name="body" class="d24c-inp" rows="1" maxlength="5000" placeholder="{{ __('Nachricht schreiben …') }}" required autocomplete="off"></textarea>
        <button type="submit" class="d24c-send" aria-label="{{ __('Senden') }}"><span class="snd-ico">➤</span></button>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('chat-list');
    const scroller = document.getElementById('chat-scroll');
    list.dataset.lastDay = @json($lastDay ?? '');
    D24Chat.wireComposer({
        form: document.getElementById('chat-form'),
        input: document.getElementById('chat-input'),
        fileInput: document.getElementById('chat-file'),
        filesBar: document.getElementById('chat-files'),
        list: list, scroller: scroller
    });
    // Seite offen = Chat sichtbar: neue Beraternachrichten direkt als
    // gelesen markieren und live anhaengen.
    D24Chat.poll({
        list: list, scroller: scroller,
        url: '{{ route('portal.messages.feed') }}',
        every: 10000,
        markRead: function () { return true; }
    });
});
</script>
@endsection
