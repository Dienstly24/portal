@extends('layouts.admin')
@section('content')
@php
    $isManager = in_array(auth()->user()->role, ['admin', 'manager'], true);
    $fmtBytes = function (int $b) {
        if ($b >= 1048576) return number_format($b / 1048576, 1, ',', '.') . ' MB';
        if ($b >= 1024) return number_format($b / 1024, 0, ',', '.') . ' KB';
        return $b . ' B';
    };
@endphp
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Medien</span></div>
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <div class="page-title">Medienverwaltung Website</div>
            <div class="page-sub">Bild hochladen → Platz (Slot) wählen → Alt-Texte eintragen → speichern: sofort live auf www.dienstly24.de. Ohne FTP, ohne Code. Jedes Bild wird automatisch verkleinert (AVIF/WebP/JPG, drei Größen, je &lt; 200 KB).</div>
        </div>
        <div style="text-align:right;font-size:12.5px;color:var(--ink-soft);">
            Speicherverbrauch<br><b style="font-size:16px;color:var(--ink);">{{ $fmtBytes($usedBytes) }}</b>
        </div>
    </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

{{-- ============ Hochladen ============ --}}
<div class="card">
    <div class="card-title">⬆️ Bild hochladen</div>
    <form method="POST" action="{{ route('admin.media.store') }}" enctype="multipart/form-data" id="uploadForm">
        @csrf
        <div id="dropzone" style="border:2px dashed var(--line,#d8d8d8);border-radius:12px;padding:26px;text-align:center;cursor:pointer;margin-bottom:14px;">
            <div style="font-size:26px;">🖼️</div>
            <div style="font-weight:600;margin-top:6px;">Dateien hierher ziehen oder klicken</div>
            <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">JPG, PNG, WebP, SVG · max. 10 MB je Datei · mehrere Dateien möglich (bei Slot-Zuweisung genau eine)</div>
            <div id="dropFiles" style="font-size:12.5px;font-weight:600;color:var(--ink);margin-top:8px;"></div>
            <input type="file" name="files[]" id="fileInput" multiple accept=".jpg,.jpeg,.png,.webp,.svg" style="display:none;">
        </div>
        <div class="grid-2">
            <div class="field">
                <label>Platz auf der Website (Slot, optional)</label>
                <select name="slot">
                    <option value="">— nur in Bibliothek ablegen —</option>
                    @foreach($slots as $key => $slot)
                        <option value="{{ $key }}" @selected(old('slot') === $key)>{{ $slot['label'] }} {{ isset($slotUsage[$key]) ? '(belegt – wird ersetzt)' : '(frei)' }}</option>
                    @endforeach
                </select>
                <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">Bei Zuweisung wandert das bisherige Slot-Bild automatisch ins Archiv (nichts wird gelöscht).</div>
            </div>
            <div class="field"><label>Titel (optional, sonst Dateiname)</label><input type="text" name="title" value="{{ old('title') }}" maxlength="150"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Alt-Text Deutsch * <span style="font-weight:400;color:var(--ink-soft);">(Pflicht – Barrierefreiheit/SEO)</span></label><input type="text" name="alt_de" value="{{ old('alt_de') }}" required maxlength="500" placeholder="z. B. Beraterin erklärt Kfz-Versicherung am Tablet"></div>
            <div class="field"><label>Alt-Text Arabisch * <span style="font-weight:400;color:var(--ink-soft);">(Pflicht)</span></label><input type="text" name="alt_ar" value="{{ old('alt_ar') }}" required maxlength="500" dir="rtl" placeholder="وصف الصورة بالعربية"></div>
        </div>
        <div class="field"><label>Bildnachweis (optional, z. B. „Foto: Pexels/…“)</label><input type="text" name="credit" value="{{ old('credit') }}" maxlength="500"></div>
        <button type="submit" class="btn btn-primary" id="uploadBtn">Hochladen & speichern</button>
        <span id="uploadHint" style="display:none;font-size:12.5px;color:var(--ink-soft);margin-inline-start:10px;">⏳ Bilder werden verarbeitet …</span>
    </form>
</div>

{{-- ============ Slots-Uebersicht ============ --}}
<div class="card">
    <div class="card-title">📌 Feste Plätze (Slots)</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;">
        @foreach($slots as $key => $slot)
            <div style="border:1px solid var(--line,#e4e4e4);border-radius:10px;padding:10px 12px;font-size:12.5px;">
                <b>{{ $slot['label'] }}</b><br>
                <span style="color:var(--ink-soft);">{{ $slot['hint'] }}</span><br>
                <span style="font-weight:600;color:{{ isset($slotUsage[$key]) ? 'var(--green,#128a4b)' : '#b3261e' }};">{{ isset($slotUsage[$key]) ? '✔ belegt' : '– leer (Website zeigt eingebauten Fallback)' }}</span>
            </div>
        @endforeach
    </div>
</div>

{{-- ============ Bibliothek ============ --}}
<div class="card">
    <div class="card-title">🗂️ Bibliothek</div>
    <form method="GET" action="{{ route('admin.media') }}" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
        <input type="text" name="q" value="{{ $q }}" placeholder="Suche: Titel, Dateiname, Alt-Text" style="max-width:280px;">
        <select name="slot" onchange="this.form.submit()">
            <option value="">Alle Slots</option>
            @foreach($slots as $key => $slot)<option value="{{ $key }}" @selected($slotFilter === $key)>{{ $slot['label'] }}</option>@endforeach
        </select>
        <button type="submit" class="btn btn-ghost">Suchen</button>
    </form>

    @if($assets->isEmpty())
        <p style="color:var(--ink-soft);">Noch keine Bilder hochgeladen.</p>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;">
        @foreach($assets as $asset)
            @php
                $thumb = $asset->isSvg()
                    ? $asset->fallbackUrl()
                    : (collect($asset->variantsOf('webp'))->first()['path'] ?? null ? \App\Models\MediaAsset::publicUrl(collect($asset->variantsOf('webp'))->first()['path']) : $asset->fallbackUrl());
            @endphp
            <div style="border:1px solid var(--line,#e4e4e4);border-radius:12px;overflow:hidden;background:#fff;">
                <div style="height:130px;background:#f4f2ec;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                    @if($asset->processing_status === 'ready' && $thumb)
                        <img src="{{ $thumb }}" alt="{{ $asset->alt_de }}" style="max-width:100%;max-height:130px;object-fit:contain;" loading="lazy">
                    @elseif($asset->processing_status === 'failed')
                        <span style="font-size:12px;color:#b3261e;padding:8px;text-align:center;">⚠ Verarbeitung fehlgeschlagen<br>{{ Str::limit($asset->processing_error, 60) }}</span>
                    @else
                        <span style="font-size:12px;color:var(--ink-soft);">⏳ in Verarbeitung …</span>
                    @endif
                </div>
                <div style="padding:10px 12px;font-size:12.5px;">
                    <b title="{{ $asset->original_name }}">{{ Str::limit($asset->title, 34) }}</b><br>
                    <span style="color:var(--ink-soft);">{{ $asset->width }}×{{ $asset->height }} · {{ $fmtBytes($asset->totalBytes()) }} · {{ $asset->created_at->format('d.m.Y') }}</span><br>
                    @if($asset->slot)
                        <span style="display:inline-block;margin-top:4px;background:#e8f5ee;color:#128a4b;border-radius:99px;padding:2px 9px;font-weight:600;">📌 {{ $slots[$asset->slot]['label'] ?? $asset->slot }}</span>
                    @endif
                    <details style="margin-top:8px;">
                        <summary style="cursor:pointer;font-weight:600;">Bearbeiten / Slot</summary>
                        <form method="POST" action="{{ route('admin.media.update', $asset) }}" style="margin-top:8px;">
                            @csrf @method('PUT')
                            <div class="field"><label>Titel *</label><input type="text" name="title" value="{{ $asset->title }}" required maxlength="150"></div>
                            <div class="field"><label>Alt DE *</label><input type="text" name="alt_de" value="{{ $asset->alt_de }}" required maxlength="500"></div>
                            <div class="field"><label>Alt AR *</label><input type="text" name="alt_ar" value="{{ $asset->alt_ar }}" required maxlength="500" dir="rtl"></div>
                            <div class="field"><label>Bildnachweis</label><input type="text" name="credit" value="{{ $asset->credit }}" maxlength="500"></div>
                            <div class="field"><label>Slot</label>
                                <select name="slot">
                                    <option value="">— kein Slot (Archiv) —</option>
                                    @foreach($slots as $key => $slot)
                                        <option value="{{ $key }}" @selected($asset->slot === $key)>{{ $slot['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary" style="padding:7px 14px;font-size:12.5px;">Speichern</button>
                        </form>
                        <form method="POST" action="{{ route('admin.media.replace', $asset) }}" enctype="multipart/form-data" style="margin-top:10px;border-top:1px solid var(--line,#eee);padding-top:8px;">
                            @csrf
                            <div class="field"><label>Ersetzen (neue Datei, Slot + Alt-Texte werden übernommen)</label><input type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.svg" required></div>
                            <button type="submit" class="btn btn-ghost" style="padding:7px 14px;font-size:12.5px;">Ersetzen</button>
                        </form>
                        @if($isManager)
                            <form method="POST" action="{{ route('admin.media.delete', $asset) }}" style="margin-top:10px;"
                                  onsubmit="return confirm('{{ $asset->slot ? 'ACHTUNG: Dieses Bild ist einem aktiven Slot zugewiesen - die Website zeigt dann den eingebauten Fallback. ' : '' }}In den Papierkorb legen? (30 Tage wiederherstellbar)');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-ghost" style="padding:7px 14px;font-size:12.5px;color:#b3261e;">🗑 In den Papierkorb</button>
                            </form>
                        @endif
                    </details>
                </div>
            </div>
        @endforeach
    </div>
    <div style="margin-top:14px;">{{ $assets->links() }}</div>
</div>

{{-- ============ Papierkorb ============ --}}
@if($trashed->isNotEmpty())
<div class="card">
    <div class="card-title">🗑 Papierkorb <span style="font-weight:400;font-size:12.5px;color:var(--ink-soft);">(automatische endgültige Löschung nach {{ config('website.media.trash_days') }} Tagen)</span></div>
    <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <tr style="text-align:start;color:var(--ink-soft);"><th style="text-align:start;padding:6px;">Titel</th><th style="text-align:start;padding:6px;">Gelöscht am</th><th style="text-align:start;padding:6px;">Endgültig weg am</th><th></th></tr>
        @foreach($trashed as $asset)
            <tr style="border-top:1px solid var(--line,#eee);">
                <td style="padding:6px;">{{ $asset->title }} <span style="color:var(--ink-soft);">({{ $asset->original_name }})</span></td>
                <td style="padding:6px;">{{ $asset->deleted_at->format('d.m.Y H:i') }}</td>
                <td style="padding:6px;">{{ $asset->deleted_at->addDays((int) config('website.media.trash_days'))->format('d.m.Y') }}</td>
                <td style="padding:6px;text-align:end;">
                    @if($isManager)
                        <form method="POST" action="{{ route('admin.media.restore', $asset->id) }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-ghost" style="padding:5px 12px;font-size:12px;">↩ Wiederherstellen</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
</div>
@endif

<script>
(function () {
    var dz = document.getElementById('dropzone'), input = document.getElementById('fileInput'),
        label = document.getElementById('dropFiles'), form = document.getElementById('uploadForm'),
        btn = document.getElementById('uploadBtn'), hint = document.getElementById('uploadHint');
    function showFiles() {
        label.textContent = input.files.length
            ? Array.from(input.files).map(function (f) { return f.name; }).join(' · ')
            : '';
    }
    dz.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', showFiles);
    ['dragover', 'dragenter'].forEach(function (ev) {
        dz.addEventListener(ev, function (e) { e.preventDefault(); dz.style.borderColor = '#128a4b'; });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        dz.addEventListener(ev, function (e) { e.preventDefault(); dz.style.borderColor = ''; });
    });
    dz.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; showFiles(); }
    });
    form.addEventListener('submit', function (e) {
        if (!input.files.length) { e.preventDefault(); alert('Bitte mindestens eine Datei auswählen.'); return; }
        btn.disabled = true; hint.style.display = 'inline';
    });
})();
</script>
@endsection
