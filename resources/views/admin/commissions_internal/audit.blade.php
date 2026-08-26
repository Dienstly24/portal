@extends('layouts.admin')
@section('content')
@include('admin.partials.provision_styles')
<div class="page-header">
    <div class="page-title">📋 Protokoll der Provisionen</div>
    <div class="page-sub">
        Jeder Upload, Import, jede Änderung, Zahlung und Rechnungsverknüpfung.
        <b>Rein lesend</b> – aus der Oberfläche lässt sich hier nichts löschen.
    </div>
</div>

@include('admin.commissions_internal._tabs', ['active' => 'protokoll'])

<div class="card" style="max-width:1200px;">
    <form method="GET" action="{{ route('admin.commissions_internal.audit') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
        <div class="field" style="margin:0;min-width:220px;">
            <label>Suche (Vertragsnummer, Datei, Benutzer)</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}">
        </div>
        <div class="field" style="margin:0;min-width:220px;">
            <label>Vorgang</label>
            <select name="aktion">
                <option value="">Alle</option>
                @foreach($actions as $key => $label)
                <option value="{{ $key }}" @selected(($filters['aktion'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filtern</button>
        <a href="{{ route('admin.commissions_internal.audit') }}" class="btn">Zurücksetzen</a>
    </form>
</div>

<div class="card" style="max-width:1200px;">
    @if($entries->isEmpty())
        <div style="font-size:13px;color:var(--ink-soft);">Keine Einträge zu dieser Auswahl.</div>
    @else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead><tr style="text-align:left;color:var(--ink-soft);">
                <th style="padding:8px;">Wann</th><th style="padding:8px;">Wer</th><th style="padding:8px;">Vorgang</th>
                <th style="padding:8px;">Interne Vertragsnr.</th><th style="padding:8px;">Feld</th>
                <th style="padding:8px;">Vorher</th><th style="padding:8px;">Nachher</th>
                <th style="padding:8px;">Datei</th><th style="padding:8px;"></th>
            </tr></thead>
            <tbody>
            @foreach($entries as $entry)
            <tr style="border-top:1px solid var(--line);vertical-align:top;">
                <td style="padding:8px;white-space:nowrap;">{{ $entry->created_at?->lokal()->format('d.m.Y H:i') }}</td>
                <td style="padding:8px;">{{ $entry->user?->name ?? $entry->user_label ?? 'System' }}</td>
                <td style="padding:8px;">{{ $entry->actionLabel() }}</td>
                <td style="padding:8px;">{{ $entry->internal_contract_number ?: '—' }}</td>
                <td style="padding:8px;color:var(--ink-soft);">{{ $entry->field ?: '—' }}</td>
                <td style="padding:8px;color:var(--ink-soft);">{{ $entry->old_value ?: '—' }}</td>
                <td style="padding:8px;">{{ $entry->new_value ?: '—' }}</td>
                <td style="padding:8px;color:var(--ink-soft);">{{ $entry->source_file ?: '—' }}</td>
                <td style="padding:8px;">
                    @if($entry->commission_id)
                    <a href="{{ route('admin.commissions_internal.show', $entry->commission_id) }}">öffnen →</a>
                    @endif
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div style="margin-top:16px;">{{ $entries->links() }}</div>
    @endif
</div>
@endsection
