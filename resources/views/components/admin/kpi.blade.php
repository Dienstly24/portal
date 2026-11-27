{{-- Kennzahl-Kachel (UI-Modernisierung 27.11.2026).

     Mit `href` ist die ganze Kachel ein Link auf die Liste, deren Zeilen sie
     zaehlt - eine Zahl ohne Weg zu ihren Datensaetzen ist eine Behauptung.
     `trend` ist das Ergebnis von DashboardUebersicht::trend() und erscheint
     nur, wenn es eine Vergleichsbasis gibt ("neu" statt "+100 %"). --}}
@props(['label', 'wert', 'symbol' => 'chart', 'ton' => '', 'href' => null, 'trend' => null, 'vergleich' => null])
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'kpi']) }}>
    <div class="kpi-kopf">
        <span class="kpi-symbol {{ $ton ? 'kpi-symbol-'.$ton : '' }}"><x-admin.symbol :name="$symbol" /></span>
        <span>{{ $label }}</span>
    </div>
    <div class="kpi-wert">{{ $wert }}</div>
    <div class="kpi-unter">
        @if($trend)
            <span class="trend trend-{{ $trend['art'] }}">{{ $trend['text'] }}</span>
            @if($vergleich !== null)<span>{{ $vergleich }}</span>@endif
        @endif
        {{ $slot }}
    </div>
    @if($href)<span class="kpi-mehr" aria-hidden="true">→</span>@endif
</{{ $tag }}>
