@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Vermittler-Provisionen</span></div>
    <div class="page-title">Vermittler-Provisionen</div>
    <div class="page-sub">Vergütungen an Mitarbeiter und Partner für geworbene Neukunden - erfasst aus dem Neukunden-Bericht oder manuell.</div>
</div>

{{-- Tabs: Eingang (Gutschriften) / Ausgang (Vermittler) --}}
<div style="display:flex;gap:8px;margin-bottom:20px;">
    <a href="{{ route('admin.commissions') }}" class="rep-tab">Gutschriften (Eingang)</a>
    <a href="{{ route('admin.provisions') }}" class="rep-tab rep-tab-active">Vermittler-Provisionen (Ausgang)</a>
    <a href="{{ route('admin.reports.neukunden') }}" class="rep-tab" style="margin-left:auto;">Zum Neukunden-Bericht →</a>
</div>

{{-- Kennzahlen --}}
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon icon-amber">⏳</div>
        <div class="metric-label">Offen</div>
        <div class="metric-value">{{ number_format($totals['offen'], 2, ',', '.') }} €</div>
        <div class="metric-sub">Noch nicht ausgezahlt</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">✅</div>
        <div class="metric-label">Ausgezahlt</div>
        <div class="metric-value">{{ number_format($totals['ausgezahlt'], 2, ',', '.') }} €</div>
        <div class="metric-sub">Gesamt</div>
    </div>
</div>

{{-- Manuell erfassen --}}
<div class="card" style="margin-bottom:20px;">
    <div class="card-title" style="margin-bottom:14px;">Provision manuell erfassen</div>
    <form method="POST" action="{{ route('admin.provisions.store') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:0;">
        @csrf
        <div class="flt-group">
            <label class="flt-lbl">Empfänger</label>
            <select name="empfaenger" class="flt-sel" required>
                <option value="">— wählen —</option>
                <optgroup label="Mitarbeiter">
                    @foreach($employees as $e)
                    <option value="u:{{ $e->id }}">{{ $e->name }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Partner">
                    @foreach($partners as $p)
                    <option value="p:{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </optgroup>
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Betrag (EUR)</label>
            <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0,00" style="width:120px;padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
        </div>
        <div class="flt-group" style="flex:1;min-width:220px;">
            <label class="flt-lbl">Notiz</label>
            <input type="text" name="note" maxlength="500" placeholder="z. B. Sonderprämie Juli" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
        </div>
        <button type="submit" class="btn btn-primary">Erfassen</button>
    </form>
</div>

{{-- Filter --}}
<div class="card" style="padding:14px 20px;margin-bottom:16px;">
    <form method="GET" action="{{ route('admin.provisions') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:0;">
        <div class="flt-group">
            <label class="flt-lbl">Status</label>
            <select name="status" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach(\App\Models\Provision::STATUSES as $key => $label)
                <option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Empfänger</label>
            <select name="empfaenger" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                <optgroup label="Mitarbeiter">
                    @foreach($employees as $e)
                    <option value="u:{{ $e->id }}" {{ request('empfaenger') === 'u:' . $e->id ? 'selected' : '' }}>{{ $e->name }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Partner">
                    @foreach($partners as $p)
                    <option value="p:{{ $p->id }}" {{ request('empfaenger') === 'p:' . $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                    @endforeach
                </optgroup>
            </select>
        </div>
        @if(request()->filled('status') || request()->filled('empfaenger'))
        <a href="{{ route('admin.provisions') }}" class="btn btn-ghost btn-sm">Zurücksetzen</a>
        @endif
    </form>
</div>

{{-- Liste --}}
<div class="card" style="padding:0;overflow:hidden;">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 20px;">Empfänger</th>
            <th>Zeitraum / Notiz</th>
            <th>Betrag</th>
            <th>Status</th>
            <th>Erfasst</th>
            <th style="padding-right:20px;">Aktion</th>
        </tr></thead>
        <tbody>
        @forelse($provisions as $p)
        <tr>
            <td style="padding:13px 20px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="wb-badge {{ $p->user_id ? 'wb-mit' : 'wb-par' }}">{{ $p->user_id ? '👤' : '🤝' }}</span>
                    <span style="font-weight:600;">{{ $p->recipientName() }}</span>
                </div>
                @if($p->customer)
                <a href="{{ route('admin.customer', $p->customer_id) }}" style="font-size:12px;color:var(--ink-soft);">Kunde: {{ $p->customer->user?->name }}</a>
                @endif
            </td>
            <td style="max-width:320px;">
                @if($p->period_from && $p->period_to)
                <div style="font-size:12.5px;">{{ $p->period_from->format('d.m.Y') }} – {{ $p->period_to->format('d.m.Y') }}</div>
                @endif
                @if($p->note)<div style="font-size:12px;color:var(--ink-soft);">{{ $p->note }}</div>@endif
            </td>
            <td style="font-weight:700;white-space:nowrap;">{{ number_format((float) $p->amount, 2, ',', '.') }} €</td>
            <td>
                <span class="wb-badge {{ ['offen' => 'wb-offen', 'ausgezahlt' => 'wb-mit', 'storniert' => 'wb-storno'][$p->status] ?? 'wb-none' }}">{{ $p->statusLabel() }}</span>
                @if($p->status === 'ausgezahlt' && $p->paid_at)
                <div style="font-size:11.5px;color:var(--ink-soft);margin-top:2px;">{{ $p->paid_at->format('d.m.Y') }} · {{ $p->payer?->name ?? '—' }}</div>
                @endif
            </td>
            <td style="font-size:12.5px;color:var(--ink-soft);white-space:nowrap;">
                {{ $p->created_at->format('d.m.Y') }}<br>{{ $p->creator?->name ?? 'System' }}
            </td>
            <td style="padding-right:20px;white-space:nowrap;">
                @if($p->status === 'offen')
                <form method="POST" action="{{ route('admin.provisions.status', $p->id) }}" style="display:inline;margin:0;" onsubmit="return confirm('Provision über {{ number_format((float) $p->amount, 2, ',', '.') }} EUR als ausgezahlt markieren?');">
                    @csrf
                    <input type="hidden" name="status" value="ausgezahlt">
                    <button type="submit" class="btn btn-primary btn-sm">Auszahlen</button>
                </form>
                <form method="POST" action="{{ route('admin.provisions.status', $p->id) }}" style="display:inline;margin:0;">
                    @csrf
                    <input type="hidden" name="status" value="storniert">
                    <button type="submit" class="btn btn-ghost btn-sm" title="Stornieren">✕</button>
                </form>
                @elseif($p->status === 'storniert')
                <form method="POST" action="{{ route('admin.provisions.status', $p->id) }}" style="display:inline;margin:0;">
                    @csrf
                    <input type="hidden" name="status" value="offen">
                    <button type="submit" class="btn btn-ghost btn-sm">Wieder öffnen</button>
                </form>
                @else
                <span style="font-size:12.5px;color:var(--ink-soft);">—</span>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:36px;color:var(--ink-soft);">Noch keine Provisionen erfasst. Aus dem <a href="{{ route('admin.reports.neukunden') }}">Neukunden-Bericht</a> per Klick erfassen oder oben manuell anlegen.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div style="margin-top:16px;">{{ $provisions->links() }}</div>

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
.wb-none { background:#EEF0F3; color:var(--ink-soft); }
.wb-offen { background:#F7E7D6; color:#B5651D; }
.wb-storno { background:#F9E3E3; color:#A32D2D; }
</style>
@endsection
