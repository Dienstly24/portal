@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Banner</span></div>
    <div class="page-title">Bannerverwaltung</div>
    <div class="page-sub">Werbebanner im Kundenportal – Bild, Video oder GIF, planbar, mit Klick-Ziel und Statistiken. Mehrere aktive Banner rotieren als Slider.</div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

{{-- ============ Neuen Banner erstellen ============ --}}
<div class="card">
    <div class="card-title">➕ Neuen Banner erstellen</div>
    <form method="POST" action="{{ route('admin.banners.store') }}" enctype="multipart/form-data" id="createForm">
        @csrf
        <div class="grid-2">
            <div class="field"><label>Titel *</label><input type="text" name="title" required maxlength="150" placeholder="z.B. Stromwechsel Juli 2026"></div>
            <div class="field"><label>Bild / Video / GIF * (beliebige Maße – JPG/PNG/WEBP/GIF/MP4/WEBM, max. 20 MB)</label>
                <input type="file" name="media" id="createMedia" required accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm">
                <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">JPG/PNG werden automatisch komprimiert und als WebP gespeichert.</div>
            </div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Klick-Ziel (Link, optional)</label><input type="text" name="link_url" placeholder="/portal/contracts oder https://beispiel.de"></div>
            <div class="field"><label>Öffnen in</label>
                <select name="link_target"><option value="self">Gleicher Seite</option><option value="blank">Neuem Tab</option></select>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
            <div class="field"><label>Startdatum</label><input type="date" name="start_date"></div>
            <div class="field"><label>Enddatum</label><input type="date" name="end_date"></div>
            <div class="field"><label>Schließen-Button <span style="font-weight:400;color:var(--ink-soft);">(Tage ausgeblendet)</span></label><input type="number" name="dismiss_days" min="1" max="365" placeholder="leer = kein ✕"></div>
            <div class="field"><label>Als Entwurf</label>
                <label style="display:flex;align-items:center;gap:8px;margin-top:8px;cursor:pointer;font-size:13.5px;"><input type="checkbox" name="is_draft" value="1" style="width:16px;height:16px;"> nicht sofort ausspielen</label>
            </div>
        </div>
        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Banner erstellen</button>
            <button type="button" class="btn btn-ghost" onclick="previewCreate()">👁 Vorschau</button>
        </div>
    </form>
</div>

{{-- ============ Bannerliste ============ --}}
<div class="card">
    <div class="card-title">Vorhandene Banner ({{ $banners->count() }})</div>
    @forelse($banners as $idx => $b)
    @php $st = $b->statusInfo(); @endphp
    <div style="border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin-bottom:14px;">
        <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
            {{-- Thumbnail --}}
            <div style="flex:none;">
                @if($b->media_type === 'video')
                <video src="{{ asset('storage/' . $b->media_path) }}" style="width:150px;height:84px;object-fit:cover;border-radius:8px;border:1px solid var(--line);" muted></video>
                @else
                <img src="{{ asset('storage/' . $b->media_path) }}" style="width:150px;height:84px;object-fit:cover;border-radius:8px;border:1px solid var(--line);" alt="">
                @endif
            </div>
            {{-- Infos --}}
            <div style="flex:1;min-width:230px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <strong style="font-size:15px;">{{ $b->title }}</strong>
                    <span style="background:{{ $st['bg'] }};color:{{ $st['color'] }};border-radius:12px;padding:2px 11px;font-size:11.5px;font-weight:600;">{{ $st['label'] }}</span>
                    <span style="font-size:12px;color:var(--ink-soft);">{{ strtoupper($b->media_type) }} · Pos. {{ $idx + 1 }}</span>
                </div>
                <div style="font-size:12.5px;color:var(--ink-soft);margin-top:5px;">
                    Zeitraum: {{ $b->start_date?->format('d.m.Y') ?? 'sofort' }} – {{ $b->end_date?->format('d.m.Y') ?? 'unbegrenzt' }}
                    @if($b->link_url) · 🔗 {{ \Illuminate\Support\Str::limit($b->link_url, 40) }} ({{ $b->link_target === 'blank' ? 'neuer Tab' : 'gleiche Seite' }}) @endif
                    @if($b->dismiss_days) · ✕ {{ $b->dismiss_days }} Tage @endif
                </div>
                {{-- Statistiken --}}
                <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:9px;font-size:12.5px;">
                    <span title="Heute: {{ $b->impressionsSince(1) }} · 7 Tage: {{ $b->impressionsSince(7) }} · 30 Tage: {{ $b->impressionsSince(30) }}">👁 <strong>{{ number_format($b->total_impressions, 0, ',', '.') }}</strong> Impressions</span>
                    <span>👤 <strong>{{ $b->uniqueViewers() }}</strong> Kunden</span>
                    <span>🖱 <strong>{{ number_format($b->total_clicks, 0, ',', '.') }}</strong> Klicks</span>
                    <span>📈 CTR <strong>{{ number_format($b->ctr(), 1, ',', '.') }} %</strong></span>
                    <span style="color:var(--ink-soft);">Zuletzt gezeigt: {{ $b->last_shown_at?->format('d.m.Y H:i') ?? '—' }}</span>
                </div>
                <div style="font-size:11.5px;color:var(--ink-soft);margin-top:6px;">
                    Erstellt: {{ $b->created_at->format('d.m.Y H:i') }}{{ $b->created_by && isset($creators[$b->created_by]) ? ' von ' . $creators[$b->created_by] : '' }}
                    @if($b->updated_by) · Zuletzt geändert: {{ $b->updated_at->format('d.m.Y H:i') }}{{ isset($creators[$b->updated_by]) ? ' von ' . $creators[$b->updated_by] : '' }} @endif
                </div>
            </div>
            {{-- Sortierung --}}
            <div style="display:flex;flex-direction:column;gap:4px;">
                <form method="POST" action="{{ route('admin.banners.move', $b->id) }}">@csrf<input type="hidden" name="direction" value="up"><button type="submit" class="btn btn-ghost btn-sm" {{ $idx === 0 ? 'disabled style=opacity:.35' : '' }} title="Nach oben">↑</button></form>
                <form method="POST" action="{{ route('admin.banners.move', $b->id) }}">@csrf<input type="hidden" name="direction" value="down"><button type="submit" class="btn btn-ghost btn-sm" {{ $idx === $banners->count() - 1 ? 'disabled style=opacity:.35' : '' }} title="Nach unten">↓</button></form>
            </div>
        </div>

        {{-- Aktionen --}}
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;border-top:1px solid var(--line);padding-top:12px;">
            <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('edit-{{ $b->id }}').style.display = document.getElementById('edit-{{ $b->id }}').style.display === 'none' ? 'block' : 'none'">✏️ Bearbeiten</button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="previewBanner('{{ asset('storage/' . $b->media_path) }}', '{{ $b->media_type }}', '{{ addslashes($b->title) }}')">👁 Vorschau</button>
            <form method="POST" action="{{ route('admin.banners.toggle', $b->id) }}">@csrf<button type="submit" class="btn btn-ghost btn-sm">{{ $b->is_active ? '⏸ Deaktivieren' : '▶ Aktivieren' }}</button></form>
            <form method="POST" action="{{ route('admin.banners.reset_stats', $b->id) }}" onsubmit="return confirm('Statistiken dieses Banners wirklich auf null setzen?');">@csrf<button type="submit" class="btn btn-ghost btn-sm">🔄 Statistik zurücksetzen</button></form>
            <form method="POST" action="{{ route('admin.banners.delete', $b->id) }}" onsubmit="return confirm('Banner {{ addslashes($b->title) }} endgültig löschen?');">@csrf<button type="submit" class="btn btn-sm" style="background:#F9E3E3;color:#A32D2D;border:1px solid #F0A0A0;">🗑 Löschen</button></form>
        </div>

        {{-- Bearbeiten-Formular (aufklappbar) --}}
        <div id="edit-{{ $b->id }}" style="display:none;margin-top:14px;border-top:1px dashed var(--line);padding-top:14px;">
            <form method="POST" action="{{ route('admin.banners.update', $b->id) }}" enctype="multipart/form-data">
                @csrf
                <div class="grid-2">
                    <div class="field"><label>Titel</label><input type="text" name="title" value="{{ $b->title }}" required maxlength="150"></div>
                    <div class="field"><label>Neues Medium (optional – ersetzt das aktuelle)</label><input type="file" name="media" accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm"></div>
                </div>
                <div class="grid-2">
                    <div class="field"><label>Klick-Ziel (Link)</label><input type="text" name="link_url" value="{{ $b->link_url }}" placeholder="/portal/contracts oder https://…"></div>
                    <div class="field"><label>Öffnen in</label>
                        <select name="link_target"><option value="self" {{ $b->link_target === 'self' ? 'selected' : '' }}>Gleicher Seite</option><option value="blank" {{ $b->link_target === 'blank' ? 'selected' : '' }}>Neuem Tab</option></select>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
                    <div class="field"><label>Startdatum</label><input type="date" name="start_date" value="{{ $b->start_date?->format('Y-m-d') }}"></div>
                    <div class="field"><label>Enddatum</label><input type="date" name="end_date" value="{{ $b->end_date?->format('Y-m-d') }}"></div>
                    <div class="field"><label>Schließen-Button (Tage)</label><input type="number" name="dismiss_days" min="1" max="365" value="{{ $b->dismiss_days }}" placeholder="leer = kein ✕"></div>
                    <div class="field"><label>Entwurf</label>
                        <label style="display:flex;align-items:center;gap:8px;margin-top:8px;cursor:pointer;font-size:13.5px;"><input type="checkbox" name="is_draft" value="1" {{ $b->is_draft ? 'checked' : '' }} style="width:16px;height:16px;"> nicht ausspielen</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">💾 Speichern</button>
            </form>
        </div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Banner angelegt.</p>
    @endforelse
</div>

{{-- Vorschau-Modal: zeigt das Medium in voller Breite wie im Kundenportal --}}
<div id="previewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:400;align-items:center;justify-content:center;padding:24px;" onclick="this.style.display='none'">
    <div style="background:#fff;border-radius:14px;max-width:900px;width:100%;overflow:hidden;" onclick="event.stopPropagation()">
        <div style="padding:12px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);">
            <strong style="font-size:14px;">Vorschau – so erscheint der Banner im Kundenportal</strong>
            <button onclick="document.getElementById('previewModal').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
        </div>
        <div id="previewBody" style="position:relative;background:#0e1f1b;"></div>
    </div>
</div>

<script>
function previewBanner(src, type, title) {
    const body = document.getElementById('previewBody');
    const media = type === 'video'
        ? '<video src="' + src + '" style="width:100%;height:auto;max-height:70vh;display:block;" autoplay muted loop playsinline></video>'
        : '<img src="' + src + '" style="width:100%;height:auto;max-height:70vh;object-fit:contain;display:block;">';
    body.innerHTML = media + '<span style="position:absolute;left:0;right:0;bottom:0;padding:14px 18px;background:linear-gradient(transparent,rgba(0,0,0,.65));color:#fff;font-weight:700;font-size:15px;">' + title + ' <span style="font-weight:400;font-size:12.5px;">– Mehr erfahren →</span></span>';
    document.getElementById('previewModal').style.display = 'flex';
}
// Vorschau VOR dem Speichern: liest die gewählte Datei lokal (FileReader).
function previewCreate() {
    const input = document.getElementById('createMedia');
    const title = document.querySelector('#createForm [name=title]').value || 'Banner-Titel';
    if (!input.files || !input.files[0]) { alert('Bitte zuerst eine Datei auswählen.'); return; }
    const file = input.files[0];
    const url = URL.createObjectURL(file);
    previewBanner(url, file.type.startsWith('video') ? 'video' : 'image', title.replace(/'/g, ''));
}
</script>
@endsection
