{{-- Interne Provisionen zu diesem Vertrag (Betreiber-Auftrag 26.08.2026).

     VERTRAULICH: Diese Box gehoert AUSSCHLIESSLICH in die Beraterwelt und
     wird zusaetzlich hier am Recht `provisionen-verwalten` geprueft - nicht
     nur an der Route. Eine Box, die nur deshalb unsichtbar ist, weil sie in
     einer Admin-View liegt, waere beim naechsten Umbau versehentlich in
     einer anderen View wieder da.

     Die Daten werden erst INNERHALB der Pruefung geladen: wer sie nicht
     sehen darf, loest die Abfrage gar nicht erst aus. --}}
@can('provisionen-verwalten')
@php
    $commissions = $contract->commissions()->limit(20)->get();
    $commissionTotal = (float) $contract->commissions()->sum('amount');
    $commissionOpen = (float) $contract->commissions()
        ->whereIn('status', [\App\Support\CommissionStatus::OFFEN, \App\Support\CommissionStatus::FAELLIG, \App\Support\CommissionStatus::TEILWEISE])
        ->sum('amount');
@endphp
<div class="card" style="max-width:980px;border-left:3px solid #B8A16B;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
        <div style="font-weight:700;font-size:14px;">💶 Provisionen <span style="font-weight:500;color:var(--ink-soft);font-size:12px;">· intern</span></div>
        <a href="{{ route('admin.commissions_internal.index', ['vertrag' => $contract->id]) }}" style="font-size:12.5px;">alle anzeigen →</a>
    </div>
    <div style="font-size:11.5px;color:var(--ink-soft);margin-bottom:14px;">
        Nur für Berechtigte sichtbar. Diese Angaben erscheinen <b>nirgends</b> im Kundenportal,
        in Kunden-E-Mails oder in Dokumenten, die der Kunde erhält.
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;font-size:13px;margin-bottom:14px;">
        <div>
            <div style="font-size:11.5px;color:var(--ink-soft);">Interne Vertragsnummer</div>
            <div style="font-weight:600;">{{ $contract->internal_contract_number ?: '—' }}</div>
        </div>
        <div>
            <div style="font-size:11.5px;color:var(--ink-soft);">Provision gesamt</div>
            <div style="font-weight:600;">{{ number_format($commissionTotal, 2, ',', '.') }} €</div>
        </div>
        <div>
            <div style="font-size:11.5px;color:var(--ink-soft);">Davon offen</div>
            <div style="font-weight:600;color:{{ $commissionOpen > 0 ? '#B5651D' : 'inherit' }};">{{ number_format($commissionOpen, 2, ',', '.') }} €</div>
        </div>
        <div>
            <div style="font-size:11.5px;color:var(--ink-soft);">Datensätze</div>
            <div style="font-weight:600;">{{ $commissions->count() }}</div>
        </div>
    </div>

    @if($commissions->isEmpty())
        <div style="font-size:12.5px;color:var(--ink-soft);">
            Zu diesem Vertrag liegt keine Provision vor.
            @if(blank($contract->internal_contract_number))
            Ohne <b>interne Vertragsnummer</b> findet ein Import ihn nur über Referenz-Nr., Vermittler-Id oder
            Auftr.-Nr. – die Nummer lässt sich im Vertragsformular nachtragen.
            @endif
        </div>
    @else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Art</th><th style="padding:8px;">Empfänger</th>
                <th style="padding:8px;text-align:right;">Betrag</th><th style="padding:8px;">Datum</th>
                <th style="padding:8px;">Fällig</th><th style="padding:8px;">Bezahlt</th>
                <th style="padding:8px;">Status</th><th style="padding:8px;">Rechnung</th>
                <th style="padding:8px;">Quelle</th><th style="padding:8px;"></th>
            </tr></thead>
            <tbody>
            @foreach($commissions as $c)
            <tr style="border-top:1px solid var(--line);">
                <td style="padding:8px;">{{ $c->commission_type ?: '—' }}</td>
                <td style="padding:8px;">{{ $c->recipient_name ?: '—' }}</td>
                <td style="padding:8px;text-align:right;font-weight:600;white-space:nowrap;">{{ $c->amountLabel() }}</td>
                <td style="padding:8px;white-space:nowrap;">{{ $c->commission_date?->format('d.m.Y') ?: '—' }}</td>
                <td style="padding:8px;white-space:nowrap;">{{ $c->due_date?->format('d.m.Y') ?: '—' }}</td>
                <td style="padding:8px;white-space:nowrap;">{{ $c->payment_date?->format('d.m.Y') ?: '—' }}</td>
                <td style="padding:8px;"><span class="badge badge-{{ $c->statusBadge() }}">{{ $c->statusLabel() }}</span></td>
                <td style="padding:8px;">{{ $c->invoice_number ?: '—' }}</td>
                <td style="padding:8px;color:var(--ink-soft);font-size:11.5px;">{{ $c->source_file ?: '—' }}</td>
                <td style="padding:8px;"><a href="{{ route('admin.commissions_internal.show', $c->id) }}">öffnen →</a></td>
            </tr>
            @if($c->notes)
            <tr><td colspan="10" style="padding:0 8px 8px;color:var(--ink-soft);font-size:11.5px;">Notiz: {{ $c->notes }}</td></tr>
            @endif
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endcan
