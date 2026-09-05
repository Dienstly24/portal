@extends('layouts.admin')
@section('content')
@include('admin.provisionsmanagement._layout', ['active' => 'unklar', 'titel' => 'Unklare Zuordnungen',
    'untertitel' => 'Was ein Mensch entscheiden muss – automatisch wurde bewusst nichts zugeordnet.'])

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
    <a class="rep-tab {{ $art === 'ohne_vertrag' ? 'rep-tab-active' : '' }}"
       href="{{ route('admin.provisionsmanagement.unclear', ['art' => 'ohne_vertrag']) }}">Ohne Vertrag ({{ $anzahlOhneVertrag }})</a>
    <a class="rep-tab {{ $art === 'status' ? 'rep-tab-active' : '' }}"
       href="{{ route('admin.provisionsmanagement.unclear', ['art' => 'status']) }}">Unklarer Status ({{ $anzahlStatus }})</a>
</div>

<div class="card" style="max-width:1250px;">
    <p style="font-size:12.5px;color:var(--ink-soft);margin-top:0;">
        Diese Buchungen sind <b>gespeichert</b> und gehen nicht verloren. Sie tragen nur noch keinen Vertrag –
        entweder weil keine Kennung passte, oder weil mehrere Verträge gepasst hätten.
    </p>
    <table class="table" style="font-size:13px;">
        <tr><th>Datum</th><th>Pool / Datei</th><th>Kennungen</th><th>Kunde laut Datei</th>
            <th class="num">Betrag</th><th>Grund</th><th></th></tr>
        @forelse($liste as $buchung)
        <tr>
            <td>{{ $buchung->commission_date?->format('d.m.Y') ?? '—' }}</td>
            <td>{{ $buchung->poolLabel() }}<div style="font-size:11px;color:var(--ink-soft);">{{ $buchung->source_file }}@if($buchung->source_row), Zeile {{ $buchung->source_row }}@endif</div></td>
            <td style="font-size:11.5px;">
                @foreach(['internal_contract_number' => 'Intern', 'reference_number' => 'Referenz', 'vermittler_id' => 'Pool-Id', 'external_contract_number' => 'Gesellschaft'] as $feld => $label)
                    @if($buchung->{$feld})<div>{{ $label }}: {{ $buchung->{$feld} }}</div>@endif
                @endforeach
            </td>
            <td>{{ $buchung->customer_label ?: '—' }}</td>
            <td class="num-strong">{{ $buchung->amountLabel() }}</td>
            <td class="muted-2xs">{{ $buchung->match_reason ?: ($buchung->status === \App\Support\CommissionStatus::UNKLAR ? 'Status der Quelle nicht eindeutig' : 'Kein Vertrag gefunden') }}</td>
            <td><a href="{{ route('admin.commissions_internal.show', $buchung->id) }}">zuordnen →</a></td>
        </tr>
        @empty
        <tr><td colspan="7" class="muted">Nichts zu prüfen.</td></tr>
        @endforelse
    </table>
    <div style="margin-top:14px;">{{ $liste->links() }}</div>
</div>
@endsection
