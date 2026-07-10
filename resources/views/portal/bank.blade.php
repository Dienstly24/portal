@extends('layouts.portal')
@section('content')
<div class="page-title">🏦 Bankverbindung</div>
<div class="page-sub">Ihre Bankdaten können nur über eine geprüfte Änderungsanfrage aktualisiert werden.</div>

@php $pendingBank = $requests->where('status','pending')->first(); @endphp

<div class="grid-2">
    <div class="card">
        <div class="card-title">Aktuelle Bankverbindung</div>
        <div class="item-row">
            <span style="font-size:13px;color:var(--ink-soft);">IBAN</span>
            <span style="font-size:14px;font-weight:600;font-family:monospace;">{{ $customer->iban ? '••••' . substr($customer->iban, -4) : '—' }}</span>
        </div>
        <div class="item-row">
            <span style="font-size:13px;color:var(--ink-soft);">Kontoinhaber</span>
            <span style="font-size:14px;font-weight:600;">{{ $customer->account_holder ?? '—' }}</span>
        </div>
        @if($pendingBank)
        <div class="notice" style="margin-top:14px;margin-bottom:0;">⏳ Es liegt bereits eine Bankänderung in Prüfung. Sie können dennoch eine weitere Änderung einreichen – jede wird einzeln bearbeitet.</div>
        @endif
    </div>

    <div class="card">
        <div class="card-title">Neue Bankverbindung beantragen</div>
        <form method="POST" action="{{ route('portal.bank.store') }}">
            @csrf
            <div class="field"><label>IBAN *</label><input type="text" name="iban" required maxlength="34" placeholder="DE00 0000 0000 0000 0000 00" oninput="this.value=this.value.toUpperCase().replace(/\s/g,'')"></div>
            <div class="field"><label>Kontoinhaber *</label><input type="text" name="account_holder" required maxlength="255" value="{{ auth()->user()->name }}"></div>
            @error('iban')<div class="alert-error">Bitte geben Sie eine gültige IBAN ein (ohne Leerzeichen).</div>@enderror
            <button type="submit" class="btn btn-primary">Änderung einreichen</button>
            <p style="font-size:12px;color:var(--ink-soft);margin-top:10px;">🔒 Die neue Bankverbindung wird erst nach Freigabe durch unser Team wirksam.</p>
        </form>
    </div>
</div>

@if($requests->whereIn('status',['approved','rejected'])->count())
<div class="card">
    <div class="card-title">Bisherige Anfragen</div>
    @foreach($requests->whereIn('status',['approved','rejected']) as $r)
    <div class="item-row">
        <div>
            <div style="font-size:14px;">IBAN endend auf <b>{{ substr($r->new_data['iban'] ?? '', -4) }}</b></div>
            <div style="font-size:12px;color:var(--ink-soft);">{{ $r->created_at->format('d.m.Y H:i') }} @if($r->notes) · {{ $r->notes }} @endif</div>
        </div>
        <span class="badge {{ $r->status === 'approved' ? 'badge-active' : '' }}" style="{{ $r->status === 'rejected' ? 'background:#F9E3E3;color:#A32D2D;' : '' }}">{{ $r->status === 'approved' ? 'Genehmigt' : 'Abgelehnt' }}</span>
    </div>
    @endforeach
</div>
@endif
@endsection
