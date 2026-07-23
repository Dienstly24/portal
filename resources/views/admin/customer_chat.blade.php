@extends('layouts.admin')
@section('content')
{{-- Zentraler Kunden-Chat: links alle Unterhaltungen (sichtbares
     Portfolio), rechts der Verlauf im Messenger-Stil. Nutzt dieselben
     d24c-Bausteine wie der Portal-Chat des Kunden. --}}
@include('partials.chat_styles')
<style>
.kchat{display:grid;grid-template-columns:340px minmax(0,1fr);background:var(--surface);border:1px solid var(--line);border-radius:14px;overflow:hidden;height:calc(100vh - var(--header-h) - 56px);min-height:460px;}
.kchat-side{border-inline-end:1px solid var(--line);display:flex;flex-direction:column;min-width:0;min-height:0;background:var(--surface);}
.kchat-side-head{padding:14px 14px 10px;border-bottom:1px solid var(--line);}
.kchat-title{font-size:15px;font-weight:700;margin-bottom:10px;}
.kchat-search input{width:100%;padding:9px 13px;border:1px solid var(--line);border-radius:999px;font-size:13px;background:var(--canvas);color:var(--ink);}
.kchat-search input:focus{outline:2px solid var(--gold);outline-offset:1px;background:#fff;}
.kchat-convs{overflow-y:auto;flex:1;}
.kchat-group{font-size:11px;font-weight:700;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.06em;padding:12px 14px 6px;}
.kchat-conv{display:flex;gap:10px;padding:12px 14px;border-bottom:1px solid var(--line);text-decoration:none;color:var(--ink);align-items:center;transition:background .12s;}
.kchat-conv:hover{background:var(--canvas);}
.kchat-conv.active{background:#E7F4EC;}
.kchat-conv .d24c-av{width:40px;height:40px;font-size:13px;}
.kchat-meta{min-width:0;flex:1;}
.kchat-name{display:flex;justify-content:space-between;gap:8px;font-size:13.5px;font-weight:700;}
.kchat-time{font-size:11px;color:var(--ink-soft);font-weight:500;flex:none;}
.kchat-snippet{font-size:12.5px;color:var(--ink-soft);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
.kchat-unread{background:#E24B4A;color:#fff;border-radius:999px;font-size:11px;font-weight:800;min-width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;padding:0 6px;flex:none;}
.kchat-none{padding:26px 18px;text-align:center;color:var(--ink-soft);font-size:13.5px;line-height:1.7;}
.kchat-thread{display:flex;flex-direction:column;min-width:0;min-height:0;background:var(--surface);}
.kchat-head{display:flex;align-items:center;gap:11px;padding:11px 14px;background:linear-gradient(135deg,var(--petrol),var(--petrol-dark));color:#fff;}
.kchat-head-name{font-weight:700;font-size:14.5px;}
.kchat-head-sub{font-size:11.5px;color:var(--akzent-hell);}
.kchat-head-link{margin-left:auto;color:rgba(255,255,255,.75);font-size:12.5px;text-decoration:none;border:1px solid rgba(255,255,255,.25);border-radius:999px;padding:5px 12px;flex:none;}
.kchat-head-link:hover{background:rgba(255,255,255,.1);color:#fff;}
.kchat-back{display:none;color:rgba(255,255,255,.8);font-size:19px;text-decoration:none;flex:none;width:34px;height:34px;border-radius:9px;align-items:center;justify-content:center;}
.kchat-opts{display:flex;gap:10px;align-items:center;padding:8px 12px;background:var(--surface);border-top:1px solid var(--line);flex-wrap:wrap;}
.kchat-opts select{padding:7px 11px;border:1px solid var(--line);border-radius:999px;font-size:12.5px;background:#fff;color:var(--ink);max-width:240px;}
.kchat-opts .opt-label{font-size:12px;color:var(--ink-soft);}
.kchat-idle{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:var(--ink-soft);background:#EFEBDF;text-align:center;padding:20px;}
.kchat-idle .big{font-size:44px;}
@media (max-width: 900px) {
    .kchat{grid-template-columns:1fr;height:calc(100dvh - var(--header-h) - 40px);}
    .kchat.mode-thread .kchat-side{display:none;}
    .kchat.mode-list .kchat-thread{display:none;}
    .kchat-back{display:inline-flex;}
}
</style>

<div class="kchat {{ $active ? 'mode-thread' : 'mode-list' }}">
    <aside class="kchat-side">
        <div class="kchat-side-head">
            <div class="kchat-title">💬 Kunden-Chat</div>
            <form method="GET" action="{{ route('admin.customer_chat') }}" class="kchat-search">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Kunde suchen (Name, Nr., E-Mail) …" aria-label="Kunde suchen">
            </form>
        </div>
        <div class="kchat-convs">
            @if($searchResults !== null)
                <div class="kchat-group">Suchergebnisse</div>
                @forelse($searchResults as $c)
                <a href="{{ route('admin.customer_chat', ['kunde' => $c->id]) }}" class="kchat-conv {{ $active?->id === $c->id ? 'active' : '' }}">
                    <div class="d24c-av">{{ strtoupper(mb_substr($c->user?->name ?? '??', 0, 2)) }}</div>
                    <div class="kchat-meta">
                        <div class="kchat-name"><span>{{ $c->user?->name ?? 'Ohne Namen' }}</span></div>
                        <div class="kchat-snippet">Nr. {{ $c->customer_number }} · Unterhaltung starten</div>
                    </div>
                </a>
                @empty
                <div class="kchat-none">Kein Kunde gefunden.</div>
                @endforelse
                <a href="{{ route('admin.customer_chat') }}" style="display:block;padding:12px 14px;font-size:12.5px;color:var(--ink-soft);text-decoration:none;">← Alle Unterhaltungen</a>
            @else
                @forelse($conversations as $c)
                @php $last = $c->messages->first(); @endphp
                <a href="{{ route('admin.customer_chat', ['kunde' => $c->id]) }}" class="kchat-conv {{ $active?->id === $c->id ? 'active' : '' }}">
                    <div class="d24c-av">{{ strtoupper(mb_substr($c->user?->name ?? '??', 0, 2)) }}</div>
                    <div class="kchat-meta">
                        <div class="kchat-name">
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $c->user?->name ?? 'Ohne Namen' }}</span>
                            <span class="kchat-time">{{ $last?->created_at->isToday() ? $last->created_at->format('H:i') : $last?->created_at->format('d.m.y') }}</span>
                        </div>
                        <div class="kchat-snippet">{{ $last?->from_staff ? 'Sie: ' : '' }}{{ \Illuminate\Support\Str::limit(trim($last->body ?? ''), 44) ?: '📎 Anhang' }}</div>
                    </div>
                    @if($c->unread_count > 0)<span class="kchat-unread">{{ $c->unread_count > 9 ? '9+' : $c->unread_count }}</span>@endif
                </a>
                @empty
                <div class="kchat-none">Noch keine Unterhaltungen.<br>Suchen Sie oben einen Kunden, um den Chat zu starten – der Kunde sieht die Nachricht sofort im Portal.</div>
                @endforelse
            @endif
        </div>
    </aside>

    <section class="kchat-thread">
        @if($active)
        <div class="kchat-head">
            <a href="{{ route('admin.customer_chat') }}" class="kchat-back" title="Zur Übersicht">‹</a>
            <div class="d24c-av" style="width:36px;height:36px;font-size:12px;">{{ strtoupper(mb_substr($active->user?->name ?? '??', 0, 2)) }}</div>
            <div style="min-width:0;">
                <div class="kchat-head-name">{{ $active->user?->name ?? 'Ohne Namen' }}</div>
                <div class="kchat-head-sub">Nr. {{ $active->customer_number }}@unless($active->user?->hasRealEmail()) · ⚠ keine echte E-Mail @endunless</div>
            </div>
            <a class="kchat-head-link" href="{{ route('admin.customer', $active->id) }}">Kundenakte →</a>
        </div>
        <div class="d24c-scroll" id="kc-scroll">
            @php $lastDay = null; @endphp
            <div class="d24c-list" id="kc-list" data-last-day="">
                @forelse($messages as $m)
                    @php
                        $day = $m->created_at->isToday() ? __('Heute') : ($m->created_at->isYesterday() ? __('Gestern') : $m->created_at->format('d.m.Y'));
                    @endphp
                    @if($day !== $lastDay)
                        <div class="d24c-day">{{ $day }}</div>
                        @php $lastDay = $day; @endphp
                    @endif
                    <div class="d24c-bub {{ $m->from_staff ? 'me' : 'them' }}" data-mid="{{ $m->id }}">
                        @if($m->from_staff)<span class="d24c-sender">{{ $m->sender?->name ?? 'Dienstly24 Team' }}</span>@endif
                        <span class="d24c-body">{{ $m->body }}</span>
                        @foreach($m->attachments as $att)
                        <span class="d24c-att">
                            <span class="d24c-att-n">{{ $att->isImage() ? '🖼️' : ($att->isPdf() ? '📄' : '📎') }} {{ $att->file_name }}</span>
                            <span class="d24c-attbtns">
                                @if($att->isViewable())<a href="{{ route('admin.messages.attachment.view', $att->id) }}" target="_blank" rel="noopener">👁 {{ __('Anzeigen') }}</a>@endif
                                <a href="{{ route('admin.messages.attachment', $att->id) }}">⬇ {{ __('Herunterladen') }}</a>
                            </span>
                        </span>
                        @endforeach
                        <span class="d24c-tm">{{ $m->created_at->format('H:i') }}@if($m->from_staff)<span class="d24c-ticks{{ $m->read_at ? ' read' : '' }}" title="{{ $m->read_at ? __('Gelesen') : __('Gesendet') }}">{{ $m->read_at ? '✓✓' : '✓' }}</span>@endif</span>
                    </div>
                @empty
                    <div class="d24c-empty">👋 Noch keine Nachrichten. Starten Sie die Unterhaltung – der Kunde sieht sie sofort im Portal unter „Nachrichten".</div>
                @endforelse
            </div>
        </div>
        <div class="kchat-opts">
            <select id="kc-template" aria-label="Vorlage einfügen">
                <option value="">📋 Vorlage einfügen…</option>
                @foreach($templates as $tpl)
                <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                @endforeach
            </select>
            <span class="opt-label">✉️ E-Mail an den Kunden:</span>
            <select name="email_mode" form="kc-form" aria-label="E-Mail-Modus">
                <option value="hint" selected>Nur Hinweis (ohne Inhalt)</option>
                <option value="full">Kompletter Text per E-Mail</option>
                <option value="none">Keine E-Mail</option>
            </select>
        </div>
        <div class="d24c-files" id="kc-files" hidden></div>
        <form class="d24c-comp" id="kc-form" method="POST" action="{{ route('admin.customer.messages.store', $active->id) }}" enctype="multipart/form-data">
            @csrf
            <label class="d24c-clip" title="{{ __('Anhang hinzufügen') }}">📎<input id="kc-file" type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" hidden></label>
            <textarea id="kc-input" name="body" class="d24c-inp" rows="1" maxlength="5000" placeholder="Nachricht an {{ $active->user?->name ?? 'den Kunden' }} …" required></textarea>
            <button type="submit" class="d24c-send" aria-label="{{ __('Senden') }}"><span class="snd-ico">➤</span></button>
        </form>
        @else
        <div class="kchat-idle">
            <div class="big">💬</div>
            <div style="font-weight:700;font-size:15px;color:var(--ink);">Unterhaltung auswählen</div>
            <div style="font-size:13px;max-width:320px;line-height:1.7;">Wählen Sie links einen Kunden oder suchen Sie nach Name, Kundennummer oder E-Mail, um eine neue Unterhaltung zu starten.</div>
        </div>
        @endif
    </section>
</div>

@include('partials.chat_core')
@if($active)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('kc-list');
    const scroller = document.getElementById('kc-scroll');
    list.dataset.lastDay = @json($lastDay ?? '');
    D24Chat.wireComposer({
        form: document.getElementById('kc-form'),
        input: document.getElementById('kc-input'),
        fileInput: document.getElementById('kc-file'),
        filesBar: document.getElementById('kc-files'),
        list: list, scroller: scroller
    });
    // Chat offen = Kundenantworten gelten als gelesen; neue Nachrichten
    // erscheinen live.
    D24Chat.poll({
        list: list, scroller: scroller,
        url: '{{ route('admin.customer_chat.feed', $active->id) }}',
        every: 10000,
        markRead: function () { return true; }
    });
    // Vorlage in das Eingabefeld uebernehmen (gleicher Endpoint wie in
    // der Kundenakte, Platzhalter werden fuer diesen Kunden gerendert).
    document.getElementById('kc-template').addEventListener('change', function () {
        if (!this.value) return;
        fetch('{{ url('admin/vorlagen') }}/' + this.value + '/render?customer_id={{ $active->id }}', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                const inp = document.getElementById('kc-input');
                inp.value = d.body || inp.value;
                inp.focus();
            })
            .catch(function () {});
        this.value = '';
    });
});
</script>
@endif
@endsection
