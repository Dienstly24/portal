@extends('layouts.admin')
@section('content')
{{-- Wissensluecken (Betreiber-Auftrag 18.08.2026).

     Der Assistent lernt NICHT von selbst - er sagt nur weiter, was ein
     Mensch freigegeben hat. Was er aber kann: melden, wonach gefragt wurde,
     ohne dass eine Antwort hinterlegt ist. Diese Liste ist genau diese
     Rueckmeldung: einmal beantworten, ab dann beantwortet er es selbst. --}}
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <a href="{{ route('admin.ai_knowledge') }}">KI-Wissensbasis</a><span class="breadcrumb-sep">›</span>
        <span>Wissenslücken</span>
    </div>
    <div>
        <div class="page-title">Wissenslücken</div>
        <div class="page-sub">
            Danach wurde gefragt, ohne dass eine Antwort hinterlegt ist – häufigstes zuerst.
            Einmal beantworten genügt: ab dann beantwortet der Assistent es selbst.
        </div>
    </div>
</div>

@if(session('success'))<div style="background:#D9F4E6;color:#17A65B;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if($errors->any())<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ $errors->first() }}</div>@endif

<form method="GET" action="{{ route('admin.ai_knowledge_gaps') }}" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
    <select name="status" style="max-width:220px;">
        <option value="offen" @selected($status === 'offen')>Offen ({{ $openCount }})</option>
        <option value="erledigt" @selected($status === 'erledigt')>Erledigt</option>
        <option value="ignoriert" @selected($status === 'ignoriert')>Ignoriert</option>
        <option value="alle" @selected($status === 'alle')>Alle</option>
    </select>
    <select name="bereich" style="max-width:220px;">
        <option value="">Alle Bereiche</option>
        @foreach($scopes as $key => $label)
        <option value="{{ $key }}" @selected(request('bereich') === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <button type="submit" class="btn">Filtern</button>
    <a href="{{ route('admin.ai_knowledge') }}" class="btn">Zur Wissensbasis</a>
</form>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 20px;font-weight:700;border-bottom:1px solid var(--line);">Themen ({{ $gaps->total() }})</div>
    @forelse($gaps as $gap)
    <div style="padding:16px 20px;border-bottom:1px solid var(--line);">
        <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap;margin-bottom:10px;">
            <span style="font-weight:700;font-size:15px;">{{ $gap->topic }}</span>
            <span style="background:#F1EEE5;border-radius:999px;padding:2px 10px;font-size:12.5px;">{{ $gap->hits }}× gefragt</span>
            <span style="font-size:12.5px;color:var(--ink-soft);">
                {{ $gap->scopeLabel() }}
                @if($gap->last_seen_at) · zuletzt {{ $gap->last_seen_at->format('d.m.Y H:i') }}@endif
                @if($gap->status === 'erledigt') · erledigt{{ $gap->resolver ? ' von ' . $gap->resolver->name : ' (automatisch)' }}@endif
                @if($gap->status === 'ignoriert') · ignoriert @endif
            </span>
        </div>

        @if($gap->status !== 'erledigt')
        {{-- Antwort schreiben = Wissenseintrag anlegen und Luecke schliessen
             in einem Schritt. Der Titel ist mit dem gefragten Thema
             vorbelegt, damit die Suche des Assistenten ihn spaeter findet. --}}
        <form method="POST" action="{{ route('admin.ai_knowledge_gaps.answer', $gap->id) }}">
            @csrf
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;">
                <div class="field"><label>Titel *</label><input type="text" name="title" required maxlength="255" value="{{ $gap->topic }}"></div>
                <div class="field">
                    <label>Kategorie *</label>
                    <select name="category" required>
                        @foreach($categories as $key => $label)
                        <option value="{{ $key }}" @selected($key === 'faq')>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Sprache</label>
                    <select name="language">
                        <option value="">Alle Sprachen</option>
                        @foreach($languages as $key => $label)
                        <option value="{{ $key }}" @selected($gap->language === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Antwort *</label>
                <textarea name="content" rows="4" required maxlength="8000"
                          placeholder="Die Antwort, die der Assistent ab jetzt geben soll – kurz, sachlich, abschließend."></textarea>
            </div>
            <div class="field"><label>Stichwörter (Komma getrennt)</label><input type="text" name="keywords" maxlength="500" placeholder="strom, angebot, tarif"></div>
            <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                <label style="display:flex;gap:8px;align-items:center;cursor:pointer;">
                    <input type="checkbox" name="active" value="1" checked>
                    <span>Sofort aktiv</span>
                </label>
                <button type="submit" class="btn btn-primary">Antwort speichern</button>
            </div>
        </form>
        @endif

        <form method="POST" action="{{ route('admin.ai_knowledge_gaps.status', $gap->id) }}" style="margin-top:8px;">
            @csrf
            @if($gap->status === 'ignoriert')
            <button type="submit" name="status" value="offen" class="btn">Wieder öffnen</button>
            @elseif($gap->status !== 'erledigt')
            <button type="submit" name="status" value="ignoriert" class="btn"
                    title="Kein Thema für die Wissensbasis – taucht wieder auf, wenn erneut danach gefragt wird.">
                Ignorieren
            </button>
            @endif
        </form>
    </div>
    @empty
    <div style="padding:24px 20px;color:var(--ink-soft);">
        Keine Einträge. Sobald der Assistent nach einem Thema sucht und nichts findet,
        steht es hier – mit der Zahl, wie oft danach gefragt wurde.
    </div>
    @endforelse
</div>

<div style="margin-top:16px;">{{ $gaps->links() }}</div>
@endsection
