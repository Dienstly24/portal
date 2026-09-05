@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>Provisionen</span></div>
    <div>
        <div class="page-title">Provisionen</div>
        <div class="page-sub">Automatisch erfasste Gutschriften prüfen und als Lexoffice-Beleg buchen.</div>
    </div>
</div>

{{-- Tabs: Eingang (Gutschriften) / Ausgang (Vermittler-Provisionen) --}}
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="{{ route('admin.commissions') }}" class="rep-tab rep-tab-active">Gutschriften (Eingang)</a>
    <a href="{{ route('admin.provisions') }}" class="rep-tab">Provisionen (Ausgang)</a>
    <a href="{{ route('admin.provisions.rates') }}" class="rep-tab">Sätze</a>
    <a href="{{ route('admin.provisions.report') }}" class="rep-tab">Monatsbericht</a>
    <a href="{{ route('admin.provisions.dashboard') }}" class="rep-tab">Dashboard</a>
    @can('provisionen-verwalten')
    <a href="{{ route('admin.provisionsmanagement.dashboard') }}" class="rep-tab" style="margin-left:auto;">Provisionsmanagement →</a>
    @endcan
</div>
<style>
.rep-tab { padding:9px 18px; border-radius:999px; border:1px solid var(--line); background:#fff; font-size:13.5px; font-weight:600; color:var(--ink); text-decoration:none; }
.rep-tab:hover { background:#F4F7F5; }
.rep-tab-active { background:var(--graphite); color:#fff; border-color:var(--graphite); }
</style>

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald);padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

<div class="card" style="padding:0;overflow:hidden;margin-bottom:24px;">
    <div class="card-head-bar">Zu prüfen ({{ $pending->count() }})</div>
    @forelse($pending as $c)
    <form method="POST" action="{{ route('admin.commissions.book', $c->id) }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid var(--line);">
        @csrf
        <div style="min-width:180px;">
            <div style="font-weight:600;font-size:14px;"><a href="{{ route('admin.partners.show', $c->partner_id) }}">{{ $c->partner->name }}</a></div>
            <div class="muted-xs">erfasst {{ $c->created_at->lokal()->format('d.m.Y H:i') }}</div>
        </div>
        <div>
            <label style="font-size:11px;color:var(--ink-soft);display:block;">Gutschrift-Nr.</label>
            <input type="text" name="credit_note_number" value="{{ $c->credit_note_number }}" maxlength="100" style="padding:7px 10px;border:1px solid var(--line);border-radius:8px;width:150px;">
        </div>
        <div>
            <label style="font-size:11px;color:var(--ink-soft);display:block;">Betrag (€) *</label>
            <input type="number" step="0.01" min="0.01" name="amount" value="{{ $c->amount }}" required style="padding:7px 10px;border:1px solid var(--line);border-radius:8px;width:120px;">
        </div>
        <div>
            <label style="font-size:11px;color:var(--ink-soft);display:block;">Datum *</label>
            <input type="date" name="statement_date" value="{{ $c->statement_date?->format('Y-m-d') ?? now()->format('Y-m-d') }}" required style="padding:7px 10px;border:1px solid var(--line);border-radius:8px;">
        </div>
        <div style="display:flex;gap:8px;margin-left:auto;">
            <button type="submit" class="btn btn-emerald btn-sm" data-h-click="7ede9fa4ef">Buchen (Lexoffice)</button>
            <button type="submit" form="reject-{{ $c->id }}" class="btn btn-ghost btn-sm" data-h-click="6ad15fbff3">Ablehnen</button>
        </div>
    </form>
    <form id="reject-{{ $c->id }}" method="POST" action="{{ route('admin.commissions.reject', $c->id) }}">@csrf</form>
    @empty
    <div style="text-align:center;padding:28px;color:var(--ink-soft);">Keine offenen Gutschriften – neue Provisions-Mails erscheinen hier automatisch.</div>
    @endforelse
</div>

<div class="card card-flush">
    <div class="card-head-bar">Zuletzt bearbeitet</div>
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:10px 20px;">Partner</th>
            <th>Gutschrift-Nr.</th>
            <th>Betrag</th>
            <th>Status</th>
            <th>Geprüft von</th>
            <th>Am</th>
        </tr></thead>
        <tbody>
        @forelse($recent as $c)
        <tr>
            <td style="padding:12px 20px;font-size:13px;"><a href="{{ route('admin.partners.show', $c->partner_id) }}">{{ $c->partner->name }}</a></td>
            <td style="font-size:13px;">{{ $c->credit_note_number ?? '—' }}</td>
            <td style="font-size:13px;font-weight:600;">{{ $c->amount !== null ? number_format($c->amount, 2, ',', '.') . ' €' : '—' }}</td>
            <td><span class="badge {{ $c->status === 'booked' ? 'badge-active' : 'badge-danger' }}">{{ $c->statusLabel() }}</span></td>
            <td class="muted-sm">{{ $c->reviewer?->name ?? '—' }}</td>
            <td class="muted-sm">{{ $c->reviewed_at?->lokal()->format('d.m.Y H:i') ?? '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--ink-soft);">Noch keine bearbeiteten Gutschriften.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["7ede9fa4ef"] = function (event) { return confirm('Diese Provision jetzt in Lexoffice verbuchen? Die Buchung wird an ein externes System uebertragen.') };
window.__h["6ad15fbff3"] = function (event) { return confirm('Diese Provision wirklich ablehnen?') };
</script>
@endPushOnce
