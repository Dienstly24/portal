@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span><span>Verwandte Kunden</span></div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <div class="page-title">Verwandte Kunden</div>
        <a href="{{ route('admin.customers.duplicates') }}" class="btn btn-ghost">← Mögliche Dubletten</a>
    </div>
    <div class="page-sub">Paare, die als „kein Duplikat" markiert wurden – z. B. Familienmitglieder oder ein Haushalt mit gleicher Anschrift/Telefon. Sie sind bewusst KEINE Dubletten, sondern verbundene Kunden. Ist ein Paar doch dieselbe Person, kann es hier wieder als Dublette freigegeben oder direkt zusammengeführt werden.</div>
</div>

@if(count($relations) === 0)
<div class="card" style="padding:40px;text-align:center;color:var(--ink-soft);">
    <div style="font-size:38px;margin-bottom:10px;">🔗</div>
    <div style="font-size:15px;font-weight:600;color:var(--ink);">Noch keine verwandten Kunden</div>
    <div style="font-size:13px;margin-top:6px;">Markieren Sie in der Dubletten-Prüfung ein Paar mit „✕ Kein Duplikat", um es hier als Beziehung zu sammeln.</div>
</div>
@else
<div style="font-size:13px;color:var(--ink-soft);margin-bottom:14px;">{{ count($relations) }} Beziehung(en)</div>

@foreach($relations as $rel)
<div class="card" style="margin-bottom:16px;padding:0;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px;">
        <span style="background:#EDE9FE;color:#5B21B6;border-radius:999px;padding:4px 12px;font-size:12px;font-weight:700;">🔗 Verwandt · kein Duplikat</span>
        <div style="display:flex;align-items:center;gap:8px;">
            <a href="{{ route('admin.customer.merge', $rel->customerA->id) }}?duplicate={{ $rel->customerB->id }}" class="btn btn-ghost" style="padding:7px 14px;">Doch zusammenführen</a>
            <form method="POST" action="{{ route('admin.customers.relationships.delete', $rel->id) }}" style="margin:0;"
                  onsubmit="return confirm('Beziehung entfernen? Das Paar kann danach wieder als mögliche Dublette erscheinen.');">
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
