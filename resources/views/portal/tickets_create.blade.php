@extends('layouts.portal')
@section('content')
<div class="page-title">{{ __('Neue Anfrage') }}</div>
<div class="page-sub">{{ __('Beschreiben Sie Ihr Anliegen.') }}</div>
<div class="card">
    <form method="POST" action="{{ route('portal.tickets.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
            <label>{{ __('Art der Anfrage') }}</label>
            <select name="type" required>
                <option value="damage">{{ __('Schaden melden') }}</option>
                <option value="change">{{ __('Vertragsänderung') }}</option>
                <option value="offer">{{ __('Neues Angebot anfragen') }}</option>
                <option value="data_update">{{ __('Datenaktualisierung') }}</option>
                <option value="complaint">{{ __('Beschwerde') }}</option>
                <option value="cancellation">{{ __('Kündigung') }}</option>
                <option value="other">{{ __('Allgemeine Frage') }}</option>
            </select>
        </div>
        <div class="field">
            <label>{{ __('Dringlichkeit') }}</label>
            <select name="priority" required>
                <option value="niedrig">🟢 {{ __('Niedrig') }}</option>
                <option value="mittel" selected>🟡 {{ __('Mittel') }}</option>
                <option value="hoch">🔴 {{ __('Hoch') }}</option>
            </select>
        </div>
        <div class="field">
            <label>{{ __('Betreff') }}</label>
            <input type="text" name="subject" required placeholder="{{ __('Kurze Zusammenfassung') }}">
        </div>
        <div class="field">
            <label>{{ __('Beschreibung') }}</label>
            <textarea name="description" required placeholder="{{ __('Beschreiben Sie Ihr Anliegen...') }}"></textarea>
        </div>
        <div class="field">
            <label>{{ __('Anhänge (optional)') }}</label>
            <input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp">
            <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">{{ __('PDF, JPG, PNG oder WEBP · mehrere Dateien möglich · max. 10 MB pro Datei') }}<br>{{ __('z.B. Versichertenkarte, Unfallfotos, Dokumente') }}</div>
        </div>
        @if ($errors->any())
        <div style="background:#F9E3E3;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
        @endif
        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">{{ __('Absenden') }}</button>
            <a href="{{ route('portal.tickets') }}" class="btn btn-ghost">{{ __('Abbrechen') }}</a>
        </div>
    </form>
</div>
@endsection
