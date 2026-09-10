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
    // Texte fuer das JavaScript. BEWUSST hier und nicht als mehrzeiliges
    // @json im Skript: Blade zerbricht an einem ueber mehrere Zeilen
    // gehenden Array-Literal in einer Direktive ("Unclosed '['").
    $jsTexte = [
        'fehlt' => __('signing.missing_signature'),
        'speichert' => __('signing.saving'),
        'absenden' => __('signing.sign_document'),
        'unterschrieben' => __('signing.signed_mark'),
        'leer' => __('signing.empty'),
        'fortschritt' => __('signing.progress', ['done' => ':done', 'total' => ':total']),
    ];
@endphp

<div class="karte">
    <h1>{{ $signature->title }}</h1>
    <p class="lead">
        {{ __('signing.intro', ['name' => $signer->name, 'pages' => $signature->page_count, 'fields' => $fields->count()]) }}
    </p>
    @if($signature->expires_at)
    <p class="lead" style="margin-top:8px;">{{ __('signing.valid_until', ['date' => $signature->expires_at->lokal()->format('d.m.Y')]) }}</p>
    @endif
    <p style="margin-top:12px;">
        <a href="{{ route('signature.document', $token) }}" target="_blank" rel="noopener">{{ __('signing.open_original') }}</a>
    </p>
</div>

@if(!$previewAvailable)
<div class="hinweis hinweis-warn">
    {{ __('signing.preview_missing') }}
    <a href="{{ route('signature.document', $token) }}" target="_blank" rel="noopener">{{ __('signing.open_original') }}</a>
</div>
@else
<div id="dokument">
    @for($page = 1; $page <= $signature->page_count; $page++)
    <div class="seite" data-seite="{{ $page }}">
        {{-- loading="lazy": ein 30-seitiges Dokument soll auf dem Telefon
             nicht 30 Bilder auf einmal laden. --}}
        <img src="{{ route('signature.page', [$token, $page]) }}" alt="{{ __('signing.page_of', ['page' => $page, 'total' => $signature->page_count]) }}"
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
                <span style="font-weight:400;color:var(--ink-soft);"> · {{ __('signing.page_n', ['page' => $field->page]) }}</span>
            </label>
            @if($field->type === 'kreuz')
                <label style="display:flex;gap:10px;align-items:center;font-weight:400;">
                    <input type="checkbox" name="felder[{{ $field->id }}]" value="ja" style="width:22px;height:22px;">
                    <span>{{ $field->label ?: __('signing.checkbox_default') }}</span>
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
            {{ __('signing.draw_hint', ['page' => $field->page]) }}
        </p>
        <canvas class="zeichenflaeche" data-unterschrift="{{ $field->id }}"
                aria-label="{{ __('signing.canvas_label', ['field' => $field->label ?: $field->typeLabel()]) }}"></canvas>
        <input type="hidden" name="felder[{{ $field->id }}]" id="daten-{{ $field->id }}">
        <div style="display:flex;gap:10px;margin-top:10px;align-items:center;">
            <button type="button" class="knopf knopf-still" style="width:auto;"
                    data-h-click="sigLeeren" data-ziel="{{ $field->id }}">{{ __('signing.redraw') }}</button>
            <span class="fortschritt" id="status-{{ $field->id }}">{{ __('signing.empty') }}</span>
        </div>
    </div>
    @endforeach

    <div class="karte">
        <h2>{{ __('signing.consent_heading') }}</h2>
        <p class="lead">{{ $signature->consentTextFor($signer) }}</p>
        <label style="display:flex;gap:10px;align-items:flex-start;margin-top:14px;font-size:14px;">
            <input type="checkbox" name="zustimmung" value="1" required style="width:22px;height:22px;margin-top:2px;flex:none;">
            <span>{{ __('signing.consent_checkbox') }}</span>
        </label>
    </div>
</form>

<div class="karte">
    <h2>{{ __('signing.decline_heading') }}</h2>
    <p class="lead">{{ __('signing.decline_lead') }}</p>
    <form method="POST" action="{{ route('signature.decline', $token) }}" style="margin-top:12px;"
          data-confirm="{{ __('signing.decline_confirm_long') }}">
        @csrf
        <div class="feld">
            <label for="grund">{{ __('signing.decline_reason') }}</label>
            <input id="grund" type="text" name="grund" maxlength="500">
        </div>
        <button type="submit" class="knopf knopf-still">{{ __('signing.decline') }}</button>
    </form>
</div>
@endsection

@section('fussleiste')
<div class="fussleiste">
    <div class="innen">
        <span class="fortschritt" id="gesamt-status"></span>
        <button type="submit" form="unterschrift-formular" class="knopf" id="absenden">{{ __('signing.sign_document') }}</button>
    </div>
</div>
@endsection

@push('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
(function () {
    "use strict";

    // Die Texte kommen aus derselben Uebersetzungsdatei wie die Seite - ein
    // deutscher Satz aus einem JavaScript heraus waere sonst genau die
    // Mischsprache, die den Unterzeichner glauben laesst, die Seite sei
    // kaputt.
    var texte = @json($jsTexte);

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
        merken(id, feld.value);
        if (status) { status.textContent = texte.unterschrieben; }
        gesamtStand();
        markiereFelder();
    }

    // DIE HANDSCHRIFT DARF NICHT VERLOREN GEHEN. Meldet der Server einen
    // Fehler (fehlendes Pflichtfeld, Stoerung), laedt die Seite neu - und
    // eine Zeichenflaeche ist danach leer. Wer gerade muehsam mit dem Finger
    // unterschrieben hat, muss dann von vorn anfangen und haelt das zu Recht
    // fuer einen Defekt. Die Zeichnung bleibt deshalb im Browser (nur diesem
    // Reiter, nur bis er geschlossen wird) - sie wird bewusst NICHT ueber die
    // Sitzung zurueckgegeben: als data:-URL sind das schnell 40 kB und mehr.
    function schluessel(id) { return 'sig:' + id; }

    function merken(id, wert) {
        try { window.sessionStorage.setItem(schluessel(id), wert); } catch (e) { /* privater Modus */ }
    }

    function vergessen(id) {
        try { window.sessionStorage.removeItem(schluessel(id)); } catch (e) { /* egal */ }
    }

    function wiederherstellen(id) {
        var wert = null;
        try { wert = window.sessionStorage.getItem(schluessel(id)); } catch (e) { return; }
        if (!wert) { return; }
        var eintrag = zustand[id];
        var bild = new Image();
        bild.onload = function () {
            var rect = eintrag.canvas.getBoundingClientRect();
            eintrag.ctx.drawImage(bild, 0, 0, rect.width, rect.height);
            eintrag.gezeichnet = true;
            document.getElementById('daten-' + id).value = wert;
            var status = document.getElementById('status-' + id);
            if (status) { status.textContent = texte.unterschrieben; }
            gesamtStand();
            markiereFelder();
        };
        bild.src = wert;
    }

    window.__h["sigLeeren"] = function () {
        var id = this.getAttribute('data-ziel');
        var eintrag = zustand[id];
        if (!eintrag) { return; }
        eintrag.ctx.clearRect(0, 0, eintrag.canvas.width, eintrag.canvas.height);
        eintrag.gezeichnet = false;
        document.getElementById('daten-' + id).value = '';
        vergessen(id);
        var status = document.getElementById('status-' + id);
        if (status) { status.textContent = texte.leer; }
        gesamtStand();
        markiereFelder();
    };

    function gesamtStand() {
        var offen = 0;
        Object.keys(zustand).forEach(function (id) { if (!zustand[id].gezeichnet) { offen++; } });
        var anzeige = document.getElementById('gesamt-status');
        var gesamt = Object.keys(zustand).length;
        if (!anzeige) { return; }
        anzeige.textContent = gesamt === 0 ? '' :
            texte.fortschritt.replace(':done', gesamt - offen).replace(':total', gesamt);
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
    Object.keys(zustand).forEach(wiederherstellen);

    // Vor dem Absenden pruefen, ob eine Pflicht-Unterschrift fehlt. Die
    // eigentliche Pruefung macht der Server (dem Browser wird nichts
    // geglaubt) - aber der Unterzeichner soll es VOR dem Klick erfahren.
    var formular = document.getElementById('unterschrift-formular');
    var knopf = document.getElementById('absenden');
    var laeuft = false;

    formular.addEventListener('submit', function (event) {
        // ZWEITER KLICK: der Knopf wird zwar gesperrt, aber Enter im
        // Textfeld und ein schneller Doppelklick loesen trotzdem ein
        // zweites submit aus. Der Server ist dagegen abgesichert
        // (idempotent), der Browser soll es gar nicht erst versuchen.
        if (laeuft) { event.preventDefault(); return; }

        var fehlt = Object.keys(zustand).some(function (id) { return !zustand[id].gezeichnet; });
        if (fehlt) {
            event.preventDefault();
            alert(texte.fehlt);
            return;
        }
        laeuft = true;
        knopf.disabled = true;
        knopf.textContent = texte.speichert;
    });

    // Zurueck-Taste und Seiten-Cache: der Browser zeigt die Seite dann im
    // Zustand von vorhin - mit gesperrtem Knopf. Ohne dies steht der
    // Unterzeichner vor einem Formular, das sich nicht mehr absenden laesst.
    window.addEventListener('pageshow', function () {
        laeuft = false;
        knopf.disabled = false;
        knopf.textContent = texte.absenden;
    });
})();
</script>
@endpush
