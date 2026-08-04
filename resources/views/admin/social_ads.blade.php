@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.banners') }}">Banner</a><span class="breadcrumb-sep">›</span><span>Werbeanzeigen</span></div>
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div>
            <div class="page-title">🎯 Werbeanzeigen (Meta)</div>
            <div class="page-sub">Kampagnen auf Facebook &amp; Instagram komplett aus dem System steuern: starten, pausieren, Budget ändern, löschen – Ausgaben und Ergebnisse im Blick.</div>
        </div>
        <a href="{{ route('admin.banners') }}" class="btn btn-ghost">📢 Zur Bannerverwaltung</a>
    </div>
</div>

{{-- Erfolgsmeldung rendert das Layout zentral; hier nur Fehler. --}}
@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

@if(!$configured)
<div class="card">
    <p style="font-size:14px;color:var(--ink-soft);">⚠ Werbekonto noch nicht verbunden. Einmalig auf dem Server <code>php artisan meta:einrichten</code> ausführen (der Assistent findet das Werbekonto automatisch). Anleitung: <code>docs/ANLEITUNG_META_API_AR.md</code></p>
</div>
@else

{{-- Seiten-Ueberblick (aus dem Cache - social:refresh-insights) --}}
@if($pageInsights)
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-icon icon-blue">👥</div>
        <div class="metric-label">Follower der Seite</div>
        <div class="metric-value">{{ number_format($pageInsights['followers'] ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">👍</div>
        <div class="metric-label">„Gefällt mir"</div>
        <div class="metric-value">{{ number_format($pageInsights['fans'] ?? 0, 0, ',', '.') }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">👁</div>
        <div class="metric-label">Seitenaufrufe (28 Tage)</div>
        <div class="metric-value">{{ number_format($pageInsights['page_views_28d'] ?? 0, 0, ',', '.') }}</div>
        <div class="metric-sub">Stand: {{ \Illuminate\Support\Carbon::parse($pageInsights['refreshed_at'])->format('d.m.Y H:i') }}</div>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <div class="card-title">Kampagnen</div>
        <span style="font-size:12px;color:var(--ink-soft);">Neue Anzeige: Banner → Social-Media → „📢 Bewerben" · Tagesbudget max. {{ $maxBudget }} EUR (Schutzgrenze)</span>
    </div>
    @if($apiError)
    <p style="font-size:13.5px;color:#A32D2D;">⚠ {{ $apiError }}</p>
    @elseif(empty($campaigns))
    <p style="font-size:13.5px;color:var(--ink-soft);">Noch keine Kampagnen. Einen Banner per API posten und dann über „📢 Bewerben" die erste Anzeige erstellen – sie startet erst nach Ihrem Klick.</p>
    @else
    {{-- overflow-x: 8 Spalten inkl. Budget-Formular sind breiter als Handy/Tablet --}}
    <div style="overflow-x:auto;">
    <table>
        <thead><tr><th>Kampagne</th><th>Status</th><th style="text-align:right;">Tagesbudget</th><th style="text-align:right;">Ausgegeben</th><th style="text-align:right;">Impressionen</th><th style="text-align:right;">Klicks</th><th style="text-align:right;">Ø Klickpreis</th><th>Aktionen</th></tr></thead>
        <tbody>
        @foreach($campaigns as $c)
        @php
            $aktiv = $c['effective_status'] === 'ACTIVE';
            $statusLabel = match($c['effective_status']) {
                'ACTIVE' => ['Aktiv', '#3B7A57', '#E4F0E7'],
                'PAUSED' => ['Pausiert', '#92400E', '#FEF3C7'],
                'IN_PROCESS', 'PENDING_REVIEW' => ['In Prüfung', '#185FA5', '#E6F1FB'],
                'WITH_ISSUES', 'DISAPPROVED' => ['Problem', '#A32D2D', '#F9E3E3'],
                default => [$c['effective_status'], '#5F5E5A', '#F1EFE8'],
            };
        @endphp
        <tr>
            <td style="font-weight:600;max-width:260px;">{{ $c['name'] }}
                <div style="font-size:11.5px;color:var(--ink-soft);font-weight:400;">{{ $c['objective'] === 'OUTCOME_TRAFFIC' ? 'Ziel: Klicks' : 'Ziel: Reichweite' }}{{ $c['stop_time'] ? ' · endet ' . \Illuminate\Support\Carbon::parse($c['stop_time'])->format('d.m.Y') : '' }}</div>
            </td>
            <td><span style="background:{{ $statusLabel[2] }};color:{{ $statusLabel[1] }};border-radius:12px;padding:2px 10px;font-size:11.5px;font-weight:600;white-space:nowrap;">{{ $statusLabel[0] }}</span></td>
            <td style="text-align:right;">
                <form method="POST" action="{{ route('admin.werbung.budget', $c['id']) }}" style="display:flex;gap:4px;justify-content:flex-end;align-items:center;">
                    @csrf
                    {{-- exakten Wert zeigen (nicht runden - sonst aendert Speichern still das Budget) --}}
                    <input type="number" name="daily_budget_eur" value="{{ $c['daily_budget_eur'] !== null ? rtrim(rtrim(number_format($c['daily_budget_eur'], 2, '.', ''), '0'), '.') : '' }}" min="1" max="{{ $maxBudget }}" step="0.01" style="width:80px;padding:5px 7px;border:1px solid var(--line);border-radius:7px;font-size:12.5px;text-align:right;background:#F7F5EF;"> €
                    <button type="submit" class="btn btn-ghost btn-sm" title="Tagesbudget speichern">💾</button>
                </form>
            </td>
            <td style="text-align:right;font-weight:600;">{{ number_format($c['spend_eur'], 2, ',', '.') }} €</td>
            <td style="text-align:right;">{{ number_format($c['impressions'], 0, ',', '.') }}</td>
            <td style="text-align:right;font-weight:600;">{{ number_format($c['clicks'], 0, ',', '.') }}</td>
            <td style="text-align:right;">{{ $c['cpc_eur'] !== null ? number_format($c['cpc_eur'], 2, ',', '.') . ' €' : '—' }}</td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    {{-- Js::from statt addslashes: korrektes JS-Escaping, auch bei Zeilenumbruechen im Kampagnennamen --}}
                    <form method="POST" action="{{ route('admin.werbung.status', $c['id']) }}" @if(!$aktiv) onsubmit="return confirm({{ \Illuminate\Support\Js::from('Anzeige „' . $c['name'] . '" jetzt starten? Ab dann wird Budget ausgegeben.') }});" @endif>
                        @csrf<input type="hidden" name="action" value="{{ $aktiv ? 'pause' : 'start' }}">
                        <button type="submit" class="btn {{ $aktiv ? 'btn-ghost' : 'btn-primary' }} btn-sm">{{ $aktiv ? '⏸ Pausieren' : '▶ Starten' }}</button>
                    </form>
                    <form method="POST" action="{{ route('admin.werbung.delete', $c['id']) }}" onsubmit="return confirm({{ \Illuminate\Support\Js::from('Kampagne „' . $c['name'] . '" endgültig löschen?') }});">
                        @csrf<button type="submit" class="btn btn-sm" style="background:#F9E3E3;color:#A32D2D;border:1px solid #F0A0A0;">🗑</button>
                    </form>
                </div>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    <p style="font-size:12px;color:var(--ink-soft);margin-top:10px;">💡 Neue Anzeigen entstehen immer PAUSIERT und geben erst nach „▶ Starten" Geld aus. Zahlungsmittel (Kreditkarte) verwaltet Meta selbst – das ist der einzige Schritt, der dort bleibt.</p>
    @endif
</div>

{{-- Schutzgrenze: nur der Admin darf sie aendern (eine Rolle ueber den
     Anzeigen-Aktionen) - jede Aenderung steht im Aktivitaets-Log. --}}
<div class="card">
    <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
        <div style="flex:1;min-width:260px;">
            <div style="font-weight:600;font-size:14px;">🛡 Schutzgrenze: max. Tagesbudget</div>
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:4px;">Kein Tagesbudget im System kann über <strong>{{ $maxBudget }} EUR</strong> gesetzt werden – Schutz vor Tippfehlern mit echtem Geld. Gilt für neue Anzeigen und Budget-Änderungen.</div>
        </div>
        @if(auth()->user()->role === 'admin')
        <form method="POST" action="{{ route('admin.werbung.cap') }}" style="display:flex;gap:8px;align-items:center;">
            @csrf
            <input type="number" name="max_daily_budget_eur" value="{{ $maxBudget }}" min="1" max="{{ \App\Services\Social\MetaAdsService::CAP_CEILING_EUR }}" step="1" required style="width:100px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;text-align:right;background:#F7F5EF;"> <span style="font-size:13px;">EUR/Tag</span>
            <button type="submit" class="btn btn-ghost btn-sm">💾 Grenze ändern</button>
        </form>
        @else
        <span style="font-size:12px;color:var(--ink-soft);">Änderbar nur durch den Admin.</span>
        @endif
    </div>
</div>
@endif
@endsection
