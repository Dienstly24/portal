@extends('layouts.admin')
@section('content')
{{-- Wissensbasis des KI-Kundenassistenten (Spezifikation Abschnitt 19).

     Grundregel: NUR was hier steht (und was in der Kundenakte steht), darf
     der Assistent als allgemeine Auskunft geben. Fehlt ein Thema, uebergibt
     er an das Team - er denkt sich nichts aus. Deshalb ist die Pflege eine
     bewusste Verwaltungsaufgabe (admin/manager). --}}
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.settings') }}">Einstellungen</a><span class="breadcrumb-sep">›</span>
        <span>KI-Wissensbasis</span>
    </div>
    <div>
        <div class="page-title">KI-Wissensbasis</div>
        <div class="page-sub">Freigegebene Antworten des Kundenassistenten. Was hier nicht steht, gibt er nicht als Auskunft weiter – dann übernimmt das Team.</div>
    </div>
</div>

@if(session('success'))<div style="background:#D9F4E6;color:#17A65B;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if($errors->any())<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ $errors->first() }}</div>@endif

{{-- Mehrere Fragen auf einmal: bei 40 Fragen ist ein Formular je Eintrag
     der eigentliche Grund, warum die Wissensbasis leer bleibt. --}}
<div class="card" style="margin-bottom:24px;">
    <div class="card-title" style="margin-bottom:6px;">📥 Mehrere Fragen auf einmal</div>
    <div style="font-size:13px;color:var(--ink-soft);margin-bottom:14px;">
        Fragen und Antworten untereinander schreiben oder einfügen – jede Frage mit <code>F:</code>,
        jede Antwort mit <code>A:</code> (arabisch: <code>س:</code> / <code>ج:</code>), Paare durch eine Leerzeile getrennt.
        Antworten dürfen mehrzeilig sein.
    </div>
    <form method="POST" action="{{ route('admin.ai_knowledge.import') }}">
        @csrf
        <div class="field">
            <textarea name="text" rows="8" required maxlength="100000" placeholder="F: Habt ihr Stromangebote?
A: Ja. Wir vergleichen anbieterunabhängig Strom- und Gastarife und melden uns mit passenden Angeboten.

F: Was kostet die Beratung?
A: Die Beratung ist kostenlos und unverbindlich.">{{ old('text') }}</textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="field">
                <label>Kategorie für alle *</label>
                <select name="category" required>
                    @foreach($categories as $key => $label)
                    <option value="{{ $key }}" @selected($key === 'faq')>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label>Sprache für alle</label>
                <select name="language">
                    <option value="">Alle Sprachen</option>
                    @foreach($languages as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <label style="display:flex;gap:8px;align-items:center;cursor:pointer;margin-bottom:14px;">
            <input type="checkbox" name="active" value="1">
            <span>Sofort aktiv (sonst als Entwurf zum Nachlesen)</span>
        </label>
        <button type="submit" class="btn btn-primary">Einträge anlegen</button>
    </form>
</div>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title" style="margin-bottom:14px;">➕ Neuer Eintrag</div>
    <form method="POST" action="{{ route('admin.ai_knowledge.store') }}">
        @csrf
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;">
            <div class="field"><label>Titel *</label><input type="text" name="title" required maxlength="255" placeholder="z. B. Unterlagen für eine Adressänderung"></div>
            <div class="field">
                <label>Kategorie *</label>
                <select name="category" required>
                    @foreach($categories as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label>Sprache</label>
                <select name="language">
                    <option value="">Alle Sprachen</option>
                    @foreach($languages as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="field"><label>Inhalt *</label><textarea name="content" rows="5" required maxlength="8000" placeholder="Kurz, sachlich und abschließend formuliert – der Assistent gibt diesen Inhalt sinngemäß an den Kunden weiter."></textarea></div>
        <div class="field"><label>Stichwörter (Komma getrennt)</label><input type="text" name="keywords" maxlength="500" placeholder="adresse, umzug, meldebescheinigung"></div>
        <label style="display:flex;gap:8px;align-items:center;cursor:pointer;margin-bottom:14px;">
            <input type="checkbox" name="active" value="1" checked>
            <span>Aktiv (der Assistent darf diesen Eintrag verwenden)</span>
        </label>
        <button type="submit" class="btn btn-primary">Eintrag speichern</button>
    </form>
</div>

{{-- Entwuerfe aus ki:wissensbasis-vorschlag: sie stammen woertlich aus den
     Leistungsseiten, sind aber INAKTIV. Erst die Freigabe hier macht sie zur
     Auskunft des Assistenten - deshalb steht der Weg dorthin ganz oben. --}}
@if($draftCount > 0)
<div style="background:#FFF6E0;border:1px solid #E8D9A8;color:#6B5A2A;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <strong>{{ $draftCount }} Entwurf/Entwürfe warten auf Freigabe.</strong>
    Entwürfe sind inaktiv – der Assistent verwendet sie nicht.
    Bitte lesen, bei Bedarf anpassen und dann freigeben.
    <a href="{{ route('admin.ai_knowledge', ['status' => 'entwurf']) }}" style="font-weight:700;">Nur Entwürfe anzeigen</a>
</div>
@endif

<form method="GET" action="{{ route('admin.ai_knowledge') }}" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Suchen …" style="max-width:280px;">
    <select name="status" style="max-width:200px;">
        <option value="">Alle Einträge</option>
        <option value="aktiv" @selected(request('status') === 'aktiv')>Nur aktive ({{ $activeCount }})</option>
        <option value="entwurf" @selected(request('status') === 'entwurf')>Nur Entwürfe ({{ $draftCount }})</option>
    </select>
    <select name="kategorie" style="max-width:220px;">
        <option value="">Alle Kategorien</option>
        @foreach($categories as $key => $label)
        <option value="{{ $key }}" @selected(request('kategorie') === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <button type="submit" class="btn">Filtern</button>
    <a href="{{ route('admin.ai_knowledge_gaps') }}" class="btn">Wissenslücken ansehen</a>
</form>

{{-- Leeres Ziel-Formular: die Auswahlkaestchen und Knopfe der Liste
     verweisen ueber form="bulkForm" darauf. Die Eintraege tragen jeweils ein
     eigenes Bearbeiten-Formular - verschachtelte Formulare waeren ungueltig. --}}
<form method="POST" action="{{ route('admin.ai_knowledge.bulk') }}" id="bulkForm"
      onsubmit="return confirm('Ausgewählte Einträge ändern? Freigegebene Einträge gibt der Assistent ab sofort als Auskunft weiter.');">@csrf</form>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--line);display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
        <span style="font-weight:700;">Einträge ({{ $entries->total() }})</span>
        @if($entries->count())
        <span style="font-size:12.5px;color:var(--ink-soft);">Auswahl:</span>
        <button type="submit" name="aktion" value="freigeben" form="bulkForm" class="btn btn-primary">Freigeben</button>
        <button type="submit" name="aktion" value="deaktivieren" form="bulkForm" class="btn">Deaktivieren</button>
        <button type="submit" name="aktion" value="loeschen" form="bulkForm" class="btn" style="color:#B3261E;">Löschen</button>
        @endif
    </div>
    @forelse($entries as $entry)
    <div style="padding:16px 20px;border-bottom:1px solid var(--line);{{ $entry->active ? '' : 'opacity:.6;' }}">
        <label style="display:flex;gap:8px;align-items:center;cursor:pointer;margin-bottom:10px;font-size:12.5px;color:var(--ink-soft);">
            <input type="checkbox" name="ids[]" value="{{ $entry->id }}" form="bulkForm">
            <span>
                @if($entry->active) Aktiv @else <strong>Entwurf – nicht aktiv</strong> @endif
                @if($entry->sourceLabel()) · Quelle: {{ $entry->sourceLabel() }} @endif
            </span>
        </label>
        <form method="POST" action="{{ route('admin.ai_knowledge.update', $entry->id) }}">
            @csrf @method('PUT')
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;">
                <div class="field"><label>Titel</label><input type="text" name="title" value="{{ $entry->title }}" required maxlength="255"></div>
                <div class="field">
                    <label>Kategorie</label>
                    <select name="category" required>
                        @foreach($categories as $key => $label)
                        <option value="{{ $key }}" @selected($entry->category === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Sprache</label>
                    <select name="language">
                        <option value="">Alle Sprachen</option>
                        @foreach($languages as $key => $label)
                        <option value="{{ $key }}" @selected($entry->language === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="field"><label>Inhalt</label><textarea name="content" rows="4" required maxlength="8000">{{ $entry->content }}</textarea></div>
            <div class="field"><label>Stichwörter</label><input type="text" name="keywords" value="{{ $entry->keywords }}" maxlength="500"></div>
            <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                <label style="display:flex;gap:8px;align-items:center;cursor:pointer;">
                    <input type="checkbox" name="active" value="1" @checked($entry->active)>
                    <span>Aktiv</span>
                </label>
                <button type="submit" class="btn btn-primary">Speichern</button>
                <span style="font-size:12.5px;color:var(--ink-soft);">
                    {{ $entry->categoryLabel() }} · {{ $entry->languageLabel() }}
                    @if($entry->editor) · zuletzt {{ $entry->editor->name }}@endif
                    @if($entry->updated_at) · {{ $entry->updated_at->format('d.m.Y H:i') }}@endif
                </span>
            </div>
        </form>
        <form method="POST" action="{{ route('admin.ai_knowledge.destroy', $entry->id) }}"
              onsubmit="return confirm('Diesen Eintrag löschen? Der Assistent kann das Thema danach nicht mehr beantworten und übergibt an das Team.');"
              style="margin-top:8px;">
            @csrf @method('DELETE')
            <button type="submit" class="btn" style="color:#B3261E;">Löschen</button>
        </form>
    </div>
    @empty
    <div style="padding:24px 20px;color:var(--ink-soft);">
        Noch keine Einträge. Bis hier etwas steht, beantwortet der Assistent ausschließlich Fragen,
        die er aus den Kundendaten belegen kann – alles andere geht an das Team.
        <div style="margin-top:10px;">
            Startpunkt: <code>php artisan ki:wissensbasis-vorschlag --schreiben</code> überträgt die Texte
            der gepflegten Leistungsseiten (Einleitung, Leistungen, häufige Fragen – deutsch und arabisch)
            wörtlich als Entwürfe hierher. Danach hier lesen und freigeben.
        </div>
    </div>
    @endforelse
</div>

<div style="margin-top:16px;">{{ $entries->links() }}</div>
@endsection
