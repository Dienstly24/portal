@extends('layouts.admin')
@section('content')
@php
// Menschlich lesbare Bezeichnungen fuer die Vorschau (welche Daten werden uebertragen).
$labels = [
    'contracts' => 'Verträge', 'documents' => 'Dokumente', 'tickets' => 'Tickets',
    'appointments' => 'Termine', 'customer_notes' => 'Notizen', 'customer_family' => 'Familienmitglieder',
    'customer_vehicles' => 'Fahrzeuge', 'customer_timeline' => 'Timeline-Einträge',
    'customer_change_requests' => 'Änderungswünsche', 'customer_addresses' => 'Adressen',
    'customer_contacts' => 'Kontaktdaten', 'internal_messages' => 'Interne Nachrichten',
    'customer_messages' => 'Portal-Nachrichten', 'customer_consents' => 'Einwilligungen (DSGVO)',
    'document_requests' => 'Dokumentanfragen', 'tasks' => 'Aufgaben', 'email_messages' => 'E-Mail-Zuordnungen',
    'approval_requests' => 'Freigaben', 'employee_customers' => 'Betreuer-Zuordnungen',
    'external_references' => 'Externe Kennungen',
];
@endphp
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span><span>Zusammenführen</span></div>
    <div class="page-title">Kunden zusammenführen</div>
</div>
<div class="card" style="max-width:680px;">
    <div style="background:#FEF3C7;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:13.5px;color:#92400E;line-height:1.6;">
        ⚠ <strong>Hauptkunde:</strong> {{ $customer->user?->name }} ({{ $customer->customer_number }})<br>
        Alle Verträge, Tickets, Dokumente, Familie, Fahrzeuge, Notizen, Nachrichten, Einwilligungen und Termine des Duplikats werden auf den Hauptkunden übertragen. Fehlende Stammdaten werden ergänzt. <strong>Es wird nichts gelöscht</strong> außer der dann leeren Duplikat-Akte. Diese Aktion kann nicht rückgängig gemacht werden.
    </div>

    @if($suggested)
    <div style="background:#D9F4E6;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#17191d;line-height:1.6;">
        <strong>🔎 Möglicher Treffer erkannt:</strong> „{{ $suggested->user?->name }}" ({{ $suggested->customer_number }}) ähnelt diesem Kunden und ist unten vorausgewählt. Bitte prüfen.
        @if(!empty($preview))
        <div style="margin-top:10px;">Diese Daten würden übernommen:</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
            @foreach($preview as $table => $count)
            <span style="background:#fff;border:1px solid var(--line);border-radius:6px;padding:3px 9px;font-size:12px;">{{ $count }}× {{ $labels[$table] ?? $table }}</span>
            @endforeach
        </div>
        @endif
    </div>
    @endif

    <form method="POST" action="{{ route('admin.customer.merge.do', $customer->id) }}" onsubmit="return confirm('Wirklich zusammenführen? Alle Daten des Duplikats werden übertragen, die leere Duplikat-Akte danach gelöscht.');">
        @csrf
        <div class="field">
            <label>Duplikat auswählen (wird nach Übertragung entfernt)</label>
            <select name="duplicate_id" required style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                <option value="">— Kunde wählen —</option>
                @foreach($others as $o)
                <option value="{{ $o->id }}" {{ $suggested && $suggested->id === $o->id ? 'selected' : '' }}>{{ $o->user?->name }} · {{ $o->customer_number }} · {{ $o->user?->email }}</option>
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
