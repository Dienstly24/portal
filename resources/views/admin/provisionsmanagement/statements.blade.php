@extends('layouts.admin')
@section('content')
@include('admin.provisionsmanagement._layout', ['active' => 'abrechnungen', 'titel' => 'Abrechnungen',
    'untertitel' => 'Die bestätigten Läufe mit ihren Summen – Entwürfe stehen unter „Importe“.'])

<div class="card" style="max-width:1250px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
        <div class="field" style="margin:0;min-width:220px;">
            <label>Pool</label>
            <select name="pool">
                <option value="">Alle Pools</option>
                @foreach($poolListe as $key => $pool)
                <option value="{{ $key }}" @selected(($filters['pool'] ?? '') === $key)>{{ $pool->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn" type="submit">Filtern</button>
    </form>
</div>

<div class="card" style="max-width:1250px;">
    <table class="table" style="font-size:13px;">
        <tr><th>Abrechnung</th><th>Pool</th><th>Importiert am</th><th class="num">Buchungen</th>
            <th class="num">Netto</th><th class="num">Ohne Vertrag</th><th></th></tr>
        @forelse($imports as $import)
        @php $summe = $summen[$import->id] ?? null; @endphp
        <tr>
            <td><b>{{ $import->filename }}</b></td>
            <td>{{ $import->poolLabel() }}</td>
            <td>{{ $import->confirmed_at?->lokal()->format('d.m.Y H:i') }}<div style="font-size:11px;color:var(--ink-soft);">{{ $import->importer?->name ?? 'System' }}</div></td>
            <td class="num">{{ (int) ($summe->anzahl ?? 0) }}</td>
            <td class="num-strong">{{ number_format((float) ($summe->netto ?? 0), 2, ',', '.') }} €</td>
            <td class="num">{{ $import->rows_unlinked_kept }}</td>
            <td>
                <a href="{{ route('admin.commissions_internal.index', ['import' => $import->id]) }}">Buchungen →</a>
                <a href="{{ route('admin.commissions_internal.preview', $import->id) }}" style="margin-left:8px;">Lauf →</a>
            </td>
        </tr>
        @empty
        <tr><td colspan="7" class="muted">Noch keine bestätigte Abrechnung.</td></tr>
        @endforelse
    </table>
    <div style="margin-top:14px;">{{ $imports->links() }}</div>
</div>
@endsection
