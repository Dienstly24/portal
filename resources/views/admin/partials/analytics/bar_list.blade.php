{{--
  BALKENLISTE - der wiederverwendete Baustein fuer jede Verteilung
  (Sparten, Kundenstruktur, spaeter beliebige weitere).

  Erwartet:
    $eintraege  Liste aus ['label', 'wert', 'anteil' (0-100), 'farbe'?]
    $basis      Bezugswert fuer die Balkenlaenge (Standard: groesster Wert)
    $leer       Text, wenn nichts da ist

  WARUM BALKEN UND KEIN KUCHEN: Zahl und Anteil stehen hier OHNE Hovern
  am Balken - der Betreiber soll die Verteilung im Vorbeigehen lesen
  koennen. Ein Kuchendiagramm verlangt fuer jede Zahl eine Mausbewegung
  und macht ab etwa fuenf Segmenten Groessenvergleiche unmoeglich.
--}}
@php
    $basis = $basis ?? max(1, collect($eintraege)->max('wert') ?: 1);
@endphp
@forelse($eintraege as $e)
    @php $laenge = $basis > 0 ? min(100, round($e['wert'] / $basis * 100, 1)) : 0; @endphp
    <div>
        <div class="an-bar-kopf">
            <span class="an-bar-name">{{ $e['label'] }}</span>
            <span class="an-bar-zahl">
                <b>{{ number_format((float) $e['wert'], 0, ',', '.') }}</b>
                @isset($e['anteil'])<span>{{ number_format((float) $e['anteil'], 1, ',', '.') }} %</span>@endisset
            </span>
        </div>
        <div class="an-bar-spur">
            <div class="an-bar-fuellung" style="width:{{ $laenge }}%;background:{{ $e['farbe'] ?? 'var(--emerald)' }};"></div>
        </div>
    </div>
@empty
    <div class="an-leer">{{ $leer ?? 'Keine Daten im gewählten Zeitraum.' }}</div>
@endforelse
