@extends('layouts.portal')
@section('content')
<div class="toolbar">
    <div>
        <div class="page-title">{{ __('Dokumente') }}</div>
        <div class="page-sub" style="margin-bottom:0;">Alle Ihre Dokumente und Unterlagen. Sie können hier auch eigene Dokumente hochladen.</div>
    </div>
    <button onclick="smartScan.open()" class="btn btn-gold">+ {{ __('Dokument hinzufügen') }}</button>
</div>

@if(session('success'))<div style="background:#E4F0E7;color:#3B7A57;padding:10px 16px;border-radius:8px;margin:16px 0;">{{ session('success') }}</div>@endif
@if($errors->any())<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin:16px 0;">{{ $errors->first() }}</div>@endif

{{-- Angeforderte Dokumente (Architekturplan Abschnitt 14: klare Statusanzeige) --}}
@if($documentRequests->isNotEmpty())
<div class="card" style="margin-bottom:20px;">
    <div style="font-weight:700;font-size:15px;margin-bottom:4px;">Angeforderte Dokumente</div>
    <div style="font-size:13px;color:var(--ink-soft);margin-bottom:12px;">Wir benötigen die folgenden Unterlagen von Ihnen. Nach dem Hochladen prüft unser Team Ihr Dokument.</div>
    @foreach($documentRequests as $req)
    <div style="border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin-bottom:10px;
        {{ $req->status === 'rejected' ? 'border-color:#E4B4B4;background:#FDF7F7;' : '' }}
        {{ $req->status === 'approved' ? 'opacity:.65;' : '' }}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
            <div style="min-width:220px;">
                <div style="font-weight:600;font-size:14px;">{{ $req->title }}</div>
                @if($req->description)<div style="font-size:13px;color:var(--ink-soft);margin-top:2px;">{{ $req->description }}</div>@endif
                @if($req->contract)<div style="font-size:12px;color:var(--ink-soft);margin-top:2px;">Vertrag: {{ $req->contract->contract_number }} ({{ $req->contract->insurer }})</div>@endif
                @if($req->deadline && in_array($req->status, ['open','rejected']))
                    <div style="font-size:12px;color:{{ $req->deadline->isPast() ? '#B3261E' : 'var(--ink-soft)' }};margin-top:2px;">Frist: {{ $req->deadline->format('d.m.Y') }}</div>
                @endif
                @if($req->status === 'rejected' && $req->rejection_note)
                    <div style="font-size:13px;color:#B3261E;margin-top:6px;">Hinweis unseres Teams: {{ $req->rejection_note }}</div>
                @endif
            </div>
            <div style="text-align:right;">
                @if($req->status === 'open')<span class="badge" style="background:#FEF3C7;color:#92400E;">{{ $req->statusLabel() }}</span>
                @elseif($req->status === 'uploaded')<span class="badge" style="background:#E6F1FB;color:#185FA5;">{{ $req->statusLabel() }}</span>
                @elseif($req->status === 'approved')<span class="badge" style="background:#E4F0E7;color:#3B7A57;">{{ $req->statusLabel() }}</span>
                @else<span class="badge" style="background:#FBE9E9;color:#B3261E;">{{ $req->statusLabel() }}</span>@endif
            </div>
        </div>
        @if($req->acceptsUpload())
        <form method="POST" action="{{ route('portal.document_requests.upload', $req->id) }}" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap;">
            @csrf
            <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.gif,.doc,.docx" style="font-size:13px;">
            <button type="submit" class="btn btn-gold" style="padding:7px 16px;font-size:13px;">{{ $req->status === 'rejected' ? 'Erneut hochladen' : 'Hochladen' }}</button>
        </form>
        @elseif($req->status === 'uploaded')
        <div style="font-size:13px;color:var(--ink-soft);margin-top:8px;">✓ Ihr Dokument ist eingegangen und wird von unserem Team geprüft.</div>
        @endif
    </div>
    @endforeach
</div>
@endif

<style>
.doc-folder{background:#fff;border:1px solid var(--line);border-radius:14px;margin-bottom:16px;overflow:hidden;}
.doc-folder>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:12px;padding:16px 18px;font-weight:700;font-size:15px;}
.doc-folder>summary::-webkit-details-marker{display:none;}
.doc-folder>summary .fold-ico{font-size:26px;line-height:1;}
.doc-folder>summary .fold-sub{font-weight:500;font-size:12.5px;color:var(--ink-soft);}
.doc-folder>summary .fold-count{margin-left:auto;background:#EEF0F3;color:var(--ink-soft);font-size:12px;font-weight:600;padding:2px 10px;border-radius:20px;}
.doc-folder>summary .fold-chev{transition:transform .15s;color:var(--ink-soft);}
.doc-folder[open]>summary .fold-chev{transform:rotate(90deg);}
.doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;padding:0 18px 18px;}
.doc-tile{background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .15s,transform .15s;}
.doc-tile:hover{box-shadow:0 6px 20px rgba(0,0,0,.08);transform:translateY(-2px);}
.doc-thumb{display:flex;align-items:center;justify-content:center;height:126px;background:#F4F5F7;position:relative;overflow:hidden;text-decoration:none;}
.doc-thumb img{width:100%;height:100%;object-fit:cover;}
.doc-thumb .doc-emoji{font-size:50px;line-height:1;}
.doc-thumb .doc-ext{position:absolute;bottom:8px;right:8px;font-size:10px;font-weight:700;letter-spacing:.03em;background:#17191d;color:#fff;padding:2px 7px;border-radius:6px;text-transform:uppercase;}
.doc-body{padding:11px 13px;display:flex;flex-direction:column;gap:7px;flex:1;}
.doc-name{font-weight:600;font-size:13px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;}
.doc-tags{display:flex;flex-wrap:wrap;gap:5px;font-size:11px;}
.doc-tag{padding:1px 7px;border-radius:5px;background:#EEF0F3;color:var(--ink-soft);}
.doc-tag-you{background:#EAF2FB;color:#185FA5;}
.doc-date{font-size:12px;color:var(--ink-soft);margin-top:auto;}
.doc-actions{display:flex;border-top:1px solid var(--line);}
.doc-actions a{flex:1;text-align:center;padding:10px 4px;font-size:12.5px;font-weight:600;text-decoration:none;color:var(--ink);}
.doc-actions a:hover{background:#F4F5F7;}
.doc-actions a.view{color:#128a4b;border-right:1px solid var(--line);}
</style>

@php
    // Nach Vertrag gruppieren: jeder Vertrag ist ein eigener "Ordner",
    // nicht zugeordnete Dokumente kommen zuletzt.
    $groups = $documents->groupBy(fn($d) => $d->contract_id ?: '');
    $withContract = $groups->filter(fn($v, $k) => $k !== '');
    $withoutContract = $groups->get('', collect());
@endphp

@if($documents->isEmpty())
<div class="card"><p style="color:var(--ink-soft);font-size:14px;padding:12px 0;text-align:center;">📂 Noch keine Dokumente vorhanden. Laden Sie oben Ihr erstes Dokument hoch.</p></div>
@else

    {{-- Ein Ordner je Vertrag --}}
    @foreach($withContract as $docs)
    @php $contract = $docs->first()->contract; @endphp
    <details class="doc-folder" open>
        <summary>
            <span class="fold-ico">{{ $contract?->typeIcon() ?? '📁' }}</span>
            <span>
                {{ $contract?->typeLabel() ?? 'Vertrag' }}
                <span class="fold-sub">{{ $contract?->insurer }}@if($contract?->contract_number) · {{ $contract->contract_number }}@endif</span>
            </span>
            <span class="fold-count">{{ $docs->count() }}</span>
            <span class="fold-chev">▸</span>
        </summary>
        <div class="doc-grid">
            @foreach($docs as $d)@include('portal.partials.document_tile')@endforeach
        </div>
    </details>
    @endforeach

    {{-- Ordner fuer Dokumente ohne Vertragszuordnung --}}
    @if($withoutContract->isNotEmpty())
    <details class="doc-folder" open>
        <summary>
            <span class="fold-ico">📁</span>
            <span>Weitere Dokumente <span class="fold-sub">ohne Vertragszuordnung</span></span>
            <span class="fold-count">{{ $withoutContract->count() }}</span>
            <span class="fold-chev">▸</span>
        </summary>
        <div class="doc-grid">
            @foreach($withoutContract as $d)@include('portal.partials.document_tile')@endforeach
        </div>
    </details>
    @endif
@endif

{{-- ============================================================
     Smart Document Upload: Mehrseiten-Scanner mit KI-Analyse
     (Foto aufnehmen / Bilder auswaehlen / PDF hochladen)
     ============================================================ --}}
<style>
#smart-upload-modal .d24-modal-box{max-width:560px;}
.scan-option{display:flex;align-items:center;gap:14px;width:100%;border:1px solid var(--line);background:#fff;border-radius:12px;padding:15px 16px;margin-bottom:10px;cursor:pointer;text-align:start;font-size:14.5px;font-weight:600;color:var(--ink);transition:border-color .15s, background .15s;}
.scan-option:hover{border-color:var(--gold);background:var(--gold-soft);}
.scan-option .so-ico{font-size:26px;line-height:1;}
.scan-option .so-sub{display:block;font-weight:500;font-size:12px;color:var(--ink-soft);margin-top:2px;}
.scan-pages{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:10px;margin:12px 0;}
.scan-page{position:relative;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#F4F5F7;}
.scan-page img{width:100%;height:110px;object-fit:cover;display:block;}
.scan-page .sp-no{position:absolute;top:6px;inset-inline-start:6px;background:#17191d;color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:10px;}
.scan-page .sp-del{position:absolute;top:4px;inset-inline-end:4px;background:rgba(179,38,30,.92);color:#fff;border:none;border-radius:8px;width:22px;height:22px;font-size:12px;cursor:pointer;line-height:1;}
.scan-page .sp-tools{display:flex;border-top:1px solid var(--line);background:#fff;}
.scan-page .sp-tools button{flex:1;border:none;background:none;padding:5px 0;font-size:12px;cursor:pointer;color:var(--ink-soft);}
.scan-page .sp-tools button:hover{color:var(--ink);background:#F4F5F7;}
#scan-video{width:100%;max-height:52vh;background:#000;border-radius:12px;object-fit:contain;}
.scan-spinner{width:42px;height:42px;border:4px solid var(--line);border-top-color:var(--gold);border-radius:50%;animation:scanspin 0.9s linear infinite;margin:0 auto 14px;}
@keyframes scanspin{to{transform:rotate(360deg);}}
</style>

<div id="smart-upload-modal" class="d24-modal">
    <div class="d24-modal-box">
        <button type="button" onclick="smartScan.close()" style="position:absolute;top:16px;inset-inline-end:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>

        {{-- Schritt 1: Quelle waehlen --}}
        <div data-scan-step="choose">
            <div style="font-size:18px;font-weight:700;margin-bottom:6px;">{{ __('Dokument hinzufügen') }}</div>
            <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">{{ __('Fotografieren Sie mehrere Seiten nacheinander oder wählen Sie Dateien aus. Unser System erkennt den Dokumenttyp automatisch.') }}</p>
            <button type="button" class="scan-option" onclick="smartScan.startCamera()">
                <span class="so-ico">📷</span>
                <span>{{ __('Foto aufnehmen') }}<span class="so-sub">{{ __('Mehrere Seiten nacheinander fotografieren') }}</span></span>
            </button>
            <button type="button" class="scan-option" onclick="document.getElementById('scan-images-input').click()">
                <span class="so-ico">🖼️</span>
                <span>{{ __('Bilder auswählen') }}<span class="so-sub">{{ __('Vorhandene Fotos aus der Galerie') }}</span></span>
            </button>
            <button type="button" class="scan-option" onclick="document.getElementById('scan-pdf-input').click()">
                <span class="so-ico">📄</span>
                <span>{{ __('PDF hochladen') }}<span class="so-sub">{{ __('Fertige PDF-Datei (max. 10 MB)') }}</span></span>
            </button>
            @if($contracts->isNotEmpty())
            <div class="field" style="margin-top:14px;margin-bottom:8px;">
                <label>{{ __('Zu welchem Vertrag gehört das Dokument? (optional)') }}</label>
                <select id="scan-contract">
                    <option value="">{{ __('— Automatisch erkennen / kein Vertrag —') }}</option>
                    @foreach($contracts as $c)
                    <option value="{{ $c->id }}">{{ $c->typeIcon() }} {{ $c->typeLabel() }} · {{ $c->insurer }}@if($c->contract_number) ({{ $c->contract_number }})@endif</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div style="text-align:center;margin-top:12px;">
                <a href="#" onclick="smartScan.close();document.getElementById('upload-doc-modal').style.display='flex';return false;" style="font-size:12.5px;color:var(--ink-soft);">{{ __('Klassischer Upload (Kategorie selbst wählen)') }}</a>
            </div>
        </div>

        {{-- Schritt 2: Kamera --}}
        <div data-scan-step="camera" style="display:none;">
            <div style="font-size:16px;font-weight:700;margin-bottom:10px;">📷 <span id="scan-camera-title">{{ __('Seite fotografieren') }}</span></div>
            <video id="scan-video" autoplay playsinline muted></video>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:14px;">
                <button type="button" class="btn btn-ghost" onclick="smartScan.stopCamera(true)">{{ __('Abbrechen') }}</button>
                <button type="button" class="btn btn-gold" style="font-size:15px;padding:10px 26px;" onclick="smartScan.capture()">⚪ {{ __('Aufnehmen') }}</button>
                <button type="button" class="btn btn-primary" onclick="smartScan.stopCamera(false)">{{ __('Fertig') }}</button>
            </div>
            <p style="font-size:12px;color:var(--ink-soft);text-align:center;margin-top:8px;">{{ __('Tipp: Dokument flach hinlegen und den Ausschnitt füllen.') }}</p>
        </div>

        {{-- Schritt 3: Seitenuebersicht --}}
        <div data-scan-step="pages" style="display:none;">
            <div style="font-size:16px;font-weight:700;" id="scan-pages-title">{{ __('Seiten aufgenommen') }}</div>
            <div class="scan-pages" id="scan-pages-grid"></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                <button type="button" class="btn btn-ghost" style="font-size:12.5px;padding:7px 12px;" onclick="smartScan.startCamera()">📷 {{ __('Seite fotografieren') }}</button>
                <button type="button" class="btn btn-ghost" style="font-size:12.5px;padding:7px 12px;" onclick="document.getElementById('scan-images-input').click()">🖼️ {{ __('Bilder hinzufügen') }}</button>
            </div>
            <button type="button" class="btn btn-gold" style="width:100%;font-size:15px;" onclick="smartScan.upload()">⬆ {{ __('Dokument hochladen') }}</button>
        </div>

        {{-- Schritt 4: Upload + Analyse --}}
        <div data-scan-step="processing" style="display:none;text-align:center;padding:16px 0;">
            <div class="scan-spinner" id="scan-spinner"></div>
            <div style="font-size:15.5px;font-weight:700;" id="scan-status-title">{{ __('Dokument wird hochgeladen...') }}</div>
            <div style="font-size:13px;color:var(--ink-soft);margin-top:8px;" id="scan-status-sub"></div>
            <div id="scan-progress" style="height:8px;background:var(--surface);border:1px solid var(--line);border-radius:6px;overflow:hidden;margin:16px 24px 0;">
                <div id="scan-progress-bar" style="height:100%;width:0;background:var(--gold);transition:width .2s;"></div>
            </div>
            <div id="scan-result" style="display:none;text-align:start;margin:14px 0 0;border:1px solid var(--line);border-radius:12px;padding:14px 16px;"></div>
            <button type="button" class="btn btn-primary" style="display:none;margin-top:16px;" id="scan-done-btn" onclick="window.location.reload()">{{ __('Fertig') }}</button>
        </div>

        <input type="file" id="scan-images-input" accept="image/*" multiple style="display:none;">
        <input type="file" id="scan-camera-input" accept="image/*" capture="environment" style="display:none;">
        <input type="file" id="scan-pdf-input" accept=".pdf,application/pdf" style="display:none;">
    </div>
</div>

<script>
window.smartScan = (function() {
    var pages = [];          // {blob, url}
    var pdfFile = null;
    var stream = null;
    var retakeIndex = null;  // Seite neu aufnehmen
    var pollTimer = null;
    var modal = function() { return document.getElementById('smart-upload-modal'); };

    var L = {
        pagesTaken: @json(__('Seiten aufgenommen')), pageTaken: @json(__('Seite aufgenommen')),
        uploading: @json(__('Dokument wird hochgeladen...')), analyzing: @json(__('Dokument wird analysiert...')),
        analyzeSub: @json(__('Typ erkennen, Daten lesen, zuordnen – das dauert einen Moment.')),
        saved: @json(__('Dokument gespeichert')), recognized: @json(__('erkannt')),
        savedSub: @json(__('Ihr Dokument wurde sicher gespeichert. Unser Team wurde informiert.')),
        stillRunning: @json(__('Die Analyse läuft noch im Hintergrund. Sie können dieses Fenster schließen.')),
        failed: @json(__('Analyse nicht möglich – das Dokument wurde trotzdem gespeichert.')),
        uploadError: @json(__('Upload fehlgeschlagen. Bitte erneut versuchen.')),
        imageError: @json(__('Dieses Bild konnte nicht verarbeitet werden.')),
        cameraError: @json(__('Kamera nicht verfügbar – bitte Fotos über die Galerie auswählen.')),
        retake: @json(__('Neu')), page: @json(__('Seite'))
    };

    function showStep(name) {
        modal().querySelectorAll('[data-scan-step]').forEach(function(el) {
            el.style.display = (el.getAttribute('data-scan-step') === name) ? '' : 'none';
        });
    }

    // Bild dekodieren, auf max. 2000px verkleinern, als JPEG kodieren.
    function toJpeg(source) {
        return new Promise(function(resolve, reject) {
            var url = URL.createObjectURL(source);
            var img = new Image();
            img.onload = function() {
                try {
                    var max = 2000;
                    var scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
                    var w = Math.max(1, Math.round(img.naturalWidth * scale));
                    var h = Math.max(1, Math.round(img.naturalHeight * scale));
                    var canvas = document.createElement('canvas');
                    canvas.width = w; canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    canvas.toBlob(function(blob) {
                        URL.revokeObjectURL(url);
                        blob ? resolve(blob) : reject(new Error('encode'));
                    }, 'image/jpeg', 0.85);
                } catch (e) { URL.revokeObjectURL(url); reject(e); }
            };
            img.onerror = function() { URL.revokeObjectURL(url); reject(new Error('decode')); };
            img.src = url;
        });
    }

    function addPage(blob, index) {
        var entry = { blob: blob, url: URL.createObjectURL(blob) };
        if (index !== null && index !== undefined && pages[index]) {
            URL.revokeObjectURL(pages[index].url);
            pages[index] = entry;
        } else {
            pages.push(entry);
        }
        renderPages();
    }

    // Rendert nur die Seitenuebersicht - Schrittwechsel machen die Aufrufer.
    function renderPages() {
        var grid = document.getElementById('scan-pages-grid');
        grid.innerHTML = '';
        pages.forEach(function(p, i) {
            var div = document.createElement('div');
            div.className = 'scan-page';
            div.innerHTML =
                '<span class="sp-no">' + (i + 1) + '</span>' +
                '<button type="button" class="sp-del" title="' + L.page + ' ' + (i + 1) + ' ✕">✕</button>' +
                '<img alt="' + L.page + ' ' + (i + 1) + '">' +
                '<div class="sp-tools">' +
                    '<button type="button" data-act="left" title="◀">◀</button>' +
                    '<button type="button" data-act="retake" title="📷">📷 ' + L.retake + '</button>' +
                    '<button type="button" data-act="right" title="▶">▶</button>' +
                '</div>';
            div.querySelector('img').src = p.url;
            div.querySelector('.sp-del').onclick = function() { removePage(i); };
            div.querySelector('[data-act="left"]').onclick = function() { movePage(i, -1); };
            div.querySelector('[data-act="right"]').onclick = function() { movePage(i, 1); };
            div.querySelector('[data-act="retake"]').onclick = function() { retakeIndex = i; startCamera(); };
            grid.appendChild(div);
        });
        document.getElementById('scan-pages-title').textContent =
            pages.length + ' ' + (pages.length === 1 ? L.pageTaken : L.pagesTaken);
    }

    function removePage(i) {
        URL.revokeObjectURL(pages[i].url);
        pages.splice(i, 1);
        renderPages();
        if (!pages.length) showStep('choose');
    }

    function movePage(i, dir) {
        var j = i + dir;
        if (j < 0 || j >= pages.length) return;
        var tmp = pages[i]; pages[i] = pages[j]; pages[j] = tmp;
        renderPages();
    }

    function updateCameraTitle() {
        var next = (retakeIndex !== null && retakeIndex !== undefined)
            ? L.page + ' ' + (retakeIndex + 1)
            : L.page + ' ' + (pages.length + 1);
        document.getElementById('scan-camera-title').textContent = next;
    }

    function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            document.getElementById('scan-camera-input').click();
            return;
        }
        stopStream(); // kein zweiter paralleler Kamera-Stream
        navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 2560 }, height: { ideal: 1920 } },
            audio: false
        }).then(function(s) {
            stream = s;
            document.getElementById('scan-video').srcObject = s;
            updateCameraTitle();
            showStep('camera');
        }).catch(function() {
            // Kein Kamera-Zugriff: nativer Kamera-Dialog des Geraets als Fallback
            document.getElementById('scan-camera-input').click();
        });
    }

    function stopStream() {
        if (stream) { stream.getTracks().forEach(function(t) { t.stop(); }); stream = null; }
    }

    function stopCamera(cancel) {
        stopStream();
        retakeIndex = null;
        renderPages();
        showStep(pages.length ? 'pages' : 'choose');
    }

    function capture() {
        var video = document.getElementById('scan-video');
        if (!video.videoWidth) return;
        var max = 2000;
        var scale = Math.min(1, max / Math.max(video.videoWidth, video.videoHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function(blob) {
            if (!blob) return;
            var idx = retakeIndex; retakeIndex = null;
            addPage(blob, idx);
            if (idx !== null && idx !== undefined) {
                // Neuaufnahme einer einzelnen Seite: zurueck zur Uebersicht
                stopStream();
                showStep('pages');
            } else {
                // Mehrseiten-Modus: Kamera bleibt offen fuer die naechste Seite
                updateCameraTitle();
            }
        }, 'image/jpeg', 0.85);
    }

    function handleImagesPicked(input) {
        var files = Array.from(input.files || []);
        input.value = '';
        if (!files.length) return;
        var chain = Promise.resolve();
        files.forEach(function(file) {
            chain = chain.then(function() {
                return toJpeg(file).then(function(blob) { addPage(blob, null); })
                    .catch(function() { alert(L.imageError + ' (' + file.name + ')'); });
            });
        });
        chain.then(function() { showStep(pages.length ? 'pages' : 'choose'); });
    }

    function upload() {
        if (!pages.length && !pdfFile) return;
        showStep('processing');
        document.getElementById('scan-status-title').textContent = L.uploading;
        document.getElementById('scan-status-sub').textContent = '';
        document.getElementById('scan-progress').style.display = '';
        document.getElementById('scan-result').style.display = 'none';
        document.getElementById('scan-done-btn').style.display = 'none';
        document.getElementById('scan-spinner').style.display = '';

        var data = new FormData();
        data.append('_token', @json(csrf_token()));
        var contractSel = document.getElementById('scan-contract');
        if (contractSel && contractSel.value) data.append('contract_id', contractSel.value);
        if (pdfFile) {
            data.append('pdf', pdfFile, pdfFile.name);
        } else {
            pages.forEach(function(p, i) { data.append('pages[]', p.blob, 'seite-' + (i + 1) + '.jpg'); });
        }

        var xhr = new XMLHttpRequest();
        xhr.upload.addEventListener('progress', function(e) {
            if (!e.lengthComputable) return;
            document.getElementById('scan-progress-bar').style.width = Math.round(e.loaded / e.total * 100) + '%';
        });
        xhr.addEventListener('load', function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                var res = {};
                try { res = JSON.parse(xhr.responseText); } catch (e) {}
                document.getElementById('scan-progress').style.display = 'none';
                if (res.ai_enabled) {
                    document.getElementById('scan-status-title').textContent = L.analyzing;
                    document.getElementById('scan-status-sub').textContent = L.analyzeSub;
                    poll(res.id, 0);
                } else {
                    finish(L.saved, L.savedSub, null);
                }
            } else {
                var msg = L.uploadError;
                try { var j = JSON.parse(xhr.responseText); if (j.message) msg = j.message; } catch (e) {}
                failUpload(msg);
            }
        });
        xhr.addEventListener('error', function() { failUpload(L.uploadError); });
        xhr.open('POST', @json(route('portal.documents.scan')));
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(data);
    }

    function failUpload(msg) {
        document.getElementById('scan-spinner').style.display = 'none';
        document.getElementById('scan-progress').style.display = 'none';
        document.getElementById('scan-status-title').textContent = '⚠ ' + msg;
        var btn = document.getElementById('scan-done-btn');
        btn.style.display = '';
        btn.textContent = @json(__('Schließen'));
        btn.onclick = function() { showStep(pages.length ? 'pages' : 'choose'); btn.onclick = function() { window.location.reload(); }; };
    }

    function poll(id, attempt) {
        if (attempt > 40) { finish(L.saved, L.stillRunning, null); return; }
        pollTimer = setTimeout(function() {
            fetch(@json(route('portal.documents.analyse_status', ['id' => '__ID__'])).replace('__ID__', id), {
                headers: { 'Accept': 'application/json' }, credentials: 'same-origin'
            }).then(function(r) { return r.json(); }).then(function(s) {
                if (s.status === 'done') {
                    finish('✓ ' + (s.type_label || s.file_name) + ' ' + L.recognized, s.summary || '', s);
                } else if (s.status === 'failed') {
                    finish(L.saved, L.failed, null);
                } else if (s.status === 'none') {
                    finish(L.saved, L.savedSub, null);
                } else {
                    poll(id, attempt + 1);
                }
            }).catch(function() { poll(id, attempt + 2); });
        }, 2500);
    }

    function finish(title, sub, statusData) {
        document.getElementById('scan-spinner').style.display = 'none';
        document.getElementById('scan-progress').style.display = 'none';
        document.getElementById('scan-status-title').textContent = title;
        document.getElementById('scan-status-sub').textContent = sub || '';
        if (statusData && statusData.file_name) {
            var box = document.getElementById('scan-result');
            box.style.display = '';
            box.innerHTML = '';
            var name = document.createElement('div');
            name.style.cssText = 'font-weight:700;font-size:13.5px;';
            name.textContent = '📄 ' + statusData.file_name;
            var cat = document.createElement('div');
            cat.style.cssText = 'font-size:12.5px;color:var(--ink-soft);margin-top:4px;';
            cat.textContent = @json(__('Abgelegt unter')) + ': ' + (statusData.category_label || '');
            box.appendChild(name); box.appendChild(cat);
        }
        document.getElementById('scan-done-btn').style.display = '';
    }

    function reset() {
        stopStream();
        if (pollTimer) clearTimeout(pollTimer);
        pages.forEach(function(p) { URL.revokeObjectURL(p.url); });
        pages = []; pdfFile = null; retakeIndex = null;
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('scan-images-input').addEventListener('change', function() { handleImagesPicked(this); });
        document.getElementById('scan-camera-input').addEventListener('change', function() {
            var files = Array.from(this.files || []);
            this.value = '';
            if (!files.length) return;
            var idx = retakeIndex; retakeIndex = null;
            toJpeg(files[0]).then(function(blob) { addPage(blob, idx); showStep('pages'); })
                .catch(function() { alert(L.imageError); });
        });
        document.getElementById('scan-pdf-input').addEventListener('change', function() {
            var file = (this.files || [])[0];
            this.value = '';
            if (!file) return;
            if (file.size > 10 * 1024 * 1024) { alert(@json(__('Die Datei ist größer als 10 MB.'))); return; }
            pdfFile = file;
            upload();
        });
    });

    return {
        open: function() { reset(); showStep('choose'); modal().style.display = 'flex'; },
        close: function() { reset(); modal().style.display = 'none'; },
        startCamera: startCamera,
        stopCamera: stopCamera,
        capture: capture,
        upload: upload
    };
})();
</script>

<div id="upload-doc-modal" class="d24-modal">
    <div class="d24-modal-box">
        <button onclick="document.getElementById('upload-doc-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:6px;">Dokument hochladen</div>
        <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;">PDF, Bild (JPG, PNG, HEIC, WEBP), DOC oder XLS – max. 10 MB. Unser Team wird über Ihren Upload informiert.</p>
        <form method="POST" action="{{ route('portal.documents.upload') }}" enctype="multipart/form-data">
            @csrf
            <div class="field"><label>Kategorie *</label>
                <select name="category" required>
                    @foreach(\App\Models\Document::CATEGORIES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if($contracts->isNotEmpty())
            <div class="field"><label>Zu welchem Vertrag gehört das Dokument? (optional)</label>
                <select name="contract_id">
                    <option value="">— Keinem Vertrag zuordnen —</option>
                    @foreach($contracts as $c)
                    <option value="{{ $c->id }}">{{ $c->typeIcon() }} {{ $c->typeLabel() }} · {{ $c->insurer }}@if($c->contract_number) ({{ $c->contract_number }})@endif</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="field"><label>Datei *</label><input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.gif,.doc,.docx,.xls,.xlsx"></div>
            @error('document')<div class="alert-error">{{ $message }}</div>@enderror
            <button type="submit" class="btn btn-primary" style="width:100%;">{{ __('Hochladen') }}</button>
        </form>
    </div>
</div>
@endsection
