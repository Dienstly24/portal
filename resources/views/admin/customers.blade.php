@extends('layouts.admin')
@section('content')
@php
// Vertragstyp -> Icon (identisch zur Kundenakte, damit die Liste dieselbe
// Bildsprache spricht). Unbekannte Typen fallen auf 'andere' zurück.
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
<div class="toolbar">
    <div>
        <div class="page-title">Kunden</div>
        <div class="page-sub">Alle Kundenakten verwalten.</div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <a href="{{ route('admin.customers.duplicates') }}" class="btn btn-ghost">🔀 Dubletten prüfen @if(($dupCount ?? 0) > 0)<span class="nav-badge" style="background:#A32D2D;">{{ $dupCount }}</span>@endif</a>
        <a href="{{ route('admin.customers.create') }}" class="btn btn-primary">+ Neuer Kunde</a>
    </div>
</div>

{{-- Filter- und Sortierleiste (fuer alle Rollen sichtbar; der Betreuer-Filter
     nur fuer admin/manager – Mitarbeiter sehen ohnehin nur ihr Portfolio). --}}
@php
    $filterKeys = ['q','betreuer','email','sparte','portal','ablauf','kontakt','buchstabe'];
    $hasActiveFilter = collect($filterKeys)->contains(fn($k) => request()->filled($k))
        || (request()->filled('sort') && request('sort') !== 'neueste');
@endphp
<div class="card" style="padding:16px 20px;margin-bottom:16px;">
    {{-- Schnellfilter mit Kennzahlen (klickbar) --}}
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
        <a href="{{ route('admin.customers') }}" class="kf-chip {{ !$hasActiveFilter ? 'kf-active' : '' }}">Alle <b>{{ $counts['total'] }}</b></a>
        <a href="{{ route('admin.customers', ['sparte'=>'strom']) }}" class="kf-chip {{ request('sparte')==='strom' ? 'kf-active' : '' }}">⚡ Strom <b>{{ $counts['strom'] }}</b></a>
        <a href="{{ route('admin.customers', ['sparte'=>'gas']) }}" class="kf-chip {{ request('sparte')==='gas' ? 'kf-active' : '' }}">🔥 Gas <b>{{ $counts['gas'] }}</b></a>
        <a href="{{ route('admin.customers', ['sparte'=>'kfz']) }}" class="kf-chip {{ request('sparte')==='kfz' ? 'kf-active' : '' }}">🚗 KFZ <b>{{ $counts['kfz'] }}</b></a>
        <a href="{{ route('admin.customers', ['email'=>'ohne']) }}" class="kf-chip {{ request('email')==='ohne' ? 'kf-active' : '' }}">✉️ Ohne E-Mail <b>{{ $counts['ohne_email'] }}</b></a>
        <a href="{{ route('admin.customers', ['ablauf'=>'60']) }}" class="kf-chip {{ request('ablauf') ? 'kf-active' : '' }}">⏳ Laeuft bald ab <b>{{ $counts['ablauf'] }}</b></a>
        <a href="{{ route('admin.customers', ['kontakt'=>'180']) }}" class="kf-chip {{ request('kontakt') ? 'kf-active' : '' }}">💤 Lange kein Kontakt <b>{{ $counts['kontakt'] }}</b></a>
    </div>
    {{-- Ausfuehrliche Filter + Sortierung (auto-submit bei Auswahl) --}}
    <form method="GET" action="{{ route('admin.customers') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:0;">
        {{-- Freitext-Suche ueber ALLE Kundenfelder (Name, E-Mail, Telefon,
             Kundennummer, Vertragsnummer, Anschrift, PLZ/Ort, Kennzeichen, FIN,
             Zaehlernummer ...). Wert eingeben + Enter zeigt alle Treffer. --}}
        <div class="flt-group" style="flex:1;min-width:230px;">
            <label class="flt-lbl" for="kunden-suche">Suche</label>
            <div style="display:flex;gap:8px;">
                <input type="text" name="q" id="kunden-suche" value="{{ request('q') }}" autocomplete="off"
                    placeholder="Name, Nummer, Telefon, Kennzeichen, Zaehler ..."
                    style="flex:1;min-width:0;padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;background:#fff;">
                <button type="submit" class="btn btn-primary btn-sm" title="Suchen">🔍</button>
                @if(request()->filled('q'))
                <a href="{{ route('admin.customers', request()->except(['q','page'])) }}" class="btn btn-ghost btn-sm" title="Suche loeschen">✕</a>
                @endif
            </div>
        </div>
        @if(in_array(auth()->user()->role, ['admin','manager']))
        <div class="flt-group">
            <label class="flt-lbl">Betreuer</label>
            <select name="betreuer" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach($employees as $e)
                <option value="{{ $e->id }}" {{ request('betreuer') == $e->id ? 'selected' : '' }}>{{ $e->name }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div class="flt-group">
            <label class="flt-lbl">Sparte (aktiv)</label>
            <select name="sparte" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle Sparten</option>
                @foreach($sparten as $key => $cfg)
                <option value="{{ $key }}" {{ request('sparte') === $key ? 'selected' : '' }}>{{ $cfg['icon'] }} {{ $cfg['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">E-Mail</label>
            <select name="email" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                <option value="mit" {{ request('email')==='mit' ? 'selected' : '' }}>Mit E-Mail</option>
                <option value="ohne" {{ request('email')==='ohne' ? 'selected' : '' }}>Ohne E-Mail</option>
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Portal-Status</label>
            <select name="portal" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach(['kein_account'=>'Kein Portal-Account','passwort_nicht_gesetzt'=>'Passwort nicht gesetzt','einladung_gesendet'=>'Einladung gesendet','aktiviert'=>'Aktiviert - kein Login','erster_login'=>'Aktiv - Login erfolgt','deaktiviert'=>'Deaktiviert'] as $k => $lbl)
                <option value="{{ $k }}" {{ request('portal')===$k ? 'selected' : '' }}>{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Vertrag laeuft ab in</label>
            <select name="ablauf" class="flt-sel" onchange="this.form.submit()">
                <option value="">Egal</option>
                @foreach(['30'=>'30 Tagen','60'=>'60 Tagen','90'=>'90 Tagen','180'=>'180 Tagen'] as $k => $lbl)
                <option value="{{ $k }}" {{ request('ablauf')===$k ? 'selected' : '' }}>{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Letzter Kontakt</label>
            <select name="kontakt" class="flt-sel" onchange="this.form.submit()">
                <option value="">Egal</option>
                <option value="nie" {{ request('kontakt')==='nie' ? 'selected' : '' }}>Nie kontaktiert</option>
                @foreach(['30'=>'vor >30 Tagen','90'=>'vor >90 Tagen','180'=>'vor >180 Tagen','365'=>'vor >1 Jahr'] as $k => $lbl)
                <option value="{{ $k }}" {{ request('kontakt')===$k ? 'selected' : '' }}>{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Sortierung</label>
            <select name="sort" class="flt-sel" onchange="this.form.submit()">
                @foreach(['neueste'=>'Neueste zuerst','aelteste'=>'Aelteste zuerst','name'=>'Name A-Z','name_desc'=>'Name Z-A','kontakt'=>'Laengster Kontakt'] as $k => $lbl)
                <option value="{{ $k }}" {{ request('sort','neueste')===$k ? 'selected' : '' }}>{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="btn btn-primary btn-sm">Anwenden</button></noscript>
        @if($hasActiveFilter)
        <a href="{{ route('admin.customers') }}" class="btn btn-ghost btn-sm">✕ Filter zuruecksetzen</a>
        @endif
    </form>
    @if($customers->total() !== $counts['total'])
    <div style="font-size:12.5px;color:var(--ink-soft);margin-top:10px;">Treffer: <b>{{ $customers->total() }}</b> von {{ $counts['total'] }} Kunden</div>
    @endif
</div>

{{-- Alphabet-Index: schneller Sprung zu Kunden nach Anfangsbuchstabe des
     Namens. Erhaelt die aktiven Filter/Sortierung (nur die Seite wird
     zurueckgesetzt). "XYZ" fasst die seltenen Buchstaben X/Y/Z zusammen. --}}
@php
    $letterKeys = array_merge(range('A', 'W'), ['XYZ']);
    $activeLetter = strtoupper((string) request('buchstabe'));
    $azBaseParams = request()->except(['buchstabe', 'page']);
@endphp
<div class="card az-bar">
    <a href="{{ route('admin.customers', $azBaseParams) }}"
       class="az-chip {{ $activeLetter === '' ? 'az-active' : '' }}">Alle</a>
    @foreach($letterKeys as $letter)
    <a href="{{ route('admin.customers', array_merge($azBaseParams, ['buchstabe' => $letter])) }}"
       class="az-chip {{ $activeLetter === $letter ? 'az-active' : '' }}">{{ $letter }}</a>
    @endforeach
</div>

@if(in_array(auth()->user()->role, ['admin','manager']))

{{-- Massen-Aktionen: zwei EIGENSTÄNDIGE Formulare (NICHT verschachtelt – sonst
     verwirft der Browser das innere Formular und der Löschen-Button reagiert
     nicht). Die Zeilen-Checkboxen weiter unten gehören per form="bulkForm" zum
     Zuweisungs-Formular; der Löschen-Button überträgt die aktuelle Auswahl per
     JS in bulkDeleteForm. --}}
<form method="POST" action="{{ route('admin.customers.bulk-assign') }}" id="bulkForm">@csrf</form>
@if(auth()->user()->role === 'admin')
<form method="POST" action="{{ route('admin.customers.bulk-delete') }}" id="bulkDeleteForm" onsubmit="return confirmBulkDelete(this);">@csrf</form>
@endif

<div id="bulkBar" style="display:none;position:sticky;top:0;z-index:10;background:#131A17;color:#fff;border-radius:10px;padding:12px 20px;margin-bottom:12px;align-items:center;gap:14px;flex-wrap:wrap;">
    <span style="font-size:13.5px;font-weight:600;"><span id="bulkCount">0</span> Kunden ausgewaehlt</span>
    <select name="employee_id" form="bulkForm" required style="padding:8px 12px;border-radius:8px;border:none;font-size:13px;">
        <option value="">— Mitarbeiter waehlen —</option>
        @foreach($employees as $e)
        <option value="{{ $e->id }}">{{ $e->name }}</option>
        @endforeach
    </select>
    <input type="text" name="reason" form="bulkForm" required placeholder="Grund der Zuweisung (Pflicht)" style="padding:8px 12px;border-radius:8px;border:none;font-size:13px;flex:1;min-width:200px;">
    <label style="font-size:12.5px;display:flex;align-items:center;gap:6px;cursor:pointer;">
        <input type="checkbox" name="replace_existing" value="1" form="bulkForm" style="width:auto;"> Bisherige Betreuer ersetzen
    </label>
    <button type="submit" form="bulkForm" class="btn btn-primary" style="padding:8px 18px;font-size:13px;">Zuweisen</button>
    @if(auth()->user()->role === 'admin')
    <button type="submit" form="bulkDeleteForm" id="bulkDeleteBtn"
        style="padding:8px 18px;font-size:13px;background:#A32D2D;color:#fff;border:none;border-radius:8px;cursor:pointer;">
        🗑 Ausgewählte löschen
    </button>
    @endif
</div>

@if(auth()->user()->role === 'admin')
<script>
// Überträgt die aktuell angehakten Zeilen-Checkboxen in das Lösch-Formular und
// verlangt eine bewusste Bestätigung mit Anzahl, bevor endgültig gelöscht wird.
function confirmBulkDelete(form) {
    var checked = document.querySelectorAll('.rowCheck:checked');
    if (checked.length === 0) { alert('Bitte zuerst Kunden auswählen.'); return false; }
    if (checked.length > 30) {
        alert('Es können höchstens 30 Kunden auf einmal gelöscht werden.\nBitte weniger auswählen.');
        return false;
    }
    if (!confirm('⚠️ ' + checked.length + ' Kunde(n) ENDGÜLTIG löschen?\n\nAlle zugehörigen Daten (Verträge, Tickets, Dokumente, E-Mails, Portal-Zugang) werden unwiderruflich entfernt.')) {
        return false;
    }
    // Auswahl als EIN kommagetrenntes Feld übergeben (nicht ein hidden input pro
    // Kunde). So funktioniert auch das Löschen sehr vieler Kunden auf einmal –
    // ohne an PHPs max_input_vars-Limit zu stoßen.
    form.querySelectorAll('input[name="customer_ids"]').forEach(function (i) { i.remove(); });
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'customer_ids';
    input.value = Array.prototype.map.call(checked, function (cb) { return cb.value; }).join(',');
    form.appendChild(input);
    return true;
}
</script>
@endif
@endif

<div class="card">
    <table>
        <thead><tr>
            @if(in_array(auth()->user()->role, ['admin','manager']))<th style="width:36px;"><input type="checkbox" id="checkAll" style="width:17px;height:17px;cursor:pointer;accent-color:#131A17;"></th>@endif
            <th>Kunde</th><th>Adresse</th><th>Portal</th><th>Betreuer</th><th>Aktive Verträge</th><th style="text-align:right;">Aktionen</th>
        </tr></thead>
        <tbody>
        @forelse($customers as $c)
        <tr class="rowLink" data-href="{{ route('admin.customer', $c->id) }}" style="cursor:pointer;">
            @if(in_array(auth()->user()->role, ['admin','manager']))
            <td class="noNav"><input type="checkbox" class="rowCheck" name="customer_ids[]" value="{{ $c->id }}" form="bulkForm" style="width:17px;height:17px;cursor:pointer;accent-color:#131A17;"></td>
            @endif
            <td>
                <div style="font-weight:600;">{{ $c->user?->name }}</div>
                @if($c->birth_date)
                <div style="color:var(--ink-soft);font-size:12px;margin-top:2px;">🎂 {{ \Illuminate\Support\Carbon::parse($c->birth_date)->format('d.m.Y') }}</div>
                @endif
            </td>
            @php $addr = $c->fullAddress(); @endphp
            <td style="color:var(--ink-soft);font-size:13px;white-space:nowrap;">{{ $addr !== '' ? $addr : '—' }}</td>
            @php $ps = $c->portalStatus(); @endphp
            <td title="Einladung: {{ $c->user?->invitation_sent_at?->format('d.m.Y') ?? '—' }} · Passwort gesetzt: {{ $c->user?->portal_password_set_at ? 'Ja' : 'Nein' }}">
                <span style="background:{{ $ps['bg'] }};color:{{ $ps['color'] }};border-radius:12px;padding:2px 10px;font-size:11.5px;white-space:nowrap;">{{ $ps['label'] }}</span>
            </td>
            <td style="font-size:12.5px;">
                @forelse($c->betreuer as $b)
                <span style="background:#D9F4E6;color:#17A65B;border-radius:12px;padding:2px 10px;display:inline-block;margin:1px 0;">{{ $b->name }}</span>
                @empty
                <span style="color:#B5651D;">— offen —</span>
                @endforelse
            </td>
            {{-- Aktive Verträge als Icons (eager-geladen, nur status=active) --}}
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
            {{-- Aktionen: 3-Punkte-Menü pro Kunde (Alpine). Zelle ist .noNav,
                 damit ein Klick hier NICHT die Zeilennavigation auslöst. --}}
            <td class="noNav" style="text-align:right;position:relative;" x-data="{open:false}">
                <button type="button" @click="open=!open" aria-haspopup="true" :aria-expanded="open" title="Aktionen"
                    style="background:none;border:none;cursor:pointer;font-size:18px;line-height:1;color:var(--ink-soft);padding:4px 10px;border-radius:6px;letter-spacing:1px;">•••</button>
                <div x-show="open" x-cloak @click.outside="open=false" @keydown.escape.window="open=false"
                    style="position:absolute;right:8px;top:100%;z-index:50;background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.14);min-width:230px;padding:6px;">
                    <a href="{{ route('admin.customer.merge', $c->id) }}" class="rowmenu-item">🔀 {{ $c->user?->name }}: Dublette bereinigen</a>
                    @if(auth()->user()->role === 'admin')
                    <form method="POST" action="{{ route('admin.customers.delete', $c->id) }}" style="margin:0;"
                        onsubmit="return confirm('⚠️ Kunde {{ addslashes($c->user?->name) }} ENDGÜLTIG löschen?\n\nAlle Verträge, Tickets, Dokumente, E-Mails und der Portal-Zugang werden unwiderruflich entfernt.') && confirm('Wirklich sicher? Diese Aktion kann NICHT rückgängig gemacht werden.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rowmenu-item" style="background:none;border:none;cursor:pointer;color:#A32D2D;">🗑 Löschen</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Kunden gefunden.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

{{-- Seitennavigation (an das App-Design angepasst, ohne Framework-Theme) --}}
@if($customers->hasPages())
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:16px 2px;flex-wrap:wrap;">
    <div style="font-size:13px;color:var(--ink-soft);">
        {{ $customers->firstItem() }}–{{ $customers->lastItem() }} von {{ $customers->total() }} Kunden
    </div>
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

@if(in_array(auth()->user()->role, ['admin','manager']))
<script>
(function () {
    var bar = document.getElementById('bulkBar');
    var count = document.getElementById('bulkCount');
    var all = document.getElementById('checkAll');
    function refresh() {
        var checked = document.querySelectorAll('.rowCheck:checked').length;
        count.textContent = checked;
        bar.style.display = checked > 0 ? 'flex' : 'none';
    }
    document.querySelectorAll('.rowCheck').forEach(function (cb) { cb.addEventListener('change', refresh); });
    if (all) all.addEventListener('change', function () {
        document.querySelectorAll('.rowCheck').forEach(function (cb) { cb.checked = all.checked; });
        refresh();
    });
})();
</script>
@endif
<style>
.rowLink:hover td { background: #F4F7F5; }
.flt-group { display:flex; flex-direction:column; gap:4px; }
.flt-lbl { font-size:11.5px; color:var(--ink-soft); font-weight:600; }
.flt-sel { padding:8px 12px; border:1px solid var(--line); border-radius:8px; font-size:13.5px; background:#fff; min-width:150px; }
.kf-chip { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; border-radius:999px; border:1px solid var(--line); background:#fff; font-size:13px; color:var(--ink); text-decoration:none; white-space:nowrap; }
.kf-chip:hover { background:#F4F7F5; }
.kf-chip b { background:#EEF0F3; border-radius:999px; padding:1px 8px; font-size:12px; }
.kf-chip.kf-active { background:#131A17; color:#fff; border-color:#131A17; }
.kf-chip.kf-active b { background:rgba(255,255,255,.22); color:#fff; }
.az-bar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; padding:12px 14px; margin-bottom:16px; }
.az-chip { display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 9px; border-radius:8px; background:#EEF0F3; color:var(--ink); font-size:13.5px; font-weight:600; text-decoration:none; transition:background .12s; }
.az-chip:hover { background:#E5E1D6; }
.az-chip.az-active { background:#17A65B; color:#fff; }
.az-chip.az-active:hover { background:#128a4b; }
[x-cloak] { display: none !important; }
.rowmenu-item { display:block; width:100%; text-align:left; padding:9px 12px; border-radius:7px; font-size:13.5px; color:var(--ink); text-decoration:none; box-sizing:border-box; }
.rowmenu-item:hover { background:#F4F7F5; }
</style>
<script id="rowLinkScript">
document.querySelectorAll('tr.rowLink').forEach(function (row) {
    row.addEventListener('click', function (e) {
        if (e.target.closest('.noNav') || e.target.closest('input') || e.target.closest('a') || e.target.closest('button')) return;
        window.location = row.dataset.href;
    });
});
</script>
@endsection
