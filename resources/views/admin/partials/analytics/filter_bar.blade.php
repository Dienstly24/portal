{{--
  FILTERLEISTE der Auswertungsseite (und ihrer Detaillisten).

  Erwartet: $f (App\Services\Reporting\AnalyticsFilters)
  Optional: $ziel    Routenname, auf den die Leiste zeigt (Standard: admin.reports)
            $auswahl ['gesellschaften' => [...], 'werber' => [[schluessel,name],...]]
            $extra   zusaetzliche Query-Werte, die erhalten bleiben (z.B. liste)

  ALLES SIND LINKS bzw. ein GET-Formular - keine Schaltflaeche, die per
  JavaScript einen Zustand haelt. Damit ist jeder Stand der Auswertung
  teilbar (URL kopieren), im Verlauf zurueck-tauglich und als Lesezeichen
  speicherbar. Genau das kann eine im Browser gefilterte Seite nicht, und
  genau darum ging es beim Umbau der grossen Listen (20.08.2026).

  KOMPAKT STATT KASTEN: eine Zeile, die beim Scrollen stehen bleibt -
  welcher Zeitraum gilt, muss man auch unten bei der Karte noch sehen.
--}}
@php
    $ziel = $ziel ?? 'admin.reports';
    $extra = $extra ?? [];
    $auswahl = $auswahl ?? ['gesellschaften' => [], 'werber' => []];
    $url = fn (array $q) => route($ziel, $q + $extra);
@endphp
<div class="an-filter">
    <span class="an-filter-label">Zeitraum</span>
    <span class="an-seg">
        @foreach(\App\Services\Reporting\AnalyticsFilters::ZEITRAEUME as $schluessel => $label)
            @continue($schluessel === 'monatswahl')
            @if($schluessel === 'benutzerdefiniert')
                <a href="{{ $url($f->alsQuery(['zeitraum' => 'benutzerdefiniert', 'von' => $f->von->format('Y-m-d'), 'bis' => $f->bis->format('Y-m-d'), 'monat' => null])) }}"
                   class="{{ $f->zeitraum === $schluessel ? 'on' : '' }}">{{ $label }}</a>
            @else
                <a href="{{ $url($f->alsQuery(['zeitraum' => $schluessel, 'von' => null, 'bis' => null, 'monat' => null])) }}"
                   class="{{ $f->zeitraum === $schluessel ? 'on' : '' }}">{{ $label }}</a>
            @endif
        @endforeach
    </span>

    {{-- Monatsauswahl: "alle Abschluesse im November 2026" mit einem Feld. --}}
    <form method="GET" action="{{ route($ziel) }}" class="an-monat-form" style="display:inline-flex;align-items:center;gap:6px;">
        <input type="hidden" name="zeitraum" value="monatswahl">
        @foreach($f->alsQuery(['zeitraum' => null, 'von' => null, 'bis' => null, 'monat' => null]) + $extra as $k => $v)
            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
        @endforeach
        <input type="month" class="an-date {{ $f->zeitraum === 'monatswahl' ? 'on' : '' }}" name="monat" value="{{ $f->monatWert() }}" aria-label="Monat wählen">
        <button type="submit" class="btn btn-ghost btn-sm">Monat</button>
    </form>

    @if($f->zeitraum === 'benutzerdefiniert')
        <form method="GET" action="{{ route($ziel) }}" style="display:inline-flex;align-items:center;gap:6px;">
            <input type="hidden" name="zeitraum" value="benutzerdefiniert">
            @foreach($f->alsQuery(['zeitraum' => null, 'von' => null, 'bis' => null]) + $extra as $k => $v)
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach
            <input type="date" class="an-date" name="von" value="{{ $f->von->format('Y-m-d') }}" aria-label="Von Datum">
            <span class="muted-xs">–</span>
            <input type="date" class="an-date" name="bis" value="{{ $f->bis->format('Y-m-d') }}" aria-label="Bis Datum">
            <button type="submit" class="btn btn-primary btn-sm">Anwenden</button>
        </form>
    @endif

    {{-- Sachfilter: ein GET-Formular; Auswahllisten schicken sich beim
         Wechseln selbst ab (Verdrahtung im Skript der Seite - ein
         onchange-Attribut kann keinen CSP-Nonce tragen, Audit SEC-4).
         Textfelder (Stadt, PLZ) mit Enter oder "Filtern". --}}
    <form method="GET" action="{{ route($ziel) }}" id="anSachfilter" style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <input type="hidden" name="zeitraum" value="{{ $f->zeitraum }}">
        @if($f->zeitraum === 'benutzerdefiniert')
            <input type="hidden" name="von" value="{{ $f->von->format('Y-m-d') }}">
            <input type="hidden" name="bis" value="{{ $f->bis->format('Y-m-d') }}">
        @endif
        @if($f->zeitraum === 'monatswahl')
            <input type="hidden" name="monat" value="{{ $f->monatWert() }}">
        @endif
        <input type="hidden" name="takt" value="{{ $f->granularitaet }}">
        @foreach($extra as $k => $v)
            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
        @endforeach

        <select class="an-select" name="sparte" aria-label="Sparte">
            <option value="">Alle Sparten</option>
            @foreach(\App\Models\Contract::TYPES as $schluessel => $cfg)
                <option value="{{ $schluessel }}" @selected($f->sparte === $schluessel)>{{ $cfg['label'] }}</option>
            @endforeach
        </select>

        <select class="an-select" name="bundesland" aria-label="Bundesland">
            <option value="">Alle Bundesländer</option>
            @foreach(\App\Support\Bundesland::NAMEN as $kuerzel => $name)
                <option value="{{ $kuerzel }}" @selected($f->bundesland === $kuerzel)>{{ $name }}</option>
            @endforeach
            <option value="{{ \App\Support\Bundesland::UNBEKANNT }}" @selected($f->bundesland === \App\Support\Bundesland::UNBEKANNT)>Ohne Bundesland (PLZ fehlt)</option>
        </select>

        <select class="an-select" name="kundentyp" aria-label="Kundentyp">
            <option value="">Alle Kundentypen</option>
            <option value="privat" @selected($f->kundentyp === 'privat')>Privatkunden</option>
            <option value="firma" @selected($f->kundentyp === 'firma')>Firmenkunden</option>
        </select>

        <select class="an-select" name="status" aria-label="Vertragsstatus">
            <option value="">Alle Vertragsstatus</option>
            @foreach(\App\Models\Contract::GROUP_LABELS as $schluessel => $label)
                <option value="{{ $schluessel }}" @selected($f->vertragsstatus === $schluessel)>{{ $label }}</option>
            @endforeach
        </select>

        @if(! empty($auswahl['gesellschaften']))
            <select class="an-select" name="gesellschaft" aria-label="Gesellschaft">
                <option value="">Alle Gesellschaften</option>
                @foreach($auswahl['gesellschaften'] as $name)
                    <option value="{{ $name }}" @selected($f->gesellschaft === $name)>{{ $name }}</option>
                @endforeach
            </select>
        @endif

        @if(! empty($auswahl['werber']))
            <select class="an-select" name="werber" aria-label="Mitarbeiter/Vermittler">
                <option value="">Alle Mitarbeiter/Vermittler</option>
                @foreach($auswahl['werber'] as $w)
                    <option value="{{ $w['schluessel'] }}" @selected($f->werber === $w['schluessel'])>{{ $w['name'] }}</option>
                @endforeach
                <option value="keiner" @selected($f->werber === 'keiner')>Ohne Mitarbeiter/Vermittler</option>
            </select>
        @endif

        <input type="text" class="an-text" name="stadt" value="{{ $f->stadt }}" placeholder="Stadt" aria-label="Stadt" maxlength="80">
        <input type="text" class="an-text" name="plz" value="{{ $f->plz }}" placeholder="PLZ (Anfang)" aria-label="Postleitzahl" inputmode="numeric" pattern="\d{1,5}" maxlength="5" style="width:96px;">
        <button type="submit" class="btn btn-ghost btn-sm">Filtern</button>
    </form>

    <span class="an-spacer"></span>

    @foreach($f->chips() as $chip)
        <a class="an-chip" href="{{ $url($f->alsQuery([$chip['schluessel'] => null])) }}"
           title="{{ $chip['label'] }}-Filter entfernen">
            <strong>{{ $chip['wert'] }}</strong><span aria-hidden="true">×</span>
        </a>
    @endforeach

    @unless($f->istStandard())
        <a class="an-reset" href="{{ route($ziel, $extra) }}">Filter zurücksetzen</a>
    @endunless
</div>
