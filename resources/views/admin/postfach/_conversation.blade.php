@php
    $unbekannt = $active->customer_id === null;
    $betreuer = $active->customer?->betreuerPrimary();
    $kiZustand = $active->customer_id
        ? \App\Models\AiConversation::where('customer_id', $active->customer_id)->first()
        : null;
@endphp

{{-- KOPF: Kanal, Kunde, Betreuer, Zustaendigkeit, KI - die fuenf Angaben,
     die ein Mitarbeiter braucht, um den Fall ohne Nachlesen zu uebernehmen. --}}
<div style="display:flex;gap:16px;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;
            border-bottom:1px solid var(--line);padding-bottom:12px;margin-bottom:12px">
  <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:13px">
    <div>
      <div style="color:var(--ink-soft);font-size:11px">Kunde</div>
      @if($unbekannt)
        <strong>Unbekannter Kontakt</strong>
        <div style="color:var(--ink-soft)">{{ $active->external_user_id }}</div>
      @else
        <a href="{{ route('admin.customer', $active->customer_id) }}">
          <strong>{{ $active->customer?->user?->name ?: $active->customer?->customer_number }}</strong>
        </a>
      @endif
    </div>
    <div>
      {{-- MEHRERE KANAELE (Phase 2): eine Unterhaltung kann ueber
           WhatsApp begonnen und im Portal weitergegangen sein. Wer nur
           einen Namen sieht, haelt den Rest fuer einen anderen Vorgang. --}}
      <div style="color:var(--ink-soft);font-size:11px">
        {{ $kanaeleDerUnterhaltung->count() > 1 ? 'Kanäle' : 'Kanal' }}
      </div>
      @if($kanaeleDerUnterhaltung->isEmpty())
        <strong>{{ $active->channel?->name }}</strong>
      @else
        <div class="kanal-liste">
          @foreach($kanaeleDerUnterhaltung as $link)
            <span class="kanal-marke" title="{{ $link->joinMethodLabel() }}{{ $link->external_user_id ? ' · '.$link->external_user_id : '' }}">
              {{ $link->channel?->name }}
              @if($link->isJoined())<span class="kanal-marke-zusatz">+</span>@endif
            </span>
          @endforeach
        </div>
      @endif
      @if($active->channelAccount)
        <div style="color:var(--ink-soft)">{{ $active->channelAccount->name }}</div>
      @endif
    </div>
    @if($kanaeleDerUnterhaltung->count() > 1)
      <div>
        <div style="color:var(--ink-soft);font-size:11px">Zuletzt über</div>
        <strong>{{ $active->lastChannel?->name ?: $active->channel?->name }}</strong>
      </div>
    @endif
    <div>
      <div style="color:var(--ink-soft);font-size:11px">Betreuer</div>
      <strong>{{ $betreuer?->name ?: '—' }}</strong>
    </div>
    <div>
      <div style="color:var(--ink-soft);font-size:11px">Zuständig</div>
      <strong>{{ $active->assignee?->name ?: 'Niemand' }}</strong>
    </div>
    <div>
      <div style="color:var(--ink-soft);font-size:11px">KI</div>
      <strong>
        @if($kiZustand && ! $kiZustand->ai_active)
          Pausiert
        @elseif($kiZustand && $kiZustand->handover_required)
          Übergabe an das Team
        @else
          Aktiv
        @endif
      </strong>
    </div>
    <div>
      <div style="color:var(--ink-soft);font-size:11px">Zustand</div>
      <strong>{{ \App\Models\Conversation::STATUSES[$active->status] ?? $active->status }}</strong>
    </div>
  </div>

  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <form method="POST" action="{{ route('admin.postfach.take_over', $active->id) }}">
      @csrf<button class="btn btn-ghost">Übernehmen</button>
    </form>
    <form method="POST" action="{{ route('admin.postfach.status', $active->id) }}" style="display:flex;gap:4px">
      @csrf
      <select name="status" class="eingabe">
        @foreach(\App\Models\Conversation::STATUSES as $key => $label)
          <option value="{{ $key }}" @selected($active->status === $key)>{{ $label }}</option>
        @endforeach
      </select>
      <button class="btn btn-ghost">Setzen</button>
    </form>
  </div>
</div>

{{-- Der Betreuer aendert sich NIE durch eine Uebernahme. Das steht hier
     ausdruecklich, weil genau diese Verwechslung der Anlass war. --}}
<form method="POST" action="{{ route('admin.postfach.reassign', $active->id) }}"
      style="display:flex;gap:6px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
  @csrf
  <label style="font-size:12px;color:var(--ink-soft)">Zuständigkeit übergeben an</label>
  <select name="employee_id" class="eingabe" aria-label="Zuständigkeit übergeben an">
    @foreach($mitarbeiter as $m)
      <option value="{{ $m->id }}" @selected($active->assigned_employee_id === $m->id)>{{ $m->name }}</option>
    @endforeach
  </select>
  <button class="btn btn-ghost">Übergeben</button>
  <span style="font-size:11px;color:var(--ink-soft)">Der Betreuer des Kunden bleibt unverändert.</span>
</form>

@if(($identitaet ?? null) && $identitaet->needsVerification())
  {{-- ZUORDNUNG AUF EINEM INDIZ (Auftrag Abschnitt 8).
       Die Zuordnung funktioniert - sie wurde nur nie von einem Menschen
       geprueft. Eine Rufnummer kann weitergegeben oder von einem
       Familienmitglied benutzt werden; das faellt sonst erst auf, wenn
       ein Kunde die Unterhaltung eines anderen liest. --}}
  <div class="zuordnung-hinweis">
    <div>
      <strong>Zuordnung noch nicht bestätigt</strong>
      <p>
        {{ $identitaet->methodLabel() }} — von keinem Mitarbeiter geprüft.
        Bitte kurz ansehen, ob die Nachrichten wirklich zu dieser Kundenakte gehören.
      </p>
    </div>
    <form method="POST" action="{{ route('admin.postfach.confirm_identity', $active->id) }}">
      @csrf<button class="btn btn-ghost btn-sm">Zuordnung bestätigen</button>
    </form>
  </div>
@endif

@if($unbekannt)
  {{-- UNBEKANNTER KONTAKT (Prioritaet 3): die Nachricht ist da, nur die
       Akte fehlt. Beides laesst sich hier erledigen, ohne den Verlauf zu
       verlieren - die Unterhaltung bleibt dieselbe. --}}
  <div class="card" style="padding:12px;margin-bottom:12px;background:var(--surface-soft)">
    <strong style="font-size:13px">Kein Kunde zugeordnet</strong>
    <p style="font-size:12px;color:var(--ink-soft);margin:4px 0 10px">
      Kennung: {{ $active->external_user_id }} — Verlauf und Zuständigkeit bleiben erhalten.
    </p>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
      <form method="POST" action="{{ route('admin.postfach.create_customer', $active->id) }}"
            style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        @csrf
        <input name="first_name" class="eingabe" style="width:130px" placeholder="Vorname" required aria-label="Vorname">
        <input name="last_name" class="eingabe" style="width:130px" placeholder="Nachname" required aria-label="Nachname">
        <button class="btn btn-primary">Kunde anlegen</button>
      </form>
      <form method="POST" action="{{ route('admin.postfach.link_customer', $active->id) }}"
            style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        @csrf
        <input name="customer_id" class="eingabe" style="width:280px"
               placeholder="Kundennummer/ID einer bestehenden Akte" required aria-label="Kundennummer/ID einer bestehenden Akte">
        <button class="btn btn-ghost">Mit Kunde verknüpfen</button>
      </form>
    </div>
  </div>
@endif

{{-- Verlauf --}}
@php
    // Der Uebergang von der nachgelieferten Historie zum laufenden
    // Betrieb. Ohne diese Trennung liest ein Mitarbeiter eine Frage von
    // vor drei Monaten wie eine von heute - und antwortet darauf.
    $ersteLive = $messages->firstWhere('source', \App\Models\CustomerMessage::SOURCE_LIVE);
    $hatHistorie = $messages->contains('source', \App\Models\CustomerMessage::SOURCE_HISTORICAL);
    $trennerGesetzt = false;
@endphp

<div style="max-height:50vh;overflow-y:auto;display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
  @if($hatHistorie)
    <div style="text-align:center;font-size:11px;color:var(--ink-soft);text-transform:uppercase;
                letter-spacing:.05em;margin:4px 0">
      ──── WhatsApp-Historie (vor der Anbindung) ────
    </div>
  @endif

  @foreach($messages as $m)
    @php $eigen = $m->from_staff; @endphp

    @if($hatHistorie && ! $trennerGesetzt && $ersteLive && $m->id === $ersteLive->id)
      @php $trennerGesetzt = true; @endphp
      <div style="text-align:center;font-size:11px;color:var(--ink-soft);text-transform:uppercase;
                  letter-spacing:.05em;margin:8px 0;border-top:1px solid var(--line);padding-top:8px">
        ──── Mit Dienstly verbunden ────
      </div>
    @endif
    <div style="align-self:{{ $eigen ? 'flex-end' : 'flex-start' }};max-width:80%">
      <div style="padding:8px 12px;border-radius:10px;font-size:13px;
                  background:{{ $eigen ? 'var(--emerald-soft, #e7f5ee)' : 'var(--surface-soft)' }}">
        {!! nl2br(e($m->body)) !!}
        @foreach($m->attachments as $a)
          <div class="anhang-zeile">
            <a href="{{ route('admin.messages.attachment', $a->id) }}">{{ $a->file_name }}</a>
            {{-- Der Weg in die Akte. Bewusst je Anhang und als
                 ausdrueckliche Aktion: nicht jedes Bild in einer
                 Unterhaltung ist ein Nachweis. --}}
            @if($a->istUebernommen())
              <span class="badge badge-approved" title="Liegt als Unterlage in der Kundenakte">in der Akte</span>
            @elseif($active->customer_id)
              <form method="POST" action="{{ route('admin.postfach.file_attachment', $a->id) }}">
                @csrf
                <button class="btn btn-ghost btn-sm">In die Akte</button>
              </form>
            @endif
          </div>
        @endforeach
      </div>
      <div style="font-size:11px;color:var(--ink-soft);margin-top:2px;text-align:{{ $eigen ? 'right' : 'left' }}">
        @if($m->ai_generated)
          🤖 {{ \App\Models\CustomerMessage::AI_SENDER_NAME }}
        @elseif($eigen)
          {{ $m->sender?->name ?: 'Team' }}
        @else
          Kunde
        @endif
        · {{ $m->created_at?->lokal()?->format('d.m.Y H:i') }}
        {{-- WOHER kam diese Nachricht? (Auftrag Abschnitt 7) Nur wenn
             die Unterhaltung mehr als einen Kanal traegt - sonst stuende
             an jeder Zeile derselbe Name und saegte nichts aus. --}}
        @if($kanaeleDerUnterhaltung->count() > 1 && $m->channel)
          · <span class="kanal-marke kanal-marke-klein">{{ $m->channel->name }}</span>
        @endif
        @if($m->isHistorical())
          · <span title="Aus der WhatsApp Business App übernommen">Historie</span>
        @endif
      </div>
    </div>
  @endforeach
</div>

{{-- INTERNE NOTIZEN (Auftrag Abschnitt 13).
     Bewusst ein eigener, anders aussehender Block und NICHT in den
     Verlauf gemischt: wer eine interne Bemerkung zwischen den
     Kundennachrichten stehen sieht, tippt sie beim naechsten Mal ins
     Antwortfeld. Die Trennung muss man SEHEN. --}}
<details class="notizen" @if($notizen->isNotEmpty()) open @endif>
  <summary>Interne Notizen ({{ $notizen->count() }}) — der Kunde sieht sie nicht</summary>

  @foreach($notizen as $n)
    <div class="notiz">
      <div class="notiz-text">{!! nl2br(e($n->body)) !!}</div>
      <div class="notiz-fuss">
        {{ $n->author?->name ?: 'Unbekannt' }}
        · {{ $n->created_at?->lokal()?->format('d.m.Y H:i') }}
        @if($n->visibility === \App\Models\ConversationNote::VISIBILITY_SUPPORT)
          · <span title="Auch für einen beauftragten externen Support sichtbar">auch für externen Support</span>
        @endif
      </div>
    </div>
  @endforeach

  <form method="POST" action="{{ route('admin.postfach.note', $active->id) }}" class="notiz-formular">
    @csrf
    <textarea name="body" class="eingabe" rows="2" required
              placeholder="Interne Notiz zu diesem Vorgang …" aria-label="Interne Notiz"></textarea>
    <div class="notiz-zeile">
      <label class="check-inline">
        <input type="checkbox" name="visibility"
               value="{{ \App\Models\ConversationNote::VISIBILITY_SUPPORT }}">
        <span>Auch für externen Support sichtbar</span>
      </label>
      <button class="btn btn-ghost btn-sm">Notiz speichern</button>
    </div>
  </form>
</details>

@php $trennbar = $kanaeleDerUnterhaltung->filter(fn ($l) => $l->isJoined()); @endphp
@if($trennbar->isNotEmpty())
  {{-- DER RUECKWEG. Ohne ihn waere eine falsche Zusammenfuehrung
       endgueltig - und genau deshalb waere sie dann auch nicht
       vertretbar. --}}
  <details class="kanal-trennen">
    <summary>Nachträglich verbundene Kanäle ({{ $trennbar->count() }})</summary>
    @foreach($trennbar as $link)
      <div class="kanal-trennen-zeile">
        <div>
          <strong>{{ $link->channel?->name }}</strong>
          <div>
            {{ $link->joinMethodLabel() }}@if($link->joinedBy) · {{ $link->joinedBy->name }}@endif
            @if($link->joined_at) · {{ $link->joined_at->lokal()->format('d.m.Y H:i') }}@endif
          </div>
        </div>
        <form method="POST" action="{{ route('admin.postfach.detach_channel', $active->id) }}"
              data-confirm="Diesen Kanal wirklich trennen? Seine Nachrichten wandern in eine eigene Unterhaltung.">
          @csrf
          <input type="hidden" name="channel_id" value="{{ $link->channel_id }}">
          <button class="btn btn-ghost btn-sm">Kanal trennen</button>
        </form>
      </div>
    @endforeach
  </details>
@endif

{{-- Composer: der Antwortweg ergibt sich aus der Unterhaltung - der
     Mitarbeiter waehlt ihn nur, wenn es wirklich mehrere gibt. Was ein
     Kanal kann, steht in seinen Faehigkeiten, nie in seinem Namen. --}}
<form method="POST" action="{{ route('admin.postfach.reply', $active->id) }}"
      enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:8px">
  @csrf
  @php $antwortName = $antwortKanaele->firstWhere('channel_id', $antwortKanal)?->channel?->name
        ?: $active->channel?->name; @endphp
  <textarea name="body" class="eingabe" style="width:100%;resize:vertical;" rows="3" required
            placeholder="Antwort über {{ $antwortName }} …" aria-label="Antwort über"></textarea>

  @if($antwortKanaele->count() > 1)
    {{-- ANTWORTWEG WAEHLEN (Auftrag Abschnitt 14). Voreingestellt ist der
         Kanal, ueber den der Kunde ZULETZT geschrieben hat - dort wartet
         er. Angeboten werden nur Kanaele, ueber die auch wirklich
         gesendet werden kann; ob gesendet werden DARF, prueft der Server
         beim Absenden erneut. --}}
    <fieldset class="kanal-wahl">
      <legend>Antworten über</legend>
      @foreach($antwortKanaele as $link)
        <label class="check-inline">
          <input type="radio" name="channel_id" value="{{ $link->channel_id }}"
                 @checked($link->channel_id === $antwortKanal)>
          <span>{{ $link->channel?->name }}</span>
        </label>
      @endforeach
    </fieldset>
  @endif
  @if($active->channel?->supports('supportsMedia') && $aktenUnterlagen->isNotEmpty())
    {{-- Unterlagen AUS DER AKTE mitschicken - ohne diesen Weg muesste
         der Mitarbeiter die Police erst herunterladen und wieder
         hochladen, und im Verlauf stuende danach nicht mehr, WELCHE
         Unterlage der Kunde bekommen hat. --}}
    <details class="akten-waehler">
      <summary>Unterlage aus der Kundenakte anhängen</summary>
      <div class="akten-liste">
        @foreach($aktenUnterlagen as $d)
          <label class="check-inline">
            <input type="checkbox" name="dokumente[]" value="{{ $d->id }}">
            <span>{{ $d->file_name }}</span>
          </label>
        @endforeach
      </div>
      <p class="akten-hinweis">Höchstens 5 Unterlagen je Nachricht.</p>
    </details>
  @endif
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    @if($active->channel?->supports('supportsMedia'))
      <input type="file" name="attachments[]" multiple class="eingabe eingabe-sm">
    @endif
    <button class="btn btn-primary">Senden</button>
    <span style="font-size:11px;color:var(--ink-soft)">Wird über {{ $active->channel?->name }} zugestellt.</span>
  </div>
</form>

@push('styles')
<style @cspNonce>
  .anhang-zeile { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 11px; margin-top: 6px; }
  .akten-waehler { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; background: var(--surface-soft); }
  .akten-waehler > summary { cursor: pointer; font-size: 13px; color: var(--ink-soft); list-style: none; font-weight: 500; }
  .akten-waehler > summary::-webkit-details-marker { display: none; }
  .akten-waehler > summary:hover { color: var(--ink); }
  .akten-liste { display: grid; gap: 6px; margin-top: 10px; max-height: 200px; overflow-y: auto; }
  .akten-hinweis { font-size: 11px; color: var(--ink-soft); margin: 8px 0 0; }

  /* Zuordnung auf einem Indiz - Hinweis, keine Fehlermeldung: die
     Zuordnung ist wahrscheinlich richtig, nur ungeprueft. Deshalb die
     Warnfarbe und nicht die Fehlerfarbe. */
  .zuordnung-hinweis { display: flex; gap: 12px; align-items: center; justify-content: space-between;
    flex-wrap: wrap; border: 1px solid var(--status-warning); border-radius: 8px;
    padding: 10px 12px; margin-bottom: 12px; background: var(--surface-soft); }
  .zuordnung-hinweis strong { font-size: 13px; }
  .zuordnung-hinweis p { font-size: 12px; color: var(--ink-soft); margin: 4px 0 0; }

  .notizen { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px;
    background: var(--surface-soft); margin-bottom: 12px; }
  .notizen > summary { cursor: pointer; font-size: 13px; font-weight: 500; color: var(--ink-soft);
    list-style: none; }
  .notizen > summary::-webkit-details-marker { display: none; }
  .notizen > summary:hover { color: var(--ink); }
  /* Deutlich anders als eine Chat-Blase - sie darf nie wie eine
     Nachricht an den Kunden aussehen. */
  .notiz { border-left: 3px solid var(--gold); padding: 6px 0 6px 10px; margin-top: 10px; }
  .notiz-text { font-size: 13px; white-space: normal; }
  .notiz-fuss { font-size: 11px; color: var(--ink-soft); margin-top: 2px; }
  .notiz-formular { display: flex; flex-direction: column; gap: 6px; margin-top: 12px; }
  .notiz-zeile { display: flex; gap: 10px; align-items: center; justify-content: space-between;
    flex-wrap: wrap; font-size: 12px; }

  /* Kanal-Marken. Ein Kanal ist eine Herkunftsangabe, keine Aktion -
     deshalb neutrale Flaeche und nie die Aktionsfarbe. */
  .kanal-liste { display: flex; gap: 4px; flex-wrap: wrap; }
  .kanal-marke { display: inline-block; border: 1px solid var(--line); border-radius: 999px;
    padding: 1px 8px; font-size: 11px; font-weight: 500; background: var(--surface); }
  .kanal-marke-klein { padding: 0 6px; font-weight: 400; }
  /* Das "+" sagt: nachtraeglich dazugekommen, nicht hier begonnen. */
  .kanal-marke-zusatz { color: var(--ink-soft); }

  .kanal-wahl { border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px;
    display: flex; gap: 14px; align-items: center; flex-wrap: wrap; background: var(--surface-soft); }
  .kanal-wahl > legend { font-size: 11px; color: var(--ink-soft); padding: 0 4px; }

  .kanal-trennen { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px;
    background: var(--surface-soft); margin-bottom: 12px; }
  .kanal-trennen > summary { cursor: pointer; font-size: 13px; font-weight: 500; color: var(--ink-soft);
    list-style: none; }
  .kanal-trennen > summary::-webkit-details-marker { display: none; }
  .kanal-trennen > summary:hover { color: var(--ink); }
  .kanal-trennen-zeile { display: flex; gap: 12px; align-items: center; justify-content: space-between;
    flex-wrap: wrap; margin-top: 10px; font-size: 12px; }
  .kanal-trennen-zeile div div { color: var(--ink-soft); font-size: 11px; }
</style>
@endpush
