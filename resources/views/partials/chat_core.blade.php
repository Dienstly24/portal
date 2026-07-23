{{-- Gemeinsamer Chat-Kern: Renderer + Composer- und Polling-Logik.
     Genutzt von Nachrichten-Seite und Widget im Kundenportal sowie vom
     Kunden-Chat der Beraterwelt. Die Perspektive steckt im Payload
     (m.own aus CustomerMessage::toChatPayload), damit Blasen, Lesehaken
     und Anhaenge ueberall identisch funktionieren. --}}
<script>
window.D24ChatL10n = {
    view: @json(__('Anzeigen')),
    download: @json(__('Herunterladen')),
    read: @json(__('Gelesen')),
    sent: @json(__('Gesendet')),
    clear: @json(__('Auswahl entfernen')),
    empty: @json(__('Noch keine Nachrichten – schreiben Sie uns einfach. Wir melden uns schnellstmöglich.'))
};
window.D24Chat = (function () {
    const L = window.D24ChatL10n;
    const esc = function (t) { const d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; };
    const cssId = function (id) { return (window.CSS && CSS.escape) ? CSS.escape(id) : id; };

    function attHtml(a) {
        const ico = a.kind === 'image' ? '🖼️' : (a.kind === 'pdf' ? '📄' : '📎');
        let btns = '';
        if (a.view_url) {
            // Vorschau-Attribute: Hover = Schnellvorschau, Klick = grosses
            // Fenster (partials/doc_preview). Ohne eingebundenes Partial
            // bleibt der Link ein normaler Neuer-Tab-Link.
            const pv = ' data-preview-open data-preview-url="' + esc(a.view_url) + '" data-preview-name="' + esc(a.name)
                + '" data-preview-kind="' + (a.kind === 'image' ? 'image' : 'pdf') + '" data-preview-download="' + esc(a.download_url) + '"';
            btns += '<a href="' + esc(a.view_url) + '" target="_blank" rel="noopener"' + pv + '>👁 ' + esc(L.view) + '</a>';
        }
        btns += '<a href="' + esc(a.download_url) + '">⬇ ' + esc(L.download) + '</a>';
        return '<span class="d24c-att"><span class="d24c-att-n">' + ico + ' ' + esc(a.name) + '</span><span class="d24c-attbtns">' + btns + '</span></span>';
    }

    function bubbleHtml(m) {
        const own = (m.own !== undefined) ? m.own : !m.from_staff;
        const showSender = (m.show_sender !== undefined) ? m.show_sender : m.from_staff;
        const ticks = own ? ('<span class="d24c-ticks' + (m.read ? ' read' : '') + '" title="' + esc(m.read ? L.read : L.sent) + '">' + (m.read ? '✓✓' : '✓') + '</span>') : '';
        const sender = showSender ? '<span class="d24c-sender">' + esc(m.sender) + '</span>' : '';
        const atts = (m.attachments || []).map(attHtml).join('');
        return '<div class="d24c-bub ' + (own ? 'me' : 'them') + '" data-mid="' + esc(m.id) + '">' + sender
            + '<span class="d24c-body">' + esc(m.body) + '</span>' + atts
            + '<span class="d24c-tm">' + esc(m.time) + ticks + '</span></div>';
    }

    // Haengt EINE Nachricht an (inkl. Tagestrenner); true = wurde ergaenzt.
    function append(list, m) {
        if (list.querySelector('[data-mid="' + cssId(m.id) + '"]')) return false;
        const empty = list.querySelector('.d24c-empty');
        if (empty) empty.remove();
        if (list.dataset.lastDay !== m.day) {
            list.insertAdjacentHTML('beforeend', '<div class="d24c-day">' + esc(m.day) + '</div>');
            list.dataset.lastDay = m.day;
        }
        list.insertAdjacentHTML('beforeend', bubbleHtml(m));
        return true;
    }

    // Gleicht den Feed mit der Liste ab: neue Blasen anhaengen, Lesehaken
    // vorhandener eigener Nachrichten aktualisieren. true = Neues kam dazu.
    function sync(list, messages) {
        let added = false;
        (messages || []).forEach(function (m) {
            const el = list.querySelector('[data-mid="' + cssId(m.id) + '"]');
            if (!el) { added = append(list, m) || added; return; }
            const own = (m.own !== undefined) ? m.own : !m.from_staff;
            if (own && m.read) {
                const t = el.querySelector('.d24c-ticks');
                if (t && !t.classList.contains('read')) { t.classList.add('read'); t.textContent = '✓✓'; t.title = L.read; }
            }
        });
        return added;
    }

    // Composer: Anhang-Vorschau, Enter-Senden (Desktop), AJAX-Versand mit
    // Fallback auf klassischen POST, wenn fetch scheitert.
    function wireComposer(o) {
        function scrollBottom() { o.scroller.scrollTop = o.scroller.scrollHeight; }
        function updateFiles() {
            const f = o.fileInput.files;
            if (!f || !f.length) { o.filesBar.hidden = true; o.filesBar.innerHTML = ''; return; }
            const names = Array.prototype.map.call(f, function (x) { return esc(x.name); }).join(', ');
            o.filesBar.innerHTML = '<span>📎 ' + names + '</span><button type="button" class="d24c-clear" aria-label="' + esc(L.clear) + '">✕</button>';
            o.filesBar.hidden = false;
            o.filesBar.querySelector('.d24c-clear').addEventListener('click', function () { o.fileInput.value = ''; updateFiles(); });
        }
        o.fileInput.addEventListener('change', updateFiles);
        o.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && window.matchMedia('(min-width:821px)').matches) {
                e.preventDefault(); o.form.requestSubmit();
            }
        });
        o.form.addEventListener('submit', function (e) {
            // Kanalwahl (z.B. Ticket-Antwort): klassischer POST mit Redirect,
            // damit die Timeline serverseitig neu aufgebaut wird.
            if (o.form.dataset.plain === '1') return;
            e.preventDefault();
            if (!o.input.value.trim()) return;
            const btn = o.form.querySelector('.d24c-send');
            btn.disabled = true;
            const fd = new FormData(o.form);
            fetch(o.form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': fd.get('_token') },
                body: fd
            }).then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
              .then(function (d) {
                  append(o.list, d.message);
                  o.input.value = ''; o.fileInput.value = ''; updateFiles(); scrollBottom();
                  if (o.onSent) o.onSent(d);
                  btn.disabled = false;
              })
              .catch(function () { btn.disabled = false; o.form.submit(); });
        });
        scrollBottom();
        return { scrollBottom: scrollBottom };
    }

    // Polling: alle N Sekunden Feed holen; markRead() sagt, ob der Chat
    // gerade sichtbar ist (dann Gegenseite-Nachrichten als gelesen markieren).
    function poll(o) {
        function tick() {
            if (document.hidden) return;
            const url = o.url + ((o.markRead && o.markRead()) ? '?mark_read=1' : '');
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
                .then(function (d) {
                    const added = sync(o.list, d.messages);
                    if (added) o.scroller.scrollTop = o.scroller.scrollHeight;
                    if (o.onData) o.onData(d);
                })
                .catch(function () {});
        }
        setInterval(tick, o.every || 10000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
        return { tick: tick };
    }

    return { esc: esc, append: append, sync: sync, wireComposer: wireComposer, poll: poll };
})();
</script>
