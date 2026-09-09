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
      <div style="color:var(--muted);font-size:11px">Kunde</div>
      @if($unbekannt)
        <strong>Unbekannter Kontakt</strong>
        <div style="color:var(--muted)">{{ $active->external_user_id }}</div>
      @else
        <a href="{{ route('admin.customer', $active->customer_id) }}">
          <strong>{{ $active->customer?->user?->name ?: $active->customer?->customer_number }}</strong>
        </a>
      @endif
    </div>
    <div>
      <div style="color:var(--muted);font-size:11px">Kanal</div>
      <strong>{{ $active->channel?->name }}</strong>
      @if($active->channelAccount)
        <div style="color:var(--muted)">{{ $active->channelAccount->name }}</div>
      @endif
    </div>
    <div>
      <div style="color:var(--muted);font-size:11px">Betreuer</div>
      <strong>{{ $betreuer?->name ?: '—' }}</strong>
    </div>
    <div>
      <div style="color:var(--muted);font-size:11px">Zuständig</div>
      <strong>{{ $active->assignee?->name ?: 'Niemand' }}</strong>
    </div>
    <div>
      <div style="color:var(--muted);font-size:11px">KI</div>
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
      <div style="color:var(--muted);font-size:11px">Zustand</div>
      <strong>{{ \App\Models\Conversation::STATUSES[$active->status] ?? $active->status }}</strong>
    </div>
  </div>

  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <form method="POST" action="{{ route('admin.postfach.take_over', $active->id) }}">
      @csrf<button class="btn btn-ghost">Übernehmen</button>
    </form>
    <form method="POST" action="{{ route('admin.postfach.status', $active->id) }}" style="display:flex;gap:4px">
      @csrf
      <select name="status" class="field" style="width:auto">
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
  <label style="font-size:12px;color:var(--muted)">Zuständigkeit übergeben an</label>
  <select name="employee_id" class="field" style="width:auto">
    @foreach($mitarbeiter as $m)
      <option value="{{ $m->id }}" @selected($active->assigned_employee_id === $m->id)>{{ $m->name }}</option>
    @endforeach
  </select>
  <button class="btn btn-ghost">Übergeben</button>
  <span style="font-size:11px;color:var(--muted)">Der Betreuer des Kunden bleibt unverändert.</span>
</form>

@if($unbekannt)
  {{-- UNBEKANNTER KONTAKT (Prioritaet 3): die Nachricht ist da, nur die
       Akte fehlt. Beides laesst sich hier erledigen, ohne den Verlauf zu
       verlieren - die Unterhaltung bleibt dieselbe. --}}
  <div class="card" style="padding:12px;margin-bottom:12px;background:var(--surface-2)">
    <strong style="font-size:13px">Kein Kunde zugeordnet</strong>
    <p style="font-size:12px;color:var(--muted);margin:4px 0 10px">
      Kennung: {{ $active->external_user_id }} — Verlauf und Zuständigkeit bleiben erhalten.
    </p>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
      <form method="POST" action="{{ route('admin.postfach.create_customer', $active->id) }}"
            style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        @csrf
        <input name="first_name" class="field" style="width:130px" placeholder="Vorname" required>
        <input name="last_name" class="field" style="width:130px" placeholder="Nachname" required>
        <button class="btn btn-primary">Kunde anlegen</button>
      </form>
      <form method="POST" action="{{ route('admin.postfach.link_customer', $active->id) }}"
            style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        @csrf
        <input name="customer_id" class="field" style="width:280px"
               placeholder="Kundennummer/ID einer bestehenden Akte" required>
        <button class="btn btn-ghost">Mit Kunde verknüpfen</button>
      </form>
    </div>
  </div>
@endif

{{-- Verlauf --}}
<div style="max-height:50vh;overflow-y:auto;display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
  @foreach($messages as $m)
    @php $eigen = $m->from_staff; @endphp
    <div style="align-self:{{ $eigen ? 'flex-end' : 'flex-start' }};max-width:80%">
      <div style="padding:8px 12px;border-radius:10px;font-size:13px;
                  background:{{ $eigen ? 'var(--emerald-soft, #e7f5ee)' : 'var(--surface-2)' }}">
        {!! nl2br(e($m->body)) !!}
        @foreach($m->attachments as $a)
          <div style="font-size:11px;margin-top:4px">
            <a href="{{ route('admin.messages.attachment', $a->id) }}">{{ $a->file_name }}</a>
          </div>
        @endforeach
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;text-align:{{ $eigen ? 'right' : 'left' }}">
        @if($m->ai_generated)
          🤖 {{ \App\Models\CustomerMessage::AI_SENDER_NAME }}
        @elseif($eigen)
          {{ $m->sender?->name ?: 'Team' }}
        @else
          Kunde
        @endif
        · {{ $m->created_at?->lokal()?->format('d.m.Y H:i') }}
      </div>
    </div>
  @endforeach
</div>

{{-- Composer: der Kanal wird NICHT gewaehlt. Er steht an der
     Unterhaltung, und was er kann, steht in seinen Faehigkeiten. --}}
<form method="POST" action="{{ route('admin.postfach.reply', $active->id) }}"
      enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:8px">
  @csrf
  <textarea name="body" class="field" rows="3" required
            placeholder="Antwort über {{ $active->channel?->name }} …"></textarea>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    @if($active->channel?->supports('supportsMedia'))
      <input type="file" name="attachments[]" multiple class="field" style="width:auto">
    @endif
    <button class="btn btn-primary">Senden</button>
    <span style="font-size:11px;color:var(--muted)">Wird über {{ $active->channel?->name }} zugestellt.</span>
  </div>
</form>
