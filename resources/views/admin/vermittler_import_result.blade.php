@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.vermittler.index') }}">Vermittler-Abrechnung</a><span class="breadcrumb-sep">›</span>
        <span>Import-Ergebnis</span>
    </div>
    <div class="page-title">Import abgeschlossen</div>
    <div class="page-sub">{{ $import->filename }} · {{ $import->created_at?->lokal()->format('d.m.Y H:i') }} · {{ $import->importer?->name ?? 'System' }}</div>
</div>

{{-- Zusammenfassung: ein Import endet nie ohne klares Ergebnis. --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;max-width:1100px;">
    @foreach([
        ['Gesamt', $import->rows_total, 'var(--graphite)', null],
        ['Erfolgreich zugeordnet', $import->rows_matched + $import->rows_new_link, 'var(--emerald-deep)', null],
        ['Davon neu verknüpft', $import->rows_new_link, '#185FA5', 'linked'],
        ['ID nicht gefunden', $import->rows_unmatched, '#B5651D', 'unmatched'],
        ['Prüfung erforderlich', $import->rows_review, '#A32D2D', 'review'],
        ['Storniert', $import->rows_storno, '#A32D2D', null],
        ['Bereits importiert', $import->rows_unchanged, '#5F5E5A', 'unchanged'],
    ] as [$label, $value, $color, $link])
    <div class="card" style="padding:14px 16px;">
        <div class="muted-2xs">{{ $label }}</div>
        <div style="font-size:22px;font-weight:700;color:{{ $color }};">
            @if($link && $value > 0)
                <a href="{{ route('admin.vermittler.show', ['id' => $import->id, 'ergebnis' => $link]) }}" style="color:inherit;">{{ $value }}</a>
            @else {{ $value }} @endif
        </div>
    </div>
    @endforeach
</div>

@if($import->rows_invalid > 0)
<div style="background:#FEF3C7;border:1px solid #E8C36A;border-radius:10px;padding:12px 14px;margin-bottom:16px;max-width:1100px;font-size:12.5px;">
    <b>{{ $import->rows_invalid }} Zeilen ohne Id</b> wurden nicht gespeichert – ohne die Id des Vermittlers lässt sich ein Datensatz weder zuordnen noch wiedererkennen.
</div>
@endif

@if($import->contracts_not_found > 0)
<div style="background:#FEF3C7;border:1px solid #E8C36A;border-radius:10px;padding:12px 14px;margin-bottom:16px;max-width:1100px;font-size:12.5px;">
    <b>{{ $import->contracts_not_found }} erfasste Verträge</b> kommen in dieser Abrechnung nicht vor und stehen jetzt auf
    „Nicht in Abrechnung gefunden". Die Verträge selbst wurden <b>nicht verändert</b>.
    <a href="{{ route('admin.vermittler.review') }}">In der Prüfliste ansehen →</a>
</div>
@endif

<div class="card" style="max-width:1100px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
        <div style="font-weight:700;font-size:14px;">Datensätze{{ $filter ? ' – ' . (\App\Models\VermittlerSettlement::RESULTS[$filter]['label'] ?? $filter) : '' }}</div>
        @if($filter)<a href="{{ route('admin.vermittler.show', $import->id) }}" class="btn btn-ghost">Filter aufheben</a>@endif
    </div>

    @if($rows->isEmpty())
        <div class="muted-sm">Keine Datensätze in dieser Auswahl.</div>
    @else
    <div class="scroll-x">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Id</th><th style="padding:8px;">Produkt</th>
                <th style="padding:8px;">Referenz-Nr.</th><th style="padding:8px;">Provision</th>
                <th style="padding:8px;">Status</th><th style="padding:8px;">Vertrag / Kunde</th>
                <th style="padding:8px;">Ergebnis</th>
            </tr></thead>
            <tbody>
            @foreach($rows as $row)
                <tr style="border-top:1px solid var(--line);">
                    <td style="padding:8px;font-weight:600;">{{ $row->vermittler_id }}</td>
                    <td style="padding:8px;">{{ $row->produkt ?: '—' }}</td>
                    <td style="padding:8px;">{{ $row->reference_number ?: '—' }}</td>
                    <td style="padding:8px;">{{ $row->provision !== null ? number_format((float) $row->provision, 2, ',', '.') . ' €' : '—' }}</td>
                    <td style="padding:8px;">
                        {{ $row->statusLabel() }}
                        @if($row->storno_reason)<div style="color:#A32D2D;font-size:11.5px;">{{ $row->storno_reason }}</div>@endif
                    </td>
                    <td style="padding:8px;">
                        @if($row->contract)
                            <a href="{{ route('admin.contract.edit', $row->contract->id) }}">{{ $row->contract_label }}</a>
                            <div style="color:var(--ink-soft);font-size:11.5px;">{{ $row->customer_label ?: '—' }}</div>
                        @else — @endif
                    </td>
                    <td style="padding:8px;">
                        <span class="badge badge-{{ $row->resultBadge() }} nowrap">{{ $row->resultIcon() }} {{ $row->resultLabel() }}</span>
                        @if($row->match_note)<div style="color:var(--ink-soft);font-size:11.5px;margin-top:4px;">{{ $row->match_note }}</div>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div style="margin-top:14px;">{{ $rows->links() }}</div>
    @endif
</div>
@endsection
