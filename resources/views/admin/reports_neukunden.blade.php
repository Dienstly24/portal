@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.reports') }}">Berichte</a><span class="breadcrumb-sep">›</span><span>Neukunden</span></div>
    <div class="page-title">Neukunden-Bericht</div>
    <div class="page-sub">Wer wurde wann angelegt, von wem geworben, bei welcher Gesellschaft - mit Direkteinstieg in jede Kundenakte.</div>
</div>

{{-- Bericht-Tabs --}}
<div style="display:flex;gap:8px;margin-bottom:20px;">
    <a href="{{ route('admin.reports') }}" class="rep-tab">Übersicht</a>
    <a href="{{ route('admin.reports.neukunden') }}" class="rep-tab rep-tab-active">Neukunden</a>
    @if($isManager)
    <a href="{{ route('admin.provisions') }}" class="rep-tab">Vermittler-Provisionen</a>
    @endif
</div>

{{-- Zeitraum: Monatsblaettern + freier Zeitraum --}}
<div class="card" style="margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    @if($month)
    @php
        $prev = $month->copy()->subMonth()->format('Y-m');
        $next = $month->copy()->addMonth()->format('Y-m');
        $isCurrent = $month->isSameMonth(now());
    @endphp
    <div style="display:flex;align-items:center;gap:10px;">
        <a href="{{ route('admin.reports.neukunden', array_merge(request()->except(['monat','from','to','page']), ['monat' => $prev])) }}" class="btn btn-ghost btn-sm" title="Vormonat">←</a>
        <div style="font-size:16px;font-weight:700;min-width:150px;text-align:center;">
            {{ $month->locale('de')->translatedFormat('F Y') }}
        </div>
        <a href="{{ route('admin.reports.neukunden', array_merge(request()->except(['monat','from','to','page']), ['monat' => $next])) }}" class="btn btn-ghost btn-sm {{ $isCurrent ? 'disabled' : '' }}" title="Folgemonat" @if($isCurrent) style="pointer-events:none;opacity:.4;" @endif>→</a>
        @if(!$isCurrent)
        <a href="{{ route('admin.reports.neukunden', request()->except(['monat','from','to','page'])) }}" class="btn btn-ghost btn-sm">Aktueller Monat</a>
        @endif
    </div>
    @else
    <div style="font-size:15px;font-weight:700;">
        Zeitraum: {{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }}
        <a href="{{ route('admin.reports.neukunden') }}" class="btn btn-ghost btn-sm" style="margin-left:8px;">Zurück zum Monat</a>
    </div>
    @endif
    <form method="GET" action="{{ route('admin.reports.neukunden') }}" style="display:flex;align-items:flex-end;gap:10px;margin-left:auto;flex-wrap:wrap;">
        <div class="flt-group">
            <label class="flt-lbl">Von</label>
            <input type="date" name="from" value="{{ request('from', $from->format('Y-m-d')) }}" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Bis</label>
            <input type="date" name="to" value="{{ request('to', $to->format('Y-m-d')) }}" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Anwenden</button>
    </form>
</div>

{{-- Kennzahlen --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon icon-green">🆕</div>
        <div class="metric-label">Neukunden</div>
        <div class="metric-value">{{ $stats['total'] }}</div>
        <div class="metric-sub">Im Zeitraum angelegt</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-blue">📄</div>
        <div class="metric-label">Davon mit Vertrag</div>
        <div class="metric-value">{{ $stats['with_contract'] }}</div>
        <div class="metric-sub">{{ $stats['total'] > 0 ? round($stats['with_contract'] / $stats['total'] * 100) : 0 }}% versorgt</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">✍️</div>
        <div class="metric-label">Neue Verträge</div>
        <div class="metric-value">{{ $stats['contracts'] }}</div>
        <div class="metric-sub">Der Neukunden</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-red">❓</div>
        <div class="metric-label">Ohne Werber</div>
        <div class="metric-value">{{ $stats['without_werber'] }}</div>
        <div class="metric-sub">Noch nicht zugeordnet</div>
    </div>
</div>

{{-- Wer hat wie viele gebracht --}}
<div class="grid-2" style="margin-bottom:24px;align-items:start;">
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="card-header" style="padding:16px 20px 10px;">
            <div class="card-title">Wer hat wie viele gebracht?</div>
        </div>
        @php $maxCustomers = max(1, (int) ($leaderboard->max('customers') ?? 1)); @endphp
        <table>
            <thead><tr style="background:#F8F9FA;">
                <th style="padding:10px 20px;">Werber</th>
                <th>Kunden</th>
                <th>Verträge</th>
                <th style="padding-right:20px;">Jahresbeitrag</th>
            </tr></thead>
            <tbody>
            @forelse($leaderboard as $row)
            <tr class="row-link" onclick="rowNav(event, '{{ route('admin.reports.neukunden', array_merge(request()->except(['werber','page']), ['werber' => $row['key'] === '' ? 'keiner' : $row['key']])) }}')" title="Liste filtern">
                <td style="padding:10px 20px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        @if($row['kind'] === 'mitarbeiter')<span class="wb-badge wb-mit">👤 Mitarbeiter</span>
                        @elseif($row['kind'] === 'partner')<span class="wb-badge wb-par">🤝 Partner</span>
                        @else<span class="wb-badge wb-none">—</span>
                        @endif
                        <span style="font-weight:600;">{{ $row['label'] }}</span>
                    </div>
                    <div class="lb-bar"><span style="width:{{ round($row['customers'] / $maxCustomers * 100) }}%;"></span></div>
                </td>
                <td style="font-weight:700;font-size:15px;">{{ $row['customers'] }}</td>
                <td>{{ $row['contracts'] }}</td>
                <td style="padding-right:20px;">{{ $row['yearly_premium'] > 0 ? number_format($row['yearly_premium'], 2, ',', '.') . ' €' : '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="4" style="text-align:center;padding:28px;color:var(--ink-soft);">Keine Neukunden im Zeitraum.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($isManager)
    {{-- Provisions-Vorschau + Ein-Klick-Erfassung --}}
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="card-header" style="padding:16px 20px 10px;display:flex;align-items:center;justify-content:space-between;">
            <div class="card-title">Provisionen für diesen Zeitraum</div>
            <a href="{{ route('admin.provisions') }}" class="btn btn-ghost btn-sm">Alle Provisionen →</a>
        </div>
        @if($provisionRows->isEmpty())
        <div style="padding:20px;color:var(--ink-soft);font-size:13.5px;">
            Noch keine Werber zugeordnet. Provisions-Sätze werden je Mitarbeiter
            (Mitarbeiter → bearbeiten) bzw. je Partner (Partnerakte) hinterlegt.
        </div>
        @else
        <table>
            <thead><tr style="background:#F8F9FA;">
                <th style="padding:10px 20px;">Werber</th>
                <th>Satz</th>
                <th>Vorschlag</th>
                <th style="padding-right:20px;">Erfassen</th>
            </tr></thead>
            <tbody>
            @foreach($provisionRows as $row)
            <tr>
                <td style="padding:10px 20px;">
                    <div style="font-weight:600;">{{ $row['label'] }}</div>
                    <div style="font-size:12px;color:var(--ink-soft);">{{ $row['customers'] }} Kunden · {{ $row['contracts'] }} Verträge</div>
                </td>
                <td style="font-size:12.5px;color:var(--ink-soft);white-space:nowrap;">
                    @if($row['has_rate'])
                        @if($row['fixed'] > 0){{ number_format($row['fixed'], 2, ',', '.') }} €/Vertrag<br>@endif
                        @if($row['percent'] > 0){{ number_format($row['percent'], 2, ',', '.') }}% v. Jahresbeitrag @endif
                    @else
                        <span style="color:#B5651D;">Kein Satz hinterlegt</span>
                    @endif
                </td>
                <td style="white-space:nowrap;">
                    <b>{{ number_format($row['suggested'], 2, ',', '.') }} €</b>
                    @if($row['already'] > 0)
                    <div style="font-size:11.5px;color:#17A65B;">✓ {{ number_format($row['already'], 2, ',', '.') }} € bereits gebucht</div>
                    @endif
                </td>
                <td style="padding-right:20px;">
                    @if($row['already'] > 0)
                        {{-- Automatik (Contract::created) hat fuer diese Neukunden bereits
                             gebucht - keine Ein-Klick-Nachbuchung, sonst doppelt (Audit PROV-1).
                             Anpassungen laufen bewusst ueber die Provisions-Seite. --}}
                        <a href="{{ route('admin.provisions') }}" class="btn btn-ghost btn-sm">Automatisch gebucht · verwalten →</a>
                    @else
                        {{-- Kein Satz hinterlegt o. Werber erst nachtraeglich gesetzt und
                             noch nichts gebucht: manuelle Ein-Klick-Erfassung als Fallback. --}}
                        <form method="POST" action="{{ route('admin.provisions.store') }}" style="display:flex;gap:6px;align-items:center;margin:0;">
                            @csrf
                            <input type="hidden" name="empfaenger" value="{{ $row['key'] }}">
                            <input type="hidden" name="period_from" value="{{ $from->format('Y-m-d') }}">
                            <input type="hidden" name="period_to" value="{{ $to->format('Y-m-d') }}">
                            <input type="hidden" name="note" value="Neukunden {{ $from->format('d.m.Y') }} - {{ $to->format('d.m.Y') }}: {{ $row['customers'] }} Kunden, {{ $row['contracts'] }} Vertraege">
                            <input type="number" name="amount" step="0.01" min="0.01" value="{{ $row['suggested'] > 0 ? number_format($row['suggested'], 2, '.', '') : '' }}" required placeholder="0,00" style="width:90px;padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
                            <button type="submit" class="btn btn-primary btn-sm">Erfassen</button>
                        </form>
                    @endif
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>
    @endif
</div>

{{-- Filterleiste --}}
<div class="card" style="padding:16px 20px;margin-bottom:16px;">
    <form method="GET" action="{{ route('admin.reports.neukunden') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:0;">
        @if($month)<input type="hidden" name="monat" value="{{ $month->format('Y-m') }}">@else<input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}">@endif
        <div class="flt-group" style="flex:1;min-width:200px;">
            <label class="flt-lbl" for="nk-suche">Suche</label>
            <input type="text" name="q" id="nk-suche" value="{{ request('q') }}" autocomplete="off" placeholder="Name, Nummer, Telefon ..." style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;background:#fff;">
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Werber</label>
            <select name="werber" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                <option value="keiner" {{ request('werber') === 'keiner' ? 'selected' : '' }}>Ohne Werber</option>
                <optgroup label="Mitarbeiter">
                    @foreach($employees as $e)
                    <option value="u:{{ $e->id }}" {{ request('werber') === 'u:' . $e->id ? 'selected' : '' }}>{{ $e->name }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Partner">
                    @foreach($partners as $p)
                    <option value="p:{{ $p->id }}" {{ request('werber') === 'p:' . $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                    @endforeach
                </optgroup>
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Angelegt von</label>
            <select name="angelegt_von" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                <option value="system" {{ request('angelegt_von') === 'system' ? 'selected' : '' }}>System / Import</option>
                @foreach($employees as $e)
                <option value="{{ $e->id }}" {{ request('angelegt_von') == $e->id ? 'selected' : '' }}>{{ $e->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Gesellschaft</label>
            <select name="gesellschaft" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach($insurers as $ins)
                <option value="{{ $ins }}" {{ request('gesellschaft') === $ins ? 'selected' : '' }}>{{ $ins }}</option>
                @endforeach
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Sparte</label>
            <select name="sparte" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach(\App\Models\Contract::TYPES as $key => $cfg)
                <option value="{{ $key }}" {{ request('sparte') === $key ? 'selected' : '' }}>{{ $cfg['icon'] }} {{ $cfg['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Vertrag</label>
            <select name="vertrag" class="flt-sel" onchange="this.form.submit()">
                <option value="">Egal</option>
                <option value="mit" {{ request('vertrag') === 'mit' ? 'selected' : '' }}>Mit Vertrag</option>
                <option value="ohne" {{ request('vertrag') === 'ohne' ? 'selected' : '' }}>Ohne Vertrag</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filtern</button>
        @if(collect(['q','werber','angelegt_von','gesellschaft','sparte','vertrag'])->contains(fn($k) => request()->filled($k)))
        <a href="{{ route('admin.reports.neukunden', $month ? ['monat' => $month->format('Y-m')] : ['from' => request('from'), 'to' => request('to')]) }}" class="btn btn-ghost btn-sm">Zurücksetzen</a>
        @endif
    </form>
</div>

{{-- Neukunden-Liste --}}
<div class="card" style="padding:0;overflow:visible;">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 20px;">Kunde</th>
            <th>Angelegt</th>
            <th>Geworben von</th>
            <th>Sichtbar für</th>
            <th style="padding-right:20px;">Verträge (Gesellschaft · Laufzeit)</th>
        </tr></thead>
        <tbody>
        @forelse($customers as $c)
        <tr class="row-link" onclick="rowNav(event, '{{ route('admin.customer', $c->id) }}')" title="Kundenakte öffnen">
            <td style="padding:14px 20px;vertical-align:top;">
                <a href="{{ route('admin.customer', $c->id) }}" style="font-weight:700;color:inherit;">{{ $c->user?->name ?? '—' }}</a>
                <div style="font-size:12px;color:var(--ink-soft);">Nr. {{ $c->customer_number }}</div>
            </td>
            <td style="vertical-align:top;white-space:nowrap;">
                <div>{{ $c->created_at->format('d.m.Y') }}</div>
                <div style="font-size:12px;color:var(--ink-soft);">von {{ $c->creator?->name ?? 'System' }}</div>
            </td>
            <td style="vertical-align:top;">
                @if($isManager)
                <details class="pop">
                    <summary class="pop-trigger" title="Werber ändern">
                        @if($c->acquirerLabel())
                        <span class="wb-badge {{ $c->acquired_by ? 'wb-mit' : 'wb-par' }}">{{ $c->acquired_by ? '👤' : '🤝' }} {{ $c->acquirerLabel() }}</span>
                        @else
                        <span class="wb-badge wb-none">+ Werber setzen</span>
                        @endif
                    </summary>
                    <div class="pop-panel">
                        <form method="POST" action="{{ route('admin.reports.neukunden.werber', $c->id) }}" style="margin:0;display:grid;gap:8px;">
                            @csrf
                            <label class="flt-lbl">Geworben von</label>
                            <select name="werber" class="flt-sel" style="min-width:200px;">
                                <option value="keiner">— Kein Werber —</option>
                                <optgroup label="Mitarbeiter">
                                    @foreach($employees as $e)
                                    <option value="u:{{ $e->id }}" {{ $c->acquired_by === $e->id ? 'selected' : '' }}>{{ $e->name }}</option>
                                    @endforeach
                                </optgroup>
                                <optgroup label="Partner">
                                    @foreach($partners as $p)
                                    <option value="p:{{ $p->id }}" {{ $c->acquired_by_partner_id === $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                                    @endforeach
                                </optgroup>
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm">Speichern</button>
                        </form>
                    </div>
                </details>
                @else
                @if($c->acquirerLabel())
                <span class="wb-badge {{ $c->acquired_by ? 'wb-mit' : 'wb-par' }}">{{ $c->acquired_by ? '👤' : '🤝' }} {{ $c->acquirerLabel() }}</span>
                @else
                <span style="color:var(--ink-soft);">—</span>
                @endif
                @endif
            </td>
            <td style="vertical-align:top;">
                @if($isManager)
                <details class="pop">
                    <summary class="pop-trigger" title="Sichtbarkeit für Mitarbeiter ändern">
                        @if($c->betreuer->isNotEmpty())
                        <span class="wb-badge wb-vis">👁 {{ $c->betreuer->pluck('name')->take(2)->implode(', ') }}{{ $c->betreuer->count() > 2 ? ' +' . ($c->betreuer->count() - 2) : '' }}</span>
                        @else
                        <span class="wb-badge wb-none">Nur Verwaltung</span>
                        @endif
                    </summary>
                    <div class="pop-panel">
                        <form method="POST" action="{{ route('admin.reports.neukunden.sichtbarkeit', $c->id) }}" style="margin:0;display:grid;gap:6px;">
                            @csrf
                            <label class="flt-lbl">Sichtbar für Mitarbeiter</label>
                            @foreach($employees as $e)
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;white-space:nowrap;">
                                <input type="checkbox" name="sichtbar[]" value="{{ $e->id }}" {{ $c->betreuer->contains('id', $e->id) ? 'checked' : '' }}>
                                {{ $e->name }}
                                @if($e->canSeeAllCustomers())<span style="font-size:11px;color:var(--ink-soft);">(sieht ohnehin alle)</span>@endif
                            </label>
                            @endforeach
                            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:4px;">Speichern</button>
                        </form>
                    </div>
                </details>
                @else
                {{ $c->betreuer->isNotEmpty() ? $c->betreuer->pluck('name')->implode(', ') : '—' }}
                @endif
            </td>
            <td style="vertical-align:top;padding:10px 20px 10px 0;">
                @forelse($c->contracts as $v)
                <a href="{{ route('admin.contract.edit', $v->id) }}" class="vt-row" title="Vertrag öffnen">
                    <span>{{ $v->typeIcon() }}</span>
                    <span style="font-weight:600;">{{ $v->insurer }}</span>
                    <span style="color:var(--ink-soft);font-size:12.5px;">
                        {{ $v->start_date ? \Carbon\Carbon::parse($v->start_date)->format('d.m.Y') : '—' }}
                        →
                        {{ $v->end_date ? \Carbon\Carbon::parse($v->end_date)->format('d.m.Y') : 'offen' }}
                    </span>
                    @php $st = $v->displayStatus(); @endphp
                    <span class="vt-status vt-{{ $st['key'] }}">{{ $st['label'] }}</span>
                </a>
                @empty
                <span style="font-size:12.5px;color:var(--ink-soft);">Kein Vertrag</span>
                <a href="{{ route('admin.contract.create', $c->id) }}" class="btn btn-ghost btn-sm" style="margin-left:6px;">+ Vertrag</a>
                @endforelse
            </td>
        </tr>
        @empty
        <tr><td colspan="5" style="text-align:center;padding:36px;color:var(--ink-soft);">Keine Neukunden im gewählten Zeitraum{{ collect(['q','werber','angelegt_von','gesellschaft','sparte','vertrag'])->contains(fn($k) => request()->filled($k)) ? ' (Filter aktiv)' : '' }}.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div style="margin-top:16px;">{{ $customers->links() }}</div>

<style>
.rep-tab { padding:9px 18px; border-radius:999px; border:1px solid var(--line); background:#fff; font-size:13.5px; font-weight:600; color:var(--ink); text-decoration:none; }
.rep-tab:hover { background:#F4F7F5; }
.rep-tab-active { background:#131A17; color:#fff; border-color:#131A17; }
.flt-group { display:flex; flex-direction:column; gap:4px; }
.flt-lbl { font-size:11.5px; color:var(--ink-soft); font-weight:600; }
.flt-sel { padding:8px 12px; border:1px solid var(--line); border-radius:8px; font-size:13.5px; background:#fff; min-width:150px; }
.wb-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; }
.wb-mit { background:#D9F4E6; color:#0E7A41; }
.wb-par { background:#F3EDDC; color:#8A7440; }
.wb-vis { background:#E6F1FB; color:#185FA5; }
.wb-none { background:#EEF0F3; color:var(--ink-soft); }
.lb-bar { height:4px; background:#EEF0F3; border-radius:999px; margin-top:6px; overflow:hidden; }
.lb-bar span { display:block; height:100%; background:linear-gradient(90deg,#19b463,#128a4b); border-radius:999px; }
.pop { position:relative; display:inline-block; }
.pop summary { list-style:none; cursor:pointer; }
.pop summary::-webkit-details-marker { display:none; }
.pop[open] .pop-panel { display:block; }
.pop-panel { position:absolute; top:calc(100% + 6px); left:0; z-index:40; background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:0 10px 30px rgba(19,26,23,.14); padding:14px; min-width:230px; max-height:320px; overflow:auto; }
.vt-row { display:flex; align-items:center; gap:8px; padding:4px 0; color:inherit; text-decoration:none; font-size:13px; }
.vt-row:hover span:nth-child(2) { text-decoration:underline; }
.vt-status { font-size:11px; font-weight:600; padding:2px 8px; border-radius:999px; }
.vt-active { background:#D9F4E6; color:#0E7A41; }
.vt-active_upcoming { background:#E6F1FB; color:#185FA5; }
.vt-pending, .vt-cancelled_upcoming { background:#F7E7D6; color:#B5651D; }
.vt-cancelled, .vt-expired { background:#F9E3E3; color:#A32D2D; }
</style>
<script>
// Offene Popover schliessen, wenn daneben geklickt wird (details-Element).
document.addEventListener('click', function (e) {
    document.querySelectorAll('details.pop[open]').forEach(function (d) {
        if (!d.contains(e.target)) d.removeAttribute('open');
    });
});
</script>
@endsection
