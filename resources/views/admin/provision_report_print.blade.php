<!DOCTYPE html>
<html lang="de">
{{-- Druckansicht des Provisionsberichts: der Browser-Druckdialog erzeugt
     daraus das PDF (bewusst ohne PDF-Fremdpaket). Nur admin/manager. --}}
<head>
<meta charset="UTF-8">
<title>Provisionsbericht {{ $from->format('d.m.Y') }} - {{ $to->format('d.m.Y') }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', Arial, sans-serif; color: #16211C; padding: 32px; font-size: 12px; }
    h1 { font-size: 20px; margin-bottom: 4px; }
    .sub { color: #5F6B62; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th { text-align: left; background: #F1EEE5; padding: 8px 10px; border-bottom: 2px solid #B8A16B; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
    td { padding: 7px 10px; border-bottom: 1px solid #E0DCD0; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .neg { color: #A32D2D; }
    tfoot td { font-weight: 700; border-top: 2px solid #16211C; border-bottom: none; }
    .footer { margin-top: 24px; color: #5F6B62; font-size: 10.5px; display: flex; justify-content: space-between; }
    .noprint { margin-bottom: 18px; }
    .noprint button { padding: 8px 16px; border: none; border-radius: 8px; background: #17A65B; color: #fff; font-weight: 600; cursor: pointer; }
    @media print { .noprint { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="noprint">
    <button onclick="window.print()">🖨️ Drucken / Als PDF speichern</button>
</div>

<h1>Provisionsbericht</h1>
<div class="sub">Zeitraum {{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }} · Dienstly24 (intern, nur Verwaltung)</div>

<table>
    <thead><tr>
        <th>Empfänger</th>
        <th>Art</th>
        <th class="num">Neukunden</th>
        <th class="num">Verträge</th>
        <th>Verträge je Sparte</th>
        <th class="num">Provision</th>
        <th class="num">Abzüge</th>
        <th class="num">Netto</th>
    </tr></thead>
    <tbody>
    @forelse($rows as $r)
    <tr>
        <td><strong>{{ $r['label'] }}</strong></td>
        <td>{{ $r['kind'] === 'partner' ? 'Partner' : 'Mitarbeiter' }}</td>
        <td class="num">{{ $r['kunden'] }}</td>
        <td class="num">{{ $r['vertraege'] }}</td>
        <td>
            @foreach($r['sparten'] as $type => $count)
            {{ $type !== '' ? (\App\Models\Contract::TYPES[$type]['label'] ?? $type) : 'Ohne' }} ×{{ $count }}@if(!$loop->last), @endif
            @endforeach
        </td>
        <td class="num">{{ number_format($r['provision'], 2, ',', '.') }} €</td>
        <td class="num neg">{{ $r['abzuege'] != 0 ? number_format($r['abzuege'], 2, ',', '.') . ' €' : '—' }}</td>
        <td class="num">{{ number_format($r['netto'], 2, ',', '.') }} €</td>
    </tr>
    @empty
    <tr><td colspan="8" style="text-align:center;padding:20px;color:#5F6B62;">Keine Aktivität im Zeitraum.</td></tr>
    @endforelse
    </tbody>
    @if($rows->isNotEmpty())
    <tfoot>
    <tr>
        <td colspan="2">Gesamt</td>
        <td class="num">{{ $rows->sum('kunden') }}</td>
        <td class="num">{{ $rows->sum('vertraege') }}</td>
        <td></td>
        <td class="num">{{ number_format($rows->sum('provision'), 2, ',', '.') }} €</td>
        <td class="num neg">{{ number_format($rows->sum('abzuege'), 2, ',', '.') }} €</td>
        <td class="num">{{ number_format($rows->sum('netto'), 2, ',', '.') }} €</td>
    </tr>
    </tfoot>
    @endif
</table>

<div class="footer">
    <span>Vertraulich - nur fuer die Verwaltung (admin/manager).</span>
    <span>Erstellt am {{ now()->format('d.m.Y H:i') }}</span>
</div>
</body>
</html>
