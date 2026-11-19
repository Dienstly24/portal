@extends('layouts.admin')
@section('content')
{{-- Unterschrifts-Link OHNE E-Mail (Betreiber-Meldung 19.11.2026).
     Das Token steht NUR in dieser einen Antwort - es liegt weder in der
     Sitzung noch in der Datenbank (dort nur sein Hash). Wer die Seite
     schliesst, erzeugt bei Bedarf einfach einen neuen Link. --}}
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.index') }}">Signaturen</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.signatures.show', $signature->id) }}">{{ Str::limit($signature->title, 40) }}</a><span class="breadcrumb-sep">›</span>
        <span>{{ $vorOrt ? 'Im Büro unterschreiben' : 'Link für WhatsApp' }}</span>
    </div>
    <h1 class="page-title">{{ $vorOrt ? '🖊 Jetzt im Büro unterschreiben' : '📱 Link für WhatsApp' }}</h1>
    <div class="page-sub">{{ $signer->name }} · {{ $signature->title }} · Sprache: {{ $signer->localeLabel() }}</div>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:18px;align-items:start;" class="sig-link-grid">
    <div class="card" style="padding:20px;display:grid;gap:14px;">
        @if($vorOrt)
            <div style="background:var(--emerald-soft);color:var(--emerald-ink);padding:12px 14px;border-radius:8px;font-size:14px;">
                Die Identität ist durch Sie bestätigt - der Kunde muss <strong>keinen Code</strong> eingeben.
                Öffnen Sie das Dokument auf dem Tablet, oder lassen Sie den Kunden den QR-Code mit seinem Handy scannen.
            </div>
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="btn btn-emerald" style="font-size:16px;padding:14px;">
                Dokument auf diesem Gerät öffnen →
            </a>
        @else
            <div style="font-size:14px;">
                Schicken Sie den Link per WhatsApp. Die Nachricht ist bereits vorformuliert
                (in der Sprache des Unterzeichners) und kann vor dem Senden noch geändert werden.
            </div>
            <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-emerald" style="font-size:16px;padding:14px;">
                📱 In WhatsApp öffnen{{ $whatsappNummer !== '' ? ' (+'.$whatsappNummer.')' : '' }}
            </a>
            @if($whatsappNummer === '')
                <div class="muted-sm">Keine Handynummer in der Kundenakte - WhatsApp fragt nach dem Empfänger.</div>
            @endif
        @endif

        <div>
            <label for="sig-link-url" class="muted-sm" style="display:block;margin-bottom:4px;">Persönlicher Link</label>
            <div style="display:flex;gap:8px;">
                <input id="sig-link-url" type="text" readonly value="{{ $url }}" dir="ltr"
                       style="flex:1;min-width:0;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
                <button type="button" class="btn btn-sm btn-ghost" data-h-click="sigLinkKopieren">Kopieren</button>
            </div>
            <div class="muted-sm" id="sig-link-kopiert" hidden style="margin-top:4px;color:var(--emerald-ink);">✓ Kopiert</div>
        </div>

        <div class="muted-sm">
            Der Link gilt nur für {{ $signer->name }}{{ $signature->expires_at ? ' und bis '.$signature->expires_at->lokal()->format('d.m.Y') : '' }}.
            Ein früher versendeter Link (E-Mail oder WhatsApp) ist damit ungültig.
            Der Link wird aus Sicherheitsgründen nicht gespeichert - wenn Sie diese Seite schließen,
            erzeugen Sie bei Bedarf einfach einen neuen.
        </div>

        <a href="{{ route('admin.signatures.show', $signature->id) }}" class="btn btn-sm btn-ghost">← Zurück zum Vorgang</a>
    </div>

    <div class="card" style="padding:18px;text-align:center;display:grid;gap:8px;justify-items:center;">
        <div style="font-weight:600;">QR-Code</div>
        <div style="background:#fff;padding:8px;border-radius:8px;max-width:240px;">{!! $qr !!}</div>
        <div class="muted-sm">Mit der Handy- oder Tablet-Kamera scannen - das Dokument öffnet sich direkt.</div>
    </div>
</div>

<style>
@media (max-width: 760px) { .sig-link-grid { grid-template-columns: 1fr !important; } }
.sig-link-grid svg { width: 100%; height: auto; display: block; }
</style>

@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["sigLinkKopieren"] = function () {
    var feld = document.getElementById('sig-link-url');
    var ok = function () { document.getElementById('sig-link-kopiert').hidden = false; };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(feld.value).then(ok, function () { feld.select(); document.execCommand('copy'); ok(); });
    } else {
        feld.select(); document.execCommand('copy'); ok();
    }
};
</script>
@endPushOnce
@endsection
