@extends('layouts.admin')
@section('content')
@include('admin.provisionsmanagement._layout', ['active' => 'vertraege', 'titel' => 'Verträge',
    'untertitel' => 'Jeder Vertrag mit seinem Provisions-Zustand: erwartet, erhalten, fehlt.'])

<div class="card" style="max-width:1250px;margin-bottom:16px;">
    <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end;">
        <div class="field" style="margin:0;">
            <label>Suche</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Vertragsnummer, Kunde, Gesellschaft…">
        </div>
        <div class="field" style="margin:0;">
            <label>Pool</label>
            <select name="pool">
                <option value="">Alle</option>
                @foreach($poolListe as $key => $pool)
                <option value="{{ $key }}" @selected(($filters['pool'] ?? '') === $key)>{{ $pool->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0;">
            <label>Provisions-Zustand</label>
            <select name="status">
                <option value="">Alle</option>
                @foreach(\App\Support\ContractCommissionStatus::ALL as $key => $info)
                <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $info['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn" type="submit">Filtern</button>
            <form method="POST" action="{{ route('admin.provisionsmanagement.recalculate') }}">@csrf
                <button class="btn" type="submit" title="Fristen und Zustände neu rechnen">Neu berechnen</button>
            </form>
        </div>
    </form>
</div>

<div class="card" style="max-width:1250px;">
    <table class="table" style="font-size:13px;">
        <tr><th>Vertrag</th><th>Kunde</th><th>Pool</th><th>Abschluss</th><th>Erwartet</th><th>Prüffrist</th>
            <th>Zustand</th><th class="num">Buchungen</th><th class="num">Netto</th><th></th></tr>
        @forelse($vertraege as $vertrag)
        <tr>
            <td>
                <b>{{ $vertrag->contract_number ?: ($vertrag->internal_contract_number ?: '—') }}</b>
                <div style="font-size:11px;color:var(--ink-soft);">{{ $vertrag->insurer }}</div>
            </td>
            <td>{{ $vertrag->customer?->user?->name ?? '—' }}</td>
            <td>{{ $poolListe[$vertrag->pool]->name ?? '—' }}</td>
            <td>{{ optional($vertrag->signing_date ?? $vertrag->application_date ?? $vertrag->start_date)->format('d.m.Y') ?? $vertrag->created_at?->lokal()->format('d.m.Y') }}</td>
            <td>{{ $vertrag->expected_commission_date?->format('d.m.Y') ?? '—' }}</td>
            <td>{{ $vertrag->commission_check_date?->format('d.m.Y') ?? '—' }}</td>
            <td><span class="badge badge-{{ $vertrag->commissionStatusBadge() }}">{{ $vertrag->commissionStatusLabel() }}</span></td>
            <td class="num">{{ $vertrag->commissions_count }}</td>
            <td class="num-strong">{{ number_format((float) ($vertrag->provision_netto ?? 0), 2, ',', '.') }} €</td>
            <td><a href="{{ route('admin.provisionsmanagement.contract', $vertrag->id) }}">öffnen →</a></td>
        </tr>
        @empty
        <tr><td colspan="10" class="muted">Kein Vertrag im Provisionsmanagement. Verträge kommen hier an, sobald sie einem Pool zugeordnet sind oder eine Provision tragen.</td></tr>
        @endforelse
    </table>
    <div style="margin-top:14px;">{{ $vertraege->links() }}</div>
</div>
@endsection
