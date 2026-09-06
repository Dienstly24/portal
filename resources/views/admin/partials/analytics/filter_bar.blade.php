{{--
  FILTERLEISTE der Auswertungsseite.

  Erwartet: $f (App\Services\Reporting\AnalyticsFilters)

  ALLES SIND LINKS bzw. ein GET-Formular - keine Schaltflaeche, die per
  JavaScript einen Zustand haelt. Damit ist jeder Stand der Auswertung
  teilbar (URL kopieren), im Verlauf zurueck-tauglich und als Lesezeichen
  speicherbar. Genau das kann eine im Browser gefilterte Seite nicht, und
  genau darum ging es beim Umbau der grossen Listen (20.08.2026).

  KOMPAKT STATT KASTEN: die Vorgaengerseite hatte fuer zwei Datumsfelder
  eine ganze Karte mit 24 px Innenabstand - rund 120 px des ersten
  Bildschirms fuer eine Eingabe, die man selten aendert. Jetzt eine Zeile,
  die beim Scrollen stehen bleibt: welcher Zeitraum gilt, muss man auch
  unten bei der Karte noch sehen koennen.
--}}
<div class="an-filter">
    <span class="an-filter-label">Zeitraum</span>
    <span class="an-seg">
        @foreach(\App\Services\Reporting\AnalyticsFilters::ZEITRAEUME as $schluessel => $label)
            @if($schluessel === 'benutzerdefiniert')
                <a href="{{ route('admin.reports', $f->alsQuery(['zeitraum' => 'benutzerdefiniert', 'von' => $f->von->format('Y-m-d'), 'bis' => $f->bis->format('Y-m-d')])) }}"
                   class="{{ $f->zeitraum === $schluessel ? 'on' : '' }}">{{ $label }}</a>
            @else
                <a href="{{ route('admin.reports', $f->alsQuery(['zeitraum' => $schluessel, 'von' => null, 'bis' => null])) }}"
                   class="{{ $f->zeitraum === $schluessel ? 'on' : '' }}">{{ $label }}</a>
            @endif
        @endforeach
    </span>

    @if($f->zeitraum === 'benutzerdefiniert')
        <form method="GET" action="{{ route('admin.reports') }}" style="display:inline-flex;align-items:center;gap:6px;">
            <input type="hidden" name="zeitraum" value="benutzerdefiniert">
            @foreach($f->alsQuery(['zeitraum' => null, 'von' => null, 'bis' => null]) as $k => $v)
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach
            <input type="date" class="an-date" name="von" value="{{ $f->von->format('Y-m-d') }}" aria-label="Von Datum">
            <span class="muted-xs">–</span>
            <input type="date" class="an-date" name="bis" value="{{ $f->bis->format('Y-m-d') }}" aria-label="Bis Datum">
            <button type="submit" class="btn btn-primary btn-sm">Anwenden</button>
        </form>
    @endif

    {{-- Sachfilter: ein GET-Formular, das sich beim Wechseln selbst
         abschickt (Verdrahtung in resources/js/analytics-page: ein
         onchange-Attribut kann keinen CSP-Nonce tragen, Audit SEC-4). --}}
    <form method="GET" action="{{ route('admin.reports') }}" id="anSachfilter" style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <input type="hidden" name="zeitraum" value="{{ $f->zeitraum }}">
        @if($f->zeitraum === 'benutzerdefiniert')
            <input type="hidden" name="von" value="{{ $f->von->format('Y-m-d') }}">
            <input type="hidden" name="bis" value="{{ $f->bis->format('Y-m-d') }}">
        @endif
        <input type="hidden" name="takt" value="{{ $f->granularitaet }}">

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

        {{-- Ohne JavaScript bleibt die Auswahl bedienbar. --}}
        <noscript><button type="submit" class="btn btn-primary btn-sm">Filtern</button></noscript>
    </form>

    <span class="an-spacer"></span>

    @foreach($f->chips() as $chip)
        <a class="an-chip" href="{{ route('admin.reports', $f->alsQuery([$chip['schluessel'] => null])) }}"
           title="{{ $chip['label'] }}-Filter entfernen">
            <strong>{{ $chip['wert'] }}</strong><span aria-hidden="true">×</span>
        </a>
    @endforeach

    @unless($f->istStandard())
        <a class="an-reset" href="{{ route('admin.reports') }}">Filter zurücksetzen</a>
    @endunless
</div>
