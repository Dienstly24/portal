@extends('layouts.admin')
@section('content')
{{-- Facebook-Empfehlungen (Betreiber-Wunsch 21.11.2026).
     Auf die Startseite kommt nur, was hier freigegeben ist. Ein Kundentext
     wird nie umformuliert - passt er nicht, wird er abgelehnt. --}}
<div class="page-header">
    <div class="breadcrumb">
        <a href="{{ route('admin.dashboard') }}">🏠</a><span class="breadcrumb-sep">›</span>
        <span>Facebook-Bewertungen</span>
    </div>
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
        <div>
            <h1 class="page-title">Facebook-Bewertungen</h1>
            <div class="page-sub">
                Empfehlungen von unserer Facebook-Seite. Freigegebene erscheinen auf der Startseite unter
                „Kundenstimmen" (höchstens {{ \App\Models\FacebookBewertung::MAX_ANZEIGE }}, die neuesten zuerst).
            </div>
        </div>
        @if($eingerichtet)
            <form method="POST" action="{{ route('admin.facebook_bewertungen.abrufen') }}">
                @csrf
                <button type="submit" class="btn btn-ghost">↻ Jetzt bei Facebook abrufen</button>
            </form>
        @endif
    </div>
</div>

@if(session('success'))<div style="background:var(--emerald-soft);color:var(--emerald);padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ session('success') }}</div>@endif
@if($errors->any())<div style="background:#FBE9E9;color:#B3261E;padding:10px 16px;border-radius:8px;margin-bottom:16px;">{{ $errors->first() }}</div>@endif

@unless($eingerichtet)
    <div class="card" style="margin-bottom:16px;">
        Der Abruf ist nicht eingerichtet: es fehlen <code>META_PAGE_ID</code> und ein Token in der Server-<code>.env</code>
        (dieselbe Einrichtung wie beim Posten, <code>php artisan meta:einrichten</code>).
    </div>
@endunless

<div class="card" style="margin-bottom:16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
    <div>
        <b>Automatische Freigabe: {{ $automatik ? 'AN' : 'AUS' }}</b>
        <div style="font-size:.85rem;color:var(--ink-soft);">
            @if($automatik)
                Positive Empfehlungen ohne jeden Hinweis gehen nach dem Abruf direkt auf die Website.
                Alles mit Hinweis bleibt unter „Neu" und erscheint nicht – Sie müssen nichts tun.
            @else
                Jede Empfehlung wartet auf Ihre Freigabe.
            @endif
        </div>
    </div>
    <form method="POST" action="{{ route('admin.facebook_bewertungen.automatik') }}">@csrf
        <input type="hidden" name="an" value="{{ $automatik ? '0' : '1' }}">
        <button type="submit" class="btn {{ $automatik ? 'btn-ghost' : 'btn-primary' }}">{{ $automatik ? 'Ausschalten' : 'Einschalten' }}</button>
    </form>
</div>

<div class="card" style="margin-bottom:16px;font-size:.9rem;">
    <b>Regeln für die Freigabe</b>
    <ul style="margin:6px 0 0;padding-inline-start:18px;">
        <li>Nur positive Empfehlungen mit Text. Der Text wird nie geändert oder gekürzt.</li>
        <li>„Beratung" ist in Ordnung, wenn es um <b>Strom, Gas oder Internet</b> geht (steht das Wort dabei, gibt die Automatik frei).
            Spricht der Text von Beratung zu einer <b>Versicherung</b>, ist die Freigabe gesperrt (keine Erlaubnis nach § 34d GewO).</li>
        <li>Auf der Website steht nur der Vorname und der erste Buchstabe des Nachnamens.</li>
        <li>Ändert der Verfasser seinen Text bei Facebook, muss er neu freigegeben werden; löscht er ihn, verschwindet er von der Website.</li>
    </ul>
    @if($stand)
        <div style="margin-top:8px;color:var(--ink-soft);">Letzter Abruf: {{ \Illuminate\Support\Carbon::parse($stand['stand'])->lokal()->format('d.m.Y H:i') }}
            – {{ $stand['gesamt'] }} Empfehlungen, davon {{ $stand['positiv'] }} positiv.</div>
    @endif
</div>

<div class="tab-row" style="margin-bottom:14px;">
    @foreach(['neu' => 'Neu', 'freigegeben' => 'Freigegeben', 'abgelehnt' => 'Abgelehnt'] as $key => $label)
        <a href="{{ route('admin.facebook_bewertungen', ['status' => $key]) }}" class="tab {{ $status === $key ? 'active' : '' }}">
            {{ $label }} ({{ $zaehler[$key] ?? 0 }})
        </a>
    @endforeach
</div>

@forelse($bewertungen as $b)
    @php $hinweise = $b->hinweise(); $sperre = $b->freigabeGesperrt(); @endphp
    <div class="card" style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <b>{{ $b->name_kurz ?? 'Ohne Namen' }}</b>
                <span style="color:var(--ink-soft);font-size:.85rem;">
                    · {{ $b->verfasst_at?->lokal()->format('d.m.Y') ?? 'ohne Datum' }}
                    · {{ $b->istPositiv() ? '👍 empfiehlt' : '👎 empfiehlt nicht' }}
                    @if($b->sterne) · {{ $b->sterne }} ★ @endif
                    @if($b->entfernt_at) · <span style="color:#B3261E;">bei Facebook nicht mehr vorhanden</span> @endif
                </span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                @if($b->status !== 'freigegeben' && ! $sperre && ! $b->entfernt_at)
                    <form method="POST" action="{{ route('admin.facebook_bewertungen.status', $b->id) }}">@csrf
                        <input type="hidden" name="status" value="freigegeben">
                        <button class="btn btn-primary btn-sm" type="submit">✓ Freigeben</button>
                    </form>
                @endif
                @if($b->status !== 'abgelehnt')
                    <form method="POST" action="{{ route('admin.facebook_bewertungen.status', $b->id) }}">@csrf
                        <input type="hidden" name="status" value="abgelehnt">
                        <button class="btn btn-ghost btn-sm" type="submit">✕ Ablehnen</button>
                    </form>
                @endif
                @if($b->status !== 'neu')
                    <form method="POST" action="{{ route('admin.facebook_bewertungen.status', $b->id) }}">@csrf
                        <input type="hidden" name="status" value="neu">
                        <button class="btn btn-ghost btn-sm" type="submit">↺ Zurück auf „neu"</button>
                    </form>
                @endif
            </div>
        </div>
        <p dir="auto" style="margin:10px 0 0;white-space:pre-line;">{{ $b->text ?? '(ohne Text)' }}</p>
        @foreach($hinweise as $h)
            <div style="margin-top:8px;font-size:.85rem;padding:6px 10px;border-radius:6px;
                {{ $h['art'] === 'sperre' ? 'background:#FBE9E9;color:#B3261E;' : 'background:#FFF4E0;color:#8A5A00;' }}">
                {{ $h['art'] === 'sperre' ? '⛔' : '⚠' }} {{ $h['text'] }}
            </div>
        @endforeach
        @if($b->geprueft_at)
            <div style="margin-top:6px;font-size:.8rem;color:var(--ink-soft);">
                {{ $b->auto_freigegeben ? 'Automatisch freigegeben' : 'Geprüft von '.($b->pruefer?->name ?? 'unbekannt') }} am {{ $b->geprueft_at->lokal()->format('d.m.Y H:i') }}
            </div>
        @endif
    </div>
@empty
    <div class="card" style="color:var(--ink-soft);">Keine Empfehlungen in dieser Liste.</div>
@endforelse

{{ $bewertungen->links() }}
@endsection
