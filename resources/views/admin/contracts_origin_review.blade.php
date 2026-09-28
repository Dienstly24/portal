@extends('layouts.admin')
@section('content')
{{--
    Pruefliste Vertragsherkunft (28.09.2026). Bei der Einfuehrung wurde jeder
    vorhandene Vertrag als Eigenvertrag ANGENOMMEN; automatisch angelegte
    Vertraege (Dokumenten-Eingang) ebenso. Hier wird die Annahme bestaetigt
    oder korrigiert.
--}}
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.contracts') }}">Verträge</a><span class="breadcrumb-sep">›</span><span>Herkunft prüfen</span></div>
    <h1 class="page-title">🔎 Verträge mit ungeprüfter Herkunft</h1>
    <div class="page-sub">Diese Verträge wurden ohne ausdrückliche Auswahl als <strong>Eigenvertrag</strong> geführt. Bitte bestätigen oder korrigieren.</div>
</div>

@if($errors->any())
<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D;">
    @foreach($errors->all() as $error)<div>• {{ $error }}</div>@endforeach
</div>
@endif

<form method="POST" action="{{ route('admin.contracts.origin_review.store') }}" id="origin-review-form">
@csrf
<div class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;padding:12px 16px;">
    <label style="display:flex;gap:6px;align-items:center;font-size:13px;"><input type="checkbox" id="origin-review-all"> Alle auf dieser Seite</label>
    <span style="flex:1;"></span>
    <button type="submit" name="aktion" value="bestaetigen" class="btn btn-primary btn-sm">✓ Herkunft bestätigen (Eigenvertrag)</button>
    @if($darfAendern)
    <button type="submit" name="aktion" value="als_fremd" class="btn btn-ghost btn-sm" id="origin-review-fremd">📁 Als Fremdvertrag kennzeichnen</button>
    @endif
</div>
@if(! $darfAendern)
<div class="muted-xs" style="margin:-6px 0 12px;">Eine abweichende Herkunft (Fremdvertrag/Übernommen) kann ein Admin oder Manager setzen – oder im Vertrag selbst.</div>
@endif

<div class="card card-flush">
    <table>
        <thead><tr style="background:#F8F9FA;">
            <th style="padding:12px 16px;width:40px;"></th>
            <th>Kunde</th><th>Vertrag</th><th>Status</th><th>Angelegt</th><th></th>
        </tr></thead>
        <tbody>
        @forelse($contracts as $c)
        <tr>
            <td style="padding:12px 16px;"><input type="checkbox" name="ids[]" value="{{ $c->id }}" class="origin-review-box" aria-label="Vertrag auswählen"></td>
            <td><a href="{{ route('admin.customer', $c->customer_id) }}" style="font-weight:600;">{{ $c->customer?->user?->name ?? '—' }}</a></td>
            <td>{{ $c->typeIcon() }} {{ $c->typeLabel() }} · {{ $c->insurer }}@if($c->contract_number) <span class="muted-2xs">({{ $c->contract_number }})</span>@endif
                <div class="muted-2xs">Aktuell: {{ $c->originLabel() }}</div></td>
            <td>@php $st = $c->displayStatus(); @endphp<span class="badge badge-{{ $st['badge'] }} nowrap">{{ $st['label'] }}</span></td>
            <td class="muted-sm nowrap">{{ $c->created_at?->lokal()->format('d.m.Y') }}<div class="muted-2xs">{{ $c->added_by ?? 'System' }}</div></td>
            <td style="padding-right:16px;"><a href="{{ route('admin.contract.edit', $c->id) }}" class="btn btn-ghost btn-sm">Öffnen</a></td>
        </tr>
        @empty
        <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--ink-soft);">✅ Alle Verträge haben eine geprüfte Herkunft.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
</form>

@if($contracts->hasPages())
<div style="display:flex;justify-content:space-between;gap:12px;margin:16px 2px;flex-wrap:wrap;" class="muted-sm">
    <span>{{ $contracts->firstItem() }}–{{ $contracts->lastItem() }} von {{ $contracts->total() }}</span>
    <span>
        @if(! $contracts->onFirstPage())<a href="{{ $contracts->previousPageUrl() }}" class="btn btn-ghost btn-sm">← Zurück</a>@endif
        @if($contracts->hasMorePages())<a href="{{ $contracts->nextPageUrl() }}" class="btn btn-ghost btn-sm">Weiter →</a>@endif
    </span>
</div>
@endif

<script @cspNonce>
document.getElementById('origin-review-all')?.addEventListener('change', function (e) {
    document.querySelectorAll('.origin-review-box').forEach(function (b) { b.checked = e.target.checked; });
});
// Rueckfrage vor dem Umdeuten zum Fremdvertrag (ui.js prueft data-confirm
// nur an Formularen, nicht an einem einzelnen Absende-Knopf).
document.getElementById('origin-review-fremd')?.addEventListener('click', function (e) {
    if (!confirm('Ausgewählte Verträge als Fremdvertrag (nur Dokumentation) kennzeichnen? Die Änderung wird protokolliert.')) e.preventDefault();
});
</script>
@endsection
