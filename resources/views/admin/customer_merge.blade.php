@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span><span>Zusammenführen</span></div>
    <div class="page-title">Kunden zusammenführen</div>
</div>
<div class="card" style="max-width:680px;">
    <div style="background:#FEF3C7;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:13.5px;color:#92400E;line-height:1.6;">
        ⚠ <strong>Hauptkunde:</strong> {{ $customer->user?->name }} ({{ $customer->customer_number }})<br>
        Alle Verträge, Tickets, Dokumente, Familie, Fahrzeuge, Notizen und Termine des Duplikats werden auf den Hauptkunden übertragen. Fehlende Stammdaten werden ergänzt. <strong>Das Duplikat wird danach gelöscht.</strong> Diese Aktion kann nicht rückgängig gemacht werden.
    </div>
    <form method="POST" action="{{ route('admin.customer.merge.do', $customer->id) }}" onsubmit="return confirm('Wirklich zusammenführen? Das Duplikat wird gelöscht.');">
        @csrf
        <div class="field">
            <label>Duplikat auswählen (wird gelöscht)</label>
            <select name="duplicate_id" required style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="">— Kunde wählen —</option>
                @foreach($others as $o)
                <option value="{{ $o->id }}">{{ $o->user?->name }} · {{ $o->customer_number }} · {{ $o->user?->email }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Zusammenführen</button>
            <a href="{{ route('admin.customer', $customer->id) }}" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
</div>
@endsection
