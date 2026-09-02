@extends('layouts.admin')
@section('content')
@include('admin.provisionsmanagement._layout', ['active' => 'fehlende', 'titel' => 'Fehlende Provisionen',
    'untertitel' => 'Verträge, deren Provision überfällig ist oder ausgeblieben – mit Bearbeitungsstand.'])

<div class="card" style="max-width:1250px;margin-bottom:16px;">
    <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">
        <div class="field" style="margin:0;">
            <label>Pool</label>
            <select name="pool"><option value="">Alle</option>
                @foreach($poolListe as $key => $pool)
                <option value="{{ $key }}" @selected(($filters['pool'] ?? '') === $key)>{{ $pool->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0;">
            <label>Zustand</label>
            <select name="status"><option value="">Überfällig + fehlend + Prüfung</option>
                @foreach(\App\Support\ContractCommissionStatus::ALL as $key => $info)
                <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $info['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0;">
            <label>Abschlussmonat</label>
            <input type="month" name="monat" value="{{ $filters['monat'] ?? '' }}">
        </div>
        <div class="field" style="margin:0;">
            <label>Gesellschaft / Produkt</label>
            <input type="text" name="produkt" value="{{ $filters['produkt'] ?? '' }}">
        </div>
        <div class="field" style="margin:0;">
            <label>Werber (Mitarbeiter)</label>
            <select name="mitarbeiter"><option value="">Alle</option>
                @foreach($mitarbeiter as $m)
                <option value="{{ $m->id }}" @selected((string) ($filters['mitarbeiter'] ?? '') === (string) $m->id)>{{ $m->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0;">
            <label>Kunde</label>
            <input type="text" name="kunde" value="{{ $filters['kunde'] ?? '' }}" placeholder="Name oder Kundennummer">
        </div>
        <button class="btn" type="submit">Filtern</button>
    </form>
</div>

<div class="card" style="max-width:1250px;">
    <div style="font-size:13px;margin-bottom:10px;">
        <b>{{ $liste->total() }}</b> {{ $liste->total() === 1 ? 'Vertrag' : 'Verträge' }} ohne erwartete Provision.
    </div>
    <table class="table" style="font-size:13px;">
        <tr><th>Kunde</th><th>Vertrag</th><th>Pool</th><th>Produkt</th><th>Abschluss</th>
            <th style="text-align:right;">Monate</th><th>Erwartet</th><th>Zustand</th><th>Bearbeitung</th><th></th></tr>
        @forelse($liste as $vertrag)
        <tr>
            <td>{{ $vertrag->customer?->user?->name ?? '—' }}</td>
            <td>{{ $vertrag->contract_number ?: ($vertrag->reference_number ?: '—') }}</td>
            <td>{{ $poolListe[$vertrag->pool]->name ?? '—' }}</td>
            <td>{{ $vertrag->insurer }}</td>
            <td>{{ optional($vertrag->signing_date ?? $vertrag->application_date ?? $vertrag->start_date)->format('d.m.Y') ?? $vertrag->created_at?->lokal()->format('d.m.Y') }}</td>
            <td style="text-align:right;">{{ $monate->monthsSinceClosing($vertrag) ?? '—' }}</td>
            <td>{{ $vertrag->expected_commission_date?->format('d.m.Y') ?? '—' }}</td>
            <td><span class="badge badge-{{ $vertrag->commissionStatusBadge() }}">{{ $vertrag->commissionStatusLabel() }}</span></td>
            <td>{{ $vertrag->commissionFollowup?->statusLabel() ?? 'Offen' }}</td>
            <td><a href="{{ route('admin.provisionsmanagement.contract', $vertrag->id) }}">bearbeiten →</a></td>
        </tr>
        @empty
        <tr><td colspan="10" style="color:var(--ink-soft);">Nichts offen – jede erwartete Provision ist da.</td></tr>
        @endforelse
    </table>
    <div style="margin-top:14px;">{{ $liste->links() }}</div>
</div>
@endsection
