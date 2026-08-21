@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.provisions') }}">Vermittler-Provisionen</a><span class="breadcrumb-sep">›</span><span>Buchung</span></div>
    <div class="page-title" style="display:flex;align-items:center;gap:12px;">
        {{ $provision->typeLabel() }} über {{ number_format((float) $provision->amount, 2, ',', '.') }} €
        <span class="wb-badge {{ ['offen' => 'wb-offen', 'freigegeben' => 'wb-frei', 'ausgezahlt' => 'wb-mit', 'storniert' => 'wb-storno'][$provision->status] ?? 'wb-none' }}">{{ $provision->statusLabel() }}</span>
    </div>
    <div class="page-sub">Empfänger: {{ $provision->recipientName() }} · erfasst am {{ $provision->created_at->lokal()->format('d.m.Y H:i') }} von {{ $provision->creator?->name ?? 'System' }}</div>
</div>

@if(session('success'))<div style="background:#D9F4E6;color:#17A65B;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('error') }}</div>@endif

<div class="grid-2" style="align-items:start;">
    <div>
        {{-- Stammdaten der Buchung --}}
        <div class="card" style="margin-bottom:20px;">
            <div class="card-title" style="margin-bottom:12px;">Buchungsdaten</div>
            <table style="font-size:13.5px;">
                <tr><td style="color:var(--ink-soft);padding:6px 0;width:160px;">Empfänger</td>
                    <td style="font-weight:600;">{{ $provision->user_id ? '👤 ' : '🤝 ' }}{{ $provision->recipientName() }}</td></tr>
                <tr><td style="color:var(--ink-soft);padding:6px 0;">Kunde</td>
                    <td>@if($provision->customer)<a href="{{ route('admin.customer', $provision->customer_id) }}">{{ $provision->customer->user?->name ?? $provision->customer->customer_number }}</a>@else — @endif</td></tr>
                <tr><td style="color:var(--ink-soft);padding:6px 0;">Vertrag</td>
                    <td>
                        @if($provision->contract)
                        <a href="{{ route('admin.contract.edit', $provision->contract_id) }}">{{ $provision->contract->typeLabel() }}{{ $provision->contract->contract_number ? ' · Nr. ' . $provision->contract->contract_number : '' }}</a>
                        @elseif($provision->contract_type)
                        {{ $provision->contractTypeLabel() }} <span style="color:var(--ink-soft);font-size:12px;">(Vertrag inzwischen gelöscht)</span>
                        @else — @endif
                    </td></tr>
                <tr><td style="color:var(--ink-soft);padding:6px 0;">Sparte / Produkt</td>
                    <td>{{ $provision->contractTypeLabel() ?? '—' }}</td></tr>
                <tr><td style="color:var(--ink-soft);padding:6px 0;">Gesellschaft</td>
                    <td>{{ $provision->insurer ?? '—' }}</td></tr>
                <tr><td style="color:var(--ink-soft);padding:6px 0;">Betrag</td>
                    <td style="font-weight:700;color:{{ $provision->isDeduction() ? '#A32D2D' : 'inherit' }};">{{ number_format((float) $provision->amount, 2, ',', '.') }} {{ $provision->currency }}</td></tr>
                <tr><td style="color:var(--ink-soft);padding:6px 0;">Notiz</td>
                    <td>{{ $provision->note ?? '—' }}</td></tr>
                @if($provision->approved_at)
                <tr><td style="color:var(--ink-soft);padding:6px 0;">Freigegeben</td>
                    <td>{{ $provision->approved_at->lokal()->format('d.m.Y H:i') }} · {{ $provision->approver?->name ?? '—' }}</td></tr>
                @endif
                @if($provision->paid_at)
                <tr><td style="color:var(--ink-soft);padding:6px 0;">Ausgezahlt</td>
                    <td>{{ $provision->paid_at->lokal()->format('d.m.Y H:i') }} · {{ $provision->payer?->name ?? '—' }}</td></tr>
                @endif
                @if($provision->relatedProvision)
                <tr><td style="color:var(--ink-soft);padding:6px 0;">Gegenbuchung zu</td>
                    <td><a href="{{ route('admin.provisions.show', $provision->related_provision_id) }}">Original über {{ number_format((float) $provision->relatedProvision->amount, 2, ',', '.') }} €</a></td></tr>
                @endif
                @foreach($provision->counterBookings as $cb)
                <tr><td style="color:var(--ink-soft);padding:6px 0;">Gegenbuchung</td>
                    <td><a href="{{ route('admin.provisions.show', $cb->id) }}" style="color:#A32D2D;">{{ $cb->typeLabel() }} über {{ number_format((float) $cb->amount, 2, ',', '.') }} €</a></td></tr>
                @endforeach
            </table>
        </div>

        {{-- Aktionen: Status + Betrag --}}
        <div class="card" style="margin-bottom:20px;">
            <div class="card-title" style="margin-bottom:12px;">Aktionen</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
                @if($provision->status === 'offen')
                <form method="POST" action="{{ route('admin.provisions.status', $provision->id) }}" style="margin:0;">
                    @csrf<input type="hidden" name="status" value="freigegeben">
                    <button type="submit" class="btn btn-primary btn-sm">Freigeben</button>
                </form>
                @endif
                @if(in_array($provision->status, ['offen', 'freigegeben'], true))
                <form method="POST" action="{{ route('admin.provisions.status', $provision->id) }}" style="margin:0;" onsubmit="return confirm('Buchung als ausgezahlt markieren?');">
                    @csrf<input type="hidden" name="status" value="ausgezahlt">
                    <button type="submit" class="btn btn-gold btn-sm">Auszahlen</button>
                </form>
                <form method="POST" action="{{ route('admin.provisions.status', $provision->id) }}" style="margin:0;">
                    @csrf<input type="hidden" name="status" value="storniert">
                    <button type="submit" class="btn btn-ghost btn-sm">Stornieren</button>
                </form>
                @endif
                @if(in_array($provision->status, ['storniert', 'ausgezahlt'], true))
                <form method="POST" action="{{ route('admin.provisions.status', $provision->id) }}" style="margin:0;" onsubmit="return confirm('Buchung wieder oeffnen? Freigabe und Auszahlung werden zurueckgesetzt.');">
                    @csrf<input type="hidden" name="status" value="offen">
                    <button type="submit" class="btn btn-ghost btn-sm">Wieder öffnen (Korrektur)</button>
                </form>
                @endif
            </div>

            @if($provision->isAmountAdjustable())
            <form method="POST" action="{{ route('admin.provisions.amount', $provision->id) }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0;border-top:1px solid var(--line);padding-top:14px;">
                @csrf
                <div class="flt-group">
                    <label class="flt-lbl">Neuer Betrag (EUR)</label>
                    <input type="number" name="amount" step="0.01" value="{{ $provision->amount }}" required style="width:130px;padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                </div>
                <div class="flt-group" style="flex:1;min-width:220px;">
                    <label class="flt-lbl">Grund (Pflicht, wird protokolliert)</label>
                    <input type="text" name="grund" maxlength="500" required placeholder="z. B. Korrektur Jahresbeitrag" style="width:100%;padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Betrag anpassen</button>
            </form>
            @else
            <div style="font-size:12.5px;color:var(--ink-soft);border-top:1px solid var(--line);padding-top:14px;">Ausgezahlte oder stornierte Buchungen können nicht mehr angepasst werden (Finanzhistorie).</div>
            @endif
        </div>
    </div>

    {{-- Audit-Log --}}
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);">Änderungsprotokoll (Audit-Log)</div>
        <table>
            <thead><tr style="background:#F8F9FA;">
                <th style="padding:10px 16px;">Wann</th>
                <th>Wer</th>
                <th>Aktion</th>
                <th>Alt → Neu</th>
                <th style="padding-right:16px;">Grund</th>
            </tr></thead>
            <tbody>
            @forelse($provision->auditLogs as $log)
            <tr>
                <td style="padding:10px 16px;font-size:12.5px;white-space:nowrap;">{{ $log->created_at->lokal()->format('d.m.Y H:i') }}</td>
                <td style="font-size:12.5px;">{{ $log->user?->name ?? 'System' }}</td>
                <td><span class="wb-badge wb-none">{{ $log->actionLabel() }}</span></td>
                <td style="font-size:12.5px;">
                    @if($log->old_value !== null || $log->new_value !== null)
                    {{ $log->old_value ?? '—' }} → <strong>{{ $log->new_value ?? '—' }}</strong>
                    @else — @endif
                </td>
                <td style="font-size:12.5px;color:var(--ink-soft);padding-right:16px;max-width:220px;">{{ $log->reason ?? '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--ink-soft);">Keine Einträge.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@include('admin.partials.provision_styles')
@endsection
