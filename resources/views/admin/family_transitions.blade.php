@extends('layouts.admin')
@section('content')

<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span>
        <span>Kinder werden 15</span>
    </div>
    <div class="page-title">👧 Kinder werden 15</div>
    <div class="page-sub">
        Familienmitglieder mit bevorstehender Verselbstständigung – sortiert nach verbleibender Zeit.
        Mit dem 15. Geburtstag wird aus dem abhängigen Familienmitglied automatisch ein eigenständiger Kunde.
        Dabei wird <strong>nichts gelöscht und nichts neu angelegt</strong>, und es wird
        <strong>kein Vertrag verändert</strong> – die Familienbeziehung bleibt bestehen.
    </div>
</div>

@if(session('success'))
<div style="background:#D9F4E6;color:#128a4b;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13.5px;">{{ session('success') }}</div>
@endif

<div class="card" style="margin-bottom:18px;">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div class="field" style="margin:0;">
            <label style="font-size:12px;">Vorlaufzeit</label>
            <select name="vorlauf" style="padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13.5px;">
                @foreach(\App\Services\Family\FamilyRelationService::LEAD_MONTH_CHOICES as $monate)
                <option value="{{ $monate }}" {{ $leadMonths === $monate ? 'selected' : '' }}>{{ $monate }} Monate vor dem 15. Geburtstag</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-ghost">Anzeigen</button>
        @if(in_array(auth()->user()->role, ['admin','manager'], true))
        <button type="submit" name="speichern" value="1" class="btn btn-gold">Als Standard speichern</button>
        <span style="font-size:12px;color:var(--ink-soft);padding-bottom:9px;">Gespeicherter Standard: {{ $gespeicherteVorlaufzeit }} Monate</span>
        @endif
    </form>
</div>

@if($relations->isEmpty())
<div class="card" style="text-align:center;padding:40px 16px;color:var(--ink-soft);">
    <div style="font-size:40px;margin-bottom:8px;">👧</div>
    <div style="font-size:14.5px;font-weight:600;margin-bottom:4px;">Kein Familienmitglied in der Übergangsphase</div>
    <div style="font-size:12.5px;">Im gewählten Zeitraum von {{ $leadMonths }} Monaten wird kein verknüpftes Kind 15 Jahre alt.</div>
</div>
@else
<div class="card">
    <div class="card-title" style="margin-bottom:14px;">{{ $relations->count() }} Familienmitglied(er) vor dem 15. Geburtstag</div>
    @foreach($relations as $rel)
    @php
        $kind = $rel->relatedCustomer;
        $stichtag = $rel->independenceDate();
        $restTage = $stichtag ? \Illuminate\Support\Carbon::today()->diffInDays($stichtag, false) : null;
        $restMonate = $stichtag ? (int) floor($restTage / 30) : null;
    @endphp
    <div style="display:flex;align-items:center;gap:14px;padding:14px 0;border-top:{{ $loop->first ? '0' : '1px solid var(--line)' }};flex-wrap:wrap;">
        <div style="width:42px;height:42px;border-radius:10px;background:#FEF3C7;display:flex;align-items:center;justify-content:center;font-size:22px;flex:none;">⚠</div>
        <div style="flex:1;min-width:220px;">
            <a href="{{ route('admin.customer', $kind->id) }}" style="font-size:14.5px;font-weight:700;color:var(--ink);text-decoration:none;">{{ $kind->user?->name ?? 'Kunde' }}</a>
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:3px;">
                {{ $kind->customer_number }} ·
                {{ \App\Models\CustomerFamilyRelation::roleLabel($rel->relationship_type) }} von
                <a href="{{ route('admin.customer', $rel->customer_id) }}" style="color:var(--ink-soft);">{{ $rel->customer?->user?->name ?? '—' }}</a>
            </div>
            <div style="font-size:12.5px;margin-top:5px;">
                <strong>15. Geburtstag: {{ $stichtag?->format('d.m.Y') ?? '—' }}</strong>
                @if($restTage !== null)
                <span style="color:var(--ink-soft);"> · noch {{ $restMonate > 0 ? $restMonate . ' Monate' : $restTage . ' Tage' }}</span>
                @endif
            </div>
            <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;">
                Empfehlung: eigenständige Verträge / eigene Kundenvorgänge sowie eigene Kontaktdaten prüfen.
            </div>
        </div>
        <div style="flex:none;">
            @if($rel->transition_prepared_at)
            <span style="font-size:12px;background:#D9F4E6;color:#128a4b;border-radius:999px;padding:5px 12px;">✓ vorbereitet am {{ $rel->transition_prepared_at->lokal()->format('d.m.Y') }}</span>
            @else
            <form method="POST" action="{{ route('admin.family.prepare_transition', $rel->id) }}">
                @csrf
                <button type="submit" class="btn btn-gold btn-sm">Als eigenständigen Kunden vorbereiten</button>
            </form>
            @endif
        </div>
    </div>
    @endforeach
</div>
@endif

@endsection
