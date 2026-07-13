@extends('layouts.admin')
@section('content')
@php
    $attachments = $ticket->attachments;
    $me = auth()->user();
    $canManage = $me->role !== 'employee' || $me->can_manage_tickets;
    $quelle = ['portal'=>'Portal','hilfe-formular'=>'Hilfe-Formular','website'=>'Website','email'=>'E-Mail'][$ticket->source] ?? $ticket->source;
@endphp
<div class="toolbar">
    <div>
        <div class="page-title">{{ $ticket->subject }}</div>
        <div class="page-sub">
            <strong>{{ $ticket->ticket_number }}</strong>
            @if($ticket->customer) · 👤 <a href="{{ route('admin.customer', $ticket->customer_id) }}" style="color:inherit;">{{ $ticket->customer?->user?->name }}</a> · Nr. {{ $ticket->customer->customer_number }}
            @else · 👤 {{ $ticket->guest_name ?? 'Gast' }}
            @endif
            <span style="color:var(--ink-soft);">(via {{ $quelle }})</span>
            · {{ $ticket->created_at->format('d.m.Y H:i') }}
            · {{ $ticket->priorityLabel() }}
        </div>
    </div>
    <div style="text-align:right;">
        <span class="badge badge-{{ $ticket->statusBadge() }}">{{ $ticket->statusLabel() }}</span>
        @if($ticket->isOverdue())<div style="font-size:12px;color:#A32D2D;font-weight:600;margin-top:6px;">⏰ Reaktionszeit überschritten (fällig {{ $ticket->due_at->format('d.m.Y H:i') }})</div>@endif
    </div>
</div>

@if($canManage)
{{-- Schnellaktionen: Statuswechsel mit einem Klick --}}
<div class="card">
    <div class="card-title">Schnellaktionen</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        @php
            $actions = match($ticket->status) {
                'open' => [['in_progress','▶ In Bearbeitung übernehmen','btn-primary'],['waiting','⏸ Wartet auf Kunde','btn-ghost'],['resolved','✔ Als gelöst markieren','btn-gold'],['closed','✖ Schließen','btn-ghost']],
                'in_progress' => [['waiting','⏸ Wartet auf Kunde','btn-ghost'],['resolved','✔ Als gelöst markieren','btn-gold'],['closed','✖ Schließen','btn-ghost']],
                'waiting' => [['in_progress','▶ Wieder in Bearbeitung','btn-primary'],['resolved','✔ Als gelöst markieren','btn-gold'],['closed','✖ Schließen','btn-ghost']],
                'resolved' => [['open','↩ Wieder öffnen','btn-ghost'],['closed','✖ Endgültig schließen','btn-primary']],
                'closed' => [['open','↩ Wieder öffnen','btn-primary']],
                default => [],
            };
        @endphp
        @foreach($actions as [$st, $label, $cls])
        <form method="POST" action="{{ route('admin.ticket.status', $ticket->id) }}">
            @csrf
            <input type="hidden" name="status" value="{{ $st }}">
            <button type="submit" class="btn {{ $cls }}">{{ $label }}</button>
        </form>
        @endforeach
    </div>
    @if($ticket->status === 'open' && !$ticket->assigned_to)
    <div style="font-size:12.5px;color:var(--ink-soft);margin-top:10px;">💡 „In Bearbeitung übernehmen" weist das Ticket automatisch Ihnen zu.</div>
    @endif
</div>
@else
<div class="card" style="background:#FFFDF7;border-color:#F7E7D6;">
    <div style="font-size:13.5px;color:var(--ink-soft);">🔒 Sie haben keine Berechtigung, Tickets zu bearbeiten (nur Lesezugriff). Wenden Sie sich an einen Administrator.</div>
</div>
@endif

<div class="grid-2">
    {{-- Eigenschaften: Zuweisung, Prioritaet, Typ --}}
    <div class="card">
        <div class="card-title">Eigenschaften</div>
        @if($canManage)
        <form method="POST" action="{{ route('admin.ticket.update', $ticket->id) }}">
            @csrf
            <div class="field">
                <label>Zugewiesen an</label>
                <select name="assigned_to">
                    <option value="">— Nicht zugewiesen —</option>
                    @foreach($staff as $u)
                    <option value="{{ $u->id }}" {{ $ticket->assigned_to == $u->id ? 'selected' : '' }}>{{ $u->name }}{{ $u->id === $me->id ? ' (ich)' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid-2">
                <div class="field">
                    <label>Priorität</label>
                    <select name="priority">
                        @foreach(\App\Models\Ticket::PRIORITIES as $key => $p)
                        <option value="{{ $key }}" {{ $ticket->priority === $key ? 'selected' : '' }}>{{ $p['icon'] }} {{ $p['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Typ</label>
                    <select name="type">
                        @foreach(\App\Models\Ticket::TYPES as $key => $label)
                        <option value="{{ $key }}" {{ $ticket->type === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary">Speichern</button>
                @if($ticket->assigned_to !== $me->id)
                <button type="submit" name="assigned_to" value="{{ $me->id }}" class="btn btn-ghost">👤 Mir zuweisen</button>
                @endif
            </div>
        </form>
        @else
        <p style="font-size:14px;color:var(--ink-soft);">
            Zugewiesen an: <strong>{{ $ticket->assignedTo?->name ?? '—' }}</strong><br>
            Priorität: {{ $ticket->priorityLabel() }} · Typ: {{ $ticket->typeLabel() }}
        </p>
        @endif
    </div>

    {{-- Kennzahlen / SLA --}}
    <div class="card">
        <div class="card-title">Details & Fristen</div>
        <table style="font-size:13.5px;">
            <tr><td style="color:var(--ink-soft);padding:7px 0;">Erstellt</td><td style="padding:7px 0;">{{ $ticket->created_at->format('d.m.Y H:i') }}</td></tr>
            <tr><td style="color:var(--ink-soft);padding:7px 0;">Reaktion fällig bis</td><td style="padding:7px 0;{{ $ticket->isOverdue() ? 'color:#A32D2D;font-weight:600;' : '' }}">{{ $ticket->due_at?->format('d.m.Y H:i') ?? '—' }}</td></tr>
            <tr><td style="color:var(--ink-soft);padding:7px 0;">Erste Antwort</td><td style="padding:7px 0;">{{ $ticket->first_response_at?->format('d.m.Y H:i') ?? 'noch keine' }}</td></tr>
            @if($ticket->resolved_at)<tr><td style="color:var(--ink-soft);padding:7px 0;">Gelöst am</td><td style="padding:7px 0;">{{ $ticket->resolved_at->format('d.m.Y H:i') }}</td></tr>@endif
            @if($ticket->closed_at)<tr><td style="color:var(--ink-soft);padding:7px 0;">Geschlossen am</td><td style="padding:7px 0;">{{ $ticket->closed_at->format('d.m.Y H:i') }}{{ $ticket->closedBy ? ' von ' . $ticket->closedBy->name : '' }}</td></tr>@endif
            @if($ticket->reopened_count > 0)<tr><td style="color:var(--ink-soft);padding:7px 0;">Wiedereröffnet</td><td style="padding:7px 0;">{{ $ticket->reopened_count }}×</td></tr>@endif
            @if($ticket->rating)<tr><td style="color:var(--ink-soft);padding:7px 0;">Kundenbewertung</td><td style="padding:7px 0;">{{ str_repeat('★', $ticket->rating) }}{{ str_repeat('☆', 5 - $ticket->rating) }} ({{ $ticket->rating }}/5){{ $ticket->rating_comment ? ' – „' . $ticket->rating_comment . '"' : '' }}</td></tr>@endif
        </table>
    </div>
</div>

@if(!$ticket->customer && ($ticket->guest_email || $ticket->guest_phone))
<div class="card" style="background:#EFF6FF;">
    <div class="card-title">Kontaktdaten (Lead)</div>
    <p style="font-size:14px;">
        @if($ticket->guest_email)📧 <a href="mailto:{{ $ticket->guest_email }}">{{ $ticket->guest_email }}</a><br>@endif
        @if($ticket->guest_phone)📞 {{ $ticket->guest_phone }}@endif
    </p>
</div>
@endif

<div class="card">
    <div class="card-title">Beschreibung</div>
    <p style="font-size:14px;color:var(--ink-soft);line-height:1.7;white-space:pre-line;">{{ $ticket->description }}</p>
</div>

@if($attachments->count())
<div class="card">
    <div class="card-title">📎 Anhänge ({{ $attachments->count() }})</div>
    @foreach($attachments as $a)
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line);">
        <span style="font-size:13.5px;">{{ $a->file_name }} <span style="color:var(--ink-soft);font-size:11.5px;">· {{ $a->created_at->format('d.m.Y H:i') }}</span></span>
        <span style="display:flex;gap:8px;"><a href="{{ route('admin.attachment.download', $a->id) }}" class="btn btn-ghost btn-sm">⬇ Herunterladen</a></span>
    </div>
    @endforeach
</div>
@endif

@php
    $customerMessages = $ticket->messages->where('is_internal', false)->sortBy('created_at');
    $internalNotes = $ticket->messages->where('is_internal', true)->sortByDesc('created_at');
@endphp

{{-- Kundenkommunikation: sichtbar im Kundenportal --}}
<div class="card">
    <div class="card-title">💬 Kundenkommunikation <span style="font-size:11.5px;font-weight:400;color:var(--ink-soft);">(für den Kunden sichtbar)</span></div>
    @forelse($customerMessages as $m)
    @php $fromCustomer = $m->sender_id === $ticket->customer?->user_id; @endphp
    <div style="margin-bottom:16px;padding:12px 16px;border-radius:8px;background:{{ $fromCustomer ? '#EFF6FF' : 'var(--canvas)' }};border:1px solid var(--line);">
        <div style="font-size:12px;color:var(--ink-soft);margin-bottom:6px;">{{ $fromCustomer ? '👤' : '🏢' }} {{ $m->sender?->name ?? 'Dienstly24 Team (Konto entfernt)' }} · {{ $m->created_at->format('d.m.Y H:i') }}</div>
        <div style="font-size:14px;line-height:1.6;white-space:pre-line;">{{ $m->body }}</div>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Nachrichten an den Kunden.</p>
    @endforelse
</div>

@if($canManage && $ticket->status !== 'closed')
<div class="card">
    <div class="card-title">Antworten</div>
    <form method="POST" action="{{ route('admin.ticket.reply', $ticket->id) }}" enctype="multipart/form-data">
        @csrf
        <div class="field"><label>Nachricht</label><textarea name="body" required placeholder="Ihre Antwort..."></textarea></div>
        <div class="field">
            <label>Datei anhängen (optional)</label>
            <input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp">
            <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">PDF, JPG, PNG oder WEBP · für den Kunden im Portal sichtbar</div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Status nach dem Senden</label>
                <select name="status">
                    @foreach(\App\Models\Ticket::STATUSES as $key => $label)
                    <option value="{{ $key }}" {{ ($ticket->status === 'open' || $ticket->status === 'in_progress' ? 'waiting' : $ticket->status) === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="display:flex;align-items:flex-end;padding-bottom:18px;">
                <span style="font-size:12px;color:var(--ink-soft);">📨 Diese Antwort geht an den Kunden{{ !$ticket->customer && $ticket->guest_email ? ' per E-Mail (' . $ticket->guest_email . ')' : '' }}.</span>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Senden</button>
    </form>
</div>
@elseif($ticket->status === 'closed')
<div class="card" style="background:var(--canvas);">
    <div style="font-size:13.5px;color:var(--ink-soft);">Dieses Ticket ist geschlossen. Zum Antworten zuerst über „Wieder öffnen" reaktivieren.</div>
</div>
@endif

{{-- Interne Notizen: NIE im Kundenportal sichtbar --}}
<div class="card" style="background:#FFFDF7;border-color:#F7E7D6;">
    <div class="card-title">🔒 Interne Notizen <span style="font-size:11.5px;font-weight:400;color:var(--ink-soft);">(nur für das Team – der Kunde sieht das nicht)</span></div>
    @foreach($internalNotes as $n)
    <div style="margin-bottom:12px;padding:10px 14px;border-radius:8px;background:#FDF6E9;border:1px solid #F7E7D6;">
        <div style="font-size:12px;color:var(--ink-soft);margin-bottom:4px;">{{ $n->sender?->name ?? 'Dienstly24 Team (Konto entfernt)' }} · {{ $n->created_at->format('d.m.Y H:i') }}</div>
        <div style="font-size:13.5px;line-height:1.6;white-space:pre-line;">{{ $n->body }}</div>
    </div>
    @endforeach
    @if($canManage)
    <form method="POST" action="{{ route('admin.ticket.note', $ticket->id) }}">
        @csrf
        <div class="field" style="margin-bottom:10px;"><textarea name="body" required placeholder="Interne Notiz für Kollegen..." style="min-height:60px;"></textarea></div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <button type="submit" class="btn btn-ghost">🔒 Notiz speichern</button>
            <a href="{{ route('admin.chat.index') }}" style="font-size:12.5px;color:var(--ink-soft);">Längere Absprache? → Interner Chat</a>
        </div>
    </form>
    @endif
</div>

{{-- Verlauf: lueckenloses Protokoll aller Aktionen --}}
<div class="card">
    <div class="card-title">🕓 Verlauf</div>
    @forelse($ticket->events->sortByDesc('created_at') as $e)
    <div style="display:flex;gap:12px;padding:9px 0;border-bottom:1px solid var(--line);font-size:13.5px;">
        <span style="flex:none;">{{ $e->icon() }}</span>
        <div style="flex:1;">
            <strong>{{ $e->label() }}</strong>
            @if($e->details)<span style="color:var(--ink-soft);"> – {{ $e->details }}</span>@endif
        </div>
        <span style="color:var(--ink-soft);white-space:nowrap;">{{ $e->user?->name ?? 'System' }} · {{ $e->created_at->format('d.m.Y H:i') }}</span>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Kein Verlauf vorhanden.</p>
    @endforelse
</div>

<a href="{{ $ticket->customer ? route('admin.tickets') : route('admin.inquiries') }}" class="btn btn-ghost" style="margin-top:8px;">← Zurück</a>
@endsection
