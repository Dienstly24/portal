{{--
    Wiederverwendbare Dokument-Vorschau (Beraterwelt + Kundenportal).

    Ermoeglicht das Ansehen von Dokumenten OHNE Download:
      * Ueberfahren eines Elements mit [data-preview-url]        -> Schnellvorschau (Quick-Look) neben dem Cursor
      * Klick auf ein Element mit [data-preview-open]            -> grosses Vorschau-Fenster (Modal) auf derselben Seite

    Erwartete Attribute am ausloesenden Element:
      data-preview-url      Inline-URL des Dokuments (z.B. ...download?view=1)
      data-preview-name     Dateiname (Anzeige)
      data-preview-kind     "pdf" | "image" | "other" (Standard: pdf)
      data-preview-download  (optional) URL fuer den Download-Button im Modal

    Einmal pro Seite einbinden: @include('partials.doc_preview')
--}}
@once
{{-- Schnellvorschau (Quick-Look): erscheint beim Ueberfahren, ohne Seitenwechsel. --}}
<div id="docpv-quicklook" class="docpv-ql" style="display:none;position:fixed;z-index:300;width:min(560px,46vw);height:min(70vh,70dvh);background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.28);overflow:hidden;">
    <div style="padding:7px 11px;font-size:12px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px;background:var(--canvas);">
        <span id="docpv-quicklook-name" style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
        <span style="color:var(--ink-soft);white-space:nowrap;">{{ __('Klick öffnet groß') }}</span>
    </div>
    <div id="docpv-quicklook-body" style="width:100%;height:calc(100% - 32px);background:#F7F5EF;"></div>
</div>

{{-- Vorschau-Fenster (Modal): grosse Ansicht auf derselben Seite, kein Download noetig. --}}
<div id="docpv-modal" class="docpv-modal" style="display:none;position:fixed;inset:0;z-index:400;background:rgba(0,0,0,.62);align-items:center;justify-content:center;padding:24px;">
    <div class="docpv-box" style="background:#fff;border-radius:14px;width:min(1100px,94vw);height:min(92vh,92dvh);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.4);">
        <div class="docpv-head" style="padding:11px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;background:var(--canvas);">
            <span id="docpv-modal-name" style="font-weight:700;font-size:14px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
            <a id="docpv-modal-tab" href="#" target="_blank" rel="noopener" class="btn btn-ghost docpv-hbtn" style="padding:6px 12px;font-size:12.5px;" title="{{ __('In neuem Tab öffnen') }}">↗ <span class="docpv-hlabel">{{ __('Neuer Tab') }}</span></a>
            <a id="docpv-modal-download" href="#" class="btn btn-ghost docpv-hbtn" style="padding:6px 12px;font-size:12.5px;" title="{{ __('Herunterladen') }}">⬇ <span class="docpv-hlabel">{{ __('Herunterladen') }}</span></a>
            <button type="button" data-h-click="737e8fe322" class="btn btn-ghost docpv-hbtn docpv-close" title="{{ __('Schließen') }} (Esc)" style="padding:6px 12px;font-size:16px;line-height:1;">✕</button>
        </div>
        <div id="docpv-modal-body" class="docpv-body" style="flex:1;min-height:0;background:#F7F5EF;display:flex;align-items:center;justify-content:center;overflow:auto;-webkit-overflow-scrolling:touch;"></div>
    </div>
</div>

<script @cspNonce>
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

    /*
     * ECHTES ZEIGEGERAET? (Responsive-Audit 12.09.2026)
     *
     * Die Schnellvorschau haengt an `mouseover`. Ein Telefon hat kein
     * Hover - es SIMULIERT es aber beim Antippen. Gemessen auf 390px:
     * ein Fingertipp liess nach 600 ms ein 179px schmales Fenster
     * (46vw) mit eingebettetem PDF aufgehen, das der Nutzer nie
     * angefordert hat, das den Inhalt verdeckt, das Dokument ein
     * ZWEITES Mal laedt und beim ersten Scrollen wieder verschwindet.
     * Direkt danach oeffnete der Klick ohnehin das grosse Fenster.
     *
     * `(hover: hover) and (pointer: fine)` ist die Frage nach dem
     * Geraet, nicht nach der Breite: ein schmales Fenster auf dem
     * Rechner behaelt die Schnellvorschau, ein 1024px breites Tablet
     * bekommt sie nicht.
     */
    var echterZeiger = window.matchMedia
        ? window.matchMedia('(hover: hover) and (pointer: fine)')
        : { matches: true };

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
            box.textContent = @json(__('Für diesen Dateityp ist keine Vorschau möglich – bitte herunterladen.'));
            return box;
        }
        var frame = document.createElement('iframe');
        frame.src = url;
        frame.title = @json(__('Vorschau'));
        frame.style.cssText = 'width:100%;height:100%;border:0;background:#F7F5EF;';

        /*
         * EHRLICH BLEIBEN (gleiche Haltung wie im Signatur-Modul).
         *
         * Ein PDF in einem <iframe> ist auf dem Rechner zuverlaessig,
         * auf dem Telefon NICHT: Android Chrome zeigt eingebettete PDFs
         * gar nicht an (leerer Rahmen), iOS Safari zeigt nur die erste
         * Seite und laesst nicht darin blaettern. Das ist Verhalten der
         * Browser, kein Fehler dieser Seite - mit CSS ist es nicht zu
         * beheben, und ich kann es in dieser Umgebung auch nicht
         * nachstellen (nur Chromium mit Geraete-Emulation).
         *
         * Deshalb wird der Rahmen auf Beruehrgeraeten nicht ENTFERNT
         * (auf iPadOS und vielen Android-Tablets funktioniert er) -
         * aber es steht IMMER ein sichtbarer Weg zum echten PDF
         * darueber. Ein leerer Rahmen ohne Ausweg sieht aus wie ein
         * kaputtes Portal; ein leerer Rahmen MIT Knopf ist eine
         * Unbequemlichkeit.
         */
        if (forModal && !echterZeiger.matches) {
            var wrap = document.createElement('div');
            wrap.style.cssText = 'width:100%;height:100%;display:flex;flex-direction:column;min-height:0;';
            var hint = document.createElement('div');
            hint.style.cssText = 'flex:none;display:flex;align-items:center;gap:10px;padding:10px 12px;'
                + 'background:var(--surface);border-bottom:1px solid var(--line);font-size:12.5px;color:var(--ink-soft);';
            var txt = document.createElement('span');
            txt.style.cssText = 'flex:1;min-width:0;';
            txt.textContent = @json(__('Zeigt das Telefon nichts an? Dann öffnen Sie das PDF direkt.'));
            var link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.className = 'btn btn-primary';
            link.style.cssText = 'flex:none;min-height:44px;';
            link.textContent = @json(__('PDF öffnen'));
            hint.appendChild(txt);
            hint.appendChild(link);
            frame.style.cssText = 'width:100%;flex:1;min-height:0;border:0;background:#F7F5EF;';
            wrap.appendChild(hint);
            wrap.appendChild(frame);
            return wrap;
        }
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
    // jeder Mausbewegung aufblitzt). NUR auf echten Zeigegeraeten - siehe
    // `echterZeiger` oben. Auf dem Telefon fuehrt der Klick direkt zum
    // grossen Fenster; es geht also keine Funktion verloren.
    if (echterZeiger.matches) {
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
    }

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

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["737e8fe322"] = function (event) { docPreview.close() };
</script>
@endPushOnce
