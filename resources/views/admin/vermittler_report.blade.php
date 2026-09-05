@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="page-title">Auswertung der Vermittler-Abrechnung</div>
    <div class="page-sub">Welche Produkte tragen sich? Und wie zuverlässig rechnet der Vermittler ab?</div>
</div>

@include('admin.partials.vermittler_tabs', ['active' => 'bericht'])

<div class="card" style="max-width:1100px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:4px;">Vermittler-Performance</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">
        Gegenüberstellung: was wir eingereicht haben und was der Vermittler tatsächlich abgerechnet hat.
        Grundlage sind ausschließlich eingelesene Abrechnungen – es wird nichts geschätzt.
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;">
        @foreach([
            ['Eingereicht', $performance['eingereicht'], 'var(--graphite)'],
            ['Abgerechnet', $performance['abgerechnet'], 'var(--emerald-deep)'],
            ['Storniert', $performance['storniert'], '#A32D2D'],
            ['Nicht gefunden', $performance['nicht_gefunden'], '#B5651D'],
            ['Prüfung', $performance['pruefung'], '#A32D2D'],
            ['Noch offen', $performance['offen'], '#185FA5'],
        ] as [$label, $value, $color])
        <div style="border:1px solid var(--line);border-radius:8px;padding:12px 14px;">
            <div class="muted-2xs">{{ $label }}</div>
            <div style="font-size:20px;font-weight:700;color:{{ $color }};">{{ $value }}</div>
        </div>
        @endforeach
        <div style="border:1px solid var(--line);border-radius:8px;padding:12px 14px;background:#F4F7F5;">
            <div class="muted-2xs">Bestätigungsquote</div>
            <div style="font-size:20px;font-weight:700;">
                {{ $performance['quote'] !== null ? number_format($performance['quote'], 1, ',', '.') . ' %' : '—' }}
            </div>
        </div>
    </div>
</div>

<div class="card" style="max-width:1100px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:14px;">Nach Produkt</div>
    @if($products === [])
        <div class="muted-sm">Noch keine Abrechnungsdaten vorhanden.</div>
    @else
    <div class="scroll-x">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Produkt</th><th style="padding:8px;">Datensätze</th>
                <th style="padding:8px;">Bestätigt</th><th style="padding:8px;">Storniert</th>
                <th style="padding:8px;">Provision</th><th style="padding:8px;">Storniert (entgangen)</th>
            </tr></thead>
            <tbody>
            @foreach($products as $row)
                <tr style="border-top:1px solid var(--line);">
                    <td style="padding:8px;font-weight:600;">{{ $row['produkt'] }}</td>
                    <td style="padding:8px;">{{ $row['anzahl'] }}</td>
                    <td style="padding:8px;color:var(--emerald-deep);">{{ $row['bestaetigt'] }}</td>
                    <td style="padding:8px;color:#A32D2D;">{{ $row['storniert'] }}</td>
                    <td style="padding:8px;font-weight:600;">{{ number_format($row['provision'], 2, ',', '.') }} €</td>
                    <td style="padding:8px;color:var(--ink-soft);">{{ number_format($row['provision_storno'], 2, ',', '.') }} €</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

<div class="card" style="max-width:1100px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:4px;">Nach Kunde</div>
    <div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px;">
        Die 50 Kunden mit der höchsten tatsächlich abgerechneten Provision. Stornierte Datensätze zählen nicht mit.
    </div>
    @if($customers === [])
        <div class="muted-sm">Noch keine zugeordneten Abrechnungsdaten.</div>
    @else
    <div class="scroll-x">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Kunde</th><th style="padding:8px;">Datensätze</th>
                <th style="padding:8px;">Bestätigt</th><th style="padding:8px;">Storniert</th>
                <th style="padding:8px;">Provision</th>
            </tr></thead>
            <tbody>
            @foreach($customers as $row)
                <tr style="border-top:1px solid var(--line);">
                    <td style="padding:8px;">
                        @if($row['customer'])
                            <a href="{{ route('admin.customer', $row['customer']->id) }}">{{ $row['customer']->user?->name ?? '—' }}</a>
                            <span class="muted">· {{ $row['customer']->customer_number }}</span>
                        @else
                            <span class="muted">Kunde gelöscht – Abrechnung bleibt erhalten</span>
                        @endif
                    </td>
                    <td style="padding:8px;">{{ $row['anzahl'] }}</td>
                    <td style="padding:8px;color:var(--emerald-deep);">{{ $row['bestaetigt'] }}</td>
                    <td style="padding:8px;color:#A32D2D;">{{ $row['storniert'] }}</td>
                    <td style="padding:8px;font-weight:600;">{{ number_format($row['provision'], 2, ',', '.') }} €</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
