@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Ankündigungen</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">Ankündigungen</div>
            <div class="page-sub">Interne Mitteilungen für Ihr Team</div>
        </div>
        <button data-h-click="dc50ce1357" class="btn btn-emerald">+ Neue Ankündigung</button>
    </div>
</div>

@if($announcements->isEmpty())
<div class="card" style="text-align:center;padding:48px;">
    <div style="font-size:40px;margin-bottom:12px;">📢</div>
    <div style="font-weight:600;font-size:16px;margin-bottom:6px;">Noch keine Ankündigungen</div>
    <div style="color:var(--ink-soft);font-size:14px;">Erstellen Sie eine neue Ankündigung für Ihr Team.</div>
</div>
@else
<div style="display:flex;flex-direction:column;gap:12px;">
@foreach($announcements as $a)
@php
$colors = ['normal'=>['#F7F5EF','var(--ink)','📋'],'important'=>['#E6F1FB','#185FA5','⚠️'],'urgent'=>['#F9E3E3','#A32D2D','🚨']];
$c = $colors[$a->priority];
@endphp
<div style="background:#fff;border:1px solid var(--line);border-left:4px solid {{ $a->priority === 'urgent' ? '#A32D2D' : ($a->priority === 'important' ? '#185FA5' : 'var(--line)') }};border-radius:12px;padding:20px 24px;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <span style="font-size:18px;">{{ $c[2] }}</span>
                <span style="font-weight:700;font-size:15px;">{{ $a->title }}</span>
                <span style="background:{{ $c[0] }};color:{{ $c[1] }};font-size:11px;padding:2px 8px;border-radius:999px;font-weight:600;">{{ ucfirst($a->priority) }}</span>
            </div>
            <p style="font-size:14px;color:var(--ink-soft);line-height:1.7;margin:0 0 10px;">{{ $a->body }}</p>
            <div class="muted-xs">
                {{ $a->createdBy?->name }} · {{ $a->created_at->lokal()->format('d.m.Y H:i') }}
                @if($a->expires_at) · Läuft ab: {{ $a->expires_at->lokal()->format('d.m.Y') }} @endif
            </div>
        </div>
        <form method="POST" action="{{ route('admin.announcements.destroy', $a->id) }}" data-h-submit="14579d00be">
            @csrf @method('DELETE')
            <button type="submit" style="border:none;background:none;cursor:pointer;color:var(--ink-soft);font-size:18px;">🗑</button>
        </form>
    </div>
</div>
@endforeach
</div>
@endif

{{-- Modal --}}
<div id="add-ann-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:520px;position:relative;">
        <button data-h-click="8f89fd6f1d" class="modal-close">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:20px;">Neue Ankündigung</div>
        <form method="POST" action="{{ route('admin.announcements.store') }}">
            @csrf
            <div class="field"><label>Titel *</label><input type="text" name="title" required placeholder="Titel der Ankündigung"></div>
            <div class="field"><label>Nachricht *</label><textarea name="body" required placeholder="Inhalt der Ankündigung..." style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;min-height:100px;font-family:inherit;resize:vertical;"></textarea></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Priorität</label>
                    <select name="priority" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="normal">📋 Normal</option>
                        <option value="important">⚠️ Wichtig</option>
                        <option value="urgent">🚨 Dringend</option>
                    </select>
                </div>
                <div class="field"><label>Läuft ab am</label><input type="date" name="expires_at"></div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" data-h-click="8f89fd6f1d" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Erstellen</button>
            </div>
        </form>
    </div>
</div>
@endsection

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["dc50ce1357"] = function (event) { document.getElementById('add-ann-modal').style.display='flex' };
window.__h["14579d00be"] = function (event) { return confirm('Löschen?') };
window.__h["8f89fd6f1d"] = function (event) { document.getElementById('add-ann-modal').style.display='none' };
</script>
@endPushOnce
