@extends('layouts.admin')
@section('content')
@php
    $signerData = $signature->signers->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'email' => $s->email])->values();
    $fieldData = $signature->fields->map(fn ($f) => [
        'id' => $f->id, 'signer_id' => $f->signature_signer_id, 'type' => $f->type, 'page' => $f->page,
        'x' => (float) $f->pos_x, 'y' => (float) $f->pos_y, 'width' => (float) $f->width, 'height' => (float) $f->height,
        'required' => (bool) $f->required, 'label' => $f->label,
    ])->values();
@endphp
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.index') }}">Signaturen</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.show', $signature->id) }}">{{ Str::limit($signature->title, 40) }}</a>
        <span class="breadcrumb-sep">›</span><span>Felder setzen</span>
    </div>
    <div>
        <div class="page-title">Felder setzen</div>
        <div class="page-sub">Feldart wählen, auf die Seite klicken, Unterzeichner zuordnen. Ziehen zum Verschieben, Ecke unten rechts zum Vergrößern.</div>
    </div>
</div>

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald-ink);padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(!$signature->isDraft())
<div style="background:#FFF6E5;color:#8A5D00;padding:10px 16px;border-radius:8px;margin-bottom:16px;">
    Diese Anfrage wurde bereits versendet. Die Aufteilung lässt sich nicht mehr ändern – sonst veränderte sich das
    Dokument unter einem Unterzeichner, der es schon geöffnet hat.
</div>
@endif
@if(!$previewAvailable)
<div style="background:#FFF6E5;color:#8A5D00;padding:10px 16px;border-radius:8px;margin-bottom:16px;">
    Die Seitenvorschau ist auf diesem Server nicht verfügbar (poppler-utils/<code>pdftoppm</code> fehlt).
    Die Seiten werden maßstabsgetreu leer dargestellt – Felder lassen sich trotzdem setzen, die Positionen stimmen.
    Zum Nachlesen: <a href="{{ route('admin.signatures.download', [$signature->id, 'original']) }}">Original-PDF herunterladen</a>.
</div>
@endif

<div style="display:grid;grid-template-columns:290px 1fr;gap:18px;align-items:start;">

    <div style="position:sticky;top:14px;display:grid;gap:14px;">
        <div class="card" style="padding:0;">
            <div class="card-head-bar">Unterzeichner</div>
            <div style="padding:14px 16px;display:grid;gap:10px;" id="signer-list"></div>
            <div style="padding:0 16px 14px;">
                <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigAddSigner" @disabled(!$signature->isDraft())>+ Unterzeichner</button>
            </div>
        </div>

        <div class="card" style="padding:0;">
            <div class="card-head-bar">Feld hinzufügen</div>
            <div style="padding:14px 16px;display:grid;gap:8px;">
                <div class="muted-sm">Zuerst Unterzeichner wählen, dann Feldart, dann auf die Seite klicken.</div>
                <select id="active-signer" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;"></select>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    @foreach($fieldTypes as $key => $label)
                    <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigPickType" data-type="{{ $key }}"
                            id="type-{{ $key }}" @disabled(!$signature->isDraft())>{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card" style="padding:0;">
            <div class="card-head-bar">Ansicht</div>
            <div style="padding:14px 16px;display:grid;gap:10px;">
                <div style="display:flex;gap:6px;align-items:center;">
                    <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigZoomOut">−</button>
                    <span id="zoom-label" class="muted-sm">100 %</span>
                    <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigZoomIn">+</button>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;" id="page-nav"></div>
            </div>
        </div>

        <div class="card" style="padding:14px 16px;display:grid;gap:10px;">
            <button type="button" class="btn btn-emerald" data-h-click="sigSave" @disabled(!$signature->isDraft())>Entwurf speichern</button>
            <a href="{{ route('admin.signatures.show', $signature->id) }}" class="btn btn-ghost btn-sm">Zur Übersicht &amp; zum Versand</a>
            <div id="save-state" class="muted-sm"></div>
        </div>
    </div>

    <div id="pages" style="display:grid;gap:22px;justify-items:center;"></div>
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
    <div style="display:flex;gap:6px;">
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
        dirty: false
    };
    var urls = {
        page: @json(route('admin.signatures.page', [$signature->id, 0])),
        save: @json(route('admin.signatures.prepare.save', $signature->id))
    };
    var typeLabels = @json($fieldTypes);
    // Farbe je Unterzeichner: die Zuordnung muss auf einen Blick sichtbar
    // sein - "welches Feld gehoert wem" ist die Frage, die dieser Editor
    // beantworten muss.
    //
    // Die Marken- und Statusfarben kommen ueber brandColor() aus demselben
    // Token wie das uebrige Portal (UX-1): die Kaesten werden per
    // JavaScript zusammengesetzt, und ein style-Attribut loest kein var()
    // auf. Die drei uebrigen Toene sind reine Unterscheidungsfarben ohne
    // Markenbedeutung - sie haben bewusst kein Token.
    // brandColor() steht im Layout vor diesem Block (public/js/brand.js,
    // klassisches Skript) und liest denselben Token wie das CSS.
    var palette = [
        brandColor('emerald'), brandColor('status-info'), brandColor('status-warning'),
        '#8E44AD', '#7A5C2E'
    ];

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

    // ---------------------------------------------------------- Seitenaufbau
    function buildPages() {
        var host = document.getElementById('pages');
        host.textContent = '';
        var nav = document.getElementById('page-nav');
        nav.textContent = '';

        for (var page = 1; page <= state.pageCount; page++) {
            var geo = geometryFor(page);
            var width = 820 * state.zoom;
            var wrap = document.createElement('div');
            wrap.style.cssText = 'width:' + width + 'px;max-width:100%;';

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
                img.src = urls.page.replace(/\/0$/, '/' + page);
                img.alt = 'Seite ' + page;
                img.style.cssText = 'width:100%;height:100%;display:block;object-fit:contain;';
                img.draggable = false;
                sheet.appendChild(img);
            }
            if (editable) {
                sheet.setAttribute('data-h-click', 'sigPlace');
            }
            wrap.appendChild(sheet);
            host.appendChild(wrap);

            var jump = document.createElement('a');
            jump.href = '#seite-' + page;
            jump.className = 'badge badge-muted';
            jump.style.cssText = 'text-decoration:none;padding:5px 9px;font-size:12px;';
            jump.textContent = String(page);
            nav.appendChild(jump);
        }
        renderFields();
    }

    function renderFields() {
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
            caption.style.cssText = 'font-size:11px;line-height:1.15;padding:2px 4px;color:' + color + ';'
                + 'font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
            var owner = state.signers.find(function (s) { return s.id === field.signer_id; });
            caption.textContent = (typeLabels[field.type] || field.type)
                + ' · ' + (owner ? owner.name : 'ohne Unterzeichner');
            box.appendChild(caption);

            if (editable) {
                box.setAttribute('data-h-pointerdown', 'sigDragStart');
                box.setAttribute('data-h-dblclick', 'sigOpenMenu');
                var handle = document.createElement('div');
                handle.setAttribute('data-resize', '1');
                handle.style.cssText = 'position:absolute;right:0;bottom:0;width:14px;height:14px;'
                    + 'background:' + color + ';cursor:nwse-resize;border-radius:3px 0 0 0;';
                box.appendChild(handle);
            }
            sheet.appendChild(box);
        });
    }

    function renderSigners() {
        var list = document.getElementById('signer-list');
        list.textContent = '';
        state.signers.forEach(function (signer, index) {
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:center;';
            var dot = document.createElement('span');
            dot.style.cssText = 'width:11px;height:11px;border-radius:50%;flex:none;background:' + palette[index % palette.length] + ';';
            var text = document.createElement('div');
            text.style.cssText = 'font-size:13px;min-width:0;flex:1;';
            var name = document.createElement('div');
            name.style.fontWeight = '600';
            name.textContent = signer.name || '(ohne Namen)';
            var mail = document.createElement('div');
            mail.className = 'muted-sm';
            mail.style.cssText = 'overflow:hidden;text-overflow:ellipsis;';
            mail.textContent = signer.email || '(ohne E-Mail)';
            text.appendChild(name); text.appendChild(mail);
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

    // ------------------------------------------------------------- Handlungen
    window.__h["sigPickType"] = function () {
        state.type = this.getAttribute('data-type');
        Object.keys(typeLabels).forEach(function (key) {
            var button = document.getElementById('type-' + key);
            if (button) { button.style.borderColor = key === state.type ? 'var(--emerald)' : ''; }
        });
    };

    window.__h["sigPlace"] = function (event) {
        if (!editable) { return; }
        if (event.target.closest('[data-field-box]')) { return; }
        if (!state.signers.length) {
            alert('Bitte zuerst einen Unterzeichner anlegen.');
            return;
        }
        var sheet = this;
        var rect = sheet.getBoundingClientRect();
        var size = defaultSize(state.type);
        var x = (event.clientX - rect.left) / rect.width - size[0] / 2;
        var y = (event.clientY - rect.top) / rect.height - size[1] / 2;
        state.fields.push({
            id: uid(),
            signer_id: document.getElementById('active-signer').value || state.signers[0].id,
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
        if (drag) { markDirty(); drag = null; }
    });

    window.__h["sigOpenMenu"] = function (event) {
        if (!editable) { return; }
        var id = this.getAttribute('data-field-box');
        var field = state.fields.find(function (f) { return f.id === id; });
        if (!field) { return; }
        state.selected = id;
        var menu = document.getElementById('field-menu');
        menu.hidden = false;
        menu.style.left = (window.scrollX + event.clientX + 8) + 'px';
        menu.style.top = (window.scrollY + event.clientY + 8) + 'px';
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

    window.__h["sigAddSigner"] = function () {
        if (state.signers.length >= 10) { return; }
        var name = prompt('Name des Unterzeichners:');
        if (!name) { return; }
        var email = prompt('E-Mail-Adresse von ' + name + ':');
        if (!email) { return; }
        state.signers.push({ id: uid(), name: name, email: email });
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
        markDirty();
        renderSigners();
        renderFields();
    };

    window.__h["sigZoomIn"] = function () { setZoom(state.zoom + 0.2); };
    window.__h["sigZoomOut"] = function () { setZoom(state.zoom - 0.2); };
    function setZoom(value) {
        state.zoom = Math.max(0.6, Math.min(2.2, Math.round(value * 10) / 10));
        document.getElementById('zoom-label').textContent = Math.round(state.zoom * 100) + ' %';
        buildPages();
    }

    function markDirty() {
        state.dirty = true;
        document.getElementById('save-state').textContent = 'Nicht gespeicherte Änderungen.';
    }

    window.__h["sigSave"] = function () {
        var button = this;
        button.disabled = true;
        document.getElementById('save-state').textContent = 'Wird gespeichert …';

        // Neue Datensaetze tragen eine Behelfs-Kennung ("neu-…"); der Server
        // vergibt die echte. Sie darf deshalb nicht mitgeschickt werden.
        var payload = {
            signers: state.signers.map(function (s) {
                // "key" ist die Kennung, unter der DIESE Seite den
                // Unterzeichner kennt - der Server schickt darueber die echte
                // zurueck an die Felder. Ohne sie verlor jedes Feld eines im
                // Editor neu angelegten Unterzeichners seinen Besitzer.
                return { id: String(s.id).indexOf('neu-') === 0 ? null : s.id, key: String(s.id), name: s.name, email: s.email };
            }),
            fields: state.fields.map(function (f) {
                return {
                    id: String(f.id).indexOf('neu-') === 0 ? null : f.id,
                    signer_id: String(f.signer_id || '').indexOf('neu-') === 0 ? null : f.signer_id,
                    signer_key: f.signer_id ? String(f.signer_id) : null,
                    type: f.type, page: f.page, x: f.x, y: f.y, width: f.width, height: f.height,
                    required: f.required ? 1 : 0, label: f.label
                };
            })
        };

        fetch(urls.save, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (data) { return { ok: response.ok, data: data }; });
        }).then(function (result) {
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

    window.addEventListener('beforeunload', function (event) {
        if (state.dirty) { event.preventDefault(); event.returnValue = ''; }
    });

    renderSigners();
    buildPages();
    var initial = document.getElementById('type-unterschrift');
    if (initial) { initial.style.borderColor = 'var(--emerald)'; }
})();
</script>
@endPushOnce
@endsection
