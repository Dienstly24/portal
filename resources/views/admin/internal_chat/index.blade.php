@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="page-title">💬 Interner Chat</div>
    <div class="page-sub">Direkte Kommunikation zwischen Mitarbeitern – getrennt von Kundentickets. Kunden haben keinen Zugriff.</div>
</div>

<div style="display:grid;grid-template-columns:340px 1fr;gap:20px;align-items:start;">
    <div class="card">
        <button data-h-click="e794190c4c" class="btn btn-primary" style="width:100%;margin-bottom:14px;">+ Neue Unterhaltung</button>
        @forelse($conversations as $c)
        <a href="{{ route('admin.chat.show', $c->id) }}" style="display:block;padding:12px;border:1px solid var(--line);border-radius:8px;margin-bottom:8px;text-decoration:none;color:var(--ink);">
            <div style="font-weight:600;font-size:14px;">{{ $c->subject }}</div>
            <div class="muted-xs">{{ $c->participants->count() }} Teilnehmer · {{ optional($c->last_message_at)->diffForHumans() ?? $c->created_at->diffForHumans() }}</div>
        </a>
        @empty
        <p style="color:var(--ink-soft);font-size:14px;">Noch keine Unterhaltungen.</p>
        @endforelse
    </div>
    <div class="card">
        <p style="color:var(--ink-soft);font-size:14px;text-align:center;padding:40px 0;">Wählen Sie links eine Unterhaltung oder erstellen Sie eine neue.</p>
    </div>
</div>

@include('admin.internal_chat._new_modal')
@endsection

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["e794190c4c"] = function (event) { document.getElementById('new-conv-modal').style.display='flex' };
</script>
@endPushOnce
