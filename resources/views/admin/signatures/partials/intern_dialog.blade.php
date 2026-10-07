{{-- Der Dialog "Intern unterschreiben" (Teil B, 07.10.2026) - im Editor und
     auf der Detailseite derselbe. Der Bestaetigungssatz steht IMMER da, auch
     wenn das Zwei-Faktor-Zeitfenster noch gilt: das Fenster spart den Code,
     nie die Bestaetigung. --}}
@if($intern['darf'])
<div id="intern-dialog" hidden
     style="position:fixed;inset:0;z-index:85;background:rgba(10,18,14,.45);display:flex;align-items:center;justify-content:center;padding:12px;">
    <div style="background:var(--surface);border-radius:12px;max-width:560px;width:100%;max-height:92dvh;overflow:auto;padding:18px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="font-weight:700;font-size:16px;margin-bottom:10px;">Intern unterschreiben</div>

        <div role="radiogroup" aria-label="Welche Unterschrift?" style="display:grid;gap:6px;margin-bottom:10px;">
            @if($intern['hat_unterschrift'])
            <label style="display:flex;gap:8px;align-items:center;font-size:13.5px;">
                <input type="radio" name="intern-quelle" value="gespeichert" checked> Gespeicherte Unterschrift verwenden
            </label>
            @endif
            <label style="display:flex;gap:8px;align-items:center;font-size:13.5px;">
                <input type="radio" name="intern-quelle" value="neu" @checked(!$intern['hat_unterschrift'])> Jetzt neu zeichnen
            </label>
            <label style="display:flex;gap:8px;align-items:center;font-size:13.5px;">
                <input type="radio" name="intern-quelle" value="handy"> Auf dem Handy zeichnen (QR-Code)
            </label>
        </div>

        <div data-intern-teil="gespeichert" @if(!$intern['hat_unterschrift']) hidden @endif
             style="background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px 14px;">
            @if($intern['bild'])<img src="{{ $intern['bild'] }}" alt="Ihre hinterlegte Unterschrift" style="max-height:70px;max-width:100%;">@endif
            <div style="border-top:1px solid var(--ink);margin-top:4px;"></div>
            <div class="muted-sm">{{ $intern['name'] }}, {{ $intern['funktion'] }}</div>
        </div>

        <div data-intern-teil="neu" @if($intern['hat_unterschrift']) hidden @endif>
            <canvas id="intern-pad" style="width:100%;height:clamp(160px,30vh,240px);background:#fff;border:2px dashed var(--line);border-radius:12px;display:block;" aria-label="Unterschriftsfeld"></canvas>
            <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;align-items:center;">
                <button type="button" class="btn btn-ghost btn-sm" id="intern-undo">↶ Rückgängig</button>
                <button type="button" class="btn btn-ghost btn-sm" id="intern-leeren">Löschen</button>
                <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;">
                    <input type="checkbox" id="intern-standard" @checked(!$intern['hat_unterschrift'])> Als Standard speichern
                </label>
            </div>
            <div class="muted-sm" style="margin-top:4px;">Ohne Haken gilt die Zeichnung nur für dieses Dokument.</div>
        </div>

        <div data-intern-teil="handy" hidden>
            <button type="button" class="btn btn-ghost btn-sm" id="intern-qr-neu">QR-Code erzeugen</button>
            <div id="intern-qr" style="margin-top:8px;"></div>
            <img id="intern-handy-bild" alt="Unterschrift vom Handy" hidden style="max-height:80px;max-width:100%;background:#fff;border:1px solid var(--line);border-radius:8px;margin-top:6px;">
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;margin-top:6px;">
                <input type="checkbox" id="intern-handy-standard"> Als Standard speichern
            </label>
        </div>

        <label style="display:flex;gap:9px;align-items:flex-start;font-size:14px;font-weight:600;margin-top:14px;background:var(--emerald-soft);padding:10px 12px;border-radius:10px;">
            <input type="checkbox" id="intern-bestaetigt" style="margin-top:3px;"> <span>{{ $intern['bestaetigung'] }}</span>
        </label>

        <div id="intern-code-box" @if($intern['freigabe']) hidden @endif style="margin-top:10px;">
            <label for="intern-code" style="font-size:13px;font-weight:600;">Code aus Ihrer Authenticator-App</label>
            <input id="intern-code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" style="max-width:160px;display:block;margin-top:4px;">
            <div class="muted-sm">Danach gilt die Bestätigung {{ \App\Services\Signature\InterneFreigabe::stunden() }} Stunden auf diesem Gerät.</div>
        </div>

        <div id="intern-meldung" role="status" style="margin-top:10px;font-size:13px;color:#B3261E;"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap;">
            <button type="button" class="btn btn-ghost btn-sm" id="intern-abbrechen">Abbrechen</button>
            <button type="button" class="btn btn-emerald btn-sm" id="intern-los" disabled>Unterschreiben</button>
        </div>
    </div>
</div>

@pushOnce('cspScripts')
<script src="/js/unterschrift-pad.js" @cspNonce></script>
<script @cspNonce>
(function () {
    "use strict";
    var $ = function (id) { return document.getElementById(id); };
    var dialog = $('intern-dialog');
    if (!dialog) { return; }
    var urls = {
        handy: @json(route('admin.meine_unterschrift.handoff')),
        status: @json(route('admin.meine_unterschrift.handoff.status', '__ID__'))
    };
    var pad = null, auftrag = null, handoffId = null, handyFertig = false, abfrage = null;

    function quelle() {
        var r = document.querySelector('input[name="intern-quelle"]:checked');
        return r ? r.value : 'neu';
    }
    function pruefen() {
        var q = quelle();
        var bild = q === 'gespeichert' || (q === 'neu' && pad && !pad.leer()) || (q === 'handy' && handyFertig);
        $('intern-los').disabled = !(bild && $('intern-bestaetigt').checked);
    }
    function zeigeQuelle() {
        var q = quelle();
        document.querySelectorAll('[data-intern-teil]').forEach(function (t) { t.hidden = t.getAttribute('data-intern-teil') !== q; });
        if (q === 'neu') {
            if (!pad) { pad = window.UnterschriftPad($('intern-pad'), { onChange: pruefen }); } else { pad.groesse(); }
        }
        pruefen();
    }
    document.querySelectorAll('input[name="intern-quelle"]').forEach(function (r) { r.addEventListener('change', zeigeQuelle); });
    $('intern-bestaetigt').addEventListener('change', pruefen);
    $('intern-undo').addEventListener('click', function () { pad && pad.rueckgaengig(); });
    $('intern-leeren').addEventListener('click', function () { pad && pad.loeschen(); });
    $('intern-abbrechen').addEventListener('click', function () { dialog.hidden = true; if (abfrage) { clearInterval(abfrage); } });

    $('intern-qr-neu').addEventListener('click', function () {
        fetch(urls.handy, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { $('intern-meldung').textContent = j.message || 'QR-Code konnte nicht erzeugt werden.'; return; }
                handoffId = j.id; handyFertig = false; $('intern-handy-bild').hidden = true; pruefen();
                $('intern-qr').innerHTML = j.qr;
                if (abfrage) { clearInterval(abfrage); }
                var bis = Date.parse(j.gueltig_bis);
                abfrage = setInterval(function () {
                    if (Date.now() > bis + 60000) { clearInterval(abfrage); $('intern-meldung').textContent = 'Der QR-Code ist abgelaufen. Bitte neu erzeugen.'; return; }
                    fetch(urls.status.replace('__ID__', handoffId), { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (s) {
                            if (!s.fertig) { return; }
                            clearInterval(abfrage);
                            handyFertig = true;
                            $('intern-qr').textContent = '';
                            $('intern-handy-bild').src = s.bild; $('intern-handy-bild').hidden = false;
                            pruefen();
                        });
                }, 2000);
            });
    });

    $('intern-los').addEventListener('click', function () {
        var knopf = this;
        knopf.disabled = true;
        $('intern-meldung').textContent = '';
        var q = quelle();
        var daten = Object.assign({}, auftrag.payload || {}, {
            quelle: q,
            zeichnung: q === 'neu' ? pad.dataUrl() : null,
            handoff_id: q === 'handy' ? handoffId : null,
            als_standard: q === 'neu' ? ($('intern-standard').checked ? 1 : 0) : (q === 'handy' ? ($('intern-handy-standard').checked ? 1 : 0) : 0),
            bestaetigt: $('intern-bestaetigt').checked ? 1 : 0,
            code: $('intern-code').value || null,
            und_senden: auftrag.undSenden ? 1 : 0
        });
        fetch(auftrag.ziel, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                       'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify(daten)
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
              if (res.ok && res.j.ok) {
                  if (window.sigEditorSauber) { window.sigEditorSauber(); }
                  window.location.href = res.j.redirect || window.location.href;
                  return;
              }
              if (res.j.code_noetig) { $('intern-code-box').hidden = false; $('intern-code').focus(); }
              $('intern-meldung').textContent = res.j.message || 'Die Unterschrift konnte nicht gesetzt werden.';
              if (res.j.redirect && !res.j.code_noetig) { setTimeout(function () { window.location.href = res.j.redirect; }, 2500); }
              pruefen();
          })
          .catch(function () { $('intern-meldung').textContent = 'Netzwerkfehler - bitte erneut versuchen.'; pruefen(); });
    });

    window.sigInternDialog = {
        oeffnen: function (a) {
            auftrag = a;
            $('intern-bestaetigt').checked = false;
            $('intern-meldung').textContent = '';
            $('intern-los').textContent = a.undSenden ? 'Unterschreiben und senden' : 'Unterschreiben';
            dialog.hidden = false;
            zeigeQuelle();
        }
    };
})();
</script>
@endPushOnce
@endif
