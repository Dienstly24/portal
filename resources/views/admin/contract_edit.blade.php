@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customer', $contract->customer_id) }}">{{ $contract->customer?->user?->name }}</a><span class="breadcrumb-sep">›</span>
        <span>Vertrag bearbeiten</span>
    </div>
    <div class="page-title">Vertrag bearbeiten</div>
    <div class="page-sub">{{ $contract->typeIcon() }} {{ $contract->typeLabel() }} · {{ $contract->insurer }}</div>
</div>

{{-- Bestandszustand auch in den Vertragsdetails eindeutig nennen (dieselbe
     Quelle wie Liste und Vertragsstruktur: Contract::displayStatus/statusGroup). --}}
@php $editStatus = $contract->displayStatus(); @endphp
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
    <span class="badge badge-{{ $editStatus['badge'] }}" style="white-space:nowrap;">{{ $editStatus['label'] }}</span>
    <span style="font-size:12.5px;color:var(--ink-soft);">
        @if($editStatus['historic'])
        🗄 <strong>Beendet / Historie</strong> – dieser Vertrag zählt nicht zum aktiven Bestand und erscheint nicht in der Vertragsstruktur des Kunden.
        @elseif($contract->isPendingStatus())
        🕓 <strong>In Bearbeitung</strong> – noch nicht im Bestand; erscheint nicht in der Vertragsstruktur.
        @else
        ✅ <strong>Aktiver Bestand</strong> – zählt zu den aktiven Verträgen und erscheint in der Vertragsstruktur.
        @endif
    </span>
</div>

@if($contract->isApplication())
{{-- Aus einem Auftrag/Antrag entstanden: die Vertragsbestaetigung fehlt noch. --}}
<div style="background:#FEF3C7;border:1px solid #E8C36A;border-radius:10px;padding:14px 16px;margin-bottom:20px;max-width:980px;font-size:13px;">
    <b>📝 Antrag – wartet auf die Vertragsbestätigung.</b>
    Dieser Vertrag wurde aus einem Auftrag/Antrag angelegt. Sobald die Vertragsbestätigung
    (Police, Versicherungsschein) hochgeladen wird, erkennt das System sie als dasselbe
    Geschäft und ergänzt automatisch Vertragsnummer, Kundennummer und die endgültigen
    Angaben – jede Änderung steht unten im Änderungsverlauf.
</div>
@endif

@if($errors->any())
<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:16px;margin-bottom:20px;max-width:800px;">
    <div style="font-weight:700;color:#A32D2D;margin-bottom:8px;">Bitte korrigieren Sie folgende Fehler:</div>
    @foreach($errors->all() as $error)<div style="font-size:13px;color:#A32D2D;">• {{ $error }}</div>@endforeach
</div>
@endif

{{-- KFZ-Cockpit: alle Vertragsdetails auf einen Blick (Redesign 17.07.2026) --}}
@include('admin.partials.contract_kfz_cockpit', ['contract' => $contract])

{{-- E-Scooter-Cockpit: kompakter Ueberblick (Kennzeichen, FIN, Deckung, Saison) --}}
@include('admin.partials.contract_escooter_cockpit', ['contract' => $contract])

{{-- Energie-Cockpit: Zaehlerstand, Verbrauch und Ablese-Historie (29.07.2026) --}}
@include('admin.partials.contract_energy_cockpit', ['contract' => $contract])

{{-- Vermittler / Abrechnung: Referenz-Nr., Vermittler-ID und Abrechnungsstand --}}
@include('admin.partials.contract_vermittler_box', ['contract' => $contract])

{{-- Interne Provisionen: was diese Police uns eingebracht hat. Bewusst
     unterhalb der Vermittler-Box - beides sind Geld-Angaben, beide gehoeren
     nur in die Beraterwelt. --}}
@include('admin.partials.contract_commission_box', ['contract' => $contract])

<div class="card" style="max-width:980px;">
    <form method="POST" action="{{ route('admin.contract.update', $contract->id) }}">
        @csrf @method('PUT')
        @include('admin.partials.contract_form_fields', ['contract' => $contract])

        <div style="border-top:1px solid var(--line);padding-top:20px;display:flex;gap:10px;justify-content:space-between;margin-top:8px;">
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                <a href="{{ route('admin.customer', $contract->customer_id) }}" class="btn btn-ghost">Abbrechen</a>
            </div>
        </div>
    </form>
</div>

{{-- Aenderungsverlauf (Version History): alte/neue Werte je Feld --}}
@include('admin.partials.contract_revisions', ['contract' => $contract])

{{-- Löschen bewusst außerhalb des Bearbeiten-Formulars (eigener POST). --}}
<div class="card" style="max-width:800px;border-color:#F0C0C0;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div>
            <div style="font-weight:700;font-size:14px;">Vertrag löschen</div>
            <div style="font-size:12.5px;color:var(--ink-soft);">Entfernt den Vertrag samt Spartendetails. Zugeordnete Dokumente bleiben in der Kundenakte erhalten.</div>
        </div>
        <form method="POST" action="{{ route('admin.contract.destroy', $contract->id) }}"
            onsubmit="return confirm('Vertrag {{ $contract->insurer }} wirklich löschen?');" style="margin:0;">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-ghost" style="color:#A32D2D;border-color:#A32D2D;">🗑 Vertrag löschen</button>
        </form>
    </div>
</div>
@endsection
