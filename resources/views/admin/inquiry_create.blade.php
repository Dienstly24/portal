@extends('layouts.admin')
@section('content')
<div class="page-title">Neue Anfrage erfassen</div>
<div class="page-sub">Anfrage von Website oder info@dienstly24.de manuell eintragen.</div>
<div class="card" style="max-width:680px;">
    <form method="POST" action="{{ route('admin.inquiries.store') }}">
        @csrf
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Name *</label><input type="text" name="name" required placeholder="Max Mustermann"></div>
            <div class="field"><label>E-Mail</label><input type="email" name="email" placeholder="max@beispiel.de"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="field"><label>Telefon</label><input type="tel" name="phone" placeholder="+49 ..."></div>
            <div class="field"><label>Dringlichkeit</label>
                <select name="priority" style="width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:8px;font-size:14px;">
                    <option value="niedrig">🟢 Niedrig</option>
                    <option value="mittel" selected>🟡 Mittel</option>
                    <option value="hoch">🔴 Hoch</option>
                </select>
            </div>
        </div>
        <div class="field"><label>Betreff *</label><input type="text" name="subject" required placeholder="Worum geht es?"></div>
        <div class="field"><label>Nachricht *</label><textarea name="message" required rows="6" placeholder="Inhalt der Anfrage..."></textarea></div>
        @if ($errors->any())
        <div style="background:#F9E3E3;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#A32D2D;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
        @endif
        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Speichern</button>
            <a href="{{ route('admin.inquiries') }}" class="btn btn-ghost">Abbrechen</a>
        </div>
    </form>
</div>
@endsection
