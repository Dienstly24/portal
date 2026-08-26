@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="page-title">💶 Interne Provisionen</div>
    <div class="page-sub">
        Provisionsdaten aus Fremdsystemen, gebunden an den Vertrag im Portal.
        <b>Intern und vertraulich</b> – diese Angaben erreichen den Kunden nirgends.
    </div>
</div>

@include('admin.commissions_internal._tabs', ['active' => 'liste'])
@include('admin.commissions_internal._flash')

@if($draftCount > 0)
<div style="background:#FEF3C7;border:1px solid #E8C36A;border-radius:10px;padding:12px 16px;margin-bottom:20px;max-width:1200px;font-size:13px;">
    ⏳ <b>{{ $draftCount }}</b> Import-{{ $draftCount === 1 ? 'Entwurf wartet' : 'Entwürfe warten' }} auf Bestätigung –
    solange ist davon <b>nichts übernommen</b>.
    <a href="{{ route('admin.commissions_internal.import') }}">Zur Import-Übersicht</a>
</div>
@endif

{{-- Kennzahlen folgen der FILTERUNG: eine Summe, die etwas anderes zaehlt
     als die Liste darunter, stiftet mehr Verwirrung als Nutzen. --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:20px;max-width:1200px;">
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11.5px;color:var(--ink-soft);">Datensätze (gefiltert)</div>
        <div style="font-size:22px;font-weight:700;">{{ number_format((int) ($totals->anzahl ?? 0), 0, ',', '.') }}</div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11.5px;color:var(--ink-soft);">Summe</div>
        <div style="font-size:22px;font-weight:700;">{{ number_format((float) ($totals->summe ?? 0), 2, ',', '.') }} €</div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11.5px;color:var(--ink-soft);">Davon noch offen</div>
        <div style="font-size:22px;font-weight:700;color:#B5651D;">{{ number_format((float) ($totals->offen ?? 0), 2, ',', '.') }} €</div>
    </div>
    <div class="card" style="padding:14px 16px;">
        <div style="font-size:11.5px;color:var(--ink-soft);">Ohne Vertrag</div>
        <div style="font-size:22px;font-weight:700;color:{{ $unmatchedCount > 0 ? '#A32D2D' : 'inherit' }};">{{ $unmatchedCount }}</div>
        @if($unmatchedCount > 0)
        <a href="{{ route('admin.commissions_internal.index', ['zuordnung' => 'offen']) }}" style="font-size:11.5px;">anzeigen →</a>
        @endif
    </div>
</div>

{{-- Filter als GET-Formular: der Stand ist teilbar und zurueck-tauglich
     (dieselbe Regel wie in der Vertragsliste). --}}
<div class="card" style="max-width:1200px;">
    <form method="GET" action="{{ route('admin.commissions_internal.index') }}"
          style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">
        <div class="field" style="margin:0;">
            <label>Suche</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
                   placeholder="Interne Vertragsnummer, Kunde, Empfänger, Rechnung…">
        </div>
        <div class="field" style="margin:0;">
            <label>Status</label>
            <select name="status">
                <option value="">Alle</option>
                @foreach($statuses as $key => $meta)
                <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0;">
            <label>Provisionsempfänger</label>
            <input type="text" name="empfaenger" value="{{ $filters['empfaenger'] ?? '' }}">
        </div>
        <div class="field" style="margin:0;">
            <label>Provisionsdatum von</label>
            <input type="date" name="von" value="{{ $filters['von'] ?? '' }}">
        </div>
        <div class="field" style="margin:0;">
            <label>bis</label>
            <input type="date" name="bis" value="{{ $filters['bis'] ?? '' }}">
        </div>
        <div class="field" style="margin:0;">
            <label>Zuordnung</label>
            <select name="zuordnung">
                <option value="">Alle</option>
                <option value="offen" @selected(($filters['zuordnung'] ?? '') === 'offen')>Nur ohne Vertrag</option>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary">Filtern</button>
            <a href="{{ route('admin.commissions_internal.index') }}" class="btn">Zurücksetzen</a>
        </div>
    </form>
    <div style="margin-top:12px;font-size:12.5px;">
        <a href="{{ route('admin.commissions_internal.export', request()->query()) }}">⬇ Gefilterte Liste als CSV exportieren</a>
        <span style="color:var(--ink-soft);"> – jeder Export wird protokolliert.</span>
    </div>
</div>

<div class="card" style="max-width:1200px;">
    @if($commissions->isEmpty())
        <div style="font-size:13px;color:var(--ink-soft);">Keine Provisionen zu dieser Auswahl.</div>
    @else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Interne Vertragsnr.</th>
                <th style="padding:8px;">Kunde / Vertrag</th>
                <th style="padding:8px;">Empfänger</th>
                <th style="padding:8px;">Art / Produkt</th>
                <th style="padding:8px;text-align:right;">Betrag</th>
                <th style="padding:8px;">Provisionsdatum</th>
                <th style="padding:8px;">Fällig</th>
                <th style="padding:8px;">Bezahlt</th>
                <th style="padding:8px;">Status</th>
                <th style="padding:8px;">Quelle</th>
                <th style="padding:8px;">Geändert</th>
            </tr></thead>
            <tbody>
            @foreach($commissions as $c)
                <tr style="border-top:1px solid var(--line);vertical-align:top;">
                    <td style="padding:8px;font-weight:600;">
                        <a href="{{ route('admin.commissions_internal.show', $c->id) }}">{{ $c->internal_contract_number ?: '—' }}</a>
                    </td>
                    <td style="padding:8px;">
                        @if($c->contract)
                            <a href="{{ route('admin.contract.edit', $c->contract->id) }}">{{ $c->customer_label ?: '—' }}</a>
                            <div style="color:var(--ink-soft);font-size:11.5px;">{{ $c->contract_label }}</div>
                        @else
                            <span style="color:#A32D2D;">⚠ Nicht zugeordnet</span>
                            <div style="color:var(--ink-soft);font-size:11.5px;">{{ $c->customer_label ?: 'aus Datei: kein Name' }}</div>
                        @endif
                    </td>
                    <td style="padding:8px;">{{ $c->recipient_name ?: '—' }}</td>
                    <td style="padding:8px;">
                        {{ $c->commission_type ?: '—' }}
                        <div style="color:var(--ink-soft);font-size:11.5px;">{{ $c->product_name }}{{ $c->company ? ' · ' . $c->company : '' }}</div>
                    </td>
                    <td style="padding:8px;text-align:right;font-weight:600;white-space:nowrap;">{{ $c->amountLabel() }}</td>
                    <td style="padding:8px;white-space:nowrap;">{{ $c->commission_date?->format('d.m.Y') ?: '—' }}</td>
                    <td style="padding:8px;white-space:nowrap;">{{ $c->due_date?->format('d.m.Y') ?: '—' }}</td>
                    <td style="padding:8px;white-space:nowrap;">{{ $c->payment_date?->format('d.m.Y') ?: '—' }}</td>
                    <td style="padding:8px;"><span class="badge badge-{{ $c->statusBadge() }}">{{ $c->statusIcon() }} {{ $c->statusLabel() }}</span></td>
                    <td style="padding:8px;color:var(--ink-soft);font-size:11.5px;">{{ $c->source_file ?: '—' }}</td>
                    <td style="padding:8px;color:var(--ink-soft);font-size:11.5px;white-space:nowrap;">{{ $c->updated_at?->lokal()->format('d.m.Y H:i') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px;">{{ $commissions->links() }}</div>
    @endif
</div>
@endsection
