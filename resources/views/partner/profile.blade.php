@extends('layouts.partner')
@section('content')
<div class="page-title">Firmenprofil</div>
<div class="page-sub">Ihr Logo erscheint oben links in Ihrem Partnerportal.</div>

<div class="card" style="max-width:560px;">
    <div class="card-title">Stammdaten</div>
    <p style="font-size:14px;margin-bottom:6px;"><strong>Name:</strong> {{ $partner->name }}</p>
    <p style="font-size:14px;margin-bottom:6px;"><strong>Partnernummer:</strong> {{ $partner->partner_number ?? '—' }}</p>
    <p style="font-size:14px;"><strong>Kontakt-E-Mail:</strong> {{ $partner->contact_email ?? '—' }}</p>
    <p style="font-size:12.5px;color:var(--ink-soft);margin-top:10px;">Änderungen an den Stammdaten nimmt Ihr Dienstly24-Ansprechpartner vor.</p>
</div>

<div class="card" style="max-width:560px;">
    <div class="card-title">Firmenlogo</div>
    @if($partner->logo_path)
    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($partner->logo_path) }}" alt="Logo"
        style="max-height:70px;max-width:220px;object-fit:contain;border:1px solid var(--line);border-radius:8px;padding:6px;margin-bottom:14px;">
    @endif
    <form method="POST" action="{{ route('partner.profile.update') }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
            <label>Neues Logo (PNG/JPG/WebP, max. 2 MB)</label>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
        </div>
        <button type="submit" class="btn">Logo speichern</button>
    </form>
</div>
@endsection
