@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customers') }}">Kunden</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.customer', $customer->id) }}">{{ $customer->user?->name }}</a><span class="breadcrumb-sep">›</span>
        <span>Vertrag anlegen</span>
    </div>
    <div class="page-title">Vertrag anlegen</div>
    <div class="page-sub">Für: {{ $customer->user?->name }} · {{ $customer->customer_number }}</div>
</div>

@if($errors->any())
<div style="background:#F9E3E3;border:1px solid #F0A0A0;border-radius:10px;padding:16px;margin-bottom:20px;max-width:800px;">
    <div style="font-weight:700;color:#A32D2D;margin-bottom:8px;">Bitte korrigieren Sie folgende Fehler:</div>
    @foreach($errors->all() as $error)<div style="font-size:13px;color:#A32D2D;">• {{ $error }}</div>@endforeach
</div>
@endif

<div class="card" style="max-width:980px;">
    <form method="POST" action="{{ route('admin.contract.store', $customer->id) }}">
        @csrf
        @include('admin.partials.contract_form_fields')

        <div style="border-top:1px solid var(--line);padding-top:20px;display:flex;gap:10px;margin-top:8px;">
            <button type="submit" class="btn btn-primary">Vertrag speichern</button>
            <a href="{{ route('admin.customer', $customer->id) }}" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
</div>
@endsection
