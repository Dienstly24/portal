@extends('signature._layout')
@section('kopftitel', Str::limit($signature->title, 44))

@section('inhalt')
@php
    $drawn = $fields->filter(fn ($f) => $f->isDrawn());
    $typed = $fields->reject(fn ($f) => $f->isDrawn());
    // Feldpositionen fuer die Markierungen auf den Seitenbildern.
    $feldDaten = $fields->map(fn ($f) => [
        'id' => $f->id, 'page' => $f->page, 'x' => (float) $f->pos_x, 'y' => (float) $f->pos_y,
        'width' => (float) $f->width, 'height' => (float) $f->height,
        'label' => $f->label ?: $f->typeLabel(), 'drawn' => $f->isDrawn(),
    ])->values();
@endphp

<div class="karte">
    <h1>{{ $signature->title }}</h1>
    <p class="lead">
        Guten Tag {{ $signer->name }}, bitte prüfen Sie das Dokument und unterschreiben Sie unten.
        Es hat {{ $signature->page_count }} Seite{{ $signature->page_count === 1 ? '' : 'n' }};
        für Sie sind {{ $fields->count() }} Feld{{ $fields->count() === 1 ? '' : 'er' }} vorgesehen.
    </p>
    @if($signature->expires_at)
    <p class="lead" style="margin-top:8px;">Gültig bis {{ $signature->expires_at->lokal()->format('d.m.Y') }}.</p>
    @endif
    <p style="margin-top:12px;">
        <a href="{{ route('signature.document', $token) }}" target="_blank" rel="noopener">Original-PDF öffnen</a>
    </p>
</div>

@if(!$previewAvailable)
<div class="hinweis hinweis-warn">
    Die Seitenansicht ist gerade nicht verfügbar. Bitte öffnen Sie das
    <a href="{{ route('signature.document', $token) }}" target="_blank" rel="noopener">Original-PDF</a>
    und unterschreiben Sie anschließend unten.
</div>
@else
<div id="dokument">
    @for($page = 1; $page <= $signature->page_count; $page++)
    <div class="seite" data-seite="{{ $page }}">
        {{-- loading="lazy": ein 30-seitiges Dokument soll auf dem Telefon
             nicht 30 Bilder auf einmal laden. --}}
        <img src="{{ route('signature.page', [$token, $page]) }}" alt="Seite {{ $page }} von {{ $signature->page_count }}"
             loading="lazy" width="1400" height="1980">
    </div>
    @endfor
</div>
@endif

<form method="POST" action="{{ route('signature.sign', $token) }}" id="unterschrift-formular">
    @csrf

    @foreach($typed as $field)
    <div class="karte">
        <div class="feld" style="margin-bottom:0;">
            <label for="feld-{{ $field->id }}">
                {{ $field->label ?: $field->typeLabel() }}
                @if($field->required)<span style="color:#B3261E;">*</span>@endif
                <span style="font-weight:400;color:var(--ink-soft);"> · Seite {{ $field->page }}</span>
            </label>
            @if($field->type === 'kreuz')
                <label style="display:flex;gap:10px;align-items:center;font-weight:400;">
                    <input type="checkbox" name="felder[{{ $field->id }}]" value="ja" style="width:22px;height:22px;">
                    <span>{{ $field->label ?: 'Hiermit bestätige ich diesen Punkt' }}</span>
                </label>
            @elseif($field->type === 'datum')
                <input id="feld-{{ $field->id }}" type="text" name="felder[{{ $field->id }}]"
                       value="{{ old('felder.'.$field->id, now()->lokal()->format('d.m.Y')) }}" maxlength="40">
            @elseif($field->type === 'name')
                <input id="feld-{{ $field->id }}" type="text" name="felder[{{ $field->id }}]"
                       value="{{ old('felder.'.$field->id, $signer->name) }}" maxlength="160">
            @else
                <input id="feld-{{ $field->id }}" type="text" name="felder[{{ $field->id }}]"
                       value="{{ old('felder.'.$field->id) }}" maxlength="500">
            @endif
        </div>
    </div>
    @endforeach

    @foreach($drawn as $field)
    <div class="karte">
        <h2>{{ $field->label ?: $field->typeLabel() }}@if($field->required)<span style="color:#B3261E;"> *</span>@endif</h2>
        <p class="lead" style="margin-bottom:10px;">
            Seite {{ $field->page }} · Mit dem Finger, dem Stift oder der Maus in das Feld zeichnen.
        </p>
        <canvas class="zeichenflaeche" data-unterschrift="{{ $field->id }}"
                aria-label="Zeichenfläche für {{ $field->label ?: $field->typeLabel() }}"></canvas>
        <input type="hidden" name="felder[{{ $field->id }}]" id="daten-{{ $field->id }}">
        <div style="display:flex;gap:10px;margin-top:10px;align-items:center;">
            <button type="button" class="knopf knopf-still" style="width:auto;"
                    data-h-click="sigLeeren" data-ziel="{{ $field->id }}">Neu zeichnen</button>
            <span class="fortschritt" id="status-{{ $field->id }}">noch leer</span>
        </div>
    </div>
    @endforeach

    <div class="karte">
        <h2>Hinweis zur elektronischen Unterschrift</h2>
        <p class="lead">{{ $signature->consent_text }}</p>
        <label style="display:flex;gap:10px;align-items:flex-start;margin-top:14px;font-size:14px;">
            <input type="checkbox" name="zustimmung" value="1" required style="width:22px;height:22px;margin-top:2px;flex:none;">
            <span>Ich habe das Dokument gelesen und unterschreibe es elektronisch.</span>
        </label>
    </div>
</form>

<div class="karte">
    <h2>Nicht unterschreiben?</h2>
    <p class="lead">Wenn Sie das Dokument nicht unterschreiben möchten, teilen Sie uns das bitte hier mit.</p>
    <form method="POST" action="{{ route('signature.decline', $token) }}" style="margin-top:12px;"
          data-confirm="Möchten Sie die Unterschrift wirklich ablehnen? Der Vorgang wird damit beendet.">
        @csrf
        <div class="feld">
            <label for="grund">Grund (optional)</label>
            <input id="grund" type="text" name="grund" maxlength="500">
        </div>
        <button type="submit" class="knopf knopf-still">Unterschrift ablehnen</button>
    </form>
</div>
@endsection

@section('fussleiste')
<div class="fussleiste">
    <div class="innen">
        <span class="fortschritt" id="gesamt-status"></span>
        <button type="submit" form="unterschrift-formular" class="knopf" id="absenden">Unterschrift bestätigen</button>
    </div>
</div>
@endsection

@push('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
(function () {
    "use strict";

    // Zeichenflaechen. Pointer-Ereignisse decken Finger, Stift und Maus mit
    // EINEM Weg ab - getrennte touch-/mouse-Behandlung laeuft erfahrungs-
    // gemaess auseinander, und ausgerechnet das Telefon ist hier der
    // Hauptfall.
    var flaechen = document.querySelectorAll('[data-unterschrift]');
    var zustand = {};

    flaechen.forEach(function (canvas) {
        var id = canvas.getAttribute('data-unterschrift');
        zustand[id] = { gezeichnet: false, canvas: canvas };

        function skalieren() {
            // Auf hochaufloesenden Bildschirmen sonst grob verpixelt - und
            // die Unterschrift landet spaeter im PDF.
            var verhaeltnis = Math.min(window.devicePixelRatio || 1, 3);
            var rect = canvas.getBoundingClientRect();
            var daten = zustand[id].gezeichnet ? canvas.toDataURL('image/png') : null;
            canvas.width = Math.round(rect.width * verhaeltnis);
            canvas.height = Math.round(rect.height * verhaeltnis);
            var ctx = canvas.getContext('2d');
            ctx.scale(verhaeltnis, verhaeltnis);
            ctx.lineWidth = 2.4;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#101828';
            zustand[id].ctx = ctx;
            if (daten) {
                var bild = new Image();
                bild.onload = function () { ctx.drawImage(bild, 0, 0, rect.width, rect.height); };
                bild.src = daten;
            }
        }
        skalieren();
        window.addEventListener('resize', skalieren);

        var zeichnet = false;
        function punkt(event) {
            var rect = canvas.getBoundingClientRect();
            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        }
        canvas.addEventListener('pointerdown', function (event) {
            zeichnet = true;
            canvas.setPointerCapture(event.pointerId);
            var p = punkt(event);
            zustand[id].ctx.beginPath();
            zustand[id].ctx.moveTo(p.x, p.y);
            event.preventDefault();
        });
        canvas.addEventListener('pointermove', function (event) {
            if (!zeichnet) { return; }
            var p = punkt(event);
            zustand[id].ctx.lineTo(p.x, p.y);
            zustand[id].ctx.stroke();
            zustand[id].gezeichnet = true;
            event.preventDefault();
        });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (typ) {
            canvas.addEventListener(typ, function () {
                if (!zeichnet) { return; }
                zeichnet = false;
                uebernehmen(id);
            });
        });
    });

    function uebernehmen(id) {
        var feld = document.getElementById('daten-' + id);
        var status = document.getElementById('status-' + id);
        if (!zustand[id].gezeichnet) { return; }
        feld.value = zustand[id].canvas.toDataURL('image/png');
        if (status) { status.textContent = 'unterschrieben'; }
        gesamtStand();
        markiereFelder();
    }

    window.__h["sigLeeren"] = function () {
        var id = this.getAttribute('data-ziel');
        var eintrag = zustand[id];
        if (!eintrag) { return; }
        eintrag.ctx.clearRect(0, 0, eintrag.canvas.width, eintrag.canvas.height);
        eintrag.gezeichnet = false;
        document.getElementById('daten-' + id).value = '';
        var status = document.getElementById('status-' + id);
        if (status) { status.textContent = 'noch leer'; }
        gesamtStand();
        markiereFelder();
    };

    function gesamtStand() {
        var offen = 0;
        Object.keys(zustand).forEach(function (id) { if (!zustand[id].gezeichnet) { offen++; } });
        var anzeige = document.getElementById('gesamt-status');
        var gesamt = Object.keys(zustand).length;
        if (!anzeige) { return; }
        anzeige.textContent = gesamt === 0 ? '' : (gesamt - offen) + ' von ' + gesamt + ' unterschrieben';
    }

    // Die Felder auf den Seitenbildern zeigen, WO im Dokument unterschrieben
    // wird. Sie sind anteilig gespeichert und liegen deshalb auf jedem
    // Bildschirm an derselben Stelle des Dokuments.
    var felder = @json($feldDaten);

    function markiereFelder() {
        var alte = document.querySelectorAll('.feldmarke');
        for (var i = 0; i < alte.length; i++) { alte[i].remove(); }
        felder.forEach(function (feld) {
            var seite = document.querySelector('[data-seite="' + feld.page + '"]');
            if (!seite) { return; }
            var marke = document.createElement('div');
            marke.className = 'feldmarke';
            if (feld.drawn && zustand[feld.id] && zustand[feld.id].gezeichnet) {
                marke.className += ' fertig';
            }
            marke.style.left = (feld.x * 100) + '%';
            marke.style.top = (feld.y * 100) + '%';
            marke.style.width = (feld.width * 100) + '%';
            marke.style.height = (feld.height * 100) + '%';
            marke.textContent = feld.label;
            marke.addEventListener('click', function () {
                var ziel = document.getElementById(feld.drawn ? 'daten-' + feld.id : 'feld-' + feld.id);
                var karte = ziel ? ziel.closest('.karte') : null;
                if (karte) { karte.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            });
            seite.appendChild(marke);
        });
    }

    markiereFelder();
    gesamtStand();

    // Vor dem Absenden pruefen, ob eine Pflicht-Unterschrift fehlt. Die
    // eigentliche Pruefung macht der Server (dem Browser wird nichts
    // geglaubt) - aber der Unterzeichner soll es VOR dem Klick erfahren.
    var formular = document.getElementById('unterschrift-formular');
    formular.addEventListener('submit', function (event) {
        var fehlt = Object.keys(zustand).some(function (id) { return !zustand[id].gezeichnet; });
        if (fehlt) {
            event.preventDefault();
            alert('Bitte zeichnen Sie Ihre Unterschrift in das dafür vorgesehene Feld.');
            return;
        }
        document.getElementById('absenden').disabled = true;
        document.getElementById('absenden').textContent = 'Wird gespeichert …';
    });
})();
</script>
@endpush
