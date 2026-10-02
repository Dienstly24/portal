@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span><span>Verwandte Kunden</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <h1 class="page-title">Verwandte Kunden</h1>
        <a href="{{ route('admin.customers.duplicates') }}" class="btn btn-ghost">← Mögliche Dubletten</a>
    </div>
    <div class="page-sub">Paare, die bewusst KEINE Dubletten sind – Ehepaar, Elternteil und Kind, Geschwister, sonstige Verwandte, gleicher Haushalt, Nachbarn oder eine andere Beziehung. Beide Akten bleiben mit allen Verträgen erhalten. Familienbeziehungen stehen zusätzlich in der Registerkarte „Familie“ beider Kunden (Gleichlauf). Ist ein Paar doch dieselbe Person, kann es hier wieder als Dublette freigegeben oder direkt zusammengeführt werden.</div>
</div>

@php
    $filterChips = ['' => 'Alle'] + ['ehepaar_unbestaetigt' => 'Ehepaar (unbestätigt)'] + \App\Models\CustomerRelationship::LABELS;
@endphp
<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;align-items:center;">
    <span style="font-size:12.5px;color:var(--ink-soft);margin-right:2px;">Filter:</span>
    @foreach($filterChips as $fKey => $fLabel)
    <a href="{{ route('admin.customers.relationships', $fKey === '' ? [] : ['filter' => $fKey]) }}"
       class="btn btn-ghost" style="padding:6px 12px;font-size:12.5px;{{ $filter === $fKey ? 'background:var(--graphite);color:#fff;border-color:var(--graphite);' : '' }}">
        {{ $fLabel }}@if($fKey === 'ehepaar_unbestaetigt') ({{ $unconfirmedCount }})@endif
    </a>
    @endforeach
</div>
@if($filter === 'ehepaar_unbestaetigt')
<div class="card" style="background:#FEF3C7;color:#92400E;padding:11px 16px;margin-bottom:14px;font-size:12.5px;line-height:1.5;">
    Bis 30.09.2026 war „Ehepaar“ die einzige Auswahl für „verwandt“ – ein Teil dieser Markierungen ist deshalb ungenau. Bitte je Paar prüfen: <strong>Bestätigen</strong> trägt die Rolle „Ehepartner/in“ in die Registerkarte „Familie“ beider Kunden ein; stimmt die Art nicht, über „Beziehung ändern“ korrigieren.
</div>
@endif

@if(count($relations) === 0)
<div class="card" style="padding:40px;text-align:center;color:var(--ink-soft);">
    <div style="font-size:38px;margin-bottom:10px;">🔗</div>
    <div style="font-size:15px;font-weight:600;color:var(--ink);">{{ $filter === '' ? 'Noch keine verwandten Kunden' : 'Keine Beziehungen für diesen Filter' }}</div>
    <div style="font-size:13px;margin-top:6px;">Legen Sie in der Dubletten-Prüfung mit „🔗 Beziehung festlegen“ oder „✕ Kein Duplikat“ eine Beziehung fest, um sie hier zu sammeln.</div>
</div>
@else
<div style="font-size:13px;color:var(--ink-soft);margin-bottom:14px;">{{ count($relations) }} Beziehung(en)</div>

@foreach($relations as $rel)
<div class="card" style="margin-bottom:16px;padding:0;overflow:hidden;">
    @php
        $relType = $rel->type ?? 'not_duplicate';
        $relEmoji = \App\Models\CustomerRelationship::typeEmoji($relType);
        $relLabel = \App\Models\CustomerRelationship::typeLabel($relType);
    @endphp
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="background:#EDE9FE;color:#5B21B6;border-radius:999px;padding:4px 12px;font-size:12px;font-weight:700;">{{ $relEmoji }} {{ $relLabel }}{{ $relType === 'not_duplicate' ? '' : ' · kein Duplikat' }}</span>
            @if($rel->unbestaetigt)
            <span style="background:#FEF3C7;color:#92400E;border-radius:999px;padding:3px 10px;font-size:11.5px;font-weight:600;" title="Keine passende Rolle in der Registerkarte „Familie“ – Altbestand oder automatisch erkannt">unbestätigt</span>
            @endif
            @if($rel->directionText())
            <span class="muted-xs">{{ $rel->directionText() }}</span>
            @endif
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            @if($rel->unbestaetigt)
            <form method="POST" action="{{ route('admin.customers.relationships.confirm', $rel->id) }}" style="margin:0;"
                  data-confirm="„{{ $relLabel }}“ bestätigen? Die passende Rolle wird in der Registerkarte „Familie“ beider Kunden eingetragen.">
                @csrf
                <button type="submit" class="btn btn-primary" style="padding:7px 14px;">✓ Bestätigen</button>
            </form>
            @endif
            @include('admin.partials.beziehung_festlegen', [
                'a' => $rel->customerA, 'b' => $rel->customerB,
                'action' => route('admin.customers.relationships.type', $rel->id),
                'hidden' => [],
                'current' => $relType === 'not_duplicate' ? null : $relType,
                'parent' => $rel->parent_customer_id ?? $rel->vorschlagElternteil,
                'suggested' => $rel->parent_customer_id === null,
                'note' => $rel->note,
                'label' => 'Beziehung ändern',
            ])
            <a href="{{ route('admin.customer.merge', $rel->customerA->id) }}?duplicate={{ $rel->customerB->id }}" class="btn btn-ghost" style="padding:7px 14px;">Doch zusammenführen</a>
            <form method="POST" action="{{ route('admin.customers.relationships.delete', $rel->id) }}" style="margin:0;"
                  data-h-submit="7d45402697">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-ghost" style="padding:7px 14px;">Beziehung entfernen</button>
            </form>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
        @foreach([$rel->customerA, $rel->customerB] as $c)
        <div style="padding:16px 20px;{{ $loop->first ? 'border-right:1px solid var(--line);' : '' }}">
            <a href="{{ route('admin.customer', $c->id) }}" style="font-size:15px;font-weight:700;color:var(--ink);text-decoration:none;">{{ $c->user?->name ?? 'Unbekannt' }}</a>
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:6px;line-height:1.7;">
                <div>🔢 {{ $c->customer_number }}</div>
                @if($c->user?->hasRealEmail())<div>✉ {{ $c->user->email }}</div>@endif
                @if($c->phone || $c->mobile)<div>📞 {{ $c->phone ?: $c->mobile }}</div>@endif
                @if($c->fullAddress())<div>📍 {{ $c->fullAddress() }}</div>@endif
            </div>
        </div>
        @endforeach
    </div>
    @if(!empty($rel->signals))
    <div style="padding:10px 20px;background:var(--surface);border-top:1px solid var(--line);display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);">Gemeinsam:</span>
        @foreach($rel->signals as $signal)
        <span style="background:#fff;border:1px solid var(--line);border-radius:6px;padding:3px 9px;font-size:12px;color:var(--ink);">✓ {{ $signal }}</span>
        @endforeach
    </div>
    @endif
    @if($rel->note)
    <div style="padding:8px 20px;font-size:12px;color:var(--ink-soft);border-top:1px solid var(--line);">📝 {{ $rel->note }}</div>
    @endif
</div>
@endforeach
@endif
@endsection

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["7d45402697"] = function (event) { return confirm('Beziehung entfernen? Das Paar kann danach wieder als mögliche Dublette erscheinen.'); };
</script>
@endPushOnce
