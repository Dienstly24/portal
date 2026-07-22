@extends('layouts.admin')
@section('content')
@php
// Vertragstyp -> Icon (identisch zur Kundenliste, gleiche Bildsprache).
$typeConfig = [
    'kfz'                 => ['icon'=>'🚗','label'=>'KFZ','bg'=>'#E6F1FB'],
    'krankenversicherung' => ['icon'=>'🏥','label'=>'Kranken','bg'=>'#D9F4E6'],
    'kranken'             => ['icon'=>'🏥','label'=>'Kranken','bg'=>'#D9F4E6'],
    'krankenzusatz'       => ['icon'=>'🩺','label'=>'Krankenzusatz','bg'=>'#DEF1E8'],
    'haftpflicht'         => ['icon'=>'🛡️','label'=>'Haftpflicht','bg'=>'#F0E6FB'],
    'rechtsschutz'        => ['icon'=>'⚖️','label'=>'Rechtsschutz','bg'=>'#FEF3C7'],
    'hausrat'             => ['icon'=>'🏠','label'=>'Hausrat','bg'=>'#D9F4E6'],
    'escooter'            => ['icon'=>'🛴','label'=>'E-Scooter','bg'=>'#E6F1FB'],
    'leben'               => ['icon'=>'❤️','label'=>'Leben','bg'=>'#FBEAF0'],
    'unfall'              => ['icon'=>'🚑','label'=>'Unfall','bg'=>'#F9E3E3'],
    'sach'                => ['icon'=>'📦','label'=>'Sach','bg'=>'#EEF0F3'],
    'internet'            => ['icon'=>'📶','label'=>'Internet','bg'=>'#EDE9FE'],
    'energie'             => ['icon'=>'⚡','label'=>'Energie','bg'=>'#FEF3C7'],
    'strom'               => ['icon'=>'⚡','label'=>'Strom','bg'=>'#FEF3C7'],
    'gas'                 => ['icon'=>'🔥','label'=>'Gas','bg'=>'#FEF0E7'],
    'strom_gas'           => ['icon'=>'⚡','label'=>'Strom/Gas','bg'=>'#FEF3C7'],
    'andere'              => ['icon'=>'📋','label'=>'Andere','bg'=>'#EEF0F3'],
];
@endphp

<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.employees') }}">Mitarbeiter</a><span class="breadcrumb-sep">›</span><span>{{ $employee->name }}</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:44px;height:44px;border-radius:50%;background:#131A17;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;">{{ strtoupper(substr($employee->name,0,2)) }}</div>
            <div>
                <div class="page-title" style="margin:0;">{{ $employee->name }} @if(!$employee->is_active)<span class="badge badge-closed">Deaktiviert</span>@endif</div>
                <div class="page-sub" style="margin:0;">{{ $employee->email }}</div>
            </div>
        </div>
        <a href="{{ route('admin.employees.edit', $employee->id) }}" class="btn btn-ghost">⚙ Einstellungen bearbeiten</a>
    </div>
</div>

{{-- Profil / Zugriff / Berechtigungen (Nur-Lese-Ueberblick; Aenderung ueber
     "Einstellungen bearbeiten"). --}}
<div class="card" style="padding:16px 20px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:22px;align-items:center;">
    <div>
        <div style="font-size:11.5px;color:var(--ink-soft);font-weight:600;margin-bottom:4px;">Rolle</div>
        <span class="badge {{ $employee->role === 'manager' ? 'badge-active' : 'badge-open' }}">{{ $employee->role === 'manager' ? '⭐ Manager' : '👤 Mitarbeiter' }}</span>
    </div>
    <div>
        <div style="font-size:11.5px;color:var(--ink-soft);font-weight:600;margin-bottom:4px;">Kundenzugriff</div>
        <span class="badge {{ $employee->can_see_all_customers ? 'badge-active' : 'badge-pending' }}">{{ $employee->can_see_all_customers ? 'Alle Kunden' : 'Begrenzt' }}</span>
    </div>
    <div style="flex:1;min-width:200px;">
        <div style="font-size:11.5px;color:var(--ink-soft);font-weight:600;margin-bottom:4px;">Berechtigungen</div>
        <div style="display:flex;flex-wrap:wrap;gap:4px;font-size:12px;">
            @if($employee->can_manage_contracts)<span style="background:#D9F4E6;color:#17A65B;padding:2px 7px;border-radius:4px;">Verträge</span>@endif
            @if($employee->can_manage_tickets)<span style="background:#E6F1FB;color:#185FA5;padding:2px 7px;border-radius:4px;">Tickets</span>@endif
            @if($employee->can_approve_changes)<span style="background:#FEF3C7;color:#92400E;padding:2px 7px;border-radius:4px;">Genehmigungen</span>@endif
            @if($employee->can_send_emails)<span style="background:#F0E6FB;color:#6D28D9;padding:2px 7px;border-radius:4px;">E-Mails</span>@endif
            @if($employee->can_import_export)<span style="background:#EEF0F3;color:#5F5E5A;padding:2px 7px;border-radius:4px;">Import/Export</span>@endif
            @if(!$employee->can_manage_contracts && !$employee->can_manage_tickets && !$employee->can_approve_changes && !$employee->can_send_emails && !$employee->can_import_export)
            <span style="color:var(--ink-soft);">—</span>
            @endif
        </div>
    </div>
</div>

@if($employee->can_see_all_customers)
<div class="card" style="padding:12px 18px;margin-bottom:16px;background:#E6F1FB;border:1px solid #CFE3F6;font-size:13px;color:#185FA5;">
    ℹ️ Dieser Mitarbeiter sieht ohnehin <strong>alle Kunden</strong>. Eine explizite Zuweisung ist nur fuer Mitarbeiter mit begrenztem Zugriff noetig.
</div>
@endif

{{-- Smarte Mehrfach-Zuweisung: Kunden ueber ALLE Felder suchen, mehrere per
     Checkbox auswaehlen (auch ueber mehrere Suchen hinweg) und gebuendelt
     zuweisen. Bereits zugewiesene Treffer sind markiert. --}}
<div class="card" style="margin-bottom:16px;">
    <div class="card-title" style="margin-bottom:6px;">Kunden zuweisen</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:12px;">Suche nach Name, Nummer, Telefon, Anschrift, Kennzeichen, Zaehlernummer ... – mehrere auswaehlen und gebuendelt zuweisen.</div>
    <script type="application/json" id="assignedIdsData">{!! $assignedIds->toJson() !!}</script>
    <input type="text" id="assignSearch" autocomplete="off" placeholder="Kunden suchen (mind. 2 Zeichen) ..."
        style="width:100%;padding:11px 14px;border:1px solid var(--line);border-radius:10px;font-size:14px;margin-bottom:8px;">
    <div id="assignResultsBar" style="display:none;align-items:center;gap:14px;margin-bottom:8px;font-size:12.5px;">
        <a id="assignSelectAll" style="cursor:pointer;color:#17A65B;font-weight:600;">☑ Alle Treffer auswaehlen</a>
    </div>
    <div id="assignResults" style="display:none;border:1px solid var(--line);border-radius:10px;background:#fff;max-height:280px;overflow-y:auto;margin-bottom:12px;"></div>

    <form method="POST" action="{{ route('admin.employees.assign_customers', $employee->id) }}" id="assignForm" onsubmit="return prepareAssign();">
        @csrf
        <div id="assignChipsWrap" style="display:none;margin-bottom:12px;">
            <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:6px;">Ausgewaehlt: <strong id="assignSelCount">0</strong> Kunde(n)</div>
            <div id="assignChips" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
        </div>
        <div id="assignHidden"></div>
        <button type="submit" id="assignSubmit" class="btn btn-primary" disabled style="opacity:.5;">Ausgewaehlte zuweisen</button>
    </form>
</div>

{{-- Zugewiesene Kunden: durchsuchbare, paginierte Liste (gleiche UX wie der
     Kundenbereich). Suche laeuft ueber ALLE Kundenfelder, begrenzt auf das
     Portfolio dieses Mitarbeiters. --}}
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
        <div class="card-title" style="margin:0;">Zugewiesene Kunden <span style="color:var(--ink-soft);font-weight:500;">({{ $assignedCount }})</span></div>
        <form method="GET" action="{{ route('admin.employees.show', $employee->id) }}" style="display:flex;gap:8px;margin:0;">
            <input type="text" name="q" value="{{ request('q') }}" autocomplete="off"
                placeholder="In zugewiesenen Kunden suchen ..."
                style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;min-width:230px;">
            <button type="submit" class="btn btn-primary btn-sm" title="Suchen">🔍</button>
            @if(request()->filled('q'))
            <a href="{{ route('admin.employees.show', $employee->id) }}" class="btn btn-ghost btn-sm" title="Suche loeschen">✕</a>
            @endif
        </form>
    </div>

    @if(request()->filled('q'))
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:10px;">Treffer: <b>{{ $customers->total() }}</b> von {{ $assignedCount }} zugewiesenen Kunden</div>
    @endif

    <table>
        <thead><tr>
            <th>Kunde</th><th>Adresse</th><th>Aktive Verträge</th><th style="text-align:right;">Aktion</th>
        </tr></thead>
        <tbody>
        @forelse($customers as $c)
        <tr>
            <td>
                <a href="{{ route('admin.customer', $c->id) }}" style="font-weight:600;color:var(--ink);text-decoration:none;">{{ $c->user?->name }}</a>
                <div style="color:var(--ink-soft);font-size:12px;margin-top:2px;">{{ $c->customer_number }}</div>
            </td>
            @php $addr = $c->fullAddress(); @endphp
            <td style="color:var(--ink-soft);font-size:13px;">{{ $addr !== '' ? $addr : '—' }}</td>
            <td style="white-space:nowrap;">
                @php $activeTypes = $c->contracts->pluck('type')->unique(); @endphp
                @forelse($activeTypes as $t)
                    @php $cfg = $typeConfig[$t] ?? $typeConfig['andere']; @endphp
                    <span title="{{ $cfg['label'] }}" aria-label="{{ $cfg['label'] }}"
                        style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:7px;background:{{ $cfg['bg'] }};margin-right:3px;font-size:14px;">{{ $cfg['icon'] }}</span>
                @empty
                    <span style="color:var(--ink-soft);font-size:12.5px;">—</span>
                @endforelse
            </td>
            <td style="text-align:right;">
                <form method="POST" action="{{ route('admin.employees.unassign_customer', [$employee->id, $c->id]) }}" style="margin:0;"
                    onsubmit="return confirm('{{ addslashes($c->user?->name) }} aus dem Portfolio von {{ addslashes($employee->name) }} entfernen?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:#A32D2D;border-color:#F0D5D5;">✕ Entfernen</button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--ink-soft);">
            @if(request()->filled('q')) Keine zugewiesenen Kunden passen zu „{{ request('q') }}". @else Diesem Mitarbeiter sind noch keine Kunden zugewiesen. @endif
        </td></tr>
        @endforelse
        </tbody>
    </table>

    @if($customers->hasPages())
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;flex-wrap:wrap;">
        <div style="font-size:13px;color:var(--ink-soft);">{{ $customers->firstItem() }}–{{ $customers->lastItem() }} von {{ $customers->total() }}</div>
        <div style="display:flex;gap:8px;align-items:center;">
            @if($customers->onFirstPage())
                <span class="btn btn-ghost btn-sm" style="opacity:.45;pointer-events:none;">← Zurück</span>
            @else
                <a href="{{ $customers->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>
            @endif
            <span style="font-size:13px;color:var(--ink-soft);">Seite {{ $customers->currentPage() }} / {{ $customers->lastPage() }}</span>
            @if($customers->hasMorePages())
                <a href="{{ $customers->nextPageUrl() }}" class="btn btn-ghost btn-sm">Weiter →</a>
            @else
                <span class="btn btn-ghost btn-sm" style="opacity:.45;pointer-events:none;">Weiter →</span>
            @endif
        </div>
    </div>
    @endif
</div>

<script>
(function () {
    var assignedSet = new Set(JSON.parse(document.getElementById('assignedIdsData').textContent).map(String));
    var selected = {};                 // id -> {id,name,number}
    var lastResults = [];              // aktuell angezeigte, nicht bereits zugewiesene Treffer
    var input = document.getElementById('assignSearch');
    var results = document.getElementById('assignResults');
    var bar = document.getElementById('assignResultsBar');
    var chipsWrap = document.getElementById('assignChipsWrap');
    var chips = document.getElementById('assignChips');
    var selCount = document.getElementById('assignSelCount');
    var submit = document.getElementById('assignSubmit');
    var hidden = document.getElementById('assignHidden');

    function refreshSelected() {
        var ids = Object.keys(selected);
        selCount.textContent = ids.length;
        chipsWrap.style.display = ids.length ? 'block' : 'none';
        submit.disabled = ids.length === 0;
        submit.style.opacity = ids.length ? '1' : '.5';
        submit.textContent = ids.length ? ('Ausgewaehlte zuweisen (' + ids.length + ')') : 'Ausgewaehlte zuweisen';
        chips.innerHTML = '';
        ids.forEach(function (id) {
            var c = selected[id];
            var chip = document.createElement('span');
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#D9F4E6;border:1px solid #17A65B;border-radius:20px;padding:5px 12px;font-size:12.5px;';
            chip.textContent = '👤 ' + (c.name || '—') + ' ' + (c.number || '');
            var x = document.createElement('a');
            x.textContent = '✕';
            x.style.cssText = 'cursor:pointer;color:#A32D2D;font-weight:700;margin-left:2px;';
            x.onclick = function () { delete selected[id]; refreshSelected(); renderResults(lastResults); };
            chip.appendChild(x);
            chips.appendChild(chip);
        });
    }

    function renderResults(list) {
        results.innerHTML = '';
        if (!list.length) { results.style.display = 'none'; bar.style.display = 'none'; return; }
        list.forEach(function (c) {
            var id = String(c.id);
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:9px 14px;font-size:13.5px;border-bottom:1px solid var(--line);';
            var already = assignedSet.has(id);
            var meta = '<div><strong>' + (c.name || '—') + '</strong> <span style="color:var(--ink-soft);">' + (c.number || '') + '</span>'
                + (c.address ? '<div style="font-size:12px;color:var(--ink-soft);">' + c.address + '</div>' : '') + '</div>';
            if (already) {
                row.innerHTML = '<span style="width:16px;"></span>' + meta
                    + '<span style="margin-left:auto;font-size:11.5px;background:#EEF0F3;color:#5F5E5A;border-radius:10px;padding:2px 9px;white-space:nowrap;">bereits zugewiesen</span>';
            } else {
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.style.cssText = 'width:17px;height:17px;cursor:pointer;accent-color:#131A17;flex:none;';
                cb.checked = !!selected[id];
                cb.onchange = function () {
                    if (cb.checked) { selected[id] = { id: id, name: c.name, number: c.number }; }
                    else { delete selected[id]; }
                    refreshSelected();
                };
                row.appendChild(cb);
                var wrap = document.createElement('div');
                wrap.style.cssText = 'flex:1;cursor:pointer;';
                wrap.innerHTML = meta;
                wrap.onclick = function () { cb.checked = !cb.checked; cb.onchange(); };
                row.appendChild(wrap);
            }
            results.appendChild(row);
        });
        results.style.display = 'block';
        bar.style.display = list.some(function (c) { return !assignedSet.has(String(c.id)); }) ? 'flex' : 'none';
    }

    document.getElementById('assignSelectAll').onclick = function () {
        lastResults.forEach(function (c) {
            var id = String(c.id);
            if (!assignedSet.has(id)) selected[id] = { id: id, name: c.name, number: c.number };
        });
        refreshSelected();
        renderResults(lastResults);
    };

    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { results.style.display = 'none'; bar.style.display = 'none'; lastResults = []; return; }
        timer = setTimeout(function () {
            fetch('{{ route('admin.employees.customer-search') }}?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (list) { lastResults = list || []; renderResults(lastResults); });
        }, 250);
    });

    window.prepareAssign = function () {
        var ids = Object.keys(selected);
        if (!ids.length) return false;
        hidden.innerHTML = '';
        ids.forEach(function (id) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = 'customer_ids[]'; i.value = id;
            hidden.appendChild(i);
        });
        return true;
    };

    document.addEventListener('click', function (e) {
        if (!results.contains(e.target) && e.target !== input) results.style.display = 'none';
    });
})();
</script>
@endsection
