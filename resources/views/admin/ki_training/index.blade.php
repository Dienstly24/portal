@extends('layouts.admin')
@section('title', 'KI-Training')

@section('content')
<div style="display:flex;flex-direction:column;gap:16px">

  <div>
    <h1 class="page-title" style="margin-bottom:2px;">KI-Training</h1>
    <div class="page-sub">
      Echte Gespräche hochladen, prüfen, schwärzen — und einzeln freigeben.
    </div>
  </div>

  {{-- EHRLICH, WAS HIER PASSIERT. Der Wunsch heisst "die KI lernt aus
       unseren Gespraechen"; was technisch geschieht, ist etwas anderes -
       und wer das nicht weiss, wartet auf eine Wirkung, die nie
       eintritt. --}}
  <div class="card kit-erklaerung">
    <strong>Was hier geschieht — und was nicht</strong>
    <p>
      Das Sprachmodell wird durch den Upload <strong>nicht</strong> nachtrainiert; seine Gewichte
      sind unveränderlich. Was wirklich entsteht: aus den Gesprächen werden
      <strong>Frage-Antwort-Paare</strong>, und jedes einzelne wird von einem Menschen freigegeben.
      Ab dann antwortet der Assistent mit <strong>Ihren</strong> Antworten — nachlesbar, änderbar und
      einzeln abschaltbar. Ein nachtrainiertes Modell könnte man weder befragen noch zurückdrehen.
    </p>
    <p>
      Jedes vierte Paar wird als <strong>Prüfsatz</strong> zurückgehalten. Es kommt bewusst nicht in
      die Wissensbasis, sondern dient später der Kompetenzmessung: wer die KI an genau den Antworten
      prüft, die er ihr vorher gegeben hat, bekommt immer ein gutes und immer wertloses Ergebnis.
    </p>
  </div>

  {{-- Hochladen --}}
  <div class="card">
    <div class="card-title" style="margin-bottom:8px;">Verlauf hochladen</div>
    <form method="POST" action="{{ route('admin.ki_training.store') }}" enctype="multipart/form-data"
          style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      @csrf
      <input type="file" name="datei" accept=".txt,text/plain" required class="eingabe"
             aria-label="Exportierte Chat-Datei">
      <button class="btn btn-primary">Lesen und Vorschau zeigen</button>
      <span class="kit-hinweis">
        WhatsApp → Chat öffnen → Menü → „Mehr" → „Chat exportieren" → <strong>ohne Medien</strong>.
        Es wird noch nichts übernommen.
      </span>
    </form>
  </div>

  @if($active)
    @php
      $blocker = $active->blockers();
      $stats = $active->stats ?? [];
    @endphp
    {{-- VORSCHAU VOR DER ENTSCHEIDUNG (Lehre vom Provisions-Import):
         ein Import, der sein Ergebnis erst nach dem Schreiben zeigt,
         laesst keine Wahl mehr. --}}
    <div class="card">
      <div class="card-title" style="margin-bottom:8px;">
        Vorschau: {{ $active->file_name }}
        <span class="badge">{{ $active->statusLabel() }}</span>
      </div>

      <div class="kit-zahlen">
        <div><strong>{{ $stats['message_count'] ?? 0 }}</strong><span>Nachrichten erkannt</span></div>
        <div><strong>{{ $stats['system_lines'] ?? 0 }}</strong><span>Systemzeilen übersprungen</span></div>
        <div><strong>{{ $stats['media_notes'] ?? 0 }}</strong><span>Dateien nicht im Export</span></div>
        <div><strong>{{ $stats['unparsed_lines'] ?? 0 }}</strong><span>Zeilen nicht lesbar</span></div>
        <div>
          <strong>
            {{ $stats['first_at'] ? \Illuminate\Support\Carbon::parse($stats['first_at'])->lokal()->format('d.m.Y') : '—' }}
            –
            {{ $stats['last_at'] ? \Illuminate\Support\Carbon::parse($stats['last_at'])->lokal()->format('d.m.Y') : '—' }}
          </strong>
          <span>Zeitraum</span>
        </div>
      </div>

      @if(($stats['media_notes'] ?? 0) > 0)
        <p class="kit-hinweis" style="margin-top:8px">
          Der Export enthält keine Bilder oder Dokumente — nur den Hinweis, dass dort etwas war.
          Diese Dateien fehlen im Verlauf und lassen sich später nicht nachholen.
        </p>
      @endif

      @if($active->isDraft())
        <form method="POST" action="{{ route('admin.ki_training.update', $active->id) }}" class="kit-zuordnung">
          @csrf @method('PUT')

          <div class="kit-feld">
            <label for="kit-kunde">Kundenakte</label>
            <input id="kit-kunde" name="customer_id" class="eingabe" style="width:320px"
                   value="{{ $active->customer_id }}" placeholder="Kundennummer / ID">
            {{-- Der ANZEIGENAME im Export ist kein Nachweis - ein Name
                 zaehlt in diesem System nie als Zuordnung. --}}
            <small>Der Name im Export ordnet nichts zu — die Akte wählt ein Mensch.</small>
          </div>

          <div class="kit-feld">
            <label for="kit-absender">Welcher Absender sind wir?</label>
            <select id="kit-absender" name="business_sender" class="eingabe" style="width:320px">
              <option value="">— bitte wählen —</option>
              @foreach($active->senders() as $name => $anzahl)
                <option value="{{ $name }}" @selected($active->business_sender === $name)>
                  {{ $name }} ({{ $anzahl }})
                </option>
              @endforeach
            </select>
            <small>Steht nicht in der Datei. Ohne diese Angabe ist nicht bestimmbar, was Frage und was Antwort ist.</small>
          </div>

          <button class="btn btn-ghost">Zuordnung speichern</button>
        </form>

        @if($blocker)
          <div class="kit-blocker">
            <strong>Noch nicht übernehmbar:</strong>
            <ul>@foreach($blocker as $grund)<li>{{ $grund }}</li>@endforeach</ul>
          </div>
        @endif

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
          <form method="POST" action="{{ route('admin.ki_training.confirm', $active->id) }}">
            @csrf
            <button class="btn btn-primary" @disabled($blocker !== [])>In die Kundenakte übernehmen</button>
          </form>
          <form method="POST" action="{{ route('admin.ki_training.discard', $active->id) }}"
                data-confirm="Diesen Entwurf wirklich verwerfen?">
            @csrf<button class="btn btn-ghost">Entwurf verwerfen</button>
          </form>
        </div>
      @endif

      @if($vorschau->isNotEmpty())
        <div class="kit-verlauf">
          <div class="kit-verlauf-kopf">Erste {{ $vorschau->count() }} erkannte Zeilen</div>
          @foreach($vorschau as $zeile)
            @php $eigen = $active->business_sender
                  && mb_strtolower(trim($zeile->sender_label)) === mb_strtolower(trim($active->business_sender)); @endphp
            <div class="kit-zeile {{ $eigen ? 'kit-zeile-eigen' : '' }}">
              <div class="kit-zeile-kopf">
                {{ $zeile->sender_label }}
                @if($eigen)<span class="badge">wir</span>@endif
                · {{ $zeile->sent_at?->lokal()?->format('d.m.Y H:i') ?: 'ohne Zeitstempel' }}
              </div>
              <div>{{ \Illuminate\Support\Str::limit($zeile->body, 220) }}</div>
            </div>
          @endforeach
        </div>
      @endif
    </div>
  @endif

  {{-- Liste der Verlaeufe --}}
  <div class="card">
    <div class="card-title" style="margin-bottom:8px;">Hochgeladene Verläufe</div>
    @if($imports->isEmpty())
      <p class="kit-hinweis">Noch kein Verlauf hochgeladen.</p>
    @else
      <table>
        <thead><tr>
          <th>Datei</th><th>Kunde</th><th>Nachrichten</th><th>Paare</th><th>Zustand</th><th>Hochgeladen</th><th></th>
        </tr></thead>
        <tbody>
        @foreach($imports as $i)
          <tr>
            <td>{{ $i->file_name }}</td>
            <td>{{ $i->customer?->user?->name ?: ($i->customer?->customer_number ?: '—') }}</td>
            <td>{{ $i->messages_count }}</td>
            <td>{{ $i->examples_count }}</td>
            <td>{{ $i->statusLabel() }}</td>
            <td>{{ $i->created_at?->lokal()?->format('d.m.Y H:i') }}</td>
            <td><a href="{{ route('admin.ki_training', ['verlauf' => $i->id]) }}" class="btn btn-ghost btn-sm">Ansehen</a></td>
          </tr>
        @endforeach
        </tbody>
      </table>
      {{ $imports->links() }}
    @endif
  </div>

  {{-- Beispiele pruefen --}}
  <div class="card">
    <div class="card-title" style="margin-bottom:8px;">
      Frage-Antwort-Paare prüfen
      <span class="badge">{{ $zahlen['offen'] }} offen</span>
      <span class="badge">{{ $zahlen['freigegeben'] }} freigegeben</span>
      <span class="badge">{{ $zahlen['pruefsatz'] }} im Prüfsatz</span>
    </div>

    @if($offeneBeispiele->isEmpty())
      <p class="kit-hinweis">Nichts zu prüfen. Paare entstehen beim Übernehmen eines Verlaufs.</p>
    @else
      @foreach($offeneBeispiele as $b)
        <div class="kit-beispiel">
          <div class="kit-beispiel-kopf">
            @if($b->is_holdout)
              <span class="badge badge-pending">Prüfsatz — kommt nicht in die Wissensbasis</span>
            @endif
            @foreach($b->redactionCounts() as $art => $anzahl)
              <span class="badge">{{ $art }} ×{{ $anzahl }}</span>
            @endforeach
          </div>

          <div class="kit-paar">
            <div><span>Kunde fragt</span>{{ $b->question }}</div>
            <div><span>Wir antworten</span>{{ $b->answer }}</div>
          </div>

          @if($b->warnings())
            {{-- Was die Schwaerzung NICHT zuordnen konnte, steht hier im
                 Klartext - eine Warnung, die den Fund verschweigt, kann
                 niemand pruefen. --}}
            <div class="kit-warnung">
              <strong>Bitte vor der Freigabe ansehen:</strong>
              <ul>@foreach($b->warnings() as $w)<li>{{ $w }}</li>@endforeach</ul>
            </div>
          @endif

          <form method="POST" action="{{ route('admin.ki_training.review', $b->id) }}" class="kit-aktionen">
            @csrf
            @unless($b->is_holdout)
              <input name="titel" class="eingabe" style="flex:1;min-width:220px"
                     placeholder="Titel für die Wissensbasis (sonst die Frage)"
                     aria-label="Titel für die Wissensbasis">
            @endunless
            <button name="entscheidung" value="freigeben" class="btn btn-primary btn-sm">Freigeben</button>
            <button name="entscheidung" value="ablehnen" class="btn btn-ghost btn-sm">Ablehnen</button>
          </form>
        </div>
      @endforeach
    @endif
  </div>
</div>

@push('styles')
<style @cspNonce>
  .kit-erklaerung p { font-size: 12.5px; color: var(--ink-soft); line-height: 1.6; margin: 8px 0 0; }
  .kit-hinweis { font-size: 12px; color: var(--ink-soft); }

  .kit-zahlen { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 10px; }
  .kit-zahlen > div { display: flex; flex-direction: column; }
  .kit-zahlen strong { font-size: 18px; }
  .kit-zahlen span { font-size: 11px; color: var(--ink-soft); }

  .kit-zuordnung { display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;
    border-top: 1px solid var(--line); padding-top: 12px; margin-top: 4px; }
  .kit-feld { display: flex; flex-direction: column; gap: 4px; }
  .kit-feld label { font-size: 11px; color: var(--ink-soft); }
  .kit-feld small { font-size: 11px; color: var(--ink-soft); }

  /* Ein Hinweis, keine Fehlermeldung: es ist noch nichts kaputt, es
     fehlt nur eine Angabe. */
  .kit-blocker { border: 1px solid var(--status-warning); border-radius: 8px; padding: 10px 12px;
    margin-top: 12px; font-size: 12px; background: var(--surface-soft); }
  .kit-blocker ul { margin: 4px 0 0 18px; }

  .kit-verlauf { border: 1px solid var(--line); border-radius: 8px; margin-top: 14px;
    max-height: 40vh; overflow-y: auto; }
  .kit-verlauf-kopf { font-size: 11px; color: var(--ink-soft); padding: 8px 12px;
    border-bottom: 1px solid var(--line); }
  .kit-zeile { padding: 8px 12px; border-bottom: 1px solid var(--line); font-size: 13px; }
  .kit-zeile:last-child { border-bottom: none; }
  .kit-zeile-eigen { background: var(--surface-soft); }
  .kit-zeile-kopf { font-size: 11px; color: var(--ink-soft); margin-bottom: 2px; }

  .kit-beispiel { border: 1px solid var(--line); border-radius: 8px; padding: 12px; margin-top: 12px; }
  .kit-beispiel-kopf { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
  .kit-paar { display: grid; gap: 8px; }
  .kit-paar > div { font-size: 13px; white-space: pre-wrap; }
  .kit-paar span { display: block; font-size: 11px; color: var(--ink-soft); }
  .kit-warnung { border-left: 3px solid var(--status-warning); padding: 6px 10px; margin-top: 10px;
    font-size: 12px; }
  .kit-warnung ul { margin: 4px 0 0 18px; }
  .kit-aktionen { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
</style>
@endpush
@endsection
