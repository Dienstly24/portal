{{-- Responsives Bild aus der Medienverwaltung (AVIF/WebP/JPG, srcset,
     width/height gegen Layout-Shift, lazy). $asset = MediaAsset|null;
     ohne Asset wird NICHTS gerendert - der Aufrufer zeigt seinen
     eingebauten Fallback (Icon-Kachel, SVG-Grafik, Logo-Panel). --}}
@if(!empty($asset))
    @if($asset->isSvg())
        <img src="{{ $asset->fallbackUrl() }}" alt="{{ $asset->alt() }}"
             class="{{ $imgClass ?? '' }}" @if($lazy ?? true) loading="lazy" @endif decoding="async">
    @else
        @php([$w, $h] = $asset->displaySize())
        <picture>
            @if($asset->srcset('avif'))<source type="image/avif" srcset="{{ $asset->srcset('avif') }}" sizes="{{ $sizes ?? '100vw' }}">@endif
            @if($asset->srcset('webp'))<source type="image/webp" srcset="{{ $asset->srcset('webp') }}" sizes="{{ $sizes ?? '100vw' }}">@endif
            <img src="{{ $asset->fallbackUrl() }}"
                 @if($asset->srcset('jpg')) srcset="{{ $asset->srcset('jpg') }}" sizes="{{ $sizes ?? '100vw' }}" @endif
                 alt="{{ $asset->alt() }}" width="{{ $w }}" height="{{ $h }}"
                 class="{{ $imgClass ?? '' }}" @if($lazy ?? true) loading="lazy" @endif decoding="async">
        </picture>
    @endif
@endif
