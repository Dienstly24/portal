@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Aufgaben</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <div class="page-title">Aufgaben</div>
        <button onclick="document.getElementById('new-task-modal').style.display='flex'" class="btn btn-gold">+ Aufgabe erstellen</button>
    </div>
</div>

<div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:24px;">
    @foreach(['mine'=>'Meine Aufgaben','customer'=>'Kunden-Aufgaben','done'=>'Erledigte Aufgaben'] as $key=>$label)
    <a href="{{ route('admin.tasks', ['tab'=>$key]) }}"
        style="padding:12px 20px;text-decoration:none;font-size:14px;font-weight:{{ $tab===$key?'700':'500' }};color:{{ $tab===$key?'var(--petrol)':'var(--ink-soft)' }};border-bottom:2px solid {{ $tab===$key?'var(--petrol)':'transparent' }};margin-bottom:-2px;">
        {{ $label }}
    </a>
    @endforeach
</div>

<div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
    <form method="GET" action="{{ route('admin.tasks') }}" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <div>
            <label style="font-size:12px;color:var(--ink-soft);display:block;margin-bottom:4px;font-weight:600;">Status</label>
            <select name="status" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;min-width:140px;">
                <option value="">Alle</option>
                <option value="open" {{ request('status')==='open'?'selected':'' }}>Offen</option>
                <option value="in_progress" {{ request('status')==='in_progress'?'selected':'' }}>In Bearbeitung</option>
                <option value="done" {{ request('status')==='done'?'selected':'' }}>Erledigt</option>
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:var(--ink-soft);display:block;margin-bottom:4px;font-weight:600;">Aufgabentyp</label>
            <select name="type" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;min-width:140px;">
                <option value="">Alle</option>
                <option value="call" {{ request('type')==='call'?'selected':'' }}>Anruf</option>
                <option value="email" {{ request('type')==='email'?'selected':'' }}>E-Mail</option>
                <option value="meeting" {{ request('type')==='meeting'?'selected':'' }}>Termin</option>
                <option value="document" {{ request('type')==='document'?'selected':'' }}>Dokument</option>
                <option value="follow_up" {{ request('type')==='follow_up'?'selected':'' }}>Follow-up</option>
                <option value="other" {{ request('type')==='other'?'selected':'' }}>Sonstige</option>
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:var(--ink-soft);display:block;margin-bottom:4px;font-weight:600;">Fällig in</label>
            <div style="display:flex;border:1px solid var(--line);border-radius:8px;overflow:hidden;">
                @foreach(['today'=>'Heute','14'=>'14 Tage',''=>'Alle'] as $val=>$lbl)
                <button type="submit" name="due" value="{{ $val }}"
                    style="padding:8px 14px;border:none;font-size:13px;cursor:pointer;background:{{ request('due')===$val?'var(--petrol)':'#fff' }};color:{{ request('due')===$val?'#fff':'var(--ink)' }};">
                    {{ $lbl }}
                </button>
                @endforeach
            </div>
        </div>
    </form>
</div>

<div style="font-size:14px;font-weight:700;margin-bottom:14px;">Aufgaben ({{ $tasks->count() }})</div>

@if($tasks->isEmpty())
<div class="card" style="text-align:center;padding:40px;color:var(--ink-soft);">Keine Einträge vorhanden</div>
@else
<div style="display:flex;flex-direction:column;gap:10px;">
@foreach($tasks as $t)
@php
$priorityColor = ['high'=>'#F9E3E3','medium'=>'#FEF3C7','low'=>'#D9F4E6'];
$priorityText = ['high'=>'#A32D2D','medium'=>'#92400E','low'=>'#17A65B'];
$priorityLabel = ['high'=>'Hoch','medium'=>'Mittel','low'=>'Niedrig'];
$typeIcon = ['call'=>'📞','email'=>'✉️','meeting'=>'📅','document'=>'📄','follow_up'=>'🔄','other'=>'📌'];
@endphp
<div class="card" id="task-{{ $t->id }}" style="padding:16px 20px;margin:0;">
    <div style="display:flex;align-items:center;gap:14px;">
        <div style="width:40px;height:40px;border-radius:10px;background:#EEF0F3;display:flex;align-items:center;justify-content:center;font-size:20px;flex:none;">
            {{ $typeIcon[$t->type] ?? '📌' }}
        </div>
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-weight:700;font-size:14px;">{{ $t->title }}</span>
                <span style="background:{{ $priorityColor[$t->priority] }};color:{{ $priorityText[$t->priority] }};font-size:11px;padding:2px 8px;border-radius:999px;font-weight:600;">{{ $priorityLabel[$t->priority] }}</span>
                @if($t->due_date)
                <span style="font-size:12px;color:{{ $t->due_date->isPast() && $t->status !== 'done' ? '#A32D2D' : 'var(--ink-soft)' }};">
                    📅 {{ $t->due_date->format('d.m.Y') }}
                    @if($t->due_date->isToday()) <span style="background:#E6F1FB;color:#185FA5;padding:1px 6px;border-radius:4px;font-size:11px;">Heute</span> @endif
                </span>
                @endif
            </div>
            @if($t->description)<div style="font-size:13px;color:var(--ink-soft);margin-top:3px;">{{ $t->description }}</div>@endif
            <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                @if($t->customer)<a href="{{ route('admin.customer', $t->customer_id) }}" style="color:var(--ink-soft);">👤 {{ $t->customer->user?->name }}</a>@endif
                <span>Zugewiesen: {{ $t->assignedTo?->name }}</span>
                @if($t->email_message_id && in_array(auth()->user()->role, ['admin','manager','support']))
                <a href="{{ route('admin.email_inbox.show', $t->email_message_id) }}" style="color:var(--petrol);font-weight:600;">✉️ E-Mail öffnen</a>
                @endif
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex:none;">
            <form method="POST" action="{{ route('admin.tasks.update', $t->id) }}">
                @csrf @method('PUT')
                <select name="status" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;font-size:12px;">
                    <option value="open" {{ $t->status==='open'?'selected':'' }}>Offen</option>
                    <option value="in_progress" {{ $t->status==='in_progress'?'selected':'' }}>In Bearbeitung</option>
                    <option value="done" {{ $t->status==='done'?'selected':'' }}>Erledigt ✓</option>
                </select>
            </form>
            <form method="POST" action="{{ route('admin.tasks.destroy', $t->id) }}" onsubmit="return confirm('Aufgabe löschen?')">
                @csrf @method('DELETE')
                <button type="submit" style="border:none;background:none;cursor:pointer;color:var(--ink-soft);font-size:18px;" title="Löschen">🗑</button>
            </form>
        </div>
    </div>
</div>
@endforeach
</div>
@endif

{{-- New Task Modal --}}
<div id="new-task-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:560px;position:relative;">
        <button onclick="document.getElementById('new-task-modal').style.display='none'" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;color:var(--ink-soft);">✕</button>
        <div style="font-size:18px;font-weight:700;margin-bottom:20px;">Neue Aufgabe</div>
        <form method="POST" action="{{ route('admin.tasks.store') }}">
            @csrf
            <div class="field"><label>Titel *</label><input type="text" name="title" required placeholder="Was ist zu tun?"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Typ</label>
                    <select name="type" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="call">📞 Anruf</option>
                        <option value="email">✉️ E-Mail</option>
                        <option value="meeting">📅 Termin</option>
                        <option value="document">📄 Dokument</option>
                        <option value="follow_up">🔄 Follow-up</option>
                        <option value="other">📌 Sonstige</option>
                    </select>
                </div>
                <div class="field"><label>Priorität</label>
                    <select name="priority" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        <option value="medium">Mittel</option>
                        <option value="high">Hoch</option>
                        <option value="low">Niedrig</option>
                    </select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Fälligkeitsdatum</label><input type="date" name="due_date" value="{{ date('Y-m-d') }}"></div>
                <div class="field"><label>Zuweisen an</label>
                    <select name="assigned_to" required style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                        @foreach(\App\Models\User::whereIn('role',['admin','employee'])->get() as $u)
                        <option value="{{ $u->id }}" {{ $u->id === auth()->id() ? 'selected' : '' }}>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="field"><label>Kunde (optional)</label>
                <select name="customer_id" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    <option value="">Kein Kunde</option>
                    @foreach(\App\Models\Customer::with('user')->get() as $c)
                    <option value="{{ $c->id }}">{{ $c->user?->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label>Beschreibung</label><textarea name="description" placeholder="Details zur Aufgabe..." style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;min-height:80px;resize:vertical;font-family:inherit;"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" onclick="document.getElementById('new-task-modal').style.display='none'" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Aufgabe erstellen</button>
            </div>
        </form>
    </div>
</div>
@endsection
