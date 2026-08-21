@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Vermittler-Provisionen</span></div>
    <div class="page-title">Vermittler-Provisionen</div>
    <div class="page-sub">Automatisch je Neuvertrag gebucht (Satz je Sparte) - Freigabe und Auszahlung bleiben Handarbeit der Verwaltung.</div>
</div>

@include('admin.partials.provision_tabs', ['active' => 'liste'])

@if(session('success'))<div style="background:#D9F4E6;color:#17A65B;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

{{-- Kennzahlen (folgen den aktiven Filtern) --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon icon-amber">⏳</div>
        <div class="metric-label">Offen</div>
        <div class="metric-value" style="font-size:24px;">{{ number_format($totals['offen'], 2, ',', '.') }} €</div>
        <div class="metric-sub">Warten auf Freigabe</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-blue">✔️</div>
        <div class="metric-label">Freigegeben</div>
        <div class="metric-value" style="font-size:24px;">{{ number_format($totals['freigegeben'], 2, ',', '.') }} €</div>
        <div class="metric-sub">Zur Auszahlung</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">✅</div>
        <div class="metric-label">Ausgezahlt</div>
        <div class="metric-value" style="font-size:24px;">{{ number_format($totals['ausgezahlt'], 2, ',', '.') }} €</div>
        <div class="metric-sub">Abgeschlossen</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-red">↩️</div>
        <div class="metric-label">Abzüge / Storni</div>
        <div class="metric-value" style="font-size:24px;">{{ number_format($totals['abzuege'], 2, ',', '.') }} €</div>
        <div class="metric-sub">Negative Buchungen</div>
    </div>
</div>

{{-- Manuell erfassen (Bonus/Abzug/freie Buchung) --}}
<details class="card" style="margin-bottom:20px;padding:0;overflow:hidden;">
    <summary style="padding:14px 20px;font-weight:700;cursor:pointer;">➕ Manuell erfassen (Bonus, Abzug, freie Provision)</summary>
    <form method="POST" action="{{ route('admin.provisions.store') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:0;padding:4px 20px 18px;">
        @csrf
        <div class="flt-group">
            <label class="flt-lbl">Art</label>
            <select name="art" class="flt-sel">
                <option value="manuell">Provision (manuell)</option>
                <option value="bonus">Bonus</option>
                <option value="abzug">Abzug (wird negativ gebucht)</option>
            </select>
        </div>
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
        <div class="flt-group">
            <label class="flt-lbl">Sparte (optional)</label>
            <select name="sparte" class="flt-sel">
                <option value="">—</option>
                @foreach(\App\Models\Contract::TYPES as $key => $cfg)
                <option value="{{ $key }}">{{ $cfg['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="flt-group" style="flex:1;min-width:220px;">
            <label class="flt-lbl">Notiz / Grund (bei Bonus &amp; Abzug Pflicht)</label>
            <input type="text" name="note" maxlength="500" placeholder="z. B. Sonderprämie Juli" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
        </div>
        <button type="submit" class="btn btn-primary">Erfassen</button>
    </form>
</details>

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
        <div class="flt-group">
            <label class="flt-lbl">Art</label>
            <select name="typ" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach(\App\Models\Provision::TYPES as $key => $label)
                <option value="{{ $key }}" {{ request('typ') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Sparte</label>
            <select name="sparte" class="flt-sel" onchange="this.form.submit()">
                <option value="">Alle</option>
                @foreach(\App\Models\Contract::TYPES as $key => $cfg)
                <option value="{{ $key }}" {{ request('sparte') === $key ? 'selected' : '' }}>{{ $cfg['label'] }}</option>
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
            <label class="flt-lbl">Kunde</label>
            <input type="text" name="kunde" value="{{ request('kunde') }}" placeholder="Name / Kundennr." style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;width:150px;">
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Monat</label>
            <input type="month" name="monat" value="{{ request('monat') }}" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
        </div>
        <div class="flt-group">
            <label class="flt-lbl">Jahr</label>
            <select name="jahr" class="flt-sel" onchange="this.form.submit()" style="min-width:90px;">
                <option value="">Alle</option>
                @for($y = now()->year; $y >= now()->year - 5; $y--)
                <option value="{{ $y }}" {{ request('jahr') == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filtern</button>
        @if(request()->hasAny(['status','empfaenger','typ','sparte','gesellschaft','kunde','monat','jahr']))
        <a href="{{ route('admin.provisions') }}" class="btn btn-ghost btn-sm">Zurücksetzen</a>
        @endif
    </form>
</div>

{{-- Liste --}}
<div class="card" style="padding:0;overflow:hidden;">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 20px;">Empfänger</th>
            <th>Kunde / Vertrag</th>
            <th>Art</th>
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
                    <a href="{{ route('admin.provisions.show', $p->id) }}" style="font-weight:600;color:var(--ink);">{{ $p->recipientName() }}</a>
                </div>
            </td>
            <td style="max-width:280px;">
                @if($p->customer)
                <a href="{{ route('admin.customer', $p->customer_id) }}" style="font-size:12.5px;">{{ $p->customer->user?->name ?? $p->customer->customer_number }}</a>
                @endif
                <div style="font-size:12px;color:var(--ink-soft);">
                    @if($p->contract_type){{ $p->contractTypeLabel() }}@endif
                    @if($p->insurer) · {{ $p->insurer }}@endif
                </div>
                @if($p->note)<div style="font-size:11.5px;color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:270px;" title="{{ $p->note }}">{{ $p->note }}</div>@endif
            </td>
            <td><span class="wb-badge {{ ['neuvertrag' => 'wb-mit', 'storno' => 'wb-storno', 'bonus' => 'wb-gold', 'abzug' => 'wb-storno'][$p->type] ?? 'wb-none' }}">{{ $p->typeLabel() }}</span></td>
            <td style="font-weight:700;white-space:nowrap;color:{{ $p->isDeduction() ? '#A32D2D' : 'inherit' }};">{{ number_format((float) $p->amount, 2, ',', '.') }} €</td>
            <td>
                <span class="wb-badge {{ ['offen' => 'wb-offen', 'freigegeben' => 'wb-frei', 'ausgezahlt' => 'wb-mit', 'storniert' => 'wb-storno'][$p->status] ?? 'wb-none' }}">{{ $p->statusLabel() }}</span>
                @if($p->status === 'ausgezahlt' && $p->paid_at)
                <div style="font-size:11.5px;color:var(--ink-soft);margin-top:2px;">{{ $p->paid_at->lokal()->format('d.m.Y') }} · {{ $p->payer?->name ?? '—' }}</div>
                @endif
            </td>
            <td style="font-size:12.5px;color:var(--ink-soft);white-space:nowrap;">
                {{ $p->created_at->lokal()->format('d.m.Y') }}<br>{{ $p->creator?->name ?? 'System' }}
            </td>
            <td style="padding-right:20px;white-space:nowrap;">
                @if($p->status === 'offen')
                <form method="POST" action="{{ route('admin.provisions.status', $p->id) }}" style="display:inline;margin:0;">
                    @csrf
                    <input type="hidden" name="status" value="freigegeben">
                    <button type="submit" class="btn btn-primary btn-sm">Freigeben</button>
                </form>
                @endif
                @if(in_array($p->status, ['offen', 'freigegeben'], true))
                <form method="POST" action="{{ route('admin.provisions.status', $p->id) }}" style="display:inline;margin:0;" onsubmit="return confirm('Buchung über {{ number_format((float) $p->amount, 2, ',', '.') }} EUR als ausgezahlt markieren?');">
                    @csrf
                    <input type="hidden" name="status" value="ausgezahlt">
                    <button type="submit" class="btn btn-gold btn-sm">Auszahlen</button>
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
                <a href="{{ route('admin.provisions.show', $p->id) }}" class="btn btn-ghost btn-sm">Details</a>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;padding:36px;color:var(--ink-soft);">Keine Buchungen gefunden. Neue Verträge mit Werber und hinterlegtem <a href="{{ route('admin.provisions.rates') }}">Provisions-Satz</a> erscheinen hier automatisch.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div style="margin-top:16px;">{{ $provisions->links() }}</div>
@include('admin.partials.provision_styles')
@endsection
