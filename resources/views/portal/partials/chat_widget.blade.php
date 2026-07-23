{{-- Schwebendes Chat-Widget: Blase mit Ungelesen-Badge, Panel mit dem
     Direktnachrichten-Verlauf (CustomerMessage). Nur Desktop/Tablet -
     auf Mobile fuehrt der Tab "Nachrichten" zum Vollbild-Chat. --}}
@php
    // $unreadMsgs kommt aus dem Portal-Layout (Sidebar-Badge) - keine
    // zweite Abfrage noetig.
    $cwUnread = $unreadMsgs ?? 0;
@endphp
<div class="cw-wrap" id="cw-wrap">
    <div class="cw-panel" id="cw-panel" hidden role="dialog" aria-label="{{ __('Nachrichten') }}">
        <div class="cw-head">
            <div class="d24c-av">D24</div>
            <div style="min-width:0;">
                <div class="cw-name">{{ __('Ihr Dienstly24 Team') }}</div>
                <div class="cw-status">{{ __('Wir antworten schnellstmöglich') }}</div>
            </div>
            <a class="cw-expand" href="{{ route('portal.messages') }}" title="{{ __('Im Vollbild öffnen') }}">⤢</a>
            <button class="cw-close" id="cw-close" type="button" aria-label="{{ __('Chat schließen') }}">✕</button>
        </div>
        <div class="d24c-scroll" id="cw-scroll">
            <div class="d24c-list" id="cw-list" data-last-day="">
                <div class="d24c-empty">{{ __('Laden…') }}</div>
            </div>
        </div>
        <div class="d24c-chips">
            <a class="d24c-chip" href="{{ route('portal.tickets.create') }}">📝 {{ __('Anfrage stellen') }}</a>
            <a class="d24c-chip" href="{{ route('portal.documents') }}">📤 {{ __('Dokument hochladen') }}</a>
        </div>
        <div class="d24c-files" id="cw-files" hidden></div>
        <form class="d24c-comp" id="cw-form" method="POST" action="{{ route('portal.messages.store') }}" enctype="multipart/form-data">
            @csrf
            <label class="d24c-clip" title="{{ __('Anhang hinzufügen') }}">📎<input id="cw-file" type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" hidden></label>
            <textarea id="cw-input" name="body" class="d24c-inp" rows="1" maxlength="5000" placeholder="{{ __('Nachricht schreiben …') }}" required></textarea>
            <button type="submit" class="d24c-send" aria-label="{{ __('Senden') }}"><span class="snd-ico">➤</span></button>
        </form>
    </div>
    <button class="cw-fab" id="cw-fab" type="button" aria-label="{{ __('Chat öffnen') }}" aria-expanded="false">
        <span>💬</span>
        <span class="cw-badge" id="cw-badge" @if($cwUnread <= 0) hidden @endif>{{ $cwUnread > 9 ? '9+' : $cwUnread }}</span>
    </button>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const panel = document.getElementById('cw-panel');
    const fab = document.getElementById('cw-fab');
    const badge = document.getElementById('cw-badge');
    const list = document.getElementById('cw-list');
    const scroller = document.getElementById('cw-scroll');
    const input = document.getElementById('cw-input');
    let open = false;

    function setBadge(n) {
        badge.textContent = n > 9 ? '9+' : n;
        badge.hidden = n <= 0;
    }
    const poller = D24Chat.poll({
        list: list, scroller: scroller,
        url: '{{ route('portal.messages.feed') }}',
        every: 12000,
        markRead: function () { return open; },
        onData: function (d) {
            if (!open) setBadge(d.unread);
            if (!d.messages.length) {
                const e = list.querySelector('.d24c-empty');
                if (e) e.textContent = D24ChatL10n.empty;
            }
        }
    });
    const composer = D24Chat.wireComposer({
        form: document.getElementById('cw-form'),
        input: input,
        fileInput: document.getElementById('cw-file'),
        filesBar: document.getElementById('cw-files'),
        list: list, scroller: scroller
    });

    function openPanel() {
        panel.hidden = false; open = true;
        fab.setAttribute('aria-expanded', 'true');
        setBadge(0);
        poller.tick();
        composer.scrollBottom();
        input.focus();
    }
    function closePanel() {
        panel.hidden = true; open = false;
        fab.setAttribute('aria-expanded', 'false');
    }
    fab.addEventListener('click', function () { open ? closePanel() : openPanel(); });
    document.getElementById('cw-close').addEventListener('click', closePanel);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) closePanel(); });
    // Dashboard-Hero (und andere Seiten) koennen den Chat direkt oeffnen
    window.d24ChatOpen = openPanel;
});
</script>
