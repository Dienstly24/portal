{{-- Ein Menuepunkt. Das Badge steht nur da, wenn es eine Handlung gibt --}}
@props(['item'])
@php($active = $item->isActive())
<a href="{{ $item->url }}"
   class="nav-item {{ $active ? 'active' : '' }}"
   data-nav-key="{{ $item->key }}"
   @if($active) aria-current="page" @endif>
    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        @foreach(\App\Support\Navigation\NavIcons::paths($item->icon) as $d)
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $d }}"/>
        @endforeach
    </svg>
    <span class="nav-label">{{ $item->label }}</span>
    @if($item->hasBadge())
        <span class="nav-badge nav-badge-{{ $item->badgeTone }}"
              title="{{ $item->badge }} offen – wartet auf Bearbeitung">{{ $item->badge > 99 ? '99+' : $item->badge }}</span>
    @endif
</a>
