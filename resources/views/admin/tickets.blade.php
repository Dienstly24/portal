@extends('layouts.admin')
@section('content')
@php
// Ticket-Typ -> Icon + Farbflaeche (gleiche Bildsprache wie Kunden-/Vertragsliste,
// damit die Beraterwelt konsistent wirkt). Unbekannte Typen -> 'other'.
$typeConfig = [
    'damage'       => ['icon' => '💥', 'bg' => '#F9E3E3'],
    'change'       => ['icon' => '🔄', 'bg' => '#E6F1FB'],
    'offer'        => ['icon' => '🏷️', 'bg' => '#E4F0E7'],
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
    'niedrig'  => ['label' => 'Niedrig',  'bg' => '#E4F0E7', 'fg' => '#3B7A57'],
];
@endphp
<div class="toolbar">
    <div>
        <div class="page-title">Tickets</div>
        <div class="page-sub">Anfragen registrierter Kunden aus dem Kundenportal.</div>
    </div>
    @if(in_array(auth()->user()->role, ['admin','manager']))
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
</div>

<div class="card" style="margin-bottom:16px;">
    <form method="GET" action="{{ route('admin.tickets') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        @if(request()->boolean('overdue'))<input type="hidden" name="overdue" value="1">@endif
        <div class="field" style="flex:2;min-width:220px;margin-bottom:0;">
            <label>Suche</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Betreff, Ticket-Nr., Kunde, Kundennummer...">
        </div>
        <div class="field" style="flex:1;min-width:150px;margin-bottom:0;">
            <label>Priorität</label>
            <select name="priority" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach(\App\Models\Ticket::PRIORITIES as $key => $p)
                <option value="{{ $key }}" {{ request('priority') === $key ? 'selected' : '' }}>{{ $p['icon'] }} {{ $p['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="flex:1;min-width:170px;margin-bottom:0;">
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
        <button type="submit" class="btn btn-primary">Filtern</button>
        @if(request()->hasAny(['q','priority','assigned','status','overdue']))
        <a href="{{ route('admin.tickets') }}" class="btn btn-ghost">Zurücksetzen</a>
        @endif
    </form>
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <table class="tickets-table">
        <thead><tr>
            <th style="width:52px;"></th>
            <th>Betreff</th>
            <th>Kunde</th>
            <th>Priorität</th>
            <th>Zugewiesen</th>
            <th>Aktualisiert</th>
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
            {{-- Typ-Icon als farbige Kachel (wie Kunden-/Vertragsliste) --}}
            <td style="padding-left:20px;">
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
            <td style="color:var(--ink-soft);white-space:nowrap;font-size:13px;">{{ $t->updated_at->format('d.m.Y') }}<div style="font-size:11.5px;">{{ $t->updated_at->format('H:i') }} Uhr</div></td>
            <td>
                <span class="badge badge-{{ $t->statusBadge() }}">{{ $t->statusLabel() }}</span>
                @if($t->isOverdue())<div style="font-size:11px;color:#A32D2D;font-weight:600;margin-top:5px;white-space:nowrap;">⏰ überfällig</div>@endif
            </td>
            {{-- Aktionen: 3-Punkte-Menue. Zelle .noNav, damit der Klick hier NICHT
                 die Zeilennavigation ausloest. --}}
            <td class="noNav" style="text-align:right;padding-right:16px;position:relative;" x-data="{open:false}">
                <button type="button" @click="open=!open" aria-haspopup="true" :aria-expanded="open" title="Aktionen"
                    style="background:none;border:none;cursor:pointer;font-size:18px;line-height:1;color:var(--ink-soft);padding:4px 10px;border-radius:6px;letter-spacing:1px;">•••</button>
                <div x-show="open" x-cloak @click.outside="open=false" @keydown.escape.window="open=false"
                    style="position:absolute;right:12px;top:100%;z-index:50;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.14);min-width:200px;padding:6px;">
                    <a href="{{ route('admin.ticket', $t->id) }}" class="rowmenu-item">🎫 Ticket öffnen</a>
                    @if($t->customer)
                    <a href="{{ route('admin.customer', $t->customer_id) }}" class="rowmenu-item">👤 Kundenakte öffnen</a>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="color:var(--ink-soft);text-align:center;padding:40px;">Keine Tickets gefunden.</td></tr>
        @endforelse
        </tbody>
    </table>
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
.prio-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 11px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;}
.prio-dot{width:7px;height:7px;border-radius:50%;flex:none;}
.rowmenu-item{display:block;width:100%;text-align:left;padding:9px 12px;border-radius:7px;font-size:13.5px;color:var(--ink);text-decoration:none;box-sizing:border-box;}
.rowmenu-item:hover{background:#F4F5F7;}
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
