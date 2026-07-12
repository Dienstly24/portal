@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Banner</span></div>
    <div class="page-title">Bannerverwaltung</div>
    <div class="page-sub">Werbebanner im Kundenportal – Bild oder Video, planbar mit Start-/Enddatum. Mehrere aktive Banner rotieren als Slider.</div>
</div>

<div class="card">
    <div class="card-title">Neuen Banner erstellen</div>
    <form method="POST" action="{{ route('admin.banners.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="grid-2">
            <div class="field"><label>Titel * <span style="font-weight:400;color:var(--ink-soft);">(wird in der Supportanfrage referenziert)</span></label><input type="text" name="title" required maxlength="150" placeholder="z.B. Stromwechsel Juli 2026"></div>
            <div class="field"><label>Bild oder Video * (JPG/PNG/WEBP/MP4/WEBM, max. 20 MB)</label><input type="file" name="media" required accept=".jpg,.jpeg,.png,.webp,.mp4,.webm"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
            <div class="field"><label>Sortierung</label><input type="number" name="sort_order" value="0" min="0"></div>
            <div class="field"><label>Startdatum</label><input type="date" name="start_date"></div>
            <div class="field"><label>Enddatum</label><input type="date" name="end_date"></div>
        </div>
        @error('media')<div class="alert-error">{{ $message }}</div>@enderror
        <button type="submit" class="btn btn-primary">Banner erstellen</button>
    </form>
</div>

<div class="card">
    <div class="card-title">Vorhandene Banner ({{ $banners->count() }})</div>
    @forelse($banners as $b)
    <div style="border:1px solid var(--line);border-radius:10px;padding:14px;margin-bottom:12px;">
        <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;">
            <div style="width:180px;flex:none;">
                @if($b->media_type === 'video')
                <video src="{{ asset('storage/' . $b->media_path) }}" style="width:100%;border-radius:8px;" muted></video>
                @else
                <img src="{{ asset('storage/' . $b->media_path) }}" style="width:100%;border-radius:8px;" alt="{{ $b->title }}">
                @endif
            </div>
            <div style="flex:1;min-width:220px;">
                <div style="font-weight:700;font-size:14.5px;">{{ $b->title }}
                    <span class="badge badge-{{ $b->is_active ? 'active' : 'closed' }}">{{ $b->is_active ? 'Aktiv' : 'Inaktiv' }}</span>
                    <span style="font-size:11px;color:var(--ink-soft);">{{ $b->media_type === 'video' ? '🎬 Video' : '🖼️ Bild' }} · Sort. {{ $b->sort_order }}</span>
                </div>
                <div style="font-size:12px;color:var(--ink-soft);margin:4px 0 10px;">
                    Zeitraum: {{ $b->start_date?->format('d.m.Y') ?? 'sofort' }} – {{ $b->end_date?->format('d.m.Y') ?? 'unbegrenzt' }}
                </div>
                <form method="POST" action="{{ route('admin.banners.update', $b->id) }}" enctype="multipart/form-data" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:8px;align-items:end;">
                    @csrf
                    <div class="field" style="margin-bottom:0;"><label style="font-size:11px;">Titel</label><input type="text" name="title" value="{{ $b->title }}" required></div>
                    <div class="field" style="margin-bottom:0;"><label style="font-size:11px;">Sort.</label><input type="number" name="sort_order" value="{{ $b->sort_order }}" min="0"></div>
                    <div class="field" style="margin-bottom:0;"><label style="font-size:11px;">Start</label><input type="date" name="start_date" value="{{ $b->start_date?->toDateString() }}"></div>
                    <div class="field" style="margin-bottom:0;"><label style="font-size:11px;">Ende</label><input type="date" name="end_date" value="{{ $b->end_date?->toDateString() }}"></div>
                    <button type="submit" class="btn btn-ghost btn-sm">Speichern</button>
                </form>
                <div style="display:flex;gap:8px;margin-top:8px;">
                    <form method="POST" action="{{ route('admin.banners.toggle', $b->id) }}">@csrf<button type="submit" class="btn btn-ghost btn-sm">{{ $b->is_active ? '⏸ Deaktivieren' : '▶ Aktivieren' }}</button></form>
                    <form method="POST" action="{{ route('admin.banners.delete', $b->id) }}" onsubmit="return confirm('Banner wirklich löschen?')">@csrf<button type="submit" class="btn btn-ghost btn-sm" style="color:#A32D2D;">🗑 Löschen</button></form>
                </div>
            </div>
        </div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;padding:12px 0;">Noch keine Banner angelegt.</p>
    @endforelse
</div>
@endsection
