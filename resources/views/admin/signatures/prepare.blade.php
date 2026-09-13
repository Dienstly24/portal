@extends('layouts.admin')
@section('content')
@php
    $signerData = $signature->signers->map(fn ($s) => [
        'id' => $s->id, 'name' => $s->name, 'email' => $s->email, 'locale' => $s->localeCode(),
    ])->values();
    $sprachen = \App\Models\SignatureSigner::LOCALES;
    $assetDaten = $companyAssets->mapWithKeys(fn ($a) => [$a->id => $a->typeLabel().': '.$a->name]);
    $fieldData = $signature->fields->map(fn ($f) => [
        'id' => $f->id, 'signer_id' => $f->signature_signer_id, 'type' => $f->type, 'page' => $f->page,
        'x' => (float) $f->pos_x, 'y' => (float) $f->pos_y, 'width' => (float) $f->width, 'height' => (float) $f->height,
        'required' => (bool) $f->required, 'label' => $f->label,
        'company_asset_id' => $f->company_asset_id,
    ])->values();
@endphp

{{--
    DER EDITOR IST EIN ARBEITSGERAET, KEINE DOKUMENTSEITE.

    Vorher war das Dokument der Seiteninhalt: bei 14 Seiten musste der
    Mitarbeiter bis ans ENDE des PDF scrollen, um "Entwurf speichern" oder
    den Weg zum Versand zu erreichen. Das Dokument bestimmte damit die
    Bedienung - je laenger der Vertrag, desto weiter weg die Knoepfe.

    Jetzt hat der Rahmen eine feste Hoehe: gescrollt wird INNEN, im
    Betrachter. Werkzeugleiste oben und Aktionsleiste unten stehen immer da,
    unabhaengig davon, wo im Dokument man gerade ist.
--}}
<style>
.sig-shell{
    /* Die Hoehe der Umgebung (Kopfzeile des Layouts + Abstaende). Als
       Token, damit die zwei Stellen unten nicht auseinanderlaufen. */
    --sig-chrome:150px;
    --zoom:1;
    display:flex;flex-direction:column;
    height:calc(100dvh - var(--sig-chrome));min-height:430px;
    border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--surface);
}
.sig-bar{
    display:flex;align-items:center;gap:12px;flex-wrap:nowrap;
    padding:8px 12px;border-bottom:1px solid var(--line);background:var(--surface);
    min-height:48px;overflow-x:auto;
}
.sig-bar .sig-titel{font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:32vw;}
.sig-bar .sig-spacer{flex:1;}
.sig-zoom{display:flex;align-items:center;gap:4px;white-space:nowrap;}
.sig-body{flex:1;display:grid;grid-template-columns:172px 1fr 262px;min-height:0;}
.sig-thumbs{border-right:1px solid var(--line);overflow-y:auto;padding:10px;background:var(--canvas);}
.sig-tools{border-left:1px solid var(--line);overflow-y:auto;padding:12px;background:var(--canvas);}
.sig-viewer{overflow:auto;padding:16px 12px;background:var(--canvas);min-width:0;}
.sig-actions{
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;
    padding:10px 12px;border-top:1px solid var(--line);background:var(--surface);
}
.sig-thumb{
    display:block;width:100%;border:2px solid transparent;border-radius:6px;background:none;
    padding:0;margin-bottom:9px;cursor:pointer;text-align:center;
}
.sig-thumb.aktiv{border-color:var(--emerald);}
.sig-thumb .blatt{
    width:100%;background:#fff;border:1px solid var(--line);border-radius:4px;overflow:hidden;
    display:flex;align-items:center;justify-content:center;
}
.sig-thumb .blatt img{width:100%;display:block;}
.sig-thumb .zeile{font-size:11px;color:var(--ink-soft);padding:3px 0 0;}
.sig-seite{margin:0 auto 22px;width:calc((100% - 6px) * var(--zoom));max-width:none;}
.sig-panel-schliessen{display:none;}

/* MOBILE: die beiden Leisten sind Schubladen, kein zweites Layout mit
   halber Breite. Auf 390 px bliebe von einem Dreispalter nichts uebrig -
   und genau dort wird das Dokument am ehesten unterwegs geprueft. */
@media (max-width:1000px){
    .sig-shell{--sig-chrome:110px;}
    .sig-body{grid-template-columns:1fr;}
    /* UMBRECHEN statt seitwaerts schieben: eine Leiste mit waagerechtem
       Bildlauf versteckt genau die Knoepfe, die man auf dem Telefon
       zuerst braucht - und sie verschiebt sich beim Antippen von selbst,
       weil der Browser das fokussierte Element sichtbar macht. */
    .sig-bar{flex-wrap:wrap;overflow-x:visible;gap:6px 10px;}
    .sig-bar .sig-titel{display:none;}
    .sig-thumbs,.sig-tools{
        position:absolute;z-index:30;top:0;bottom:0;width:min(84vw,300px);
        box-shadow:0 10px 40px rgba(0,0,0,.22);border-radius:0;
    }
    .sig-thumbs{left:0;border-right:1px solid var(--line);}
    .sig-tools{right:0;border-left:1px solid var(--line);}
    .sig-body{position:relative;}
    .sig-panel-schliessen{display:block;}
}
@media (min-width:1001px){
    /* Auf dem Desktop sind beide Leisten fest - der Umschalter waere dort
       nur ein Knopf, der etwas zumacht, das niemand zumachen will. */
    .sig-nur-mobil{display:none !important;}
}
</style>

<div class="page-header" style="margin-bottom:12px;">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.index') }}">Signaturen</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.show', $signature->id) }}">{{ Str::limit($signature->title, 40) }}</a>
        <span class="breadcrumb-sep">›</span><span>Felder setzen</span>
    </div>
</div>

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald-ink);padding:9px 16px;border-radius:8px;margin-bottom:10px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:9px 16px;border-radius:8px;margin-bottom:10px;">{{ session('error') }}</div>@endif
@if(!$signature->isDraft())
<div style="background:#FFF6E5;color:#8A5D00;padding:9px 16px;border-radius:8px;margin-bottom:10px;">
    Diese Anfrage wurde bereits versendet. Die Aufteilung lässt sich nicht mehr ändern – sonst veränderte sich das
    Dokument unter einem Unterzeichner, der es schon geöffnet hat.
</div>
@endif
@if(!$previewAvailable)
<div style="background:#FFF6E5;color:#8A5D00;padding:9px 16px;border-radius:8px;margin-bottom:10px;">
    Die Seitenvorschau ist auf diesem Server nicht verfügbar (poppler-utils/<code>pdftoppm</code> fehlt).
    Die Seiten werden maßstabsgetreu leer dargestellt – Felder lassen sich trotzdem setzen, die Positionen stimmen.
</div>
@endif

<div class="sig-shell" id="sig-shell">

    <div class="sig-bar">
        <button type="button" class="btn btn-sm btn-ghost sig-nur-mobil" data-h-click="sigTogglePanel"
                data-panel="thumb-panel" aria-label="Seitenübersicht">☰ Seiten</button>
        <span class="sig-titel" title="{{ $signature->title }}">{{ $signature->title }}</span>
        <span class="muted-sm" style="white-space:nowrap;"><span id="seiten-stand">1</span> / {{ (int) $signature->page_count }}</span>
        <span class="sig-spacer"></span>
        <span class="sig-zoom">
            <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigZoomOut" aria-label="Verkleinern">−</button>
            <span id="zoom-label" class="muted-sm" style="min-width:44px;text-align:center;">100 %</span>
            <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigZoomIn" aria-label="Vergrößern">+</button>
            <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigFitWidth">Breite</button>
        </span>
        <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigNaechstesFeld">Nächstes Feld →</button>
        <button type="button" class="btn btn-sm btn-ghost sig-nur-mobil" data-h-click="sigTogglePanel"
                data-panel="tool-panel">Felder</button>
    </div>

    <div class="sig-body">

        <aside class="sig-thumbs" id="thumb-panel" data-mobil-zu="1">
            <button type="button" class="btn btn-sm btn-ghost sig-panel-schliessen sig-nur-mobil"
                    data-h-click="sigTogglePanel" data-panel="thumb-panel"
                    style="width:100%;margin-bottom:8px;">Schließen</button>
            <div id="thumb-list"></div>
        </aside>

        <main class="sig-viewer" id="viewer">
            <div id="pages"></div>
        </main>

        <aside class="sig-tools" id="tool-panel" data-mobil-zu="1">
            <button type="button" class="btn btn-sm btn-ghost sig-panel-schliessen sig-nur-mobil"
                    data-h-click="sigTogglePanel" data-panel="tool-panel"
                    style="width:100%;margin-bottom:8px;">Schließen</button>

            <div style="font-weight:600;font-size:13px;margin-bottom:8px;">Unterzeichner</div>
            <div id="signer-list" style="display:grid;gap:10px;"></div>
            <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigAddSigner"
                    style="margin-top:9px;" @disabled(!$signature->isDraft())>+ Unterzeichner</button>

            <div style="border-top:1px solid var(--line);margin:13px 0 10px;"></div>
            <div style="font-weight:600;font-size:13px;margin-bottom:6px;">Feld hinzufügen</div>
            <div class="muted-sm" style="margin-bottom:7px;">Unterzeichner wählen, Feldart wählen, auf die Seite tippen.</div>
            <select id="active-signer" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;margin-bottom:7px;"></select>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                @foreach($fieldTypes as $key => $label)
                <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigPickType" data-type="{{ $key }}"
                        id="type-{{ $key }}" @disabled(!$signature->isDraft())>{{ $label }}</button>
                @endforeach
            </div>
            @if($companyAssets->isNotEmpty())
            {{-- Das Firmenbild gehoert KEINEM Unterzeichner: die Auswahl
                 steht deshalb getrennt unter den Feldarten. --}}
            <div id="company-choice" hidden style="display:grid;gap:6px;border-top:1px solid var(--line);padding-top:8px;margin-top:8px;">
                <label for="company-asset" class="muted-sm" style="font-weight:600;">Welches Firmenbild?</label>
                <select id="company-asset" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
                    @foreach($companyAssets as $asset)
                    <option value="{{ $asset->id }}" @selected($asset->is_default)>{{ $asset->typeLabel() }}: {{ $asset->name }}</option>
                    @endforeach
                </select>
                <div class="muted-sm">
                    Kein Unterzeichner, keine Einladung - das Bild steht sofort im Dokument.
                    Im Protokoll erscheint es als „eingesetzt von", nicht als Unterschrift.
                </div>
            </div>
            @endif
        </aside>
    </div>

    {{-- IMMER ERREICHBAR. Das war der eigentliche Auftrag: kein Knopf mehr
         hinter 14 Seiten PDF. --}}
    <div class="sig-actions">
        <div style="flex:1;min-width:160px;font-size:13px;">
            <span id="feld-stand">—</span>
            <div id="save-state" class="muted-sm"></div>
        </div>
        <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigAbbrechen">Abbrechen</button>
        <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigSave" @disabled(!$signature->isDraft())>Als Entwurf speichern</button>
        <button type="button" class="btn btn-emerald btn-sm" data-h-click="sigReview" @disabled(!$signature->isDraft())>Senden</button>
    </div>
</div>

{{-- EIN Dialog fuer alle Rueckfragen (Pruefung vor dem Versand, verworfene
     Aenderungen). Drei einzelne Dialoge waeren dreimal dieselbe Mechanik
     mit drei Gelegenheiten, sie unterschiedlich falsch zu machen. --}}
<div id="sig-dialog" hidden
     style="position:fixed;inset:0;z-index:80;background:rgba(10,18,14,.42);display:flex;align-items:center;justify-content:center;padding:16px;">
    <div style="background:var(--surface);border-radius:12px;max-width:460px;width:100%;padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div id="dialog-titel" style="font-weight:700;font-size:16px;margin-bottom:8px;"></div>
        <div id="dialog-text" style="font-size:13.5px;color:var(--ink-soft);display:grid;gap:5px;"></div>
        <div id="dialog-knoepfe" style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap;"></div>
    </div>
</div>

<div id="field-menu" hidden
     style="position:absolute;z-index:40;background:var(--surface);border:1px solid var(--line);border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.16);padding:10px;min-width:210px;">
    <div style="font-size:12px;color:var(--ink-soft);margin-bottom:6px;">Feld bearbeiten</div>
    <select id="menu-signer" style="width:100%;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-size:13px;margin-bottom:6px;"></select>
    <input id="menu-label" type="text" maxlength="120" placeholder="Beschriftung (optional)"
           style="width:100%;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-size:13px;margin-bottom:6px;">
    <label style="display:flex;gap:7px;align-items:center;font-size:13px;font-weight:400;margin-bottom:8px;">
        <input id="menu-required" type="checkbox"> Pflichtfeld
    </label>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigDuplicate">Duplizieren</button>
        <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigDelete" style="color:#B3261E;">Löschen</button>
        <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigCloseMenu">Fertig</button>
    </div>
</div>

{{-- SEC-4: kein onclick-Attribut, alles ueber data-h-* und diesen
     nonce-tragenden Block. Er steht VOR dem @stack des Layouts. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
(function () {
    "use strict";

    var editable = @json($signature->isDraft());
    var state = {
        signers: @json($signerData),
        fields: @json($fieldData),
        geometry: @json($geometry),
        pageCount: @json((int) $signature->page_count),
        previews: @json($previewAvailable),
        zoom: 1,
        type: 'unterschrift',
        selected: null,
        aktuelleSeite: 1,
        navIndex: -1,
        dirty: false
    };
    var sprachen = @json($sprachen);
    var firmenbilder = @json($assetDaten);
    var sprachCodes = Object.keys(sprachen);
    var urls = {
        page: @json(route('admin.signatures.page', [$signature->id, 0])),
        save: @json(route('admin.signatures.prepare.save', $signature->id)),
        send: @json(route('admin.signatures.send', $signature->id)),
        show: @json(route('admin.signatures.show', $signature->id))
    };
    var typeLabels = @json($fieldTypes);
    // Farbe je Unterzeichner: die Zuordnung muss auf einen Blick sichtbar
    // sein - "welches Feld gehoert wem" ist die Frage, die dieser Editor
    // beantworten muss. brandColor() liest denselben Token wie das CSS
    // (ein style-Attribut loest kein var() auf).
    var palette = [
        brandColor('emerald'), brandColor('status-info'), brandColor('status-warning'),
        '#8E44AD', '#7A5C2E'
    ];
    var GEZEICHNET = ['unterschrift', 'initialen'];

    function uid() { return 'neu-' + Math.random().toString(36).slice(2, 10); }
    function colorFor(signerId) {
        var index = state.signers.findIndex(function (s) { return s.id === signerId; });
        return index < 0 ? 'var(--ink-soft)' : palette[index % palette.length];
    }
    function geometryFor(page) {
        for (var i = 0; i < state.geometry.length; i++) {
            if (state.geometry[i].page === page) { return state.geometry[i]; }
        }
        return { page: page, width: 595.28, height: 841.89 };
    }
    function defaultSize(type) {
        if (type === 'unterschrift') { return [0.28, 0.055]; }
        if (type === 'initialen') { return [0.10, 0.045]; }
        if (type === 'kreuz') { return [0.025, 0.018]; }
        if (type === 'datum') { return [0.16, 0.025]; }
        return [0.22, 0.028];
    }
    function pageUrl(page) { return urls.page.replace(/\/0$/, '/' + page); }
    function unterschriftsFelder() {
        return state.fields.filter(function (f) { return GEZEICHNET.indexOf(f.type) !== -1; });
    }

    // ---------------------------------------------------------- Seitenaufbau
    function buildPages() {
        var host = document.getElementById('pages');
        host.textContent = '';

        for (var page = 1; page <= state.pageCount; page++) {
            var geo = geometryFor(page);
            var wrap = document.createElement('div');
            wrap.className = 'sig-seite';
            wrap.setAttribute('data-seite-wrap', String(page));

            var caption = document.createElement('div');
            caption.className = 'muted-sm';
            caption.style.marginBottom = '5px';
            caption.textContent = 'Seite ' + page + ' von ' + state.pageCount;
            wrap.appendChild(caption);

            var sheet = document.createElement('div');
            sheet.id = 'seite-' + page;
            sheet.setAttribute('data-page', String(page));
            sheet.style.cssText = 'position:relative;width:100%;background:#fff;border:1px solid var(--line);'
                + 'border-radius:6px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.07);'
                + 'aspect-ratio:' + geo.width + ' / ' + geo.height + ';';
            if (state.previews) {
                var img = document.createElement('img');
                img.src = pageUrl(page);
                img.alt = 'Seite ' + page;
                img.loading = page > 2 ? 'lazy' : 'eager';
                img.style.cssText = 'width:100%;height:100%;display:block;object-fit:contain;';
                img.draggable = false;
                sheet.appendChild(img);
            }
            if (editable) { sheet.setAttribute('data-h-click', 'sigPlace'); }
            wrap.appendChild(sheet);
            host.appendChild(wrap);
        }
        renderFields();
        buildThumbs();
    }

    /**
     * Miniaturen mit ✍-Marke.
     *
     * Die Marke traegt die ANZAHL erst ab zwei Stellen: eine "1" an jedem
     * Blatt waere Ziergrafik und nimmt der Marke genau die Aussage, wegen
     * der sie da ist.
     */
    function buildThumbs() {
        var liste = document.getElementById('thumb-list');
        if (!liste) { return; }
        liste.textContent = '';

        for (var page = 1; page <= state.pageCount; page++) {
            var anzahl = state.fields.filter(function (f) { return f.page === page; }).length;
            var knopf = document.createElement('button');
            knopf.type = 'button';
            knopf.className = 'sig-thumb' + (page === state.aktuelleSeite ? ' aktiv' : '');
            knopf.setAttribute('data-h-click', 'sigZuSeite');
            knopf.setAttribute('data-seite', String(page));

            var blatt = document.createElement('div');
            blatt.className = 'blatt';
            var geo = geometryFor(page);
            blatt.style.aspectRatio = geo.width + ' / ' + geo.height;
            if (state.previews) {
                var img = document.createElement('img');
                img.src = pageUrl(page);
                img.alt = '';
                img.loading = 'lazy';
                blatt.appendChild(img);
            }
            knopf.appendChild(blatt);

            var zeile = document.createElement('div');
            zeile.className = 'zeile';
            zeile.textContent = 'Seite ' + page + (anzahl > 0 ? (anzahl > 1 ? ' ✍ ' + anzahl : ' ✍') : '');
            knopf.appendChild(zeile);

            liste.appendChild(knopf);
        }
    }

    function markiereSeite(page) {
        state.aktuelleSeite = page;
        var stand = document.getElementById('seiten-stand');
        if (stand) { stand.textContent = String(page); }
        var knoepfe = document.querySelectorAll('.sig-thumb');
        for (var i = 0; i < knoepfe.length; i++) {
            var nummer = parseInt(knoepfe[i].getAttribute('data-seite'), 10);
            knoepfe[i].classList.toggle('aktiv', nummer === page);
        }
    }

    /**
     * "Seite X von Y" folgt dem Bildlauf - gemessen an der Seite, die dem
     * oberen Viertel des Betrachters am naechsten liegt. Die GROESSTE
     * sichtbare Seite waere der falsche Massstab: beim Blaettern springt
     * der Zaehler dann zurueck.
     */
    function beobachteBildlauf() {
        var viewer = document.getElementById('viewer');
        if (!viewer) { return; }
        var laeuft = false;
        viewer.addEventListener('scroll', function () {
            if (laeuft) { return; }
            laeuft = true;
            window.requestAnimationFrame(function () {
                laeuft = false;
                var marke = viewer.getBoundingClientRect().top + viewer.clientHeight * 0.25;
                var beste = 1, abstand = Infinity;
                for (var page = 1; page <= state.pageCount; page++) {
                    var el = document.getElementById('seite-' + page);
                    if (!el) { continue; }
                    var d = Math.abs(el.getBoundingClientRect().top - marke);
                    if (d < abstand) { abstand = d; beste = page; }
                }
                if (beste !== state.aktuelleSeite) { markiereSeite(beste); }
            });
        });
    }

    function renderFields() {
        zeichneFelder();
        renderSignerGruppen();
        buildThumbs();
        standAktualisieren();
    }

    function renderSignerGruppen() {
        var liste = document.getElementById('signer-list');
        if (liste && liste.childElementCount > 0) { renderSigners(); }
    }

    function zeichneFelder() {
        var boxes = document.querySelectorAll('[data-field-box]');
        for (var i = 0; i < boxes.length; i++) { boxes[i].remove(); }

        state.fields.forEach(function (field) {
            var sheet = document.getElementById('seite-' + field.page);
            if (!sheet) { return; }
            var color = colorFor(field.signer_id);
            var box = document.createElement('div');
            box.setAttribute('data-field-box', field.id);
            box.style.cssText = 'position:absolute;box-sizing:border-box;border:2px solid ' + color + ';'
                + 'background:' + color + '22;border-radius:4px;cursor:move;overflow:hidden;'
                + 'left:' + (field.x * 100) + '%;top:' + (field.y * 100) + '%;'
                + 'width:' + (field.width * 100) + '%;height:' + (field.height * 100) + '%;';

            var caption = document.createElement('div');
            // Links Platz fuer den Stift, rechts fuer den Griff - sonst
            // laeuft die Beschriftung unter die beiden Knoepfe.
            caption.style.cssText = 'font-size:11px;line-height:1.15;padding:2px 18px 2px '
                + (editable ? '22px' : '4px') + ';color:' + color + ';'
                + 'font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
            var owner = state.signers.find(function (s) { return s.id === field.signer_id; });
            // Firmenbilder tragen den NAMEN DES BILDES, nicht "ohne
            // Unterzeichner": sie haben keinen, und das ist kein Mangel.
            caption.textContent = (typeLabels[field.type] || field.type) + ' · '
                + (field.type === 'firma'
                    ? (firmenbilder[field.company_asset_id] || 'Firmenbild')
                    : (owner ? owner.name : 'ohne Unterzeichner'));
            box.appendChild(caption);

            if (editable) {
                box.setAttribute('data-h-pointerdown', 'sigDragStart');
                box.setAttribute('data-h-dblclick', 'sigOpenMenu');

                // EIN ANTIPPEN MUSS GENUEGEN. Ein Doppelklick gibt es auf
                // dem Telefon nicht - ohne diesen Knopf war ein gesetztes
                // Feld dort nicht mehr zu aendern und nicht zu loeschen.
                var stift = document.createElement('button');
                stift.type = 'button';
                stift.setAttribute('data-h-click', 'sigOpenMenu');
                stift.setAttribute('data-field-box', field.id);
                // LINKS oben, nicht rechts: ein Unterschriftsfeld ist flach
                // (rund 20 px hoch). Rechts oben sass der Stift sonst auf
                // demselben Fleck wie der Vergroesserungs-Griff rechts
                // unten - und weil der spaeter eingehaengt wird, lag er
                // oben: der Stift war da, aber nicht antippbar. Im Browser
                // aufgefallen, nicht im Test.
                stift.style.cssText = 'position:absolute;left:0;top:0;width:20px;height:20px;line-height:1;'
                    + 'border:none;background:' + color + ';color:#fff;border-radius:3px 0 4px 0;'
                    + 'font-size:12px;cursor:pointer;padding:0;z-index:2;';
                stift.textContent = '✎';
                box.appendChild(stift);

                var handle = document.createElement('div');
                handle.setAttribute('data-resize', '1');
                handle.style.cssText = 'position:absolute;right:0;bottom:0;width:16px;height:16px;'
                    + 'background:' + color + ';cursor:nwse-resize;border-radius:3px 0 0 0;';
                box.appendChild(handle);
            }
            sheet.appendChild(box);
        });
    }

    /**
     * "Eine Unterschrift - 7 Stellen (Seiten 1, 2, 3, 4, 6, 7, 9)".
     *
     * Bewusst als SATZ und nicht als blosse Zahl: "7" allein liest sich wie
     * "sieben Unterschriften", und genau das ist es nicht.
     */
    function gruppentext(signerId) {
        var eigene = state.fields.filter(function (f) {
            return f.signer_id === signerId && GEZEICHNET.indexOf(f.type) !== -1;
        });
        if (eigene.length === 0) { return 'noch keine Unterschriftsfelder'; }

        var seiten = [];
        eigene.forEach(function (f) { if (seiten.indexOf(f.page) === -1) { seiten.push(f.page); } });
        seiten.sort(function (a, b) { return a - b; });

        return eigene.length === 1
            ? 'Eine Unterschrift - 1 Stelle (Seite ' + seiten[0] + ')'
            : 'Eine Unterschrift - ' + eigene.length + ' Stellen (Seiten ' + seiten.join(', ') + ')';
    }

    /** Die Zeile in der Aktionsleiste: wie viele Stellen, wie viele Menschen. */
    function standAktualisieren() {
        var el = document.getElementById('feld-stand');
        if (!el) { return; }
        var stellen = unterschriftsFelder().length;
        var menschen = state.signers.length;
        el.textContent = stellen === 0
            ? 'Noch keine Unterschriftsstelle gesetzt'
            : stellen + ' Unterschriftsstelle' + (stellen === 1 ? '' : 'n') + ' · '
              + menschen + ' Unterzeichner';
    }

    function renderSigners() {
        var list = document.getElementById('signer-list');
        list.textContent = '';
        state.signers.forEach(function (signer, index) {
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:flex-start;';
            var dot = document.createElement('span');
            dot.style.cssText = 'width:11px;height:11px;border-radius:50%;flex:none;margin-top:4px;background:' + palette[index % palette.length] + ';';
            var text = document.createElement('div');
            text.style.cssText = 'font-size:13px;min-width:0;flex:1;';
            var name = document.createElement('div');
            name.style.fontWeight = '600';
            name.textContent = signer.name || '(ohne Namen)';
            var mail = document.createElement('div');
            mail.className = 'muted-sm';
            mail.style.cssText = 'overflow:hidden;text-overflow:ellipsis;';
            mail.textContent = signer.email || '(ohne E-Mail)';
            var sprache = document.createElement('div');
            sprache.className = 'muted-sm';
            sprache.textContent = sprachen[signer.locale || 'de'] || sprachen.de;

            // DIE SIGNATURGRUPPE SICHTBAR MACHEN. Der Mitarbeiter setzt
            // sieben Felder und muss verstehen, dass er damit NICHT sieben
            // Unterschriften verlangt. Die Gruppe wird nirgends eingestellt -
            // sie ENTSTEHT dadurch, dass ein Feld einem Unterzeichner gehoert.
            var gruppe = document.createElement('button');
            gruppe.type = 'button';
            gruppe.className = 'muted-sm';
            gruppe.setAttribute('data-h-click', 'sigZurGruppe');
            gruppe.setAttribute('data-signer', signer.id);
            gruppe.style.cssText = 'margin-top:2px;background:none;border:none;padding:0;text-align:left;'
                + 'cursor:pointer;text-decoration:underline;';
            gruppe.textContent = gruppentext(signer.id);

            text.appendChild(name); text.appendChild(mail); text.appendChild(sprache);
            text.appendChild(gruppe);
            row.appendChild(dot); row.appendChild(text);
            if (editable) {
                var edit = document.createElement('button');
                edit.type = 'button';
                edit.className = 'btn btn-sm btn-ghost';
                edit.setAttribute('data-h-click', 'sigEditSigner');
                edit.setAttribute('data-signer', signer.id);
                edit.textContent = 'Ändern';
                row.appendChild(edit);
            }
            list.appendChild(row);
        });

        [document.getElementById('active-signer'), document.getElementById('menu-signer')].forEach(function (select) {
            var previous = select.value;
            select.textContent = '';
            state.signers.forEach(function (signer) {
                var option = document.createElement('option');
                option.value = signer.id;
                option.textContent = signer.name || signer.email || 'Unterzeichner';
                select.appendChild(option);
            });
            if (previous) { select.value = previous; }
        });
    }

    // -------------------------------------------------------- Navigation
    window.__h["sigZuSeite"] = function () {
        var seite = parseInt(this.getAttribute('data-seite'), 10);
        zeigeSeite(seite);
        schliesseMobilPanels();
    };

    function zeigeSeite(seite) {
        var el = document.getElementById('seite-' + seite);
        var viewer = document.getElementById('viewer');
        if (!el || !viewer) { return; }
        // Innerhalb des Betrachters scrollen, nicht die Seite - genau das
        // ist der Unterschied zum alten Verhalten.
        viewer.scrollTop += el.getBoundingClientRect().top - viewer.getBoundingClientRect().top - 10;
        markiereSeite(seite);
    }

    /** Reihum zur naechsten Unterschriftsstelle - ueber Seitengrenzen hinweg. */
    window.__h["sigNaechstesFeld"] = function () {
        var felder = unterschriftsFelder().slice().sort(function (a, b) {
            return a.page === b.page ? a.y - b.y : a.page - b.page;
        });
        if (felder.length === 0) {
            zeigeDialog('Noch keine Unterschriftsstelle', ['Setzen Sie zuerst ein Unterschriftsfeld auf eine Seite.'],
                [{ text: 'Verstanden', klasse: 'btn-ghost' }]);
            return;
        }
        state.navIndex = (state.navIndex + 1) % felder.length;
        var ziel = felder[state.navIndex];
        zeigeSeite(ziel.page);
        hebeHervor(ziel.id);
    };

    window.__h["sigZurGruppe"] = function () {
        var id = this.getAttribute('data-signer');
        var eigene = unterschriftsFelder().filter(function (f) { return f.signer_id === id; })
            .sort(function (a, b) { return a.page === b.page ? a.y - b.y : a.page - b.page; });
        if (eigene.length === 0) { return; }
        zeigeSeite(eigene[0].page);
        hebeHervor(eigene[0].id);
        schliesseMobilPanels();
    };

    function hebeHervor(id) {
        var box = document.querySelector('[data-field-box="' + id + '"]');
        if (!box) { return; }
        box.style.outline = '3px solid ' + brandColor('emerald');
        window.setTimeout(function () { box.style.outline = ''; }, 1400);
    }

    window.__h["sigTogglePanel"] = function () {
        var panel = document.getElementById(this.getAttribute('data-panel'));
        if (!panel) { return; }
        var zu = panel.getAttribute('data-mobil-zu') === '1';
        schliesseMobilPanels();
        if (zu) { panel.removeAttribute('data-mobil-zu'); panel.style.display = 'block'; }
    };

    function schliesseMobilPanels() {
        ['thumb-panel', 'tool-panel'].forEach(function (id) {
            var panel = document.getElementById(id);
            if (!panel) { return; }
            panel.setAttribute('data-mobil-zu', '1');
            // Nur auf schmalen Geraeten verstecken - auf dem Desktop sind
            // beide Leisten fester Bestandteil der Ansicht.
            panel.style.display = window.matchMedia('(max-width:1000px)').matches ? 'none' : '';
        });
    }

    // ------------------------------------------------------------- Handlungen
    window.__h["sigPickType"] = function () {
        state.type = this.getAttribute('data-type');
        Object.keys(typeLabels).forEach(function (key) {
            var button = document.getElementById('type-' + key);
            if (button) { button.style.borderColor = key === state.type ? 'var(--emerald)' : ''; }
        });
        var wahl = document.getElementById('company-choice');
        // [hidden] statt style.display (SEC-4-Regel: app.css erzwingt es).
        if (wahl) { wahl.hidden = state.type !== 'firma'; }
    };

    window.__h["sigPlace"] = function (event) {
        if (!editable) { return; }
        if (event.target.closest('[data-field-box]')) { return; }
        if (!state.signers.length && state.type !== 'firma') {
            zeigeDialog('Kein Unterzeichner', ['Bitte zuerst einen Unterzeichner anlegen.'],
                [{ text: 'Verstanden', klasse: 'btn-ghost' }]);
            return;
        }
        var sheet = this;
        var rect = sheet.getBoundingClientRect();
        var size = defaultSize(state.type);
        var x = (event.clientX - rect.left) / rect.width - size[0] / 2;
        var y = (event.clientY - rect.top) / rect.height - size[1] / 2;
        var istFirma = state.type === 'firma';
        var assetFeld = document.getElementById('company-asset');
        if (istFirma && (!assetFeld || !assetFeld.value)) {
            zeigeDialog('Kein Firmenbild hinterlegt',
                ['Bitte zuerst unter Einstellungen → Signaturen ein Firmenbild anlegen.'],
                [{ text: 'Verstanden', klasse: 'btn-ghost' }]);
            return;
        }
        state.fields.push({
            id: uid(),
            // ENTWEDER Unterzeichner ODER Firmenbild - nie beides.
            signer_id: istFirma ? null : (document.getElementById('active-signer').value || state.signers[0].id),
            company_asset_id: istFirma ? assetFeld.value : null,
            type: state.type,
            page: parseInt(sheet.getAttribute('data-page'), 10),
            x: Math.min(1 - size[0], Math.max(0, x)),
            y: Math.min(1 - size[1], Math.max(0, y)),
            width: size[0], height: size[1], required: true, label: null
        });
        markDirty();
        renderFields();
    };

    var drag = null;
    window.__h["sigDragStart"] = function (event) {
        if (!editable) { return; }
        if (event.target.getAttribute('data-h-click') === 'sigOpenMenu') { return; }
        var box = this;
        var sheet = box.parentElement;
        var rect = sheet.getBoundingClientRect();
        var field = state.fields.find(function (f) { return f.id === box.getAttribute('data-field-box'); });
        if (!field) { return; }
        drag = {
            field: field, rect: rect,
            resize: !!event.target.getAttribute('data-resize'),
            startX: event.clientX, startY: event.clientY,
            originX: field.x, originY: field.y, originW: field.width, originH: field.height
        };
        state.selected = field.id;
        event.preventDefault();
    };

    document.addEventListener('pointermove', function (event) {
        if (!drag) { return; }
        var dx = (event.clientX - drag.startX) / drag.rect.width;
        var dy = (event.clientY - drag.startY) / drag.rect.height;
        if (drag.resize) {
            drag.field.width = Math.min(1 - drag.field.x, Math.max(0.01, drag.originW + dx));
            drag.field.height = Math.min(1 - drag.field.y, Math.max(0.006, drag.originH + dy));
        } else {
            drag.field.x = Math.min(1 - drag.field.width, Math.max(0, drag.originX + dx));
            drag.field.y = Math.min(1 - drag.field.height, Math.max(0, drag.originY + dy));
        }
        var box = document.querySelector('[data-field-box="' + drag.field.id + '"]');
        if (box) {
            box.style.left = (drag.field.x * 100) + '%';
            box.style.top = (drag.field.y * 100) + '%';
            box.style.width = (drag.field.width * 100) + '%';
            box.style.height = (drag.field.height * 100) + '%';
        }
    });

    document.addEventListener('pointerup', function () {
        if (drag) { markDirty(); standAktualisieren(); drag = null; }
    });

    window.__h["sigOpenMenu"] = function (event) {
        if (!editable) { return; }
        var id = this.getAttribute('data-field-box');
        var field = state.fields.find(function (f) { return f.id === id; });
        if (!field) { return; }
        state.selected = id;
        var menu = document.getElementById('field-menu');
        menu.hidden = false;
        var x = Math.min(event.clientX + 8, window.innerWidth - 230);
        var y = Math.min(event.clientY + 8, window.innerHeight - 220);
        menu.style.left = (window.scrollX + Math.max(8, x)) + 'px';
        menu.style.top = (window.scrollY + Math.max(8, y)) + 'px';
        document.getElementById('menu-signer').value = field.signer_id || '';
        document.getElementById('menu-label').value = field.label || '';
        document.getElementById('menu-required').checked = !!field.required;
    };

    function selectedField() {
        return state.fields.find(function (f) { return f.id === state.selected; });
    }

    ['menu-signer', 'menu-label', 'menu-required'].forEach(function (id) {
        document.addEventListener('change', function (event) {
            if (!event.target || event.target.id !== id) { return; }
            var field = selectedField();
            if (!field) { return; }
            if (id === 'menu-signer') { field.signer_id = event.target.value; }
            if (id === 'menu-label') { field.label = event.target.value || null; }
            if (id === 'menu-required') { field.required = event.target.checked; }
            markDirty();
            renderFields();
        });
    });

    window.__h["sigDuplicate"] = function () {
        var field = selectedField();
        if (!field) { return; }
        var copy = JSON.parse(JSON.stringify(field));
        copy.id = uid();
        copy.y = Math.min(1 - copy.height, copy.y + copy.height + 0.01);
        state.fields.push(copy);
        state.selected = copy.id;
        markDirty();
        renderFields();
    };

    window.__h["sigDelete"] = function () {
        state.fields = state.fields.filter(function (f) { return f.id !== state.selected; });
        document.getElementById('field-menu').hidden = true;
        markDirty();
        renderFields();
    };

    window.__h["sigCloseMenu"] = function () {
        document.getElementById('field-menu').hidden = true;
    };

    /**
     * Sprachabfrage per prompt - dieselbe Bedienung wie Name und E-Mail
     * daneben. Eine abgebrochene oder unbekannte Eingabe aendert NICHTS.
     */
    function spracheFragen(aktuell) {
        var antwort = prompt('Sprache (' + sprachCodes.join(' / ') + '):', aktuell);
        if (antwort === null) { return aktuell; }
        antwort = String(antwort).trim().toLowerCase();
        return sprachCodes.indexOf(antwort) === -1 ? aktuell : antwort;
    }

    window.__h["sigAddSigner"] = function () {
        if (state.signers.length >= 10) { return; }
        var name = prompt('Name des Unterzeichners:');
        if (!name) { return; }
        var email = prompt('E-Mail-Adresse von ' + name + ':');
        if (!email) { return; }
        state.signers.push({ id: uid(), name: name, email: email, locale: spracheFragen('de') });
        markDirty();
        renderSigners();
        renderFields();
    };

    window.__h["sigEditSigner"] = function () {
        var id = this.getAttribute('data-signer');
        var signer = state.signers.find(function (s) { return s.id === id; });
        if (!signer) { return; }
        var name = prompt('Name:', signer.name);
        if (name === null) { return; }
        var email = prompt('E-Mail:', signer.email);
        if (email === null) { return; }
        signer.name = name; signer.email = email;
        signer.locale = spracheFragen(signer.locale || 'de');
        markDirty();
        renderSigners();
        renderFields();
    };

    // ------------------------------------------------------------- Ansicht
    window.__h["sigZoomIn"] = function () { setZoom(state.zoom + 0.25); };
    window.__h["sigZoomOut"] = function () { setZoom(state.zoom - 0.25); };
    window.__h["sigFitWidth"] = function () { setZoom(1); };
    function setZoom(value) {
        state.zoom = Math.max(0.5, Math.min(3, Math.round(value * 100) / 100));
        document.getElementById('zoom-label').textContent = Math.round(state.zoom * 100) + ' %';
        // NUR die Breite der Blaetter aendert sich - kein Neuaufbau: der
        // waere ein Sprung an den Anfang des Dokuments und laedt alle
        // Seitenbilder erneut.
        document.getElementById('sig-shell').style.setProperty('--zoom', String(state.zoom));
    }

    function markDirty() {
        state.dirty = true;
        document.getElementById('save-state').textContent = 'Nicht gespeicherte Änderungen.';
    }

    // ------------------------------------------------------------- Dialog
    function zeigeDialog(titel, zeilen, knoepfe) {
        var dialog = document.getElementById('sig-dialog');
        document.getElementById('dialog-titel').textContent = titel;
        var text = document.getElementById('dialog-text');
        text.textContent = '';
        zeilen.forEach(function (zeile) {
            var div = document.createElement('div');
            div.textContent = zeile;
            text.appendChild(div);
        });
        var leiste = document.getElementById('dialog-knoepfe');
        leiste.textContent = '';
        knoepfe.forEach(function (knopf) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm ' + (knopf.klasse || 'btn-ghost');
            button.textContent = knopf.text;
            button.addEventListener('click', function () {
                if (!knopf.bleibt) { dialog.hidden = true; }
                if (knopf.tun) { knopf.tun(); }
            });
            leiste.appendChild(button);
        });
        dialog.hidden = false;
    }

    /**
     * Die Pruefung VOR dem Versand - kurz gehalten.
     *
     * Sie nennt nur, was zaehlt: Stellen, Unterzeichner, Dokument. Eine
     * lange Bestaetigungsseite liest beim dritten Mal niemand mehr, und
     * dann ist sie schlimmer als keine.
     */
    window.__h["sigReview"] = function () {
        var stellen = unterschriftsFelder().length;
        var ohneFeld = state.signers.filter(function (s) {
            return unterschriftsFelder().filter(function (f) { return f.signer_id === s.id; }).length === 0;
        });

        if (state.signers.length === 0) {
            zeigeDialog('Noch nicht versandfertig', ['Es ist kein Unterzeichner erfasst.'],
                [{ text: 'Weiter bearbeiten', klasse: 'btn-ghost' }]);
            return;
        }
        if (ohneFeld.length > 0) {
            zeigeDialog('Noch nicht versandfertig', ohneFeld.map(function (s) {
                return 'Für ' + (s.name || s.email) + ' fehlt ein Unterschriftsfeld.';
            }), [{ text: 'Weiter bearbeiten', klasse: 'btn-ghost' }]);
            return;
        }

        zeigeDialog('Dokument bereit zum Senden', [
            '✓ ' + stellen + ' Unterschriftsstelle' + (stellen === 1 ? '' : 'n') + ' gesetzt',
            '✓ ' + state.signers.length + ' Unterzeichner mit E-Mail-Adresse',
            '✓ Dokument mit ' + state.pageCount + ' Seite' + (state.pageCount === 1 ? '' : 'n'),
            state.dirty ? 'Ihre Änderungen werden beim Senden gespeichert.' : ''
        ].filter(Boolean), [
            { text: 'Zurück bearbeiten', klasse: 'btn-ghost' },
            { text: 'Senden', klasse: 'btn-emerald', tun: absenden }
        ]);
    };

    /** Der Stand des Editors als Nutzlast - dieselbe Form fuer Speichern und Senden. */
    function payload() {
        return {
            signers: state.signers.map(function (s) {
                // "key" ist die Kennung, unter der DIESE Seite den
                // Unterzeichner kennt - der Server schickt darueber die echte
                // zurueck an die Felder. Ohne sie verlor jedes Feld eines im
                // Editor neu angelegten Unterzeichners seinen Besitzer.
                return { id: String(s.id).indexOf('neu-') === 0 ? null : s.id, key: String(s.id),
                         name: s.name, email: s.email, locale: s.locale || 'de' };
            }),
            fields: state.fields.map(function (f) {
                return {
                    id: String(f.id).indexOf('neu-') === 0 ? null : f.id,
                    signer_id: String(f.signer_id || '').indexOf('neu-') === 0 ? null : f.signer_id,
                    signer_key: f.signer_id ? String(f.signer_id) : null,
                    company_asset_id: f.company_asset_id || null,
                    type: f.type, page: f.page, x: f.x, y: f.y, width: f.width, height: f.height,
                    required: f.required ? 1 : 0, label: f.label
                };
            })
        };
    }

    /** Eine Anfrage an den Server - dieselbe Form fuer Speichern und Senden. */
    function schicke(ziel, daten) {
        return fetch(ziel, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(daten)
        }).then(function (response) {
            return response.json().then(function (data) { return { ok: response.ok, data: data }; });
        });
    }

    window.__h["sigSave"] = function () {
        var button = this;
        button.disabled = true;
        document.getElementById('save-state').textContent = 'Wird gespeichert …';

        schicke(urls.save, payload()).then(function (result) {
            button.disabled = false;
            if (!result.ok) {
                document.getElementById('save-state').textContent = result.data.message || 'Speichern fehlgeschlagen.';
                return;
            }
            state.dirty = false;
            // Nach dem Speichern neu laden: erst dann tragen neue Felder und
            // Unterzeichner ihre echten Kennungen, und ein zweites Speichern
            // legt sie nicht ein zweites Mal an.
            window.location.reload();
        }).catch(function () {
            button.disabled = false;
            document.getElementById('save-state').textContent = 'Speichern fehlgeschlagen (Netzwerk).';
        });
    };

    /**
     * SENDEN SPEICHERT SELBST.
     *
     * "Entwurf speichern" war bisher eine Bedingung, die der Mitarbeiter
     * verstehen musste, bevor er versenden durfte - wer sie uebersah,
     * verschickte den Stand von vorhin, ohne Fehlermeldung. Der Editor
     * schickt seinen Stand jetzt MIT dem Versand; gespeichert wird im
     * selben Vorgang auf dem Server.
     */
    function absenden() {
        document.getElementById('save-state').textContent = 'Wird gesendet …';
        schicke(urls.send, payload()).then(function (result) {
            if (!result.ok || !result.data.redirect) {
                zeigeDialog('Versand nicht möglich',
                    [result.data.message || 'Der Versand ist fehlgeschlagen.'],
                    [{ text: 'Weiter bearbeiten', klasse: 'btn-ghost' }]);
                document.getElementById('save-state').textContent = '';
                return;
            }
            state.dirty = false;
            window.location.href = result.data.redirect;
        }).catch(function () {
            zeigeDialog('Versand nicht möglich', ['Netzwerkfehler - bitte erneut versuchen.'],
                [{ text: 'Weiter bearbeiten', klasse: 'btn-ghost' }]);
            document.getElementById('save-state').textContent = '';
        });
    }

    window.__h["sigAbbrechen"] = function () {
        if (!state.dirty) { window.location.href = urls.show; return; }
        // NICHTS WIRD STILL VERWORFEN. Der zweite Knopf sagt ausdruecklich,
        // was er tut - "OK" auf einer Systemabfrage sagt das nicht.
        zeigeDialog('Ungespeicherte Änderungen vorhanden.', [
            'Die gesetzten Felder sind noch nicht gespeichert.',
            'Wenn Sie jetzt abbrechen, gehen sie verloren.'
        ], [
            { text: 'Weiter bearbeiten', klasse: 'btn-ghost' },
            { text: 'Änderungen verwerfen', klasse: 'btn-ghost', tun: function () {
                state.dirty = false;
                window.location.href = urls.show;
            } }
        ]);
    };

    window.addEventListener('beforeunload', function (event) {
        if (state.dirty) { event.preventDefault(); event.returnValue = ''; }
    });

    /**
     * Die Hoehe des Rahmens wird GEMESSEN, nicht geschaetzt.
     *
     * Die Umgebung ueber dem Editor ist nicht ueberall gleich hoch
     * (Kopfzeile, Brotkrumen, Hinweisbaender bei fehlender Vorschau). Mit
     * einer festen Zahl im CSS stand die Aktionsleiste auf dem Telefon
     * genau dann unter der Bildschirmkante, wenn ein Hinweis dazukam - und
     * dann war der Senden-Knopf wieder nur nach dem Scrollen erreichbar,
     * also genau der gemeldete Zustand. Die Werte im CSS bleiben als
     * Rueckfallebene, falls dieses Skript nicht laeuft.
     */
    function passeHoeheAn() {
        var shell = document.getElementById('sig-shell');
        if (!shell) { return; }
        var oben = shell.getBoundingClientRect().top + (window.scrollY || 0);
        shell.style.setProperty('--sig-chrome', Math.round(oben + 14) + 'px');
    }

    renderSigners();
    buildPages();
    passeHoeheAn();
    window.addEventListener('resize', passeHoeheAn);
    beobachteBildlauf();
    schliesseMobilPanels();
    standAktualisieren();
    var initial = document.getElementById('type-unterschrift');
    if (initial) { initial.style.borderColor = 'var(--emerald)'; }
})();
</script>
@endPushOnce
@endsection
