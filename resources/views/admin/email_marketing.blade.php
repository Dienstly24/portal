@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><span>E-Mail Marketing</span></div>
    <div class="page-title">E-Mail Marketing</div>
    <div class="page-sub">Kampagnen erstellen und spartenspezifische Wechsel-Erinnerungen senden.</div>
</div>

@php
    $statusBadges = [
        'sent' => ['Gesendet', 'badge-active'],
        'sending' => ['Wird gesendet…', 'badge-pending'],
        'scheduled' => ['Geplant', 'badge-pending'],
        'draft' => ['Entwurf', 'badge-closed'],
    ];
    $drafts = $campaigns->whereIn('status', ['draft', 'scheduled']);
    $sentCampaigns = $campaigns->whereIn('status', ['sent', 'sending']);
@endphp

<div class="metrics-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="metric-card">
        <div class="metric-icon icon-blue">📧</div>
        <div class="metric-label">Erreichbare Empfänger</div>
        <div class="metric-value">{{ $reachableCustomers }}</div>
        <div class="metric-sub">von {{ $totalCustomers }} Kunden (ohne Abgemeldete)</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">⏰</div>
        <div class="metric-label">Fällige Wechsel-Erinnerungen</div>
        <div class="metric-value">{{ $dueReminders }}</div>
        <div class="metric-sub">Kfz · Strom/Gas · Internet · GKV</div>
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
        <div class="card-title">📨 {{ isset($draft) && $draft ? 'Entwurf bearbeiten' : 'Neue Kampagne' }}</div>
    </div>
    <form method="POST" action="{{ route('admin.email_marketing.send') }}" id="campaign-form">
        @csrf
        @if(isset($draft) && $draft)
        <input type="hidden" name="draft_id" value="{{ $draft->id }}">
        @endif
        <div class="field">
            <label>Empfänger *</label>
            <select name="target" required style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                @php $t = old('target', $draft->target ?? 'all'); @endphp
                <option value="all" @selected($t==='all')>Alle Kunden ({{ $reachableCustomers }})</option>
                <option value="kfz" @selected($t==='kfz')>Kfz-Versicherung Kunden</option>
                <option value="krankenversicherung" @selected($t==='krankenversicherung')>Krankenversicherung Kunden</option>
                <option value="internet" @selected($t==='internet')>Internet & Mobilfunk Kunden</option>
                <option value="strom_gas" @selected($t==='strom_gas')>Strom & Gas Kunden</option>
                <option value="de" @selected($t==='de')>Deutschsprachige Kunden</option>
                <option value="ar" @selected($t==='ar')>Arabischsprachige Kunden</option>
            </select>
        </div>
        <div class="field">
            <label>Betreff *</label>
            <input type="text" name="subject" required placeholder="z.B. Exklusives Angebot für Sie" value="{{ old('subject', $draft->subject ?? '') }}">
        </div>
        <div class="field">
            <label>Nachricht *</label>
            <textarea name="body" required placeholder="Schreiben Sie Ihre Nachricht hier..." style="min-height:160px;width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;">{{ old('body', $draft->body ?? '') }}</textarea>
        </div>
        <div class="field">
            <label>Geplanter Versand (nur für „Später senden")</label>
            <input type="datetime-local" name="scheduled_for" min="{{ now()->format('Y-m-d\TH:i') }}" value="{{ old('scheduled_for', isset($draft) && $draft?->scheduled_for ? $draft->scheduled_for->format('Y-m-d\TH:i') : '') }}" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
        </div>
        <div style="background:#E6F1FB;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#185FA5;">
            ℹ Der Versand läuft im Hintergrund über die Warteschlange. Jede Mail enthält automatisch einen Abmelde-Link; abgemeldete Kunden werden übersprungen.
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <button type="submit" name="action" value="send" class="btn btn-primary" style="flex:1 1 100%;justify-content:center;">📤 Jetzt senden</button>
            <button type="submit" name="action" value="schedule" class="btn btn-gold" style="flex:1;justify-content:center;">🕐 Später senden</button>
            <button type="submit" name="action" value="draft" class="btn btn-ghost" style="flex:1;justify-content:center;">💾 Entwurf</button>
            <button type="submit" formaction="{{ route('admin.email_marketing.preview') }}" formtarget="_blank" class="btn btn-ghost" style="flex:1;justify-content:center;">👁 Vorschau</button>
            <button type="submit" formaction="{{ route('admin.email_marketing.test') }}" class="btn btn-ghost" style="flex:1;justify-content:center;">🧪 Test an mich</button>
        </div>
    </form>
</div>

<div style="display:flex;flex-direction:column;gap:20px;">

<div class="card">
    <div class="card-header">
        <div class="card-title">⏰ Wechsel-Erinnerungen</div>
    </div>
    <p style="font-size:14px;color:var(--ink-soft);margin-bottom:12px;">
        Spartenspezifisch nach deutscher Rechtslage: <strong>Internet & Strom/Gas</strong> 6 + 3 Monate vor Vertragsende,
        <strong>Kfz</strong> 2 Monate + 6 Wochen (Stichtag beachtet), <strong>GKV</strong> nach 12 Monaten Bindungsfrist (§175 SGB V).
        Andere Sparten erhalten bewusst keine Erinnerung.
    </p>
    <p style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;">
        Reagiert ein Kunde, markieren Sie das in der Kundenakte – die Folge-Erinnerung entfällt dann automatisch.
        Der tägliche Automatik-Lauf (08:30) und dieser Button senden nie doppelt.
    </p>
    @if($dueReminders > 0)
    <div style="background:#E4F0E7;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#3B7A57;">
        ✓ {{ $dueReminders }} Erinnerungen fällig — bereit zum Senden.
    </div>
    @else
    <div style="background:#F4F5F7;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:var(--ink-soft);">
        Aktuell keine fälligen Wechsel-Erinnerungen.
    </div>
    @endif
    <form method="POST" action="{{ route('admin.email_marketing.reminders') }}">
        @csrf
        <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center;" @disabled($dueReminders === 0)>
            ⏰ Erinnerungen jetzt senden
        </button>
    </form>
</div>

@if($drafts->isNotEmpty())
<div class="card">
    <div class="card-header">
        <div class="card-title">📝 Entwürfe & Geplante</div>
    </div>
    @foreach($drafts as $c)
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $c->subject }}</div>
            <div style="font-size:12px;color:var(--ink-soft);">
                @if($c->status === 'scheduled' && $c->scheduled_for)
                    Geplant für {{ $c->scheduled_for->format('d.m.Y H:i') }}
                @else
                    Entwurf · {{ $c->updated_at->format('d.m.Y H:i') }}
                @endif
            </div>
        </div>
        <div style="display:flex;gap:6px;align-items:center;">
            <a href="{{ route('admin.email_marketing', ['draft' => $c->id]) }}" class="btn btn-ghost" style="padding:5px 10px;font-size:12px;">Bearbeiten</a>
            <form method="POST" action="{{ route('admin.email_marketing.dispatch', $c->id) }}">@csrf
                <button type="submit" class="btn btn-primary" style="padding:5px 10px;font-size:12px;">Senden</button>
            </form>
            <form method="POST" action="{{ route('admin.email_marketing.destroy', $c->id) }}" onsubmit="return confirm('Diesen Entwurf löschen?')">@csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost" style="padding:5px 10px;font-size:12px;color:#A32D2D;">Löschen</button>
            </form>
        </div>
    </div>
    @endforeach
</div>
@endif

<div class="card">
    <div class="card-header">
        <div class="card-title">📋 Letzte Kampagnen</div>
    </div>
    @forelse($sentCampaigns->take(5) as $c)
    @php [$label, $badge] = $statusBadges[$c->status] ?? [$c->status, 'badge-closed']; @endphp
    <div class="item-row">
        <div>
            <div style="font-weight:600;font-size:14px;">{{ $c->subject }}</div>
            <div style="font-size:12px;color:var(--ink-soft);">
                {{ $c->sent_at?->format('d.m.Y H:i') }} · {{ $c->sent_count }} gesendet
                @if($c->failed_count > 0)
                    · <span style="color:#A32D2D;">{{ $c->failed_count }} fehlgeschlagen</span>
                @endif
            </div>
        </div>
        <span class="badge {{ $badge }}">{{ $label }}</span>
    </div>
    @empty
    <p style="color:var(--ink-soft);font-size:14px;">Noch keine Kampagnen.</p>
    @endforelse
</div>

</div>
</div>
@endsection
