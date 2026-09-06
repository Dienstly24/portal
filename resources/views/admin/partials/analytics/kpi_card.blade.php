{{--
  EINE KENNZAHLENKACHEL. Erwartet $kpi in der Form, die
  DashboardAnalyticsService::kennzahlen() liefert:
    schluessel, label, wert, format (zahl|euro), trend, trend_text,
    hinweis, warnung?, zeitraum_fest?

  BEWUSST OHNE SYMBOL. Sechs Kacheln mit sechs Emoji sind kein
  Informationsgewinn, sondern sechs bunte Flecken, die vom eigentlichen
  Inhalt - der Zahl - ablenken (Betreiber-Vorgabe: "keine Emoji-Oberflaeche").
  Die Rangfolge macht die Typografie: Label klein, Zahl gross, Vergleich klein.

  DER TREND WIRD NIE ERFUNDEN. Fehlt die Vergleichsbasis (Vorzeitraum = 0),
  liefert der Dienst keinen Prozentwert - dann steht hier "neu" statt eines
  rechnerisch richtigen, aber aussagelosen "+100 %".
--}}
@php
    $format = fn ($w) => ($kpi['format'] ?? 'zahl') === 'euro'
        ? number_format((float) $w, 0, ',', '.').' €'
        : number_format((float) $w, 0, ',', '.');
    $trend = $kpi['trend'] ?? null;
@endphp
<div class="an-kpi {{ ($kpi['warnung'] ?? false) ? 'an-kpi-warn' : '' }}">
    <div class="an-kpi-label">{{ $kpi['label'] }}</div>
    <div class="an-kpi-wert">{{ $format($kpi['wert']) }}</div>
    <div class="an-kpi-fuss">
        @if($trend && $trend['prozent'] !== null)
            <span class="an-trend an-trend-{{ $trend['richtung'] }}">
                @if($trend['richtung'] === 'auf')
                    {{-- Kein Umbruch zwischen Vorzeichen und Zahl: Blade macht
                         aus jedem Zeilenwechsel ein Leerzeichen, und "+ 660,9 %"
                         liest sich wie zwei Angaben. --}}
                    <svg viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M6 10V2m0 0L2.5 5.5M6 2l3.5 3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span>+{{ number_format($trend['prozent'], 1, ',', '.') }} %</span>
                @elseif($trend['richtung'] === 'ab')
                    <svg viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M6 2v8m0 0l3.5-3.5M6 10L2.5 6.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span>{{ number_format($trend['prozent'], 1, ',', '.') }} %</span>
                @else
                    <svg viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M2 6h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg><span>{{ number_format($trend['prozent'], 1, ',', '.') }} %</span>
                @endif
            </span>
        @elseif($trend && $trend['richtung'] === 'neu')
            <span class="an-trend an-trend-neu">neu</span>
        @endif
        <span>{{ $kpi['trend_text'] }}</span>
    </div>
    @if(! empty($kpi['hinweis']))
        <div class="an-kpi-hint">{{ $kpi['hinweis'] }}</div>
    @endif
</div>
