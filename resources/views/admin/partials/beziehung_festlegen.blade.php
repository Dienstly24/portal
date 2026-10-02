{{--
    "Beziehung festlegen" (Auftrag 01.10.2026): EIN Baustein fuer die
    Dubletten-Pruefung und die Seite "Verwandte Kunden".

    Erwartet:
      $a, $b       Customer (beide Seiten des Paares)
      $action      Ziel-URL (POST)
      $hidden      [name => wert] zusaetzliche versteckte Felder
      $current     aktuelle Art (oder null)
      $parent      aktueller bzw. VORGESCHLAGENER Elternteil (Kunden-ID oder null)
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
.bz-panel{position:absolute;right:0;top:calc(100% + 6px);z-index:30;width:min(340px,86vw);background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 32px rgba(15,21,18,.16);padding:14px 16px;text-align:left;}
.bz-panel .bz-typ{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;color:var(--ink);cursor:pointer;}
.bz-panel .bz-typ input{accent-color:var(--emerald);}
.bz-panel fieldset{border:1px solid var(--line);border-radius:8px;padding:8px 10px;margin:8px 0 0;}
.bz-panel legend{font-size:11.5px;color:var(--ink-soft);padding:0 4px;}
.bz-hint{font-size:11.5px;color:var(--ink-soft);margin-top:4px;}
</style>
@endonce
<details class="bz-pick">
    <summary class="btn btn-ghost" style="padding:8px 14px;">🔗 {{ $label }}</summary>
    <div class="bz-panel">
        <form method="POST" action="{{ $action }}" data-beziehung-form style="margin:0;">
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

            <fieldset data-beziehung-eltern @if($current !== 'elternteil_kind') hidden @endif>
                <legend>Wer ist der Elternteil?</legend>
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
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:10px;padding:8px 14px;">Speichern</button>
        </form>
    </div>
</details>

@include('admin.partials.beziehung_festlegen_script')
