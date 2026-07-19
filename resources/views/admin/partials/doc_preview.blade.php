{{--
    Wiederverwendbare Dokument-Vorschau fuer die Beraterwelt.

    Ermoeglicht das Ansehen von Dokumenten OHNE Download:
      * Ueberfahren eines Elements mit [data-preview-url]        -> Schnellvorschau (Quick-Look) neben dem Cursor
      * Klick auf ein Element mit [data-preview-open]            -> grosses Vorschau-Fenster (Modal) auf derselben Seite

    Erwartete Attribute am ausloesenden Element:
      data-preview-url      Inline-URL des Dokuments (z.B. ...download?view=1)
      data-preview-name     Dateiname (Anzeige)
      data-preview-kind     "pdf" | "image" | "other" (Standard: pdf)
      data-preview-download  (optional) URL fuer den Download-Button im Modal

    Einmal pro Seite einbinden: @include('admin.partials.doc_preview')
--}}
@once
{{-- Schnellvorschau (Quick-Look): erscheint beim Ueberfahren, ohne Seitenwechsel. --}}
<div id="docpv-quicklook" style="display:none;position:fixed;z-index:300;width:min(560px,46vw);height:70vh;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.28);overflow:hidden;">
    <div style="padding:7px 11px;font-size:12px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px;background:var(--canvas);">
        <span id="docpv-quicklook-name" style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
        <span style="color:var(--ink-soft);white-space:nowrap;">Klick öffnet groß</span>
    </div>
    <div id="docpv-quicklook-body" style="width:100%;height:calc(100% - 32px);background:#f4f5f7;"></div>
</div>

{{-- Vorschau-Fenster (Modal): grosse Ansicht auf derselben Seite, kein Download noetig. --}}
<div id="docpv-modal" style="display:none;position:fixed;inset:0;z-index:400;background:rgba(0,0,0,.62);align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:14px;width:min(1100px,94vw);height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.4);">
        <div style="padding:11px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;background:var(--canvas);">
            <span id="docpv-modal-name" style="font-weight:700;font-size:14px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
            <a id="docpv-modal-tab" href="#" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" title="In neuem Tab öffnen">↗ Neuer Tab</a>
            <a id="docpv-modal-download" href="#" class="btn btn-ghost btn-sm" title="Herunterladen">⬇ Herunterladen</a>
            <button type="button" onclick="docPreview.close()" class="btn btn-ghost btn-sm" title="Schließen (Esc)" style="font-size:16px;line-height:1;">✕</button>
        </div>
        <div id="docpv-modal-body" style="flex:1;min-height:0;background:#f4f5f7;display:flex;align-items:center;justify-content:center;overflow:auto;"></div>
    </div>
</div>

<script>
window.docPreview = (function () {
    var ql = document.getElementById('docpv-quicklook');
    var qlName = document.getElementById('docpv-quicklook-name');
    var qlBody = document.getElementById('docpv-quicklook-body');
    var modal = document.getElementById('docpv-modal');
    var mName = document.getElementById('docpv-modal-name');
    var mBody = document.getElementById('docpv-modal-body');
    var mTab = document.getElementById('docpv-modal-tab');
    var mDl = document.getElementById('docpv-modal-download');
    var showTimer = null, hideTimer = null;

    // Baut den passenden Vorschau-Knoten (PDF -> iframe, Bild -> img,
    // sonst -> Hinweis mit Download-Empfehlung).
    function buildViewer(url, kind, forModal) {
        if (kind === 'image') {
            var img = document.createElement('img');
            img.src = url;
            img.alt = '';
            img.style.cssText = forModal
                ? 'max-width:100%;max-height:100%;object-fit:contain;'
                : 'width:100%;height:100%;object-fit:contain;';
            return img;
        }
        if (kind === 'other') {
            var box = document.createElement('div');
            box.style.cssText = 'padding:24px;text-align:center;color:var(--ink-soft);font-size:13.5px;';
            box.textContent = 'Für diesen Dateityp ist keine Vorschau möglich – bitte herunterladen.';
            return box;
        }
        var frame = document.createElement('iframe');
        frame.src = url;
        frame.title = 'Vorschau';
        frame.style.cssText = 'width:100%;height:100%;border:0;background:#f4f5f7;';
        return frame;
    }

    function placeQuicklook(rect) {
        var w = ql.offsetWidth, h = ql.offsetHeight;
        var top = Math.min(Math.max(10, rect.top), window.innerHeight - h - 10);
        var left = rect.right + 14;
        if (left + w > window.innerWidth - 10) { left = Math.max(10, rect.left - w - 14); }
        ql.style.top = top + 'px';
        ql.style.left = left + 'px';
    }

    function showQuicklook(target) {
        var url = target.getAttribute('data-preview-url');
        if (!url) return;
        var kind = target.getAttribute('data-preview-kind') || 'pdf';
        qlName.textContent = target.getAttribute('data-preview-name') || '';
        if (qlBody.getAttribute('data-url') !== url) {
            qlBody.innerHTML = '';
            qlBody.appendChild(buildViewer(url, kind, false));
            qlBody.setAttribute('data-url', url);
        }
        ql.style.display = 'block';
        placeQuicklook(target.getBoundingClientRect());
    }

    function hideQuicklook() { ql.style.display = 'none'; }

    function openModal(target) {
        var url = target.getAttribute('data-preview-url');
        if (!url) return;
        var kind = target.getAttribute('data-preview-kind') || 'pdf';
        var dl = target.getAttribute('data-preview-download');
        hideQuicklook();
        mName.textContent = target.getAttribute('data-preview-name') || '';
        mTab.href = url;
        if (dl) { mDl.href = dl; mDl.style.display = ''; } else { mDl.style.display = 'none'; }
        mBody.innerHTML = '';
        mBody.appendChild(buildViewer(url, kind, true));
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
        mBody.innerHTML = ''; // Iframe entladen (Speicher / Autoplay stoppen)
    }

    // Hover -> Schnellvorschau (mit kurzer Verzoegerung, damit sie nicht bei
    // jeder Mausbewegung aufblitzt).
    document.addEventListener('mouseover', function (e) {
        var t = e.target.closest ? e.target.closest('[data-preview-url]') : null;
        if (!t) return;
        clearTimeout(hideTimer);
        clearTimeout(showTimer);
        showTimer = setTimeout(function () { showQuicklook(t); }, 600);
    });
    document.addEventListener('mouseout', function (e) {
        var t = e.target.closest ? e.target.closest('[data-preview-url]') : null;
        if (!t) return;
        clearTimeout(showTimer);
        hideTimer = setTimeout(hideQuicklook, 250);
    });
    ql.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
    ql.addEventListener('mouseleave', hideQuicklook);
    window.addEventListener('scroll', hideQuicklook, true);

    // Klick auf ein Vorschau-Element -> grosses Modal (kein Seitenwechsel).
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-preview-open]') : null;
        if (!t) return;
        e.preventDefault();
        openModal(t);
    });
    // Klick auf den dunklen Hintergrund oder Esc schliesst das Modal.
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
    });

    return { open: openModal, close: closeModal };
})();
</script>
@endonce
