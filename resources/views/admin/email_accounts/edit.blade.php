@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.email_accounts.index') }}">E-Mail-Postfächer</a><span class="breadcrumb-sep">›</span><span>{{ $account->name }}</span></div>
    <div class="page-title">Postfach bearbeiten</div>
</div>

@if($errors->any())
<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">
    <ul style="margin:0;padding-left:18px;">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<form method="POST" action="{{ route('admin.email_accounts.update', $account->id) }}">
@csrf @method('PUT')
@include('admin.email_accounts._fields')
<div style="margin-top:20px;max-width:900px;display:flex;gap:10px;">
    <button type="submit" class="btn btn-primary">Änderungen speichern</button>
    <a href="{{ route('admin.email_accounts.index') }}" class="btn btn-ghost">Abbrechen</a>
    <form method="POST" action="{{ route('admin.email_accounts.destroy', $account->id) }}" onsubmit="return confirm('Postfach wirklich entfernen?');" style="margin-left:auto;">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-ghost" style="color:#B3261E;">Postfach löschen</button>
    </form>
</div>
</form>
@endsection
