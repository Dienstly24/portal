@extends('layouts.admin')
@section('content')
@php
    // Literale {{platzhalter}} ohne Blade-Kollision ausgeben (der Blade-
    // Echo-Regex stoppt am ersten "}}", daher keine Braces im Echo-String).
    $mustache = fn($key) => '{' . '{' . $key . '}' . '}';
    // Daten fuer das Bearbeiten-Modal (JS) - nur die Aufgaben dieser Seite.
    $taskData = $tasks->getCollection()->mapWithKeys(fn($t) => [$t->id => [
        'id' => $t->id,
        'title' => $t->title,
        'description' => $t->description,
        'type' => $t->type,
        'priority' => $t->priority,
        'status' => $t->status,
        'due_date' => $t->due_date?->format('Y-m-d'),
        'assigned_to' => $t->assigned_to,
        'customer' => $t->customer ? [
            'id' => (string) $t->customer_id,
            'name' => $t->customer->user?->name ?? '—',
            'number' => $t->customer->customer_number,
            'email' => $t->customer->user?->hasRealEmail() ? $t->customer->user->email : null,
        ] : null,
        'auto_email' => [
            'status' => $t->auto_email_status,
            'subject' => $t->auto_email_subject,
            'body' => $t->auto_email_body,
            'send_on' => $t->auto_email_send_on?->format('Y-m-d'),
            'sent_at' => $t->auto_email_sent_at?->lokal()->format('d.m.Y H:i'),
            'error' => $t->auto_email_error,
        ],
    ]]);
@endphp

<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Aufgaben</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div class="page-title">Aufgaben</div>
        <button onclick="openTaskCreate()" class="btn btn-gold">+ Aufgabe erstellen</button>
    </div>
</div>

<div style="display:flex;gap:0;border-bottom:2px solid var(--line);margin-bottom:20px;flex-wrap:wrap;">
    @foreach(['mine'=>'Meine Aufgaben','customer'=>'Kunden-Aufgaben','done'=>'Erledigte Aufgaben'] as $key=>$label)
    <a href="{{ route('admin.tasks', ['tab'=>$key]) }}"
        style="padding:12px 20px;text-decoration:none;font-size:14px;font-weight:{{ $tab===$key?'700':'500' }};color:{{ $tab===$key?'var(--petrol)':'var(--ink-soft)' }};border-bottom:2px solid {{ $tab===$key?'var(--gold)':'transparent' }};margin-bottom:-2px;display:flex;align-items:center;gap:7px;">
        {{ $label }}
        @if($key !== 'done' && ($counts[$key] ?? 0) > 0)
        <span style="background:{{ $tab===$key?'var(--gold)':'#E5E1D5' }};color:{{ $tab===$key?'#fff':'var(--ink-soft)' }};border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;">{{ $counts[$key] }}</span>
        @endif
    </a>
    @endforeach
</div>

@if($tab === 'mine' && $counts['overdue'] > 0 && request('due') !== 'overdue')
<a href="{{ route('admin.tasks', ['tab'=>'mine','due'=>'overdue']) }}" style="display:flex;align-items:center;gap:10px;background:#F9E3E3;border:1px solid #E8B9B9;border-radius:10px;padding:11px 16px;margin-bottom:18px;text-decoration:none;color:#A32D2D;font-size:13.5px;font-weight:600;">
    ⚠️ {{ $counts['overdue'] }} {{ $counts['overdue'] === 1 ? 'Aufgabe ist' : 'Aufgaben sind' }} überfällig – jetzt anzeigen →
</a>
@endif

<div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;align-items:flex-end;">
    <form method="GET" action="{{ route('admin.tasks') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if(request('customer'))<input type="hidden" name="customer" value="{{ request('customer') }}">@endif
        @if($tab !== 'done')
        <div>
            <label style="font-size:12px;color:var(--ink-soft);display:block;margin-bottom:4px;font-weight:600;">Status</label>
            <select name="status" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;min-width:130px;">
                <option value="">Alle</option>
                <option value="open" {{ request('status')==='open'?'selected':'' }}>Offen</option>
                <option value="in_progress" {{ request('status')==='in_progress'?'selected':'' }}>In Bearbeitung</option>
            </select>
        </div>
        @endif
        <div>
            <label style="font-size:12px;color:var(--ink-soft);display:block;margin-bottom:4px;font-weight:600;">Aufgabentyp</label>
            <select name="type" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;min-width:140px;">
                <option value="">Alle</option>
                @foreach(\App\Models\Task::TYPES as $tKey => $tDef)
                <option value="{{ $tKey }}" {{ request('type')===$tKey?'selected':'' }}>{{ $tDef['icon'] }} {{ $tDef['label'] }}</option>
                @endforeach
            </select>
        </div>
        @if($tab !== 'done')
        <div>
            <label style="font-size:12px;color:var(--ink-soft);display:block;margin-bottom:4px;font-weight:600;">Fällig</label>
            <div style="display:flex;border:1px solid var(--line);border-radius:8px;overflow:hidden;">
                @foreach(['today'=>'Heute','overdue'=>'Überfällig','7'=>'7 Tage','14'=>'14 Tage',''=>'Alle'] as $val=>$lbl)
                <button type="submit" name="due" value="{{ $val }}"
                    style="padding:8px 13px;border:none;font-size:13px;cursor:pointer;background:{{ request('due','')===$val?'var(--petrol)':'#fff' }};color:{{ request('due','')===$val?'#fff':'var(--ink)' }};white-space:nowrap;">
                    {{ $lbl }}
                </button>
                @endforeach
            </div>
        </div>
        @endif
        <div>
            <label style="font-size:12px;color:var(--ink-soft);display:block;margin-bottom:4px;font-weight:600;">Suche</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Titel, Kunde, Nummer…"
                style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;min-width:190px;background:#fff;">
        </div>
        @if(request()->hasAny(['status','type','due','q','customer']) && (request('status') || request('type') || request('due') || request('q') || request('customer')))
        <a href="{{ route('admin.tasks', ['tab'=>$tab]) }}" style="font-size:12.5px;color:var(--ink-soft);padding:9px 4px;">✕ Filter zurücksetzen</a>
        @endif
    </form>
</div>

@if($filterCustomer)
<div style="display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--line);border-radius:999px;padding:6px 8px 6px 14px;font-size:13px;margin-bottom:16px;">
    <span>👤 Nur Aufgaben von <strong>{{ $filterCustomer->user?->name ?? $filterCustomer->customer_number }}</strong></span>
    <a href="{{ route('admin.tasks', ['tab'=>$tab]) }}" style="text-decoration:none;color:var(--ink-soft);background:var(--canvas);border-radius:999px;width:22px;height:22px;display:flex;align-items:center;justify-content:center;" title="Filter entfernen">✕</a>
</div>
@endif

<div style="font-size:14px;font-weight:700;margin-bottom:14px;">Aufgaben ({{ $tasks->total() }})</div>

@if($tasks->isEmpty())
<div class="card" style="text-align:center;padding:48px 24px;color:var(--ink-soft);">
    <div style="font-size:34px;margin-bottom:10px;">✅</div>
    <div style="font-weight:600;color:var(--ink);margin-bottom:6px;">Keine Aufgaben gefunden</div>
    <div style="font-size:13px;margin-bottom:18px;">Lege eine Aufgabe oder Wiedervorlage an – z. B. „Kunde in 14 Tagen nachfassen".</div>
    <button onclick="openTaskCreate()" class="btn btn-gold">+ Aufgabe erstellen</button>
</div>
@else
<div style="display:flex;flex-direction:column;gap:10px;">
@foreach($tasks as $t)
@php
$priorityColor = ['high'=>'#F9E3E3','medium'=>'#FEF3C7','low'=>'#D9F4E6'];
$priorityText = ['high'=>'#A32D2D','medium'=>'#92400E','low'=>'#17A65B'];
$overdue = $t->isOverdue();
$typeDef = \App\Models\Task::TYPES[$t->type] ?? ['label'=>ucfirst($t->type),'icon'=>'📌'];
@endphp
<div class="card" id="task-{{ $t->id }}" style="padding:16px 20px;margin:0;{{ $overdue ? 'border-left:4px solid #C25454;' : '' }}">
    <div style="display:flex;align-items:center;gap:14px;">
        <div style="width:40px;height:40px;border-radius:10px;background:{{ $overdue ? '#F9E3E3' : '#EDEAE0' }};display:flex;align-items:center;justify-content:center;font-size:20px;flex:none;" title="{{ $typeDef['label'] }}">
            {{ $typeDef['icon'] }}
        </div>
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-weight:700;font-size:14px;">{{ $t->title }}</span>
                <span style="background:{{ $priorityColor[$t->priority] ?? '#EDEAE0' }};color:{{ $priorityText[$t->priority] ?? 'var(--ink-soft)' }};font-size:11px;padding:2px 8px;border-radius:999px;font-weight:600;">{{ \App\Models\Task::PRIORITIES[$t->priority] ?? $t->priority }}</span>
                @if($t->due_date)
                <span style="font-size:12px;color:{{ $overdue ? '#A32D2D' : 'var(--ink-soft)' }};font-weight:{{ $overdue ? '700' : '400' }};">
                    📅 {{ $t->due_date->format('d.m.Y') }}
                </span>
                @if($overdue)
                <span style="background:#A32D2D;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:700;">Überfällig · {{ $t->due_date->diffInDays(today()) }} Tg.</span>
                @elseif($t->due_date->isToday())
                <span style="background:#E6F1FB;color:#185FA5;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:700;">Heute</span>
                @elseif($t->due_date->isTomorrow())
                <span style="background:#EDEAE0;color:var(--ink-soft);padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;">Morgen</span>
                @endif
                @endif
                @if($t->status === 'done' && $t->completed_at)
                <span style="background:#D9F4E6;color:#0F7A43;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;">✓ Erledigt {{ $t->completed_at->lokal()->format('d.m.Y') }}</span>
                @endif
            </div>
            @if($t->description)<div style="font-size:13px;color:var(--ink-soft);margin-top:3px;overflow:hidden;text-overflow:ellipsis;">{{ \Illuminate\Support\Str::limit($t->description, 160) }}</div>@endif
            <div style="font-size:12px;color:var(--ink-soft);margin-top:5px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                @if($t->customer)
                <a href="{{ route('admin.customer', $t->customer_id) }}" style="color:var(--ink);text-decoration:none;background:var(--canvas);border:1px solid var(--line);border-radius:999px;padding:2px 10px;font-weight:600;">👤 {{ $t->customer->user?->name ?? $t->customer->customer_number }}</a>
                @endif
                <span>Zugewiesen: {{ $t->assignedTo?->name ?? '—' }}</span>
                @if($t->createdBy && $t->created_by !== $t->assigned_to)
                <span>von {{ $t->createdBy->name }}</span>
                @endif
                @if($t->email_message_id && in_array(auth()->user()->role, ['admin','manager','support']))
                <a href="{{ route('admin.email_inbox.show', $t->email_message_id) }}" style="color:var(--petrol);font-weight:600;">✉️ E-Mail öffnen</a>
                @endif
                @if($t->auto_email_status === 'pending')
                <span style="background:#F5EFDD;color:#8A7635;border-radius:999px;padding:2px 10px;font-weight:600;" title="Wird automatisch an den Kunden gesendet">⏱️ E-Mail geplant {{ $t->auto_email_send_on?->format('d.m.Y') }}</span>
                @elseif($t->auto_email_status === 'sent')
                <span style="background:#D9F4E6;color:#0F7A43;border-radius:999px;padding:2px 10px;font-weight:600;" title="{{ $t->auto_email_subject }}">✉️✓ E-Mail gesendet {{ $t->auto_email_sent_at?->lokal()->format('d.m.Y') }}</span>
                @elseif($t->auto_email_status === 'failed')
                <span style="background:#F9E3E3;color:#A32D2D;border-radius:999px;padding:2px 10px;font-weight:600;" title="{{ $t->auto_email_error }}">⚠️ E-Mail fehlgeschlagen</span>
                @elseif($t->auto_email_status === 'skipped')
                <span style="background:#EDEAE0;color:var(--ink-soft);border-radius:999px;padding:2px 10px;font-weight:600;" title="{{ $t->auto_email_error }}">✉️ E-Mail übersprungen</span>
                @endif
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex:none;">
            @if($t->status !== 'done')
            <form method="POST" action="{{ route('admin.tasks.update', $t->id) }}">
                @csrf @method('PUT')
                <select name="postpone_days" onchange="if(this.value)this.form.submit()" title="Fälligkeit verschieben" style="padding:6px 8px;border:1px solid var(--line);border-radius:6px;font-size:12px;background:#fff;color:var(--ink-soft);max-width:92px;">
                    <option value="">⏩ Später</option>
                    <option value="1">+1 Tag</option>
                    <option value="3">+3 Tage</option>
                    <option value="7">+1 Woche</option>
                    <option value="14">+2 Wochen</option>
                    <option value="30">+1 Monat</option>
                </select>
            </form>
            @endif
            <form method="POST" action="{{ route('admin.tasks.update', $t->id) }}">
                @csrf @method('PUT')
                <select name="status" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;font-size:12px;">
                    @foreach(\App\Models\Task::STATUSES as $sKey => $sLabel)
                    <option value="{{ $sKey }}" {{ $t->status===$sKey?'selected':'' }}>{{ $sLabel }}{{ $sKey==='done'?' ✓':'' }}</option>
                    @endforeach
                </select>
            </form>
            <button type="button" onclick="openTaskEdit('{{ $t->id }}')" style="border:none;background:none;cursor:pointer;color:var(--ink-soft);font-size:16px;padding:4px;" title="Bearbeiten">✏️</button>
            <form method="POST" action="{{ route('admin.tasks.destroy', $t->id) }}" onsubmit="return confirm('Aufgabe löschen?')">
                @csrf @method('DELETE')
                <button type="submit" style="border:none;background:none;cursor:pointer;color:var(--ink-soft);font-size:18px;padding:4px;" title="Löschen">🗑</button>
            </form>
        </div>
    </div>
</div>
@endforeach
</div>
<div style="margin-top:16px;">{{ $tasks->links() }}</div>
@endif

{{-- Aufgaben-Modal (Anlegen + Bearbeiten) --}}
<div id="task-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:flex-start;justify-content:center;padding:24px;overflow-y:auto;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:640px;position:relative;margin:auto 0;">
        <button onclick="closeTaskModal()" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:20px;cursor:pointer;color:var(--ink-soft);">✕</button>
        <div id="tf-heading" style="font-size:18px;font-weight:700;margin-bottom:18px;">Neue Aufgabe</div>

        @if($errors->any())
        <div style="background:#F9E3E3;color:#A32D2D;border-radius:10px;padding:12px 16px;font-size:13px;margin-bottom:16px;">
            @foreach($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
        </div>
        @endif

        <form method="POST" action="{{ route('admin.tasks.store') }}" id="task-form">
            @csrf
            <input type="hidden" name="_method" value="PUT" id="tf-method" disabled>
            <input type="hidden" name="edit" value="1" id="tf-edit" disabled>
            <input type="hidden" name="_task_id" value="" id="tf-task-id" disabled>

            <div class="field"><label>Titel *</label><input type="text" name="title" id="tf-title" required maxlength="200" placeholder="Was ist zu tun? z. B. „Kunde nachfassen: Angebot KFZ&#8220;"></div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="field"><label>Typ</label>
                    <select name="type" id="tf-type">
                        @foreach(\App\Models\Task::TYPES as $tKey => $tDef)
                        <option value="{{ $tKey }}">{{ $tDef['icon'] }} {{ $tDef['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label>Priorität</label>
                    <select name="priority" id="tf-priority">
                        <option value="medium">Mittel</option>
                        <option value="high">Hoch</option>
                        <option value="low">Niedrig</option>
                    </select>
                </div>
            </div>

            <div class="field" style="margin-bottom:10px;"><label>Fällig am</label>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="date" name="due_date" id="tf-due" style="width:170px;" onchange="clearDueChips()">
                    <div style="display:flex;gap:6px;flex-wrap:wrap;" id="tf-due-chips">
                        @foreach([0=>'Heute',1=>'Morgen',3=>'+3 Tage',7=>'+1 Woche',10=>'+10 Tage',20=>'+20 Tage',30=>'+1 Monat'] as $d=>$lbl)
                        <button type="button" class="due-chip" data-days="{{ $d }}" onclick="pickDue({{ $d }}, this)"
                            style="border:1px solid var(--line);background:#fff;border-radius:999px;padding:5px 11px;font-size:12px;cursor:pointer;color:var(--ink);">{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>
                <div style="font-size:12px;color:var(--ink-soft);margin-top:6px;">Für Wiedervorlagen einfach „+10 Tage" / „+20 Tage" wählen – die Erinnerung kommt am Stichtag automatisch über die Glocke.</div>
            </div>

            <div class="field"><label>Zuweisen an *</label>
                <select name="assigned_to" id="tf-assigned" required>
                    @foreach($staff as $u)
                    <option value="{{ $u->id }}" {{ $u->id === auth()->id() ? 'selected' : '' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field" style="position:relative;">
                <label>Kunde (optional)</label>
                <div id="tk-picked" style="display:none;align-items:center;gap:8px;background:var(--canvas);border:1px solid var(--line);border-radius:10px;padding:9px 12px;">
                    <span style="font-size:14px;">👤</span>
                    <span style="flex:1;min-width:0;font-size:13.5px;">
                        <strong id="tk-picked-name"></strong>
                        <span style="color:var(--ink-soft);" id="tk-picked-sub"></span>
                    </span>
                    <button type="button" onclick="clearTkCustomer()" style="border:none;background:none;cursor:pointer;color:var(--ink-soft);font-size:15px;" title="Kunde entfernen">✕</button>
                </div>
                <input type="text" id="tk-search" autocomplete="off" placeholder="Kunde suchen: Name, Nummer, E-Mail, Firma, Kennzeichen…">
                <div id="tk-results" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.14);max-height:280px;overflow-y:auto;z-index:50;margin-top:4px;"></div>
                <input type="hidden" name="customer_id" id="tf-customer-id">
            </div>

            @if($canAutoEmail)
            <div id="tf-ae-wrap" style="display:none;border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:16px;background:var(--surface);">
                <label style="display:flex;align-items:center;gap:9px;cursor:pointer;font-size:13.5px;font-weight:600;">
                    <input type="checkbox" name="auto_email" value="1" id="tf-ae" onchange="toggleAeFields()" style="width:auto;">
                    ⏱️✉️ E-Mail automatisch an den Kunden senden
                </label>
                <div id="tf-ae-hint" style="font-size:12px;color:var(--ink-soft);margin-top:5px;">Die E-Mail geht am gewählten Tag ab 08:00 Uhr automatisch raus – z. B. eine Erinnerung oder Unterlagen-Anforderung. Wird die Aufgabe vorher erledigt, wird nichts gesendet.</div>
                <div id="tf-ae-fields" style="display:none;margin-top:12px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                        <div class="field" style="margin-bottom:12px;"><label>Vorlage</label>
                            <select id="tf-ae-template" onchange="applyAeTemplate()">
                                <option value="">– Frei schreiben –</option>
                                @foreach($templates as $tpl)
                                <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:12px;"><label>Senden am *</label>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <input type="date" name="auto_email_send_on" id="tf-ae-date" style="flex:1;">
                                <button type="button" onclick="aeDateFromDue()" title="Sendetermin = Fälligkeitstag" style="border:1px solid var(--line);background:#fff;border-radius:8px;padding:8px 10px;font-size:12px;cursor:pointer;white-space:nowrap;">= Fällig am</button>
                            </div>
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:12px;"><label>Betreff *</label><input type="text" name="auto_email_subject" id="tf-ae-subject" maxlength="200" placeholder="z. B. Kurze Erinnerung: Ihre Unterlagen"></div>
                    <div class="field" style="margin-bottom:8px;"><label>E-Mail-Text *</label><textarea name="auto_email_body" id="tf-ae-body" style="min-height:130px;" placeholder="{{ $mustache('anrede') }},&#10;&#10;…"></textarea></div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                        <span style="font-size:11.5px;color:var(--ink-soft);">Platzhalter:</span>
                        @foreach($placeholders as $ph => $phLabel)
                        <button type="button" onclick="insertPh('{{ $mustache($ph) }}')" title="{{ $phLabel }}"
                            style="border:1px solid var(--line);background:#fff;border-radius:999px;padding:3px 9px;font-size:11.5px;cursor:pointer;color:var(--ink-soft);font-family:monospace;">{{ $mustache($ph) }}</button>
                        @endforeach
                    </div>
                    <div style="font-size:11.5px;color:var(--ink-soft);margin-top:6px;">Platzhalter werden beim Versand automatisch mit den Kundendaten gefüllt.</div>
                </div>
            </div>
            <div id="tf-ae-sent" style="display:none;background:#D9F4E6;color:#0F7A43;border-radius:10px;padding:11px 14px;font-size:13px;margin-bottom:16px;"></div>
            <div id="tf-ae-unavailable" style="display:none;background:var(--canvas);color:var(--ink-soft);border-radius:10px;padding:11px 14px;font-size:12.5px;margin-bottom:16px;">ℹ️ Automatische E-Mail nicht möglich: Der ausgewählte Kunde hat keine echte E-Mail-Adresse.</div>
            @endif

            <div class="field"><label>Beschreibung</label><textarea name="description" id="tf-description" maxlength="5000" placeholder="Details zur Aufgabe…" style="min-height:80px;"></textarea></div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" onclick="closeTaskModal()" class="btn btn-ghost">Abbrechen</button>
                <button type="submit" class="btn btn-primary" id="tf-submit">Aufgabe erstellen</button>
            </div>
        </form>
    </div>
</div>

<script>
const TASKS = @json($taskData);
const TEMPLATES = @json($templates->keyBy('id'));
const PRESELECTED = @json($preselected);
const OLD = @json(session()->getOldInput() ?: new stdClass);
const HAS_ERRORS = @json($errors->any());
const CAN_AUTO_EMAIL = @json($canAutoEmail);
const STORE_URL = '{{ route('admin.tasks.store') }}';
const UPDATE_URL_BASE = '{{ url('admin/tasks') }}/';
const SEARCH_URL = '{{ route('admin.tasks.customer_search') }}';

let tkCustomer = null;      // aktuell gewaehlter Kunde {id,name,number,email,...}
let tkTimer = null;
let tkActive = -1;          // Tastatur-Navigation in den Suchtreffern
let tkItems = [];

function el(id) { return document.getElementById(id); }

/* ---------- Modal oeffnen/schliessen ---------- */
function showModal() { el('task-modal').style.display = 'flex'; }
function closeTaskModal() { el('task-modal').style.display = 'none'; }

function resetForm() {
    const f = el('task-form');
    f.reset();
    f.action = STORE_URL;
    el('tf-method').disabled = true;
    el('tf-edit').disabled = true;
    el('tf-task-id').disabled = true;
    el('tf-heading').textContent = 'Neue Aufgabe';
    el('tf-submit').textContent = 'Aufgabe erstellen';
    clearTkCustomer();
    clearDueChips();
    if (CAN_AUTO_EMAIL) {
        el('tf-ae').checked = false;
        el('tf-ae-sent').style.display = 'none';
        el('tf-ae-wrap').style.display = 'none';
        el('tf-ae-unavailable').style.display = 'none';
        toggleAeFields();
    }
}

function openTaskCreate() {
    resetForm();
    if (PRESELECTED) selectTkCustomer(PRESELECTED);
    showModal();
    el('tf-title').focus();
}

function openTaskEdit(id) {
    const t = TASKS[id];
    if (!t) return;
    resetForm();
    prepareEditMode(id);
    el('tf-title').value = t.title || '';
    el('tf-description').value = t.description || '';
    el('tf-type').value = t.type || 'other';
    el('tf-priority').value = t.priority || 'medium';
    el('tf-due').value = t.due_date || '';
    if (t.assigned_to) el('tf-assigned').value = t.assigned_to;
    if (t.customer) selectTkCustomer(t.customer);
    if (CAN_AUTO_EMAIL && t.auto_email) fillAutoEmail(t.auto_email);
    showModal();
}

function prepareEditMode(id) {
    const f = el('task-form');
    f.action = UPDATE_URL_BASE + id;
    el('tf-method').disabled = false;
    el('tf-edit').disabled = false;
    el('tf-task-id').disabled = false;
    el('tf-task-id').value = id;
    el('tf-heading').textContent = 'Aufgabe bearbeiten';
    el('tf-submit').textContent = 'Speichern';
}

function fillAutoEmail(ae) {
    if (ae.status === 'sent') {
        // Bereits gesendet: Historie zeigen, keine Bearbeitung mehr.
        el('tf-ae-wrap').style.display = 'none';
        el('tf-ae-sent').style.display = '';
        el('tf-ae-sent').textContent = '✓ Die automatische E-Mail „' + (ae.subject || '') + '" wurde am ' + (ae.sent_at || '') + ' gesendet.';
        return;
    }
    if (ae.status === 'pending' || ae.subject || ae.body) {
        el('tf-ae').checked = ae.status === 'pending';
        el('tf-ae-subject').value = ae.subject || '';
        el('tf-ae-body').value = ae.body || '';
        el('tf-ae-date').value = ae.send_on || '';
        toggleAeFields();
    }
}

/* ---------- Faelligkeits-Praesets ---------- */
function pickDue(days, chip) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    el('tf-due').value = d.toISOString().slice(0, 10);
    document.querySelectorAll('.due-chip').forEach(c => { c.style.background = '#fff'; c.style.borderColor = 'var(--line)'; c.style.color = 'var(--ink)'; c.style.fontWeight = '400'; });
    if (chip) { chip.style.background = 'var(--petrol)'; chip.style.borderColor = 'var(--petrol)'; chip.style.color = '#fff'; chip.style.fontWeight = '600'; }
}
function clearDueChips() {
    document.querySelectorAll('.due-chip').forEach(c => { c.style.background = '#fff'; c.style.borderColor = 'var(--line)'; c.style.color = 'var(--ink)'; c.style.fontWeight = '400'; });
}

/* ---------- Kunden-Sofortsuche ---------- */
const tkSearch = el('tk-search');
tkSearch.addEventListener('input', () => {
    clearTimeout(tkTimer);
    tkTimer = setTimeout(() => searchTk(tkSearch.value.trim()), 200);
});
tkSearch.addEventListener('focus', () => { if (!tkSearch.value.trim()) searchTk(''); });
tkSearch.addEventListener('keydown', (e) => {
    const box = el('tk-results');
    if (box.style.display === 'none') return;
    if (e.key === 'ArrowDown') { e.preventDefault(); moveTk(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); moveTk(-1); }
    else if (e.key === 'Enter') { e.preventDefault(); if (tkActive >= 0 && tkItems[tkActive]) selectTkCustomer(tkItems[tkActive]); }
    else if (e.key === 'Escape') { box.style.display = 'none'; }
});
document.addEventListener('click', (e) => {
    if (!e.target.closest('#tk-search') && !e.target.closest('#tk-results')) el('tk-results').style.display = 'none';
});

function searchTk(q) {
    fetch(SEARCH_URL + '?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(d => renderTkResults(d.customers || []))
        .catch(() => {});
}

function renderTkResults(customers) {
    const box = el('tk-results');
    box.innerHTML = '';
    tkItems = customers;
    tkActive = -1;
    customers.forEach((c, i) => {
        const row = document.createElement('div');
        row.style.cssText = 'padding:9px 13px;cursor:pointer;border-bottom:1px solid var(--line);';
        row.dataset.idx = i;
        const sub = [c.number, c.company, c.email || '⚠ keine E-Mail', c.betreuer ? 'Betreuer: ' + c.betreuer : null].filter(Boolean).join(' · ');
        row.innerHTML = '<div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>'
            + '<div style="font-size:11.5px;color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>';
        row.children[0].textContent = c.name;
        row.children[1].textContent = sub;
        row.onmouseenter = () => highlightTk(i);
        row.onclick = () => selectTkCustomer(c);
        box.appendChild(row);
    });
    if (!customers.length) box.innerHTML = '<div style="padding:11px 13px;font-size:12.5px;color:var(--ink-soft);">Keine Treffer.</div>';
    box.style.display = '';
}

function moveTk(dir) {
    if (!tkItems.length) return;
    tkActive = (tkActive + dir + tkItems.length) % tkItems.length;
    highlightTk(tkActive);
}
function highlightTk(i) {
    tkActive = i;
    el('tk-results').querySelectorAll('[data-idx]').forEach(r => {
        r.style.background = parseInt(r.dataset.idx) === i ? 'var(--canvas)' : '';
    });
}

function selectTkCustomer(c) {
    tkCustomer = c;
    el('tf-customer-id').value = c.id;
    el('tk-picked-name').textContent = c.name;
    el('tk-picked-sub').textContent = ' · ' + [c.number, c.email || 'keine E-Mail'].filter(Boolean).join(' · ');
    el('tk-picked').style.display = 'flex';
    el('tk-search').style.display = 'none';
    el('tk-results').style.display = 'none';
    tkSearch.value = '';
    syncAeAvailability();
}

function clearTkCustomer() {
    tkCustomer = null;
    el('tf-customer-id').value = '';
    el('tk-picked').style.display = 'none';
    el('tk-search').style.display = '';
    syncAeAvailability();
}

/* ---------- Automatische E-Mail ---------- */
function syncAeAvailability() {
    if (!CAN_AUTO_EMAIL) return;
    const wrap = el('tf-ae-wrap');
    const info = el('tf-ae-unavailable');
    if (el('tf-ae-sent').style.display !== 'none') { wrap.style.display = 'none'; info.style.display = 'none'; return; }
    if (tkCustomer && tkCustomer.email) {
        wrap.style.display = '';
        info.style.display = 'none';
    } else {
        wrap.style.display = 'none';
        info.style.display = tkCustomer ? '' : 'none';
        el('tf-ae').checked = false;
        toggleAeFields();
    }
}

function toggleAeFields() {
    if (!CAN_AUTO_EMAIL) return;
    const on = el('tf-ae').checked;
    el('tf-ae-fields').style.display = on ? '' : 'none';
    if (on && !el('tf-ae-date').value) aeDateFromDue();
}

function aeDateFromDue() {
    el('tf-ae-date').value = el('tf-due').value || new Date().toISOString().slice(0, 10);
}

function applyAeTemplate() {
    const id = el('tf-ae-template').value;
    if (!id || !TEMPLATES[id]) return;
    el('tf-ae-subject').value = TEMPLATES[id].subject || '';
    el('tf-ae-body').value = TEMPLATES[id].body || '';
}

function insertPh(ph) {
    const t = el('tf-ae-body');
    const s = t.selectionStart ?? t.value.length;
    const e = t.selectionEnd ?? s;
    t.value = t.value.slice(0, s) + ph + t.value.slice(e);
    t.focus();
    t.selectionStart = t.selectionEnd = s + ph.length;
}

/* ---------- Nach Validierungsfehler: Modal mit Eingaben wiederherstellen ---------- */
function restoreFromOld() {
    resetForm();
    if (OLD._task_id) prepareEditMode(OLD._task_id);
    el('tf-title').value = OLD.title || '';
    el('tf-description').value = OLD.description || '';
    if (OLD.type) el('tf-type').value = OLD.type;
    if (OLD.priority) el('tf-priority').value = OLD.priority;
    el('tf-due').value = OLD.due_date || '';
    if (OLD.assigned_to) el('tf-assigned').value = OLD.assigned_to;
    if (OLD.customer_id && PRESELECTED && PRESELECTED.id === OLD.customer_id) selectTkCustomer(PRESELECTED);
    else if (OLD._task_id && TASKS[OLD._task_id] && TASKS[OLD._task_id].customer && OLD.customer_id === TASKS[OLD._task_id].customer.id) selectTkCustomer(TASKS[OLD._task_id].customer);
    if (CAN_AUTO_EMAIL && OLD.auto_email) {
        el('tf-ae').checked = true;
        el('tf-ae-subject').value = OLD.auto_email_subject || '';
        el('tf-ae-body').value = OLD.auto_email_body || '';
        el('tf-ae-date').value = OLD.auto_email_send_on || '';
        toggleAeFields();
    }
    showModal();
}

@if($openModal || $errors->any())
document.addEventListener('DOMContentLoaded', () => {
    @if($errors->any())
    restoreFromOld();
    @else
    openTaskCreate();
    @endif
});
@endif
</script>
@endsection
