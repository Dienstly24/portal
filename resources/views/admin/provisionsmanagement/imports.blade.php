@extends('layouts.admin')
@section('content')
@include('admin.provisionsmanagement._layout', ['active' => 'importe', 'titel' => 'Importe',
    'untertitel' => 'Jeder Lauf bleibt nachvollziehbar – auch ein verworfener Entwurf.'])

<div class="card" style="max-width:1250px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
        <div class="field" style="margin:0;min-width:200px;">
            <label>Pool</label>
            <select name="pool">
                <option value="">Alle</option>
                @foreach($poolListe as $key => $pool)
                <option value="{{ $key }}" @selected(($filters['pool'] ?? '') === $key)>{{ $pool->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0;min-width:180px;">
            <label>Stand</label>
            <select name="status">
                <option value="">Alle</option>
                @foreach(['entwurf' => 'Entwurf', 'importiert' => 'Importiert', 'verworfen' => 'Verworfen'] as $k => $v)
                <option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn" type="submit">Filtern</button>
        <a class="btn btn-primary" href="{{ route('admin.commissions_internal.import') }}" style="margin-left:auto;">Neue Abrechnung importieren</a>
    </form>
</div>

<div class="card" style="max-width:1250px;">
    <table class="table" style="font-size:13px;">
        <tr>
            <th>Datei</th><th>Pool / Quelle</th><th>Stand</th>
            <th class="num">Zeilen</th><th class="num">Zugeordnet</th>
            <th class="num">Neue Verträge</th><th class="num">Neue Kunden</th>
            <th class="num">Prüfung</th><th class="num">Duplikate</th>
            <th class="num">Fehler</th><th>Wer / Wann</th><th></th>
        </tr>
        @forelse($imports as $import)
        <tr>
            <td><b>{{ $import->filename }}</b><div style="font-size:11px;color:var(--ink-soft);">{{ strtoupper($import->format) }}</div></td>
            <td>{{ $import->poolLabel() }}<div style="font-size:11px;color:var(--ink-soft);">{{ $import->providerLabel() }}</div></td>
            <td>{{ ucfirst($import->status) }}</td>
            <td class="num">{{ $import->rows_total }}</td>
            <td class="num">{{ $import->rows_total - $import->rows_unmatched }}</td>
            <td class="num">{{ $import->contracts_created }}</td>
            <td class="num">{{ $import->customers_created }}</td>
            <td style="text-align:right;color:{{ $import->rows_unmatched > 0 ? '#B5651D' : 'inherit' }};">{{ $import->rows_unmatched }}</td>
            <td class="num">{{ $import->rows_duplicate }}</td>
            <td style="text-align:right;color:{{ $import->rows_invalid > 0 ? '#A32D2D' : 'inherit' }};">{{ $import->rows_invalid }}</td>
            <td style="font-size:11.5px;">{{ $import->importer?->name ?? 'System' }}<br>{{ $import->created_at?->lokal()->format('d.m.Y H:i') }}</td>
            <td><a href="{{ route('admin.commissions_internal.preview', $import->id) }}">öffnen →</a></td>
        </tr>
        @empty
        <tr><td colspan="12" class="muted">Noch kein Import.</td></tr>
        @endforelse
    </table>
    <div style="margin-top:14px;">{{ $imports->links() }}</div>
</div>
@endsection
