@extends('layouts.admin')
@section('content')
<div class="page-header">
    <div class="breadcrumb"><a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span><a href="{{ route('admin.werbung') }}">Werbeanzeigen</a><span class="breadcrumb-sep">›</span><span>Bewerben</span></div>
    <div class="page-title">📢 Banner bewerben</div>
    <div class="page-sub">Der bereits veröffentlichte Facebook-Beitrag wird als Anzeige auf Facebook und Instagram ausgespielt. Die Anzeige entsteht PAUSIERT – Geld fließt erst nach Ihrem Start-Klick.</div>
</div>

@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

<div class="card">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
        <img src="{{ asset('storage/' . $banner->media_path) }}" style="width:150px;height:84px;object-fit:cover;border-radius:8px;border:1px solid var(--line);" alt="">
        <div>
            <strong style="font-size:15px;">{{ $banner->title }}</strong>
            <div style="font-size:12.5px;color:var(--ink-soft);margin-top:4px;">Beworben wird der veröffentlichte Facebook-Beitrag inkl. Tracking-Link – Klicks erscheinen in der Banner-Statistik.</div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.werbung.store', $banner->id) }}">
        @csrf
        <div class="grid-2">
            <div class="field">
                <label>Ziel der Anzeige</label>
                <select name="objective">
                    <option value="klicks" {{ old('objective', 'klicks') === 'klicks' ? 'selected' : '' }}>Mehr Klicks auf den Link (empfohlen)</option>
                    <option value="reichweite" {{ old('objective') === 'reichweite' ? 'selected' : '' }}>Mehr Reichweite (möglichst viele sehen es)</option>
                </select>
            </div>
            <div class="field">
                <label>Sprache der Zielgruppe</label>
                <select name="language">
                    <option value="alle" {{ old('language', 'alle') === 'alle' ? 'selected' : '' }}>Alle (Deutsch + Arabisch)</option>
                    <option value="de" {{ old('language') === 'de' ? 'selected' : '' }}>Deutschsprachig</option>
                    <option value="ar" {{ old('language') === 'ar' ? 'selected' : '' }}>Arabischsprachig</option>
                </select>
                <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">Region: Deutschland. Ausspielung automatisch auf Facebook + Instagram.</div>
            </div>
        </div>
        {{-- .grid-2 kollabiert am Handy auf eine Spalte (Inline-4er-Grid nicht) --}}
        <div class="grid-2">
            <div class="field"><label>Alter von</label><input type="number" name="age_min" value="{{ old('age_min', 20) }}" min="18" max="65"></div>
            <div class="field"><label>Alter bis</label><input type="number" name="age_max" value="{{ old('age_max', 65) }}" min="18" max="65"></div>
        </div>
        <div class="grid-2">
            <div class="field"><label>Tagesbudget (EUR, max. {{ $maxBudget }})</label><input type="number" name="daily_budget_eur" value="{{ old('daily_budget_eur', 10) }}" min="1" max="{{ $maxBudget }}" step="1" required></div>
            <div class="field"><label>Ende (optional)</label><input type="date" name="end_date" value="{{ old('end_date') }}"><div style="font-size:11.5px;color:var(--ink-soft);margin-top:3px;">leer = läuft bis zum Pausieren</div></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
            <button type="submit" class="btn btn-primary">Anzeige erstellen (startet NICHT sofort)</button>
            <a href="{{ route('admin.werbung') }}" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
</div>
@endsection
