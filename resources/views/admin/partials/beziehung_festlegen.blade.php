{{--
    "Beziehung festlegen" (Auftrag 01.10.2026): EIN Baustein fuer die
    Dubletten-Pruefung und die Seite "Verwandte Kunden".

    Erwartet:
      $a, $b       Customer (beide Seiten des Paares)
      $action      Ziel-URL (POST)
      $hidden      [name => wert] zusaetzliche versteckte Felder
      $current     aktuelle Art (oder null)
      $parent      aktuelle bzw. VORGESCHLAGENE aeltere Generation (Elternteil/
                   Grosselternteil, Kunden-ID oder null)
      $note        aktuelle Notiz (oder null)
      $label       Beschriftung des Knopfes
      $suggested   true, wenn $parent nur ein Vorschlag aus den Geburtsdaten ist

    Ohne Framework: ein <details>-Feld oeffnet den Dialog, die Elternteil-
    Auswahl blendet das Skript unten ein (CSP: Skript mit Nonce, kein
    onclick). Der Server prueft alles noch einmal - dem Browser wird nichts
    geglaubt.
--}}
@php
    $current = $current ?? null;
    $parent = $parent ?? null;
    $note = $note ?? null;
    $suggested = $suggested ?? false;
    $hidden = $hidden ?? [];
    $nameA = $a->user?->name ?: 'Kunde';
    $nameB = $b->user?->name ?: 'Kunde';
@endphp
@once
<style>
.bz-pick{position:relative;}
.bz-pick>summary{list-style:none;cursor:pointer;}
.bz-pick>summary::-webkit-details-marker{display:none;}
/* Als Dialog (data-pop="dialog", ui.js Abschnitt 7): steht ausserhalb der
   Karte, die mit overflow:hidden die Liste abgeschnitten hat; Speichern
   und Abbrechen bleiben unten immer sichtbar. */
.bz-panel{position:absolute;right:0;top:calc(100% + 6px);z-index:30;width:min(340px,86vw);background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 32px rgba(15,21,18,.16);text-align:left;}
.bz-panel .bz-typ{display:flex;align-items:center;gap:10px;padding:8px 10px;margin:0 0 4px;font-size:13px;color:var(--ink);cursor:pointer;border:1.5px solid var(--line);border-radius:9px;}
.bz-panel .bz-typ:hover{border-color:var(--emerald);}
.bz-panel .bz-typ:has(input:checked){border-color:var(--emerald);background:var(--emerald-soft);font-weight:600;}
.bz-panel fieldset{border:1px solid var(--line);border-radius:8px;padding:8px 10px;margin:8px 0 0;}
.bz-panel legend{font-size:11.5px;color:var(--ink-soft);padding:0 4px;}
.bz-hint{font-size:11.5px;color:var(--ink-soft);margin-top:4px;}
</style>
@endonce
<details class="bz-pick" data-pop="dialog">
    <summary class="btn btn-ghost" style="padding:8px 14px;">🔗 {{ $label }}</summary>
    <div class="bz-panel" role="dialog" aria-modal="true" aria-label="Beziehung festlegen">
        <form method="POST" action="{{ $action }}" data-beziehung-form style="margin:0;">
            <div class="pop-kopf">
                <span>🔗 Beziehung festlegen <span class="muted-xs" style="font-weight:400;display:block;">{{ $nameA }} ↔ {{ $nameB }}</span></span>
                <button type="button" data-pop-schliessen aria-label="Schließen">✕</button>
            </div>
            <div class="pop-inhalt">
            @csrf
            @foreach($hidden as $hName => $hValue)
            <input type="hidden" name="{{ $hName }}" value="{{ $hValue }}">
            @endforeach
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-soft);margin-bottom:4px;">Beziehung</div>
            @foreach(\App\Models\CustomerRelationship::RELATION_TYPES as $t)
            <label class="bz-typ">
                <input type="radio" name="type" value="{{ $t }}" @checked($current === $t) required>
                {{ \App\Models\CustomerRelationship::typeEmoji($t) }} {{ $t === 'sonstiges' ? 'Sonstiges (mit Freitext)' : \App\Models\CustomerRelationship::typeLabel($t) }}
            </label>
            @endforeach

            <fieldset data-beziehung-eltern @if(! \App\Models\CustomerRelationship::isDirected($current)) hidden @endif>
                <legend>Wer ist die ältere Generation (Elternteil bzw. Großelternteil)?</legend>
                <label class="bz-typ"><input type="radio" name="parent_customer_id" value="{{ $a->id }}" @checked((string) $parent === (string) $a->id)> {{ $nameA }}</label>
                <label class="bz-typ"><input type="radio" name="parent_customer_id" value="{{ $b->id }}" @checked((string) $parent === (string) $b->id)> {{ $nameB }}</label>
                @if($suggested && $parent)
                <div class="bz-hint">Vorschlag aus den Geburtsdaten (mind. {{ \App\Models\CustomerRelationship::PARENT_MIN_AGE_GAP }} Jahre Altersabstand) – bitte prüfen.</div>
                @elseif(! $parent)
                <div class="bz-hint">Kein Vorschlag möglich (Geburtsdatum fehlt oder Abstand unter {{ \App\Models\CustomerRelationship::PARENT_MIN_AGE_GAP }} Jahren).</div>
                @endif
            </fieldset>

            <div data-beziehung-notiz style="margin-top:8px;">
                <input type="text" name="note" value="{{ $note }}" maxlength="255" class="eingabe" placeholder="Notiz (bei „Sonstiges" Pflicht)" aria-label="Notiz zur Beziehung" style="width:100%;font-size:13px;">
            </div>
            <div class="bz-hint">Beide Akten bleiben mit allen Verträgen erhalten – nichts wird zusammengeführt. Das Paar verschwindet aus der Dubletten-Prüfung.</div>
            </div>
            <div class="pop-aktionen">
                <button type="button" class="btn btn-ghost" data-pop-schliessen>Abbrechen</button>
                <button type="submit" class="btn btn-emerald">Speichern</button>
            </div>
        </form>
    </div>
</details>

@include('admin.partials.beziehung_festlegen_script')
