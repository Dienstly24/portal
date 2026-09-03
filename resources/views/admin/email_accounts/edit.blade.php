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
    {{-- Knopf gehoert per form-Attribut zum Loesch-Formular UNTER dem
         Bearbeiten-Formular: ein Formular im Formular ist ungueltiges HTML. --}}
    <button type="submit" form="email-account-delete" class="btn btn-ghost" style="margin-left:auto;color:#B3261E;"
            data-h-click="2e98d51642">Postfach löschen</button>
</div>
</form>

<form method="POST" id="email-account-delete" action="{{ route('admin.email_accounts.destroy', $account->id) }}" style="display:none;">
    @csrf @method('DELETE')
</form>
@endsection

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["2e98d51642"] = function (event) { return confirm('Postfach wirklich entfernen?'); };
</script>
@endPushOnce
