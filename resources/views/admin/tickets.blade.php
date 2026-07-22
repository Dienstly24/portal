@extends('layouts.admin')
@section('content')
@php
// Ticket-Typ -> Icon + Farbflaeche (gleiche Bildsprache wie Kunden-/Vertragsliste,
// damit die Beraterwelt konsistent wirkt). Unbekannte Typen -> 'other'.
$typeConfig = [
    'damage'       => ['icon' => '💥', 'bg' => '#F9E3E3'],
    'change'       => ['icon' => '🔄', 'bg' => '#E6F1FB'],
    'offer'        => ['icon' => '🏷️', 'bg' => '#D9F4E6'],
    'data_update'  => ['icon' => '📝', 'bg' => '#EDE9FE'],
    'cancellation' => ['icon' => '🚪', 'bg' => '#F7E7D6'],
    'complaint'    => ['icon' => '⚠️', 'bg' => '#FEF3C7'],
    'other'        => ['icon' => '💬', 'bg' => '#EEF0F3'],
];
// Prioritaet -> farbiger Chip (Punkt + Label), damit die Dringlichkeit auf einen
// Blick lesbar ist statt nur als Emoji.
$prioConfig = [
    'dringend' => ['label' => 'Dringend', 'bg' => '#F9E3E3', 'fg' => '#A32D2D'],
    'hoch'     => ['label' => 'Hoch',     'bg' => '#F7E7D6', 'fg' => '#B5651D'],
    'mittel'   => ['label' => 'Mittel',   'bg' => '#FAF0DA', 'fg' => '#8A6D1B'],
    'niedrig'  => ['label' => 'Niedrig',  'bg' => '#D9F4E6', 'fg' => '#17A65B'],
];
$me = auth()->user();
// Bearbeiten: admin/manager/support immer, Mitarbeiter nur mit Recht.
// Loeschen (Papierkorb): NUR admin/manager - analog zur Kundenloeschung.
$canManage = $me->role !== 'employee' || $me->can_manage_tickets;
$canDelete = in_array($me->role, ['admin', 'manager'], true);
// Schnellaktionen je Status (gleiche Logik wie die Detailseite)
$quickActions = [
    'open'        => [['in_progress', '▶ In Bearbeitung übernehmen'], ['resolved', '✔ Als gelöst markieren'], ['closed', '✖ Schließen']],
    'in_progress' => [['waiting', '⏸ Wartet auf Kunde'], ['resolved', '✔ Als gelöst markieren'], ['closed', '✖ Schließen']],
    'waiting'     => [['in_progress', '▶ Wieder in Bearbeitung'], ['resolved', '✔ Als gelöst markieren'], ['closed', '✖ Schließen']],
    'resolved'    => [['open', '↩ Wieder öffnen'], ['closed', '✖ Endgültig schließen']],
    'closed'      => [['open', '↩ Wieder öffnen']],
];
$showBulk = $canManage && !$trashView && $tickets->count() > 0;
@endphp
<div class="toolbar">
    <div>
        <div class="page-title">Tickets</div>
        <div class="page-sub">Anfragen registrierter Kunden aus dem Kundenportal.</div>
    </div>
    @if(in_array($me->role, ['admin','manager']))
    <a href="{{ route('admin.tickets.stats') }}" class="btn btn-ghost">📊 Statistik</a>
    @endif
</div>

@php
    $s = $stats['statuses'];
    $activeCount = ($s['open'] ?? 0) + ($s['in_progress'] ?? 0) + ($s['waiting'] ?? 0);
@endphp
{{-- Kennzahlen-Karten sind klickbar und filtern die Liste direkt --}}
<div class="metrics-grid">
    <a href="{{ route('admin.tickets', ['status' => 'open']) }}" class="metric-card metric-card-link">
        <div class="metric-icon icon-blue">🎫</div>
        <div class="metric-label">Offene Tickets</div>
        <div class="metric-value">{{ $s['open'] ?? 0 }}</div>
        <div class="metric-sub">Noch nicht in Bearbeitung · ansehen →</div>
    </a>
    <a href="{{ route('admin.tickets', ['status' => 'in_progress']) }}" class="metric-card metric-card-link">
        <div class="metric-icon icon-amber">⚙️</div>
        <div class="metric-label">In Bearbeitung</div>
        <div class="metric-value">{{ $s['in_progress'] ?? 0 }}</div>
        <div class="metric-sub">Wartet auf Kunde: {{ $s['waiting'] ?? 0 }} · ansehen →</div>
    </a>
    <a href="{{ route('admin.tickets', ['overdue' => 1]) }}" class="metric-card metric-card-link">
        <div class="metric-icon icon-red">⏰</div>
        <div class="metric-label">Überfällig</div>
        <div class="metric-value">{{ $stats['overdue'] }}</div>
        <div class="metric-sub">Reaktionszeit überschritten · ansehen →</div>
    </a>
    <a href="{{ route('admin.tickets', ['assigned' => 'none', 'status' => 'aktiv']) }}" class="metric-card metric-card-link">
        <div class="metric-icon icon-green">👤</div>
        <div class="metric-label">Nicht zugewiesen</div>
        <div class="metric-value">{{ $stats['unassigned'] }}</div>
        <div class="metric-sub">Von {{ $activeCount }} aktiven Tickets · ansehen →</div>
    </a>
</div>

{{-- Status-Tabs (behalten alle uebrigen Filter bei) --}}
<div class="tab-row">
    @php $current = request()->boolean('overdue') ? '' : request('status', 'alle'); @endphp
    <a href="{{ request()->fullUrlWithQuery(['status' => null, 'overdue' => null, 'page' => null]) }}" class="tab {{ $current === 'alle' ? 'active' : '' }}">Alle</a>
    @foreach(\App\Models\Ticket::STATUSES as $key => $label)
    <a href="{{ request()->fullUrlWithQuery(['status' => $key, 'overdue' => null, 'page' => null]) }}" class="tab {{ $current === $key ? 'active' : '' }}">
        {{ $label }}<span class="tab-count">{{ $s[$key] ?? 0 }}</span>
    </a>
    @endforeach
    @if($canDelete)
    <a href="{{ request()->fullUrlWithQuery(['status' => 'papierkorb', 'overdue' => null, 'page' => null]) }}" class="tab tab-trash {{ $current === 'papierkorb' ? 'active' : '' }}">
        🗑️ Papierkorb<span class="tab-count">{{ $stats['trashed'] }}</span>
    </a>
    @endif
</div>

@if($trashView)
<div class="card" style="background:#FFFDF7;border-color:#F7E7D6;margin-bottom:16px;">
    <div style="font-size:13.5px;color:var(--ink-soft);line-height:1.6;">
        🗑️ <strong>Papierkorb:</strong> Gelöschte Tickets bleiben hier aufbewahrt und können jederzeit
        wiederhergestellt werden. Sie erscheinen weder in der Ticketliste noch im Kundenportal.
        Endgültiges Löschen (nur Administrator) entfernt Ticket, Nachrichten, Verlauf und Anhänge unwiderruflich.
    </div>
</div>
@endif

<div class="card" style="margin-bottom:16px;">
    <form method="GET" action="{{ route('admin.tickets') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        @if(request()->boolean('overdue'))<input type="hidden" name="overdue" value="1">@endif
        <div class="field" style="flex:2;min-width:200px;margin-bottom:0;">
            <label>Suche</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Betreff, Ticket-Nr., Kunde, Kundennummer...">
        </div>
        <div class="field" style="flex:1;min-width:140px;margin-bottom:0;">
            <label>Typ</label>
            <select name="type" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach(\App\Models\Ticket::TYPES as $key => $label)
                <option value="{{ $key }}" {{ request('type') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="flex:1;min-width:140px;margin-bottom:0;">
            <label>Priorität</label>
            <select name="priority" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach(\App\Models\Ticket::PRIORITIES as $key => $p)
                <option value="{{ $key }}" {{ request('priority') === $key ? 'selected' : '' }}>{{ $p['icon'] }} {{ $p['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="flex:1;min-width:160px;margin-bottom:0;">
            <label>Zugewiesen an</label>
            <select name="assigned" onchange="this.form.submit()">
                <option value="">Alle</option>
                <option value="me" {{ request('assigned') === 'me' ? 'selected' : '' }}>Mir zugewiesen</option>
                <option value="none" {{ request('assigned') === 'none' ? 'selected' : '' }}>Nicht zugewiesen</option>
                @foreach($staff as $u)
                <option value="{{ $u->id }}" {{ request('assigned') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="flex:1;min-width:170px;margin-bottom:0;">
            <label>Sortierung</label>
            <select name="sort" onchange="this.form.submit()">
                @foreach(['aktualisiert' => 'Zuletzt aktualisiert', 'neueste' => 'Neueste zuerst', 'aelteste' => 'Älteste zuerst', 'prioritaet' => 'Priorität (dringend zuerst)', 'faellig' => 'Fälligkeit (SLA)'] as $key => $label)
                <option value="{{ $key }}" {{ $sort === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filtern</button>
        @if(request()->hasAny(['q','priority','type','assigned','status','overdue','sort']))
        <a href="{{ route('admin.tickets') }}" class="btn btn-ghost">Zurücksetzen</a>
        @endif
    </form>
</div>

<div x-data="{
    selected: [],
    pageIds: @js($showBulk ? $tickets->pluck('id')->map(fn($i) => (string) $i)->values() : []),
    toggleAll(ev) { this.selected = ev.target.checked ? [...this.pageIds] : []; },
    doBulk(action) {
        if (action === 'delete' && !confirm(this.selected.length + ' Ticket(s) in den Papierkorb verschieben?')) return;
        this.$refs.bulkAction.value = action;
        this.$refs.bulkForm.submit();
    }
}">
<div class="card" style="padding:0;overflow:visible;">
    <table class="tickets-table">
        <thead><tr>
            @if($showBulk)
            <th class="noNav" style="width:40px;padding-left:20px;">
                <input type="checkbox" class="ticket-check" title="Alle auswählen"
                    :checked="selected.length === pageIds.length && pageIds.length > 0" @change="toggleAll($event)">
            </th>
            <th style="width:44px;"></th>
            @else
            <th style="width:52px;"></th>
            @endif
            <th>Betreff</th>
            <th>Kunde</th>
            <th>Priorität</th>
            <th>Zugewiesen</th>
            <th>{{ $trashView ? 'Gelöscht' : 'Aktualisiert' }}</th>
            <th>Status</th>
            <th style="width:44px;"></th>
        </tr></thead>
        <tbody>
        @forelse($tickets as $t)
        @php
            $cfg = $typeConfig[$t->type] ?? $typeConfig['other'];
            $prio = $prioConfig[$t->priority] ?? $prioConfig['mittel'];
            $custName = $t->customer?->user?->name ?? $t->guest_name ?? $t->guest_email;
            // Initialen aus dem Namen (Sonderzeichen/Klammern aus Import-Namen
            // vorher entfernen, damit die Avatare sauber bleiben).
            $clean = trim(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', (string) $custName));
            $initials = \Illuminate\Support\Str::of($clean !== '' ? $clean : '?')->explode(' ')
                ->filter()->take(2)->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
        @endphp
        <tr class="ticket-row" data-href="{{ route('admin.ticket', $t->id) }}" style="cursor:pointer;">
            @if($showBulk)
            <td class="noNav" style="padding-left:20px;">
                <input type="checkbox" class="ticket-check" value="{{ $t->id }}" x-model="selected">
            </td>
            @endif
            {{-- Typ-Icon als farbige Kachel (wie Kunden-/Vertragsliste) --}}
            <td style="{{ $showBulk ? '' : 'padding-left:20px;' }}">
                <div title="{{ $t->typeLabel() }}" aria-label="{{ $t->typeLabel() }}"
                    style="width:38px;height:38px;border-radius:10px;background:{{ $cfg['bg'] }};display:flex;align-items:center;justify-content:center;font-size:18px;">{{ $cfg['icon'] }}</div>
            </td>
            <td>
                <div style="font-weight:600;line-height:1.35;">{{ $t->subject }}</div>
                <div style="font-size:12px;color:var(--ink-soft);margin-top:2px;">
                    <span style="font-variant-numeric:tabular-nums;">{{ $t->ticket_number }}</span>
                    · {{ $t->typeLabel() }} · erstellt {{ $t->created_at->format('d.m.Y') }}
                </div>
            </td>
            <td>
                @if($custName)
                <div style="display:flex;align-items:center;gap:9px;">
                    <span class="ticket-avatar">{{ $initials }}</span>
                    <div style="min-width:0;">
                        <div style="font-weight:500;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px;">{{ $custName }}</div>
                        @if($t->customer?->customer_number)
                        <div style="font-size:11.5px;color:var(--ink-soft);">Nr. {{ $t->customer->customer_number }}</div>
                        @elseif(!$t->customer)
                        <div style="font-size:11.5px;color:var(--ink-soft);">Gast-Anfrage</div>
                        @endif
                    </div>
                </div>
                @else
                <span style="color:var(--ink-soft);">—</span>
                @endif
            </td>
            <td>
                <span class="prio-chip" style="background:{{ $prio['bg'] }};color:{{ $prio['fg'] }};">
                    <span class="prio-dot" style="background:{{ $prio['fg'] }};"></span>{{ $prio['label'] }}
                </span>
            </td>
            <td style="font-size:13px;color:var(--ink-soft);white-space:nowrap;">{{ $t->assignedTo?->name ?? '—' }}</td>
            @php $ts = $trashView ? $t->deleted_at : $t->updated_at; @endphp
            <td style="color:var(--ink-soft);white-space:nowrap;font-size:13px;">{{ $ts->format('d.m.Y') }}<div style="font-size:11.5px;">{{ $ts->format('H:i') }} Uhr</div></td>
            <td>
                <span class="badge badge-{{ $t->statusBadge() }}">{{ $t->statusLabel() }}</span>
                @if($trashView)<div style="font-size:11px;color:#A32D2D;font-weight:600;margin-top:5px;white-space:nowrap;">🗑️ im Papierkorb</div>
                @elseif($t->isOverdue())<div style="font-size:11px;color:#A32D2D;font-weight:600;margin-top:5px;white-space:nowrap;">⏰ überfällig</div>@endif
            </td>
            {{-- Aktionen: 3-Punkte-Menue. Zelle .noNav, damit der Klick hier NICHT
                 die Zeilennavigation ausloest. --}}
            <td class="noNav" style="text-align:right;padding-right:16px;position:relative;" x-data="{open:false}">
                <button type="button" @click="open=!open" aria-haspopup="true" :aria-expanded="open" title="Aktionen"
                    style="background:none;border:none;cursor:pointer;font-size:18px;line-height:1;color:var(--ink-soft);padding:4px 10px;border-radius:6px;letter-spacing:1px;">•••</button>
                <div x-show="open" x-cloak @click.outside="open=false" @keydown.escape.window="open=false"
                    style="position:absolute;right:12px;top:100%;z-index:50;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.14);min-width:230px;padding:6px;">
                    <a href="{{ route('admin.ticket', $t->id) }}" class="rowmenu-item">🎫 Ticket öffnen</a>
                    @if($t->customer)
                    <a href="{{ route('admin.customer', $t->customer_id) }}" class="rowmenu-item">👤 Kundenakte öffnen</a>
                    @endif
                    @if($trashView)
                        <div class="rowmenu-sep"></div>
                        <form method="POST" action="{{ route('admin.ticket.restore', $t->id) }}">
                            @csrf
                            <button type="submit" class="rowmenu-item">♻️ Wiederherstellen</button>
                        </form>
                        @if($me->role === 'admin')
                        <form method="POST" action="{{ route('admin.ticket.forcedelete', $t->id) }}"
                            onsubmit="return confirm('Ticket {{ $t->ticket_number }} ENDGÜLTIG löschen? Nachrichten, Verlauf und Anhänge werden unwiderruflich entfernt.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="rowmenu-item rowmenu-danger">🗑️ Endgültig löschen</button>
                        </form>
                        @endif
                    @else
                        @if($canManage)
                        <div class="rowmenu-sep"></div>
                        @if(!$t->assigned_to || $t->assigned_to !== $me->id)
                        <form method="POST" action="{{ route('admin.ticket.update', $t->id) }}">
                            @csrf
                            <input type="hidden" name="assigned_to" value="{{ $me->id }}">
                            <button type="submit" class="rowmenu-item">👤 Mir zuweisen</button>
                        </form>
                        @endif
                        @foreach($quickActions[$t->status] ?? [] as [$st, $label])
                        <form method="POST" action="{{ route('admin.ticket.status', $t->id) }}">
                            @csrf
                            <input type="hidden" name="status" value="{{ $st }}">
                            <button type="submit" class="rowmenu-item">{{ $label }}</button>
                        </form>
                        @endforeach
                        @endif
                        @if($canDelete)
                        <div class="rowmenu-sep"></div>
                        <form method="POST" action="{{ route('admin.ticket.delete', $t->id) }}"
                            onsubmit="return confirm('Ticket {{ $t->ticket_number }} in den Papierkorb verschieben?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="rowmenu-item rowmenu-danger">🗑️ In den Papierkorb</button>
                        </form>
                        @endif
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="{{ $showBulk ? 9 : 8 }}" style="color:var(--ink-soft);text-align:center;padding:40px;">
            {{ $trashView ? 'Der Papierkorb ist leer.' : 'Keine Tickets gefunden.' }}
        </td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($showBulk)
{{-- Bulk-Aktionsleiste: erscheint, sobald Tickets ausgewaehlt sind --}}
<form method="POST" action="{{ route('admin.tickets.bulk') }}" x-ref="bulkForm" x-show="selected.length > 0" x-cloak class="bulk-bar">
    @csrf
    <input type="hidden" name="action" x-ref="bulkAction">
    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
    <span class="bulk-count"><strong x-text="selected.length"></strong>&nbsp;ausgewählt</span>
    <select name="status" @change="$event.target.value && doBulk('status')">
        <option value="">Status setzen…</option>
        @foreach(\App\Models\Ticket::STATUSES as $key => $label)
        <option value="{{ $key }}">{{ $label }}</option>
        @endforeach
    </select>
    <select name="assigned_to" @change="$event.target.value && doBulk('assign')">
        <option value="">Zuweisen an…</option>
        <option value="me">Mir zuweisen</option>
        <option value="none">Zuweisung entfernen</option>
        @foreach($staff as $u)
        <option value="{{ $u->id }}">{{ $u->name }}</option>
        @endforeach
    </select>
    <select name="priority" @change="$event.target.value && doBulk('priority')">
        <option value="">Priorität setzen…</option>
        @foreach(\App\Models\Ticket::PRIORITIES as $key => $p)
        <option value="{{ $key }}">{{ $p['icon'] }} {{ $p['label'] }}</option>
        @endforeach
    </select>
    @if($canDelete)
    <button type="button" class="bulk-delete" @click="doBulk('delete')">🗑️ Löschen</button>
    @endif
    <button type="button" class="bulk-clear" @click="selected=[]" title="Auswahl aufheben">✕</button>
</form>
@endif
</div>

@if($tickets->hasPages())
<div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;">
    <div style="font-size:13px;color:var(--ink-soft);">
        {{ $tickets->firstItem() }}–{{ $tickets->lastItem() }} von {{ $tickets->total() }} Tickets
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        @if(!$tickets->onFirstPage())
            <a href="{{ $tickets->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>
        @endif
        <span style="font-size:13px;color:var(--ink-soft);">Seite {{ $tickets->currentPage() }} / {{ $tickets->lastPage() }}</span>
        @if($tickets->hasMorePages())
            <a href="{{ $tickets->nextPageUrl() }}" class="btn btn-ghost btn-sm">Weiter →</a>
        @endif
    </div>
</div>
@endif

<style>
[x-cloak]{display:none !important;}
.tickets-table td{padding-top:12px;padding-bottom:12px;vertical-align:middle;}
.ticket-row{transition:background .12s;}
.ticket-row:hover td{background:#E6E9ED;}
.ticket-avatar{width:32px;height:32px;flex:none;border-radius:50%;background:var(--petrol);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11.5px;font-weight:700;letter-spacing:.3px;}
.ticket-check{width:16px;height:16px;accent-color:#17A65B;cursor:pointer;}
.prio-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 11px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;}
.prio-dot{width:7px;height:7px;border-radius:50%;flex:none;}
.rowmenu-item{display:block;width:100%;text-align:left;padding:9px 12px;border-radius:7px;font-size:13.5px;color:var(--ink);text-decoration:none;box-sizing:border-box;background:none;border:none;cursor:pointer;font-family:inherit;}
.rowmenu-item:hover{background:#F7F5EF;}
.rowmenu-danger{color:#A32D2D;}
.rowmenu-danger:hover{background:#F9E3E3;}
.rowmenu-sep{height:1px;background:var(--line);margin:5px 4px;}
.tab-trash{margin-left:auto;}
/* Bulk-Leiste: schwebt unten in der Mitte, Graphit-Optik wie die Sidebar */
.bulk-bar{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);z-index:120;display:flex;align-items:center;gap:10px;background:#131A17;color:#fff;padding:10px 14px;border-radius:14px;box-shadow:0 12px 34px rgba(0,0,0,.35);flex-wrap:wrap;max-width:min(94vw,900px);}
.bulk-bar .bulk-count{font-size:13.5px;white-space:nowrap;padding:0 4px;}
.bulk-bar select{background:#0F1512;color:#fff;border:1px solid #2a2d33;border-radius:8px;padding:7px 10px;font-size:13px;max-width:190px;}
.bulk-bar .bulk-delete{background:#3a1518;color:#ff9b9b;border:1px solid #5a2226;border-radius:8px;padding:7px 12px;font-size:13px;cursor:pointer;white-space:nowrap;}
.bulk-bar .bulk-delete:hover{background:#4a1a1e;}
.bulk-bar .bulk-clear{background:none;border:none;color:#9aa0a8;font-size:15px;cursor:pointer;padding:4px 6px;}
.bulk-bar .bulk-clear:hover{color:#fff;}
</style>
<script>
document.querySelectorAll('tr.ticket-row').forEach(function (row) {
    row.addEventListener('click', function (e) {
        if (e.target.closest('.noNav') || e.target.closest('a') || e.target.closest('button')) return;
        window.location = row.dataset.href;
    });
});
</script>
@endsection
