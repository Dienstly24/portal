@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.provisions') }}">Vermittler-Provisionen</a><span class="breadcrumb-sep">›</span><span>Monatsbericht</span></div>
    <div class="page-title">Provisions-Monatsbericht</div>
    <div class="page-sub">Je Mitarbeiter und Partner: Neukunden, Verträge nach Sparte, Provision, Abzüge und Netto - exportierbar als Excel und PDF.</div>
</div>

@include('admin.partials.provision_tabs', ['active' => 'bericht'])

{{-- Zeitraum + Export --}}
<div class="card" style="margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    @if($month)
    @php
        $prev = $month->copy()->subMonth()->format('Y-m');
        $next = $month->copy()->addMonth()->format('Y-m');
        $isCurrent = $month->isSameMonth(now());
    @endphp
    <div style="display:flex;align-items:center;gap:10px;">
        <a href="{{ route('admin.provisions.report', ['monat' => $prev]) }}" class="btn btn-ghost btn-sm" title="Vormonat">←</a>
        <div style="font-size:16px;font-weight:700;min-width:150px;text-align:center;">{{ $month->locale('de')->translatedFormat('F Y') }}</div>
        <a href="{{ route('admin.provisions.report', ['monat' => $next]) }}" class="btn btn-ghost btn-sm {{ $isCurrent ? 'disabled' : '' }}" title="Folgemonat" @if($isCurrent) style="pointer-events:none;opacity:.4;" @endif>→</a>
        @if(!$isCurrent)<a href="{{ route('admin.provisions.report') }}" class="btn btn-ghost btn-sm">Aktueller Monat</a>@endif
    </div>
    @else
    <div style="font-size:15px;font-weight:700;">
        Zeitraum: {{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }}
        <a href="{{ route('admin.provisions.report') }}" class="btn btn-ghost btn-sm" style="margin-left:8px;">Zurück zum Monat</a>
    </div>
    @endif
    <form method="GET" action="{{ route('admin.provisions.report') }}" style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin:0;">
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
    @php $exportParams = $month ? ['monat' => $month->format('Y-m')] : ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]; @endphp
    <div style="display:flex;gap:8px;margin-left:auto;">
        <a href="{{ route('admin.provisions.report.export', $exportParams + ['format' => 'xlsx']) }}" class="btn btn-emerald btn-sm">📊 Excel</a>
        <a href="{{ route('admin.provisions.report.export', $exportParams + ['format' => 'csv']) }}" class="btn btn-ghost btn-sm">CSV</a>
        <a href="{{ route('admin.provisions.report.export', $exportParams + ['format' => 'pdf']) }}" target="_blank" class="btn btn-ghost btn-sm">🖨️ PDF / Drucken</a>
    </div>
</div>

{{-- Summen --}}
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px;">
    <div class="metric-card">
        <div class="metric-icon icon-green">🆕</div>
        <div class="metric-label">Neukunden</div>
        <div class="metric-value">{{ $summary['kunden'] }}</div>
        <div class="metric-sub">mit Werber</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-blue">✍️</div>
        <div class="metric-label">Neue Verträge</div>
        <div class="metric-value">{{ $summary['vertraege'] }}</div>
        <div class="metric-sub">mit Werber</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-green">💶</div>
        <div class="metric-label">Provision</div>
        <div class="metric-value" style="font-size:22px;">{{ number_format($summary['provision'], 2, ',', '.') }} €</div>
        <div class="metric-sub">Positive Buchungen</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-red">↩️</div>
        <div class="metric-label">Abzüge</div>
        <div class="metric-value" style="font-size:22px;">{{ number_format($summary['abzuege'], 2, ',', '.') }} €</div>
        <div class="metric-sub">Storni &amp; Kürzungen</div>
    </div>
    <div class="metric-card">
        <div class="metric-icon icon-amber">Σ</div>
        <div class="metric-label">Netto</div>
        <div class="metric-value" style="font-size:22px;">{{ number_format($summary['netto'], 2, ',', '.') }} €</div>
        <div class="metric-sub">Provision + Abzüge</div>
    </div>
</div>

{{-- Tabelle je Empfaenger --}}
<div class="card card-flush">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 20px;">Empfänger</th>
            <th>Neukunden</th>
            <th>Verträge</th>
            <th>Verträge je Sparte</th>
            <th>Provision</th>
            <th>Abzüge</th>
            <th style="padding-right:20px;">Netto</th>
        </tr></thead>
        <tbody>
        @forelse($rows as $r)
        <tr>
            <td style="padding:12px 20px;">
                <span class="wb-badge {{ $r['kind'] === 'partner' ? 'wb-par' : 'wb-mit' }}">{{ $r['kind'] === 'partner' ? '🤝' : '👤' }}</span>
                <strong>{{ $r['label'] }}</strong>
            </td>
            <td>{{ $r['kunden'] }}</td>
            <td>{{ $r['vertraege'] }}</td>
            <td style="max-width:280px;">
                @foreach($r['sparten'] as $type => $count)
                <span class="wb-badge wb-none" style="margin:1px 2px;">{{ $type !== '' ? (\App\Models\Contract::TYPES[$type]['label'] ?? $type) : 'Ohne' }} ×{{ $count }}</span>
                @endforeach
            </td>
            <td style="font-weight:600;">{{ number_format($r['provision'], 2, ',', '.') }} €</td>
            <td style="color:#A32D2D;">{{ $r['abzuege'] != 0 ? number_format($r['abzuege'], 2, ',', '.') . ' €' : '—' }}</td>
            <td style="padding-right:20px;font-weight:700;">{{ number_format($r['netto'], 2, ',', '.') }} €</td>
        </tr>
        @empty
        <tr><td colspan="7" style="text-align:center;padding:36px;color:var(--ink-soft);">Keine Aktivität im Zeitraum.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@include('admin.partials.provision_styles')
@endsection
