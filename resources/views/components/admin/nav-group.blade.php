{{-- Aufklappbare Gruppe.

     Der zugeklappte Zustand wird SERVERSEITIG vorgerendert (aus
     $openByDefault) und danach vom gemerkten Zustand des Nutzers
     ueberschrieben - so springt die Seitenleiste beim Laden nicht.
     Die Gruppe der aktiven Seite bleibt immer offen. --}}
@props(['group'])
@php
    $active = $group->hasActiveItem();
    $collapsed = !$active && !$group->openByDefault;
    $sum = $group->badgeSum();
@endphp
<div class="nav-group {{ $collapsed ? 'collapsed' : '' }}" data-group="{{ $group->key }}" @if($active) data-has-active="1" @endif>
    <button type="button" class="nav-group-header" aria-expanded="{{ $collapsed ? 'false' : 'true' }}"
            aria-controls="nav-body-{{ $group->key }}" data-h-click="7a267c02e4">
        <span class="nav-group-title">{{ $group->label }}</span>
        {{-- Summe NUR im eingeklappten Zustand: zugeklappt darf keine offene
             Aufgabe unsichtbar werden. --}}
        @if($sum > 0)<span class="nav-badge nav-group-badge">{{ $sum > 99 ? '99+' : $sum }}</span>@endif
        <svg class="nav-group-caret" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>
    <div class="nav-group-body" id="nav-body-{{ $group->key }}">
        @foreach($group->items as $item)
            <x-admin.nav-item :item="$item" />
        @endforeach
    </div>
</div>

{{-- Ereignis-Handler dieser Vorlage (Audit SEC-4): frueher
     onclick="…"-Attribute. Ein Attribut kann keinen CSP-Nonce
     tragen; dieses <script @cspNonce> kann es. Verdrahtet wird ueber
     data-h-<ereignis> in resources/js/ui.js. --}}
@pushOnce('cspScripts')
<script @cspNonce>
window.__h = window.__h || {};
window.__h["7a267c02e4"] = function (event) { toggleNavGroup(this) };
</script>
@endPushOnce
