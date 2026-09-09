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

  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <h1 style="margin:0;font-size:20px">Postfach</h1>
    @if($filters->isFiltered())
      <a href="{{ route('admin.postfach') }}" class="btn btn-ghost">Filter zurücksetzen</a>
    @endif
  </div>

  {{-- Sichten und Kanaele sind LINKS: jeder Stand ist teilbar und
       zurueck-tauglich (dieselbe Lehre wie bei den grossen Listen). --}}
  <div class="card" style="padding:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    @foreach(\App\Services\Messaging\Inbox\InboxFilters::VIEWS as $key => $label)
      <a href="{{ $link(['sicht' => $key === 'alle' ? null : $key]) }}"
         class="badge {{ $filters->view === $key ? 'badge-emerald' : '' }}"
         style="text-decoration:none">
        {{ $label }} ({{ $counts['views'][$key] ?? 0 }})
      </a>
    @endforeach

    <span style="width:1px;height:20px;background:var(--line)"></span>

    <a href="{{ $link(['kanal' => null]) }}"
       class="badge {{ $filters->channel === null ? 'badge-emerald' : '' }}"
       style="text-decoration:none">Alle Kanäle</a>
    @foreach($kanaele as $kanal)
      <a href="{{ $link(['kanal' => $kanal->key]) }}"
         class="badge {{ $filters->channel === $kanal->key ? 'badge-emerald' : '' }}"
         style="text-decoration:none">
        {{ $kanal->name }} ({{ $counts['channels'][$kanal->key] ?? 0 }})
      </a>
    @endforeach
  </div>

  {{-- EINE Suche fuer alle Kanaele. --}}
  <form method="GET" action="{{ route('admin.postfach') }}" class="card"
        style="padding:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    @foreach($filters->urlParams(['q' => null]) as $name => $wert)
      <input type="hidden" name="{{ $name }}" value="{{ $wert }}">
    @endforeach
    <input type="search" name="q" value="{{ $filters->search }}" class="field" style="flex:1;min-width:200px"
           placeholder="Kunde, Nachricht, Telefon, E-Mail oder Kennung …">
    <select name="status" class="field" style="width:auto">
      <option value="">Jeder Zustand</option>
      @foreach(\App\Models\Conversation::STATUSES as $key => $label)
        <option value="{{ $key }}" @selected($filters->status === $key)>{{ $label }}</option>
      @endforeach
    </select>
    <select name="zustaendig" class="field" style="width:auto">
      <option value="">Jede Zuständigkeit</option>
      @foreach($mitarbeiter as $m)
        <option value="{{ $m->id }}" @selected($filters->assignee === $m->id)>{{ $m->name }}</option>
      @endforeach
    </select>
    <select name="betreuer" class="field" style="width:auto">
      <option value="">Jeder Betreuer</option>
      @foreach($mitarbeiter as $m)
        <option value="{{ $m->id }}" @selected($filters->betreuer === $m->id)>{{ $m->name }}</option>
      @endforeach
    </select>
    <input type="date" name="von" value="{{ $filters->from }}" class="field" style="width:auto">
    <input type="date" name="bis" value="{{ $filters->to }}" class="field" style="width:auto">
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
                  {{ $active && $active->id === $c->id ? 'background:var(--surface-2)' : '' }}">
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
          <div style="font-size:12px;color:var(--muted);margin-top:4px;display:flex;gap:8px;flex-wrap:wrap">
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
        <p style="padding:16px;color:var(--muted);margin:0">Keine Unterhaltungen für diese Auswahl.</p>
      @endforelse
      <div style="padding:10px">{{ $conversations->links() }}</div>
    </div>

    {{-- Unterhaltung --}}
    <div class="card" style="padding:16px">
      @if(! $active)
        <p style="color:var(--muted);margin:0">Wählen Sie links eine Unterhaltung.</p>
      @else
        @include('admin.postfach._conversation')
      @endif
    </div>
  </div>
</div>

<style>
  /* Auf dem Telefon untereinander statt nebeneinander - sonst ist keine
     der beiden Spalten mehr benutzbar. */
  @media (max-width: 900px) {
    .postfach-grid { grid-template-columns: 1fr !important; }
  }
</style>
@endsection
