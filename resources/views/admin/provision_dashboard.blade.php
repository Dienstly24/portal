@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.provisions') }}">Vermittler-Provisionen</a><span class="breadcrumb-sep">›</span><span>Dashboard</span></div>
    <div class="page-title">Leistungs-Dashboard</div>
    <div class="page-sub">Verträge, Provisionen und Produktivität auf einen Blick - nur für die Verwaltung.</div>
</div>

@include('admin.partials.provision_tabs', ['active' => 'dashboard'])

{{-- Monatsblaettern --}}
<div class="card" style="margin-bottom:20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    @if($month)
    @php
        $prev = $month->copy()->subMonth()->format('Y-m');
        $next = $month->copy()->addMonth()->format('Y-m');
        $isCurrent = $month->isSameMonth(now());
    @endphp
    <a href="{{ route('admin.provisions.dashboard', ['monat' => $prev]) }}" class="btn btn-ghost btn-sm">←</a>
    <div style="font-size:16px;font-weight:700;min-width:150px;text-align:center;">{{ $month->locale('de')->translatedFormat('F Y') }}</div>
    <a href="{{ route('admin.provisions.dashboard', ['monat' => $next]) }}" class="btn btn-ghost btn-sm {{ $isCurrent ? 'disabled' : '' }}" @if($isCurrent) style="pointer-events:none;opacity:.4;" @endif>→</a>
    @if(!$isCurrent)<a href="{{ route('admin.provisions.dashboard') }}" class="btn btn-ghost btn-sm">Aktueller Monat</a>@endif
    @else
    <div style="font-size:15px;font-weight:700;">Zeitraum: {{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }}</div>
    @endif
    <div style="margin-left:auto;font-size:12.5px;color:var(--ink-soft);">
        Gesamt (alle Zeit): <strong>{{ $alltime['vertraege'] }}</strong> Verträge ·
        Netto-Provision <strong>{{ number_format($alltime['netto'], 2, ',', '.') }} €</strong> ·
        davon ausgezahlt <strong>{{ number_format($alltime['ausgezahlt'], 2, ',', '.') }} €</strong>
    </div>
</div>

{{-- KPI-Zeile --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon icon-blue">✍️</div>
        <div class="metric-label">Verträge im Zeitraum</div>
        <div class="metric-value">{{ $kpis['vertraege'] }}</div>
        <div class="metric-sub">Jahresbeitragsvolumen {{ number_format($kpis['beitragsvolumen'], 0, ',', '.') }} €</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">💶</div>
        <div class="metric-label">Provisionen (Netto)</div>
        <div class="metric-value" style="font-size:24px;">{{ number_format($kpis['provision_netto'], 2, ',', '.') }} €</div>
        <div class="metric-sub">davon Abzüge {{ number_format($kpis['abzuege'], 2, ',', '.') }} €</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">🏆</div>
        <div class="metric-label">Bester Mitarbeiter</div>
        <div class="metric-value" style="font-size:18px;">{{ $kpis['bester_mitarbeiter']['label'] ?? '—' }}</div>
        <div class="metric-sub">{{ $kpis['bester_mitarbeiter'] ? number_format($kpis['bester_mitarbeiter']['netto'], 2, ',', '.') . ' € · ' . $kpis['bester_mitarbeiter']['vertraege'] . ' Verträge' : 'Keine Daten' }}</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-gold" style="background:#F3EDDC;">🤝</div>
        <div class="metric-label">Bester Partner</div>
        <div class="metric-value" style="font-size:18px;">{{ $kpis['bester_partner']['label'] ?? '—' }}</div>
        <div class="metric-sub">{{ $kpis['bester_partner'] ? number_format($kpis['bester_partner']['netto'], 2, ',', '.') . ' € · ' . $kpis['bester_partner']['vertraege'] . ' Verträge' : 'Keine Daten' }}</div>
    </div>
</div>

<div class="grid-2" style="align-items:start;margin-bottom:24px;">
    {{-- Je Empfaenger --}}
    <div class="card card-flush">
        <div class="card-head-bar">Verträge &amp; Provision je Vermittler</div>
        @php $maxNetto = max(0.01, (float) ($byRecipient->max('netto') ?? 0)); @endphp
        <table>
            <thead><tr style="background:#F8F9FA;">
                <th style="padding:10px 20px;">Vermittler</th>
                <th>Verträge</th>
                <th style="padding-right:20px;">Provision (Netto)</th>
            </tr></thead>
            <tbody>
            @forelse($byRecipient as $r)
            <tr>
                <td style="padding:10px 20px;">
                    <span class="wb-badge {{ $r['kind'] === 'partner' ? 'wb-par' : 'wb-mit' }}">{{ $r['kind'] === 'partner' ? '🤝' : '👤' }}</span>
                    <strong>{{ $r['label'] }}</strong>
                    <div class="pv-bar {{ $r['kind'] === 'partner' ? 'pv-bar-gold' : '' }}"><span style="width:{{ $r['netto'] > 0 ? round($r['netto'] / $maxNetto * 100) : 0 }}%;"></span></div>
                </td>
                <td style="font-weight:600;">{{ $r['vertraege'] }}</td>
                <td style="padding-right:20px;font-weight:700;">{{ number_format($r['netto'], 2, ',', '.') }} €</td>
            </tr>
            @empty
            <tr><td colspan="3" style="text-align:center;padding:28px;color:var(--ink-soft);">Keine Aktivität im Zeitraum.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Nach Sparte --}}
    <div class="card card-flush">
        <div class="card-head-bar">Verträge nach Sparte</div>
        @php $maxProd = max(1, (int) ($byProduct->max() ?? 1)); @endphp
        <div style="padding:14px 20px;">
            @forelse($byProduct as $type => $count)
            @php $cfg = \App\Models\Contract::TYPES[$type] ?? \App\Models\Contract::LEGACY_TYPES[$type] ?? \App\Models\Contract::TYPES['andere']; @endphp
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                <div style="width:190px;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $cfg['icon'] }} {{ $cfg['label'] }}</div>
                <div style="flex:1;" class="pv-bar"><span style="width:{{ round($count / $maxProd * 100) }}%;"></span></div>
                <div style="width:36px;text-align:right;font-weight:700;font-size:13px;">{{ $count }}</div>
            </div>
            @empty
            <div style="text-align:center;padding:20px;color:var(--ink-soft);">Keine Verträge im Zeitraum.</div>
            @endforelse
        </div>
    </div>
</div>

<div class="grid-2" style="align-items:start;">
    {{-- Monatsvergleich --}}
    <div class="card card-flush">
        <div class="card-head-bar">Monatsvergleich (6 Monate)</div>
        @php
            $maxMV = max(1, collect($monthly)->max('vertraege'));
            $maxMN = max(0.01, collect($monthly)->max('netto'));
        @endphp
        <table>
            <thead><tr style="background:#F8F9FA;">
                <th style="padding:10px 20px;">Monat</th>
                <th>Verträge</th>
                <th style="padding-right:20px;">Provisionen (Netto)</th>
            </tr></thead>
            <tbody>
            @foreach($monthly as $m)
            <tr>
                <td style="padding:10px 20px;font-weight:600;white-space:nowrap;">{{ $m['label'] }}</td>
                <td style="width:35%;">
                    {{ $m['vertraege'] }}
                    <div class="pv-bar"><span style="width:{{ round($m['vertraege'] / $maxMV * 100) }}%;"></span></div>
                </td>
                <td style="padding-right:20px;width:35%;">
                    {{ number_format($m['netto'], 2, ',', '.') }} €
                    <div class="pv-bar pv-bar-gold"><span style="width:{{ $m['netto'] > 0 ? round($m['netto'] / $maxMN * 100) : 0 }}%;"></span></div>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    {{-- Tagesproduktivitaet --}}
    <div class="card">
        <div class="card-title" style="margin-bottom:12px;">Tagesproduktivität (Verträge je Tag)</div>
        @php $maxDay = max(1, $daily === [] ? 1 : max($daily)); @endphp
        @if($daily === [])
        <div style="text-align:center;padding:20px;color:var(--ink-soft);">Keine Verträge im Zeitraum.</div>
        @else
        <div style="display:flex;align-items:flex-end;gap:3px;height:120px;">
            @foreach($daily as $day => $count)
            <div title="{{ \Carbon\Carbon::parse($day)->format('d.m.Y') }}: {{ $count }} Vertraege" style="flex:1;min-width:6px;background:linear-gradient(180deg,var(--emerald-bright),var(--emerald-deep));border-radius:4px 4px 0 0;height:{{ max(8, round($count / $maxDay * 100)) }}%;"></div>
            @endforeach
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--ink-soft);margin-top:6px;">
            <span>{{ \Carbon\Carbon::parse(array_key_first($daily))->format('d.m.') }}</span>
            <span>{{ \Carbon\Carbon::parse(array_key_last($daily))->format('d.m.') }}</span>
        </div>
        @endif
    </div>
</div>
@include('admin.partials.provision_styles')
@endsection
