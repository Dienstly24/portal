@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Ankündigungen</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
            <div class="page-title">Ankündigungen</div>
            <div class="page-sub">Interne Mitteilungen für Ihr Team</div>
        </div>
        <button onclick="document.getElementById('add-ann-modal').style.display='flex'" class="btn btn-gold">+ Neue Ankündigung</button>
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
$colors = ['normal'=>['#F4F5F7','var(--ink)','📋'],'important'=>['#E6F1FB','#185FA5','⚠️'],'urgent'=>['#F9E3E3','#A32D2D','🚨']];
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
            <div style="font-size:12px;color:var(--ink-soft);">
                {{ $a->createdBy?->name }} · {{ $a->created_at->format('d.m.Y H:i') }}
                @if($a->expires_at) · Läuft ab: {{ $a->expires_at->format('d.m.Y') }} @endif
            </div>
        </div>
        <form method="POST" action="{{ route('admin.announcements.destroy', $a->id) }}" onsubmit="return confirm('Löschen?')">
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
        <button onclick="document.getElementById('add-ann-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;">✕</button>
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
                <button type="button" onclick="document.getElementById('add-ann-modal').style.display='none'" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Erstellen</button>
            </div>
        </form>
    </div>
</div>
@endsection
