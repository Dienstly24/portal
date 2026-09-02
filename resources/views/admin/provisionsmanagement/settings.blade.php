@extends('layouts.admin')
@section('content')
@include('admin.provisionsmanagement._layout', ['active' => 'einstellungen', 'titel' => 'Einstellungen',
    'untertitel' => 'Pools und ihre Fristen – die Rechengrundlage für „erwartet“, „überfällig“ und „fehlt“.'])

<div class="card" style="max-width:1250px;">
    <h3 style="margin-top:0;">Pools</h3>
    <p style="font-size:12.5px;color:var(--ink-soft);margin-top:0;">
        <b>Erwartet nach</b> = ab wann eine Provision fällig wäre. <b>Prüffrist</b> = ab wann sie als
        <i>fehlend</i> gilt und der Vertrag auf die Mahnliste kommt. Eine Änderung wirkt sofort:
        die betroffenen Verträge werden beim Speichern neu bewertet.
    </p>

    @foreach($pools as $pool)
    <form method="POST" action="{{ route('admin.provisionsmanagement.pool_update', $pool->id) }}"
          style="border:1px solid var(--line);border-radius:10px;padding:14px;margin-bottom:12px;">
        @csrf @method('PUT')
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end;">
            <div class="field" style="margin:0;"><label>Name</label>
                <input type="text" name="name" value="{{ $pool->name }}" required></div>
            <div class="field" style="margin:0;"><label>Dateiformat</label>
                <select name="source_profile">
                    @foreach($profile as $key => $label)
                    <option value="{{ $key }}" @selected((string) $pool->source_profile === (string) $key)>{{ $label }}</option>
                    @endforeach
                </select></div>
            <div class="field" style="margin:0;"><label>Erwartet nach (Monate)</label>
                <input type="number" name="expected_months" min="0" max="36" value="{{ $pool->expected_months }}" required></div>
            <div class="field" style="margin:0;"><label>Prüffrist (Monate)</label>
                <input type="number" name="check_months" min="0" max="60" value="{{ $pool->check_months }}" required></div>
            <div class="field" style="margin:0;"><label>Ansprechpartner</label>
                <input type="text" name="contact" value="{{ $pool->contact }}"></div>
            <div class="field" style="margin:0;"><label>Aktiv</label>
                <select name="active"><option value="1" @selected($pool->active)>Ja</option><option value="0" @selected(!$pool->active)>Nein</option></select></div>
            <div class="field" style="margin:0;grid-column:1 / -1;"><label>Notiz</label>
                <input type="text" name="notes" value="{{ $pool->notes }}"></div>
        </div>
        <div style="margin-top:10px;display:flex;gap:10px;align-items:center;">
            <button class="btn btn-primary" type="submit">Speichern</button>
            <span style="font-size:11.5px;color:var(--ink-soft);">Schlüssel: <code>{{ $pool->key }}</code> (unveränderlich – er steht an bereits importierten Provisionen)</span>
        </div>
    </form>
    @endforeach

    <h3>Neuen Pool anlegen</h3>
    <form method="POST" action="{{ route('admin.provisionsmanagement.pool_store') }}">
        @csrf
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end;">
            <div class="field" style="margin:0;"><label>Name</label><input type="text" name="name" required></div>
            <div class="field" style="margin:0;"><label>Schlüssel (optional)</label><input type="text" name="key" placeholder="wird aus dem Namen gebildet"></div>
            <div class="field" style="margin:0;"><label>Dateiformat</label>
                <select name="source_profile">@foreach($profile as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
            <div class="field" style="margin:0;"><label>Erwartet nach (Monate)</label><input type="number" name="expected_months" min="0" max="36" value="3" required></div>
            <div class="field" style="margin:0;"><label>Prüffrist (Monate)</label><input type="number" name="check_months" min="0" max="60" value="5" required></div>
            <div class="field" style="margin:0;"><label>Ansprechpartner</label><input type="text" name="contact"></div>
        </div>
        <input type="hidden" name="active" value="1">
        <button class="btn btn-primary" type="submit" style="margin-top:10px;">Pool anlegen</button>
    </form>
</div>

<div class="card" style="max-width:1250px;margin-top:16px;">
    <h3 style="margin-top:0;">Letzte Protokolleinträge</h3>
    <p style="font-size:12.5px;color:var(--ink-soft);margin-top:0;">
        Jede Änderung an Provisionen, Zuordnungen und Pools steht im Protokoll – es gibt keinen Löschweg.
        <a href="{{ route('admin.commissions_internal.audit') }}">Vollständiges Protokoll →</a>
    </p>
    <table class="table" style="font-size:13px;">
        <tr><th>Zeitpunkt</th><th>Wer</th><th>Aktion</th><th>Feld</th><th>Vorher</th><th>Nachher</th></tr>
        @forelse($letzteProtokolle as $eintrag)
        <tr>
            <td>{{ $eintrag->created_at?->lokal()->format('d.m.Y H:i') }}</td>
            <td>{{ $eintrag->user_label }}</td>
            <td>{{ $eintrag->action }}</td>
            <td>{{ $eintrag->field ?: '—' }}</td>
            <td>{{ $eintrag->old_value ?: '—' }}</td>
            <td>{{ $eintrag->new_value ?: '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="6" style="color:var(--ink-soft);">Noch nichts protokolliert.</td></tr>
        @endforelse
    </table>
</div>
@endsection
