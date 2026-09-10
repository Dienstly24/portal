@extends('layouts.admin')
@section('title', 'Postfach')

@section('content')
@php
    /**
     * EIN Postfach, viele Kanaele.
     *
     * Diese Vorlage nennt KEINEN Kanal beim Namen: Beschriftung, Reihung
     * und Faehigkeiten kommen aus der Datenbank. Ein neuer Kanal
     * erscheint hier von selbst - genau das war der Zweck der
     * Kanal-Abstraktion.
     */
    $link = fn (array $p) => route('admin.postfach', $filters->urlParams($p));
@endphp

<div style="display:flex;flex-direction:column;gap:16px">

  <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <div class="page-title" style="margin-bottom:2px;">Postfach</div>
      <div class="page-sub">Alle Kanäle in einer Liste — auch Nachrichten ohne zugeordnete Kundenakte.</div>
    </div>
    @if($filters->isFiltered())
      <a href="{{ route('admin.postfach') }}" class="btn btn-ghost">Filter zurücksetzen</a>
    @endif
  </div>

  {{-- Sichten und Kanaele sind LINKS: jeder Stand ist teilbar und
       zurueck-tauglich (dieselbe Lehre wie bei den grossen Listen).
       Als .tab, nicht als .badge: eine Marke sagt einen ZUSTAND aus,
       ein Reiter ist eine AUSWAHL - und nur der Reiter hat einen
       sichtbaren Aktiv-Zustand. Ohne ihn sahen alle sieben gleich aus. --}}
  <div class="tab-row" style="margin-bottom:0;">
    @foreach(\App\Services\Messaging\Inbox\InboxFilters::VIEWS as $key => $label)
      <a href="{{ $link(['sicht' => $key === 'alle' ? null : $key]) }}"
         class="tab {{ $filters->view === $key ? 'active' : '' }}">
        {{ $label }} <span class="tab-count">{{ $counts['views'][$key] ?? 0 }}</span>
      </a>
    @endforeach

    <span class="tab-trenner" aria-hidden="true"></span>

    <a href="{{ $link(['kanal' => null]) }}"
       class="tab {{ $filters->channel === null ? 'active' : '' }}">Alle Kanäle</a>
    @foreach($kanaele as $kanal)
      <a href="{{ $link(['kanal' => $kanal->key]) }}"
         class="tab {{ $filters->channel === $kanal->key ? 'active' : '' }}">
        {{ $kanal->name }} <span class="tab-count">{{ $counts['channels'][$kanal->key] ?? 0 }}</span>
      </a>
    @endforeach
  </div>

  <form method="GET" action="{{ route('admin.postfach') }}" class="card filterleiste">
    @foreach($filters->urlParams(['q' => null]) as $name => $wert)
      <input type="hidden" name="{{ $name }}" value="{{ $wert }}">
    @endforeach

    <div class="filter-raster">
      <div class="field filter-suche">
        <label for="fSuche">Suche</label>
        <input type="search" name="q" id="fSuche" value="{{ $filters->search }}"
               placeholder="Kunde, Nachricht, Telefon, E-Mail oder Kennung …">
      </div>
      <div class="field">
        <label for="fStatus">Zustand</label>
        <select name="status" id="fStatus">
          <option value="">Jeder Zustand</option>
          @foreach(\App\Models\Conversation::STATUSES as $key => $label)
            <option value="{{ $key }}" @selected($filters->status === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="fZustaendig">Zuständigkeit</label>
        <select name="zustaendig" id="fZustaendig">
          <option value="">Jede Zuständigkeit</option>
          @foreach($mitarbeiter as $m)
            <option value="{{ $m->id }}" @selected($filters->assignee === $m->id)>{{ $m->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="fBetreuer">Betreuer</label>
        <select name="betreuer" id="fBetreuer">
          <option value="">Jeder Betreuer</option>
          @foreach($mitarbeiter as $m)
            <option value="{{ $m->id }}" @selected($filters->betreuer === $m->id)>{{ $m->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="fVon">Von</label>
        <input type="date" name="von" id="fVon" value="{{ $filters->from }}">
      </div>
      <div class="field">
        <label for="fBis">Bis</label>
        <input type="date" name="bis" id="fBis" value="{{ $filters->to }}">
      </div>
    </div>

    <button class="btn btn-primary">Suchen</button>
  </form>

  <div style="display:grid;grid-template-columns:minmax(280px,380px) 1fr;gap:16px;align-items:start"
       class="postfach-grid">

    {{-- Liste --}}
    <div class="card" style="padding:0;overflow:hidden">
      @forelse($conversations as $c)
        @php $unbekannt = $c->customer_id === null; @endphp
        <a href="{{ $link(['unterhaltung' => $c->id]) }}"
           style="display:block;padding:12px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;
                  {{ $active && $active->id === $c->id ? 'background:var(--surface-soft)' : '' }}">
          <div style="display:flex;justify-content:space-between;gap:8px;align-items:baseline">
            <strong style="font-size:14px">
              @if($unbekannt)
                Unbekannter Kontakt
              @else
                {{ $c->customer?->user?->name ?: 'Kunde '.$c->customer?->customer_number }}
              @endif
            </strong>
            @if($c->unread_count)
              <span class="badge badge-urgent">{{ $c->unread_count }}</span>
            @endif
          </div>
          <div style="font-size:12px;color:var(--ink-soft);margin-top:4px;display:flex;gap:8px;flex-wrap:wrap">
            {{-- KANAL-KENNZEICHEN an jeder Zeile (Auftrag 4). --}}
            <span class="badge">{{ $c->channel?->name ?: 'Kanal' }}</span>
            @if($unbekannt && $c->external_user_id)
              <span>{{ $c->external_user_id }}</span>
            @endif
            @if($c->assignee)<span>Zuständig: {{ $c->assignee->name }}</span>@endif
            <span>{{ $c->last_message_at?->lokal()?->format('d.m.Y H:i') }}</span>
          </div>
        </a>
      @empty
        <p style="padding:16px;color:var(--ink-soft);margin:0">Keine Unterhaltungen für diese Auswahl.</p>
      @endforelse
      <div style="padding:10px">{{ $conversations->links() }}</div>
    </div>

    {{-- Unterhaltung --}}
    <div class="card" style="padding:16px">
      @if(! $active)
        <p style="color:var(--ink-soft);margin:0">Wählen Sie links eine Unterhaltung.</p>
      @else
        @include('admin.postfach._conversation')
      @endif
    </div>
  </div>
</div>

@push('styles')
<style @cspNonce>
  .tab-trenner { width: 1px; align-self: stretch; background: var(--line); margin: 0 4px; }

  /* Beschriftete Felder in einem Raster. Vorher eine flex-Reihe aus
     namenlosen Kaesten: man musste jedes anklicken, um zu erfahren,
     wonach es filtert. */
  .filterleiste { display: flex; flex-direction: column; gap: 14px; align-items: flex-start; }
  .filter-raster {
    display: grid; width: 100%;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 0 14px;
  }
  .filter-suche { grid-column: span 2; }
  @media (max-width: 700px) { .filter-suche { grid-column: span 1; } }

  /* Auf dem Telefon untereinander statt nebeneinander - sonst ist keine
     der beiden Spalten mehr benutzbar. */
  @media (max-width: 900px) {
    .postfach-grid { grid-template-columns: 1fr !important; }
  }
</style>
@endpush
@endsection
