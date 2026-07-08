@extends('layouts.portal')
@section('content')
<div class="page-title">Neue Anfrage</div>
<div class="page-sub">Beschreiben Sie Ihr Anliegen.</div>
<div class="card">
    <form method="POST" action="{{ route('portal.tickets.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
            <label>Art der Anfrage</label>
            <select name="type" required>
                <option value="damage">Schaden melden</option>
                <option value="change">Vertragsänderung / Kündigung</option>
                <option value="offer">Neues Angebot anfragen</option>
                <option value="data_update">Datenänderung</option>
                <option value="complaint">Beschwerde</option>
                <option value="cancellation">Kündigung</option>
                <option value="other">Allgemeine Frage</option>
            </select>
        </div>
        <div class="field">
            <label>Dringlichkeit</label>
            <select name="priority" required>
                <option value="niedrig">🟢 Niedrig</option>
                <option value="mittel" selected>🟡 Mittel</option>
                <option value="hoch">🔴 Hoch</option>
            </select>
        </div>
        <div class="field">
            <label>Betreff</label>
            <input type="text" name="subject" required placeholder="Kurze Zusammenfassung">
        </div>
        <div class="field">
            <label>Beschreibung</label>
            <textarea name="description" required placeholder="Beschreiben Sie Ihr Anliegen..."></textarea>
        </div>
        <div class="field">
            <label>Anhänge (optional)</label>
            <input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png">
            <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;">PDF, JPG oder PNG · mehrere Dateien möglich · max. 10 MB pro Datei<br>z.B. Versichertenkarte, Unfallfotos, Dokumente</div>
        </div>
        @if ($errors->any())
        <div style="background:#F9E3E3;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
        @endif
        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Absenden</button>
            <a href="{{ route('portal.tickets') }}" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
</div>
@endsection
