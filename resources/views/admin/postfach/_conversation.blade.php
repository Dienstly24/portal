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
      <div style="color:var(--ink-soft);font-size:11px">Kanal</div>
      <strong>{{ $active->channel?->name }}</strong>
      @if($active->channelAccount)
        <div style="color:var(--ink-soft)">{{ $active->channelAccount->name }}</div>
      @endif
    </div>
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
  <select name="employee_id" class="eingabe">
    @foreach($mitarbeiter as $m)
      <option value="{{ $m->id }}" @selected($active->assigned_employee_id === $m->id)>{{ $m->name }}</option>
    @endforeach
  </select>
  <button class="btn btn-ghost">Übergeben</button>
  <span style="font-size:11px;color:var(--ink-soft)">Der Betreuer des Kunden bleibt unverändert.</span>
</form>

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
        <input name="first_name" class="eingabe" style="width:130px" placeholder="Vorname" required>
        <input name="last_name" class="eingabe" style="width:130px" placeholder="Nachname" required>
        <button class="btn btn-primary">Kunde anlegen</button>
      </form>
      <form method="POST" action="{{ route('admin.postfach.link_customer', $active->id) }}"
            style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        @csrf
        <input name="customer_id" class="eingabe" style="width:280px"
               placeholder="Kundennummer/ID einer bestehenden Akte" required>
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
        @if($m->isHistorical())
          · <span title="Aus der WhatsApp Business App übernommen">Historie</span>
        @endif
      </div>
    </div>
  @endforeach
</div>

{{-- Composer: der Kanal wird NICHT gewaehlt. Er steht an der
     Unterhaltung, und was er kann, steht in seinen Faehigkeiten. --}}
<form method="POST" action="{{ route('admin.postfach.reply', $active->id) }}"
      enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:8px">
  @csrf
  <textarea name="body" class="eingabe" style="width:100%;resize:vertical;" rows="3" required
            placeholder="Antwort über {{ $active->channel?->name }} …"></textarea>
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
</style>
@endpush
