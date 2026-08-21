@extends('layouts.admin')
@section('content')
@php
    $platforms = \App\Models\BannerSocialPost::PLATFORMS;
    $formatSpecs = \App\Services\Social\SocialFormatGenerator::FORMATS;
    $st = $banner->statusInfo();
@endphp
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.banners') }}">Banner</a><span class="breadcrumb-sep">›</span><span>Social-Media</span></div>
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <div class="page-title">📣 Social-Media-Publishing</div>
            <div class="page-sub">Einmal vorbereiten, überall posten: fertige Bildformate, Beitragstexte (DE/AR) und messbare Tracking-Links für Facebook, Instagram und TikTok.</div>
        </div>
        <a href="{{ route('admin.banners.stats') }}" class="btn btn-ghost">📊 Statistik-Dashboard</a>
    </div>
</div>

{{-- Erfolgsmeldung rendert das Layout zentral; hier nur Fehler. --}}
@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

{{-- ============ Banner-Bezug ============ --}}
<div class="card">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
        @if($banner->media_type === 'video')
        <video src="{{ asset('storage/' . $banner->media_path) }}" style="width:150px;height:84px;object-fit:cover;border-radius:8px;border:1px solid var(--line);" muted></video>
        @else
        <img src="{{ asset('storage/' . $banner->media_path) }}" style="width:150px;height:84px;object-fit:cover;border-radius:8px;border:1px solid var(--line);" alt="">
        @endif
        <div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <strong style="font-size:15px;">{{ $banner->title }}</strong>
                <span style="background:{{ $st['bg'] }};color:{{ $st['color'] }};border-radius:12px;padding:2px 11px;font-size:11.5px;font-weight:600;">{{ $st['label'] }}</span>
            </div>
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:5px;">
                Portal-Zeitraum: {{ $banner->start_date?->format('d.m.Y') ?? 'sofort' }} – {{ $banner->end_date?->format('d.m.Y') ?? 'unbegrenzt' }}
                · {{ strtoupper($banner->media_type) }}
            </div>
        </div>
    </div>
</div>

{{-- ============ Beitrag pflegen ============ --}}
<div class="card">
    <div class="card-title">✍️ Beitrag &amp; Plattformen</div>
    <form method="POST" action="{{ route('admin.banners.social.save', $banner->id) }}">
        @csrf
        <div class="grid-2">
            <div class="field">
                <label>Beitragstext Deutsch</label>
                <textarea name="caption_de" rows="6" maxlength="3000" placeholder="Text für den Beitrag – z. B. Angebot, Nutzen, Aufruf. Der Tracking-Link wird unten je Plattform bereitgestellt.">{{ old('caption_de', $post?->caption_de) }}</textarea>
            </div>
            <div class="field">
                <label>Beitragstext Arabisch</label>
                <textarea name="caption_ar" rows="6" maxlength="3000" dir="rtl" placeholder="النص العربي للمنشور">{{ old('caption_ar', $post?->caption_ar) }}</textarea>
            </div>
        </div>
        <div class="grid-2">
            <div class="field">
                <label>Klick-Ziel (öffentliche Seite) *empfohlen</label>
                <input type="text" name="target_url" value="{{ old('target_url', $post?->target_url) }}" placeholder="https://www.dienstly24.de/leistungen/strom">
                <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">Muss öffentlich erreichbar sein (https://…) – Portal-interne Links liegen hinter dem Login. Ohne Angabe führt der Tracking-Link auf die Startseite.</div>
            </div>
            <div class="field">
                <label>Plattformen</label>
                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;">
                    @foreach($platforms as $key => $p)
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13.5px;">
                        {{-- padding:0 noetig: .field input bringt sonst 10px Innenabstand --}}
                        <input type="checkbox" name="platforms[]" value="{{ $key }}" style="width:16px;height:16px;padding:0;margin:0;appearance:auto;-webkit-appearance:checkbox;background:none;border:none;border-radius:0;accent-color:#17A65B;"
                            {{ in_array($key, old('platforms', $post?->channels->pluck('platform')->all() ?? [])) ? 'checked' : '' }}>
                        {{ $p['icon'] }} {{ $p['label'] }}
                    </label>
                    @endforeach
                </div>
                <div style="font-size:12px;color:var(--ink-soft);margin-top:6px;">Jede Plattform bekommt einen eigenen Tracking-Link. Abwählen entfernt den Link samt Klickzahlen.</div>
            </div>
        </div>
        {{-- Automatische Veroeffentlichung (Meta-API, Phase 2) --}}
        <div style="border:1px dashed var(--line);border-radius:10px;padding:12px 14px;margin-bottom:14px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13.5px;font-weight:600;">
                <input type="checkbox" name="auto_publish" value="1" style="width:16px;height:16px;padding:0;margin:0;appearance:auto;-webkit-appearance:checkbox;background:none;border:none;border-radius:0;accent-color:#17A65B;"
                    {{ old('auto_publish', $post?->scheduled_for) ? 'checked' : '' }}
                    onchange="document.getElementById('scheduledWrap').style.display = this.checked ? 'flex' : 'none'">
                🚀 Automatisch veröffentlichen – Facebook &amp; Instagram über die Meta-API
            </label>
            <div id="scheduledWrap" style="display:{{ old('auto_publish', $post?->scheduled_for) ? 'flex' : 'none' }};gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap;">
                <span style="font-size:13px;">Zeitpunkt (deutsche Zeit):</span>
                {{-- Eingabe/Anzeige in deutscher Zeit; gespeichert wird UTC (app.timezone). --}}
                <input type="datetime-local" name="scheduled_for"
                    value="{{ old('scheduled_for', $post?->scheduled_for?->timezone(\App\Models\BannerSocialPost::OPERATOR_TZ)->format('Y-m-d\TH:i')) }}"
                    min="{{ now(\App\Models\BannerSocialPost::OPERATOR_TZ)->format('Y-m-d\TH:i') }}"
                    style="max-width:220px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;background:#F7F5EF;font-family:inherit;">
                <span style="font-size:12px;color:var(--ink-soft);">Der Planer prüft alle 15 Minuten. TikTok bleibt manuell (TikTok öffnet seine API nur nach App-Audit).</span>
            </div>
            @if(!$metaConfigured['facebook'] && !$metaConfigured['instagram'])
            <div style="font-size:12px;color:#92400E;background:#FEF3C7;border-radius:8px;padding:8px 10px;margin-top:10px;">⚠ Meta-API noch nicht verbunden. Einmalige Einrichtung: <code>docs/ANLEITUNG_META_API_AR.md</code> – die Zugangsdaten kommen NUR in die Server-<code>.env</code>.</div>
            @endif
        </div>
        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary">💾 Speichern &amp; Formate erzeugen</button>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13.5px;">
                <input type="checkbox" name="create_task" value="1" style="width:16px;height:16px;padding:0;margin:0;appearance:auto;-webkit-appearance:checkbox;background:none;border:none;border-radius:0;accent-color:#17A65B;">
                Wiedervorlage anlegen (erinnert an die Veröffentlichung{{ $banner->start_date && $banner->start_date->isFuture() ? ', fällig am ' . $banner->start_date->format('d.m.Y') : '' }})
            </label>
        </div>
    </form>
</div>

{{-- ============ Bildformate ============ --}}
<div class="card">
    <div class="card-header">
        <div class="card-title">🖼 Bildformate für die Plattformen</div>
        @if($canZip && ($formats || $post))
        <a href="{{ route('admin.banners.social.zip', $banner->id) }}" class="card-link">📦 Alles als ZIP herunterladen</a>
        @endif
    </div>
    @if($banner->media_type === 'video')
    <p style="font-size:13.5px;color:var(--ink-soft);">Dieses Banner ist ein <strong>Video</strong> – Videos werden nicht umgerechnet. Die Originaldatei direkt auf der Plattform hochladen (Facebook/Instagram/TikTok schneiden selbst zu):
        <a href="{{ asset('storage/' . $banner->media_path) }}" download style="font-weight:600;">⬇ Original-Video herunterladen</a>
    </p>
    @elseif(empty($formats))
    <p style="font-size:13.5px;color:var(--ink-soft);">Noch keine Formate erzeugt – oben auf <strong>„Speichern &amp; Formate erzeugen"</strong> klicken.</p>
    @else
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
        @foreach($formats as $key => $path)
        @php [$w, $h, $label] = $formatSpecs[$key]; @endphp
        <div style="border:1px solid var(--line);border-radius:12px;padding:12px;">
            <div style="font-weight:600;font-size:13.5px;margin-bottom:2px;">{{ $label }}</div>
            <div style="font-size:12px;color:var(--ink-soft);margin-bottom:8px;">{{ $w }} × {{ $h }} px · JPG</div>
            <div style="background:#131A17;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:6px;">
                <img src="{{ asset('storage/' . $path) }}?v={{ \Illuminate\Support\Facades\Storage::disk('public')->lastModified($path) }}" style="max-width:100%;max-height:230px;object-fit:contain;border-radius:4px;" alt="{{ $label }}">
            </div>
            <a href="{{ asset('storage/' . $path) }}" download="dienstly24-{{ $key }}.jpg" class="btn btn-ghost btn-sm" style="margin-top:10px;display:inline-block;">⬇ Herunterladen</a>
        </div>
        @endforeach
    </div>
    @if(strtolower(pathinfo($banner->media_path, PATHINFO_EXTENSION)) === 'gif')
    <p style="font-size:12px;color:var(--ink-soft);margin-top:10px;">Hinweis: Beim GIF wird das erste Bild verwendet – für die Animation das <a href="{{ asset('storage/' . $banner->media_path) }}" download>Original-GIF</a> direkt hochladen.</p>
    @endif
    @endif
</div>

{{-- ============ Veroeffentlichung & Tracking ============ --}}
<div class="card">
    <div class="card-header">
        <div class="card-title">🔗 Veröffentlichung &amp; Tracking-Links</div>
        <div style="display:flex;gap:10px;align-items:center;">
            @if($post && $post->channels->whereNotNull('external_post_id')->isNotEmpty())
            <form method="POST" action="{{ route('admin.banners.social.refresh_insights', $banner->id) }}">
                @csrf<button type="submit" class="btn btn-ghost btn-sm">🔄 Zahlen von Meta holen</button>
            </form>
            @endif
            <span style="font-size:12px;color:{{ ($metaConfigured['facebook'] || $metaConfigured['instagram']) ? '#3B7A57' : 'var(--ink-soft)' }};">
                Meta-API: {{ ($metaConfigured['facebook'] || $metaConfigured['instagram']) ? '✓ verbunden' : 'nicht konfiguriert' }}
            </span>
        </div>
    </div>
    @if(!$post || $post->channels->isEmpty())
    <p style="font-size:13.5px;color:var(--ink-soft);">Noch keine Plattform ausgewählt – oben Plattformen anhaken und speichern.</p>
    @else
    {{-- overflow-x: die Tabelle ist breiter als schmale Viewports (Handy) --}}
    <div style="overflow-x:auto;">
    <table>
        <thead><tr><th>Plattform</th><th>Tracking-Link (in den Beitrag)</th><th style="text-align:right;">Klicks</th><th>Letzter Klick</th><th>Veröffentlicht</th><th></th></tr></thead>
        <tbody>
        @foreach($post->channels->sortBy('platform') as $ch)
        @php $info = $ch->platformInfo(); @endphp
        <tr>
            <td style="font-weight:600;white-space:nowrap;">{{ $info['icon'] }} {{ $info['label'] }}</td>
            <td>
                <div style="display:flex;gap:6px;align-items:center;max-width:380px;">
                    <input type="text" readonly value="{{ $ch->shortUrl() }}" id="link-{{ $ch->id }}" style="font-size:12.5px;flex:1;" onclick="this.select()">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="copyLink('link-{{ $ch->id }}', this)">📋 Kopieren</button>
                </div>
            </td>
            <td style="text-align:right;font-weight:600;">{{ number_format($ch->clicks, 0, ',', '.') }}</td>
            <td style="color:var(--ink-soft);font-size:12.5px;">{{ $ch->last_click_at?->lokal()->format('d.m.Y H:i') ?? '—' }}</td>
            <td style="font-size:12.5px;">
                @if($ch->published_at)
                <span style="color:#3B7A57;font-weight:600;">✓ {{ $ch->published_at->lokal()->format('d.m.Y H:i') }}</span>
                @if($ch->external_post_id)
                <span style="background:#E4F0E7;color:#3B7A57;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600;">API</span>
                @endif
                @if($ch->publisher)<span style="color:var(--ink-soft);"> von {{ $ch->publisher->name }}</span>
                @elseif($ch->external_post_id)<span style="color:var(--ink-soft);"> automatisch</span>@endif
                @elseif($post->scheduled_for && in_array($ch->platform, \App\Services\Social\MetaPublisher::AUTO_PLATFORMS) && !$ch->auto_attempted_at)
                <span style="color:#185FA5;">⏱ geplant {{ $post->scheduled_for->timezone(\App\Models\BannerSocialPost::OPERATOR_TZ)->lokal()->format('d.m.Y H:i') }} Uhr</span>
                @unless($metaConfigured[$ch->platform] ?? false)
                <div style="font-size:11.5px;color:#92400E;margin-top:3px;">⚠ Meta-API nicht verbunden - der Planer kann nicht posten (php artisan meta:einrichten).</div>
                @endunless
                @else
                <span style="color:var(--ink-soft);">noch nicht</span>
                @endif
                @if($ch->insights)
                <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;" title="Stand: {{ $ch->insights_refreshed_at?->lokal()->format('d.m.Y H:i') }}">
                    👍 {{ number_format($ch->insights['likes'] ?? 0, 0, ',', '.') }}
                    · 💬 {{ number_format($ch->insights['comments'] ?? 0, 0, ',', '.') }}
                    @if(($ch->insights['shares'] ?? 0) > 0) · ↪ {{ number_format($ch->insights['shares'], 0, ',', '.') }} @endif
                    · 👁 {{ number_format($ch->insights['reach'] ?? 0, 0, ',', '.') }} erreicht
                </div>
                @endif
                @if($ch->publish_error)
                <div style="font-size:11.5px;color:#A32D2D;max-width:240px;margin-top:4px;">⚠ {{ $ch->publish_error }}</div>
                @endif
            </td>
            <td>
                <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start;">
                    @if($ch->external_post_id)
                    <a href="{{ $ch->external_url ?: 'https://www.facebook.com/' . $ch->external_post_id }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">↗ Beitrag ansehen</a>
                    @if($ch->platform === 'facebook' && \App\Services\Social\MetaAdsService::configured())
                    <a href="{{ route('admin.werbung.neu', $banner->id) }}" class="btn btn-ghost btn-sm">📢 Bewerben</a>
                    @endif
                    @else
                    {{-- API-Posten nur solange nicht (manuell) veroeffentlicht:
                         sonst waere ein Doppel-Post auf der Plattform moeglich. --}}
                    @if($ch->publishInFlight())
                    {{-- Ein Versuch laeuft im Hintergrund. Der Knopf ist weg,
                         damit niemand denselben Beitrag zweimal absetzt. --}}
                    <span class="btn btn-ghost btn-sm" style="opacity:.7;pointer-events:none;">⏳ Wird veröffentlicht …</span>
                    <div style="font-size:11.5px;color:var(--ink-soft);max-width:240px;">
                        Läuft im Hintergrund – Sie bekommen eine Benachrichtigung, sobald der Beitrag online ist.
                    </div>
                    @elseif(!$ch->published_at && ($metaConfigured[$ch->platform] ?? false))
                    <form method="POST" action="{{ route('admin.banners.social.publish_now', [$banner->id, $ch->platform]) }}" onsubmit="return confirm('Diesen Beitrag jetzt auf {{ $info['label'] }} veröffentlichen?');">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">🚀 {{ $ch->publish_error ? 'Erneut versuchen' : 'Jetzt per API posten' }}</button>
                    </form>
                    @endif
                    <form method="POST" action="{{ route('admin.banners.social.published', [$banner->id, $ch->platform]) }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">{{ $ch->published_at ? '↩ Zurücksetzen' : '✓ Als veröffentlicht markieren' }}</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    @endif

    {{-- Kurzanleitung je Plattform --}}
    <div style="margin-top:14px;border-top:1px dashed var(--line);padding-top:12px;font-size:12.5px;color:var(--ink-soft);line-height:1.7;">
        <strong style="color:var(--ink);">So wird gepostet:</strong><br>
        🚀 <strong>Automatisch (Meta-API):</strong> Facebook &amp; Instagram auf Knopfdruck („Jetzt per API posten") oder zum geplanten Zeitpunkt – Beitrag = Text Deutsch + Arabisch + Tracking-Link, Bild „Feed-Post" (1:1).<br>
        📘 <strong>Facebook (manuell):</strong> Bild „Feed-Post" oder „Link-Vorschau" + Text + Tracking-Link direkt im Beitrag.<br>
        📸 <strong>Instagram:</strong> Feed 1:1, Story 9:16. Links im Beitragstext sind nicht klickbar – den Tracking-Link als <em>Link-Sticker in der Story</em> oder als „Link in Bio" verwenden.<br>
        🎵 <strong>TikTok:</strong> Hochformat 9:16. Den Tracking-Link im Profil hinterlegen.<br>
        Alle Klicks über die Tracking-Links erscheinen im <a href="{{ route('admin.banners.stats') }}">Statistik-Dashboard</a> – so sehen Sie, welche Plattform wirklich Besucher bringt.
    </div>
</div>

<script>
function copyLink(id, btn) {
    const input = document.getElementById(id);
    input.select();
    input.setSelectionRange(0, 99999);
    const done = () => { btn.textContent = '✓ Kopiert'; setTimeout(() => btn.textContent = '📋 Kopieren', 1600); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(done).catch(() => { document.execCommand('copy'); done(); });
    } else {
        document.execCommand('copy'); done();
    }
}
</script>
@endsection
