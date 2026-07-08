@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>E-Mail Marketing</span></div>
    <div class="page-title">E-Mail Marketing</div>
    <div class="page-sub">Kampagnen erstellen und automatische Erinnerungen senden.</div>
</div>

<div class="metrics-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="metric-card">
        <div class="metric-icon icon-blue">📧</div>
        <div class="metric-label">Kunden gesamt</div>
        <div class="metric-value">{{ $totalCustomers }}</div>
        <div class="metric-sub">Empfänger verfügbar</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">⏰</div>
        <div class="metric-label">Verträge laufen ab</div>
        <div class="metric-value">{{ $expiringSoon }}</div>
        <div class="metric-sub">In den nächsten 30 Tagen</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">✅</div>
        <div class="metric-label">Kampagnen gesendet</div>
        <div class="metric-value">{{ $campaigns->where('status','sent')->count() }}</div>
        <div class="metric-sub">Insgesamt</div>
    </div>
</div>

<div class="grid-2">

<div class="card">
    <div class="card-header">
        <div class="card-title">📨 Neue Kampagne</div>
    </div>
    <form method="POST" action="{{ route('admin.email_marketing.send') }}">
        @csrf
        <div class="field">
            <label>Empfänger *</label>
            <select name="target" required style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="all">Alle Kunden ({{ $totalCustomers }})</option>
                <option value="kfz">Kfz-Versicherung Kunden</option>
                <option value="krankenversicherung">Krankenversicherung Kunden</option>
                <option value="internet">Internet & Mobilfunk Kunden</option>
                <option value="strom_gas">Strom & Gas Kunden</option>
                <option value="de">Deutschsprachige Kunden</option>
                <option value="ar">Arabischsprachige Kunden</option>
            </select>
        </div>
        <div class="field">
            <label>Betreff *</label>
            <input type="text" name="subject" required placeholder="z.B. Exklusives Angebot für Sie">
        </div>
        <div class="field">
            <label>Nachricht *</label>
            <textarea name="body" required placeholder="Schreiben Sie Ihre Nachricht hier..." style="min-height:160px;width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>
        </div>
        <div style="background:#FEF3C7;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#92400E;">
            ⚠ Diese E-Mail wird sofort an alle ausgewählten Kunden gesendet.
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
            📤 Kampagne senden
        </button>
    </form>
</div>

<div style="display:flex;flex-direction:column;gap:20px;">

<div class="card">
    <div class="card-header">
        <div class="card-title">⏰ Automatische Erinnerungen</div>
    </div>
    <p style="font-size:14px;color:var(--ink-soft);margin-bottom:16px;">
        Sendet automatisch Erinnerungen an Kunden, deren Verträge in <strong>30, 14 oder 7 Tagen</strong> ablaufen.
    </p>
    @if($expiringSoon > 0)
    <div style="background:#E4F0E7;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#3B7A57;">
        ✓ {{ $expiringSoon }} Verträge laufen bald ab — Erinnerungen bereit zum Senden.
    </div>
    @else
    <div style="background:#F4F5F7;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:var(--ink-soft);">
        Aktuell keine ablaufenden Verträge in den nächsten 30 Tagen.
    </div>
    @endif
    <form method="POST" action="{{ route('admin.email_marketing.reminders') }}">
        @csrf
        <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center;">
            ⏰ Erinnerungen jetzt senden
        </button>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">📋 Letzte Kampagnen</div>
    </div>
    @forelse($campaigns->take(5) as $c)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $c->subject }}</div>
            <div style="font-size:12px;color:var(--ink-soft);">
                {{ $c->sent_at?->format('d.m.Y H:i') }} · {{ $c->sent_count }} gesendet
            </div>
        </div>
        <span class="badge badge-active">Gesendet</span>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Kampagnen.</p>
    @endforelse
</div>

</div>
</div>
@endsection
