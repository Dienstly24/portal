@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span><span>Dubletten</span></div>
    <div class="page-title">Mögliche Dubletten</div>
    <div class="page-sub">Automatischer Abgleich nach Name, Geburtsdatum, E-Mail, Adresse und Telefon. Bitte jedes Paar prüfen, bevor Sie es zusammenführen.</div>
</div>

@if($capped)
<div class="card" style="background:#FEF3C7;color:#92400E;padding:12px 18px;margin-bottom:16px;font-size:13px;">
    ⚠ Es wurden aus Leistungsgründen nur die neuesten Kunden geprüft. Bereinigen Sie die angezeigten Dubletten und laden Sie die Seite erneut, um weitere zu finden.
</div>
@endif

@if(count($pairs) === 0)
<div class="card" style="padding:40px;text-align:center;color:var(--ink-soft);">
    <div style="font-size:38px;margin-bottom:10px;">✅</div>
    <div style="font-size:15px;font-weight:600;color:var(--ink);">Keine Dubletten gefunden</div>
    <div style="font-size:13px;margin-top:6px;">Der Kundenbestand ({{ $scanned }} geprüft) enthält aktuell keine offensichtlichen Doppelanlagen.</div>
</div>
@else
<div style="font-size:13px;color:var(--ink-soft);margin-bottom:14px;">{{ count($pairs) }} Verdachtsfall(e) · {{ $scanned }} Kunden geprüft</div>

@foreach($pairs as $pair)
@php
    $primary = $pair['primary']; $duplicate = $pair['duplicate'];
    $score = $pair['score'];
    $badgeColor = $score >= 90 ? '#A32D2D' : ($score >= 80 ? '#B45309' : '#185FA5');
    $tierLabel = $pair['tier'] === 'auto' ? 'Sehr wahrscheinlich' : 'Wahrscheinlich';
@endphp
<div class="card" style="margin-bottom:16px;padding:0;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="background:{{ $badgeColor }};color:#fff;border-radius:999px;padding:4px 12px;font-size:12.5px;font-weight:700;">{{ $score }}% · {{ $tierLabel }}</span>
        </div>
        <a href="{{ route('admin.customer.merge', $primary->id) }}?duplicate={{ $duplicate->id }}" class="btn btn-primary" style="padding:8px 16px;">Prüfen &amp; zusammenführen →</a>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
        @foreach([['c'=>$primary,'label'=>'Hauptkunde (bleibt bestehen)','bg'=>'#E4F0E7'],['c'=>$duplicate,'label'=>'Duplikat (wird übernommen)','bg'=>'#FEF3C7']] as $col)
        @php $c = $col['c']; @endphp
        <div style="padding:16px 20px;{{ $loop->first ? 'border-right:1px solid var(--line);' : '' }}">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);margin-bottom:8px;">{{ $col['label'] }}</div>
            <a href="{{ route('admin.customer', $c->id) }}" style="font-size:15px;font-weight:700;color:var(--ink);text-decoration:none;">{{ $c->user?->name ?? 'Unbekannt' }}</a>
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:6px;line-height:1.7;">
                <div>🔢 {{ $c->customer_number }}</div>
                @if($c->user?->hasRealEmail())<div>✉ {{ $c->user->email }}</div>@endif
                @if($c->phone || $c->mobile)<div>📞 {{ $c->phone ?: $c->mobile }}</div>@endif
                @if($c->birth_date)<div>🎂 {{ \Illuminate\Support\Carbon::parse($c->birth_date)->format('d.m.Y') }}</div>@endif
                @if($c->fullAddress())<div>📍 {{ $c->fullAddress() }}</div>@endif
            </div>
        </div>
        @endforeach
    </div>
    @if(!empty($pair['signals']))
    <div style="padding:12px 20px;background:var(--surface);border-top:1px solid var(--line);">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);margin-bottom:6px;">Übereinstimmende Merkmale</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
            @foreach($pair['signals'] as $signal)
            <span style="background:#fff;border:1px solid var(--line);border-radius:6px;padding:4px 10px;font-size:12px;color:var(--ink);">✓ {{ $signal }}</span>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endforeach
@endif
@endsection
