{{-- Vermittler / Abrechnung: kleine Box in der Vertragsakte
     (Betreiber-Auftrag 20.08.2026). Sie beantwortet genau drei Fragen:
     Unter welcher Nummer kennt der Vermittler diesen Vertrag? Hat er ihn
     abgerechnet? Und wann haben wir das zuletzt geprueft?
     Die Box ist REIN LESEND - gepflegt wird im Vertragsformular darunter. --}}
@php
    $vStatus = $contract->vermittlerStatus();
    $vLast = $contract->vermittlerSettlements()->first();
    $vEvents = $contract->vermittlerEvents()->with('user')->get();
@endphp
<div class="card" style="max-width:980px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <div style="font-weight:700;font-size:14px;">🤝 Vermittler / Abrechnung</div>
        <span class="badge badge-{{ $contract->vermittlerStatusBadge() }} nowrap">
            {{ $contract->vermittlerStatusIcon() }} {{ $contract->vermittlerStatusLabel() }}
        </span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;font-size:13px;">
        <div>
            <div class="muted-2xs">Referenz-Nr.</div>
            <div style="font-weight:600;">{{ $contract->reference_number ?: '—' }}</div>
        </div>
        <div>
            <div class="muted-2xs">Vermittler-ID</div>
            <div style="font-weight:600;">{{ $contract->vermittler_id ?: '—' }}</div>
        </div>
        <div>
            <div class="muted-2xs">Produkt (Abrechnung)</div>
            <div style="font-weight:600;">{{ $vLast?->produkt ?: '—' }}</div>
        </div>
        <div>
            <div class="muted-2xs">Provision</div>
            <div style="font-weight:600;">
                @if($vLast && $vLast->provision !== null)
                    {{ number_format((float) $vLast->provision, 2, ',', '.') }} €
                    @if($vLast->isStorno())<span style="color:#A32D2D;font-weight:600;"> (storniert)</span>@endif
                @else — @endif
            </div>
        </div>
        <div>
            <div class="muted-2xs">Letzter Abgleich</div>
            <div style="font-weight:600;">{{ $contract->vermittler_last_imported_at?->lokal()->format('d.m.Y') ?: '—' }}</div>
        </div>
    </div>

    @if($vStatus === \App\Models\Contract::VERMITTLER_STORNIERT && $vLast?->storno_reason)
    <div style="margin-top:14px;background:#F9E3E3;border:1px solid #F0A0A0;border-radius:8px;padding:10px 12px;font-size:12.5px;">
        <b>Stornogrund des Vermittlers:</b> {{ $vLast->storno_reason }}
        <div style="color:var(--ink-soft);margin-top:4px;">Der Vertrag selbst bleibt unverändert erhalten – storniert ist die Abrechnung, nicht zwingend der Vertrag.</div>
    </div>
    @elseif($vStatus === \App\Models\Contract::VERMITTLER_PRUEFUNG)
    <div style="margin-top:14px;background:#FEF3C7;border:1px solid #E8C36A;border-radius:8px;padding:10px 12px;font-size:12.5px;">
        <b>⚠ Prüfung erforderlich.</b> {{ $vLast?->match_note ?: 'Die Angaben aus der Abrechnung widersprechen den erfassten Daten.' }}
        Es wurde bewusst nichts automatisch geändert – bitte in der
        <a href="{{ route('admin.vermittler.review') }}">Prüfliste</a> entscheiden.
    </div>
    @elseif($vStatus === \App\Models\Contract::VERMITTLER_NICHT_GEFUNDEN)
    <div style="margin-top:14px;background:#FEF3C7;border:1px solid #E8C36A;border-radius:8px;padding:10px 12px;font-size:12.5px;">
        <b>❓ Nicht in der Abrechnung gefunden.</b> Dieser Vertrag stand in der zuletzt eingelesenen Abrechnung nicht.
        Das heißt weder „storniert" noch „gelöscht" – es kann auch schlicht sein, dass er noch nicht abgerechnet wurde.
    </div>
    @endif

    @if($vLast || $vEvents->isNotEmpty())
    <details style="margin-top:14px;">
        <summary style="cursor:pointer;font-size:12.5px;font-weight:600;">Abrechnungsdetails anzeigen</summary>

        @if($vLast)
        <div style="margin-top:10px;overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
                <thead><tr style="text-align:left;color:var(--ink-soft);">
                    <th style="padding:6px 8px;">Datum</th><th style="padding:6px 8px;">Produkt</th>
                    <th style="padding:6px 8px;">Status</th><th style="padding:6px 8px;">Provision</th>
                    <th style="padding:6px 8px;">Import</th>
                </tr></thead>
                <tbody>
                @foreach($contract->vermittlerSettlements()->with('import')->limit(10)->get() as $row)
                    <tr style="border-top:1px solid var(--line);">
                        <td style="padding:6px 8px;">{{ $row->statement_date?->format('d.m.Y') ?: '—' }}</td>
                        <td style="padding:6px 8px;">{{ $row->produkt ?: '—' }}</td>
                        <td style="padding:6px 8px;">{{ $row->statusLabel() }}{{ $row->storno_reason ? ' – ' . $row->storno_reason : '' }}</td>
                        <td style="padding:6px 8px;">{{ $row->provision !== null ? number_format((float) $row->provision, 2, ',', '.') . ' €' : '—' }}</td>
                        <td style="padding:6px 8px;color:var(--ink-soft);">{{ $row->import?->filename ?: '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @if($vEvents->isNotEmpty())
        <div style="margin-top:14px;font-size:12.5px;">
            <div style="font-weight:600;margin-bottom:6px;">Historie der Zuordnung</div>
            @foreach($vEvents as $event)
            <div style="border-left:2px solid var(--line);padding:4px 0 4px 10px;margin-bottom:4px;">
                <span class="muted">{{ $event->created_at?->lokal()->format('d.m.Y H:i') }}</span>
                · <b>{{ $event->actionLabel() }}</b>
                @if($event->detail) · {{ $event->detail }} @endif
                @if($event->user) · {{ $event->user->name }} @endif
            </div>
            @endforeach
        </div>
        @endif
    </details>
    @endif
</div>
