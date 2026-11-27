{{-- Linien-Symbol aus derselben Quelle wie die Seitenleiste
     (App\Support\Navigation\NavIcons) - ein Symbolsatz fuer die ganze
     Beraterwelt statt bunter Emoji je Seite. --}}
@props(['name', 'size' => 18])
<svg width="{{ $size }}" height="{{ $size }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" {{ $attributes }}>
    @foreach(\App\Support\Navigation\NavIcons::paths($name) as $d)
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $d }}"/>
    @endforeach
</svg>
