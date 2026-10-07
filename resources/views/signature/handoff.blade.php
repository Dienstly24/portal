@extends('signature._layout')
@section('kopftitel', 'Auf dem Handy unterschreiben')
@section('inhalt')
@if($zustand === 'tot')
    <div class="karte">
        <h1>Link abgelaufen</h1>
        <p class="lead">Dieser Link ist abgelaufen oder wurde schon benutzt. Bitte am Rechner einen neuen QR-Code erzeugen.</p>
    </div>
@else
    <div class="karte" id="handyKarte">
        <h1>Unterschreiben</h1>
        <p class="lead" style="margin-bottom:12px;">Mit dem Finger oder Stift unterschreiben. Die Zeichnung erscheint danach am Rechner - gespeichert oder gesetzt wird sie erst dort, nach Ihrer Bestätigung.</p>
        <canvas id="handyPad" style="width:100%;height:clamp(200px,38vh,320px);background:#fff;border:2px dashed var(--line);border-radius:12px;display:block;"
                aria-label="Unterschriftsfeld"></canvas>
        <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;">
            <button type="button" class="knopf knopf-still" style="width:auto;" id="handyUndo">↶ Rückgängig</button>
            <button type="button" class="knopf knopf-still" style="width:auto;" id="handyLeeren">Löschen</button>
        </div>
        <button type="button" class="knopf" style="margin-top:14px;" id="handySenden" disabled>An den Rechner übertragen</button>
        <p class="lead" id="handyMeldung" style="margin-top:10px;" role="status"></p>
        <p class="lead" style="margin-top:6px;font-size:12px;">Gültig bis {{ $bis->lokal()->format('H:i') }} Uhr, nur einmal nutzbar.</p>
    </div>
    @push('cspScripts')
    <script src="/js/unterschrift-pad.js" @cspNonce></script>
    <script @cspNonce>
    (function () {
        var knopf = document.getElementById('handySenden');
        var meldung = document.getElementById('handyMeldung');
        var pad = window.UnterschriftPad(document.getElementById('handyPad'), {
            onChange: function (p) { knopf.disabled = p.leer(); }
        });
        document.getElementById('handyUndo').addEventListener('click', function () { pad.rueckgaengig(); });
        document.getElementById('handyLeeren').addEventListener('click', function () { pad.loeschen(); });
        knopf.addEventListener('click', function () {
            knopf.disabled = true;
            var body = new FormData();
            body.append('zeichnung', pad.dataUrl());
            body.append('_token', @json(csrf_token()));
            fetch(@json(route('signature.handoff.store', $token)), { method: 'POST', body: body, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    meldung.textContent = j.message || '';
                    if (!j.ok) { knopf.disabled = false; return; }
                    document.getElementById('handyPad').style.opacity = '.4';
                })
                .catch(function () { meldung.textContent = 'Übertragung fehlgeschlagen. Bitte erneut versuchen.'; knopf.disabled = false; });
        });
    })();
    </script>
    @endpush
@endif
@endsection
