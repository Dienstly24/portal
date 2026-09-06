{{-- Untermenues der Einstellungen-Ansicht (Betreiber-Vorgabe 06.09.2026).

     Vertrieb, Marketing und Administration standen bisher in der
     Seitenleiste. Sie stehen jetzt hier - mit denselben Zielen, denselben
     Aktiv-Mustern und denselben Rollenregeln; die Quelle bleibt
     App\Support\Navigation\AdminNavigation, damit es die Struktur weiterhin
     nur EINMAL gibt.

     Aufgeklappt statt zugeklappt: wer diese Seite oeffnet, sucht genau
     einen dieser Punkte - hier waere Zuklappen ein zusaetzlicher Klick. --}}
@php($settingsNav = \App\Support\Navigation\AdminNavigation::for(auth()->user()))
@if($settingsNav->settingsGroups() !== [])
<style>
.sub-menu{margin-bottom:20px;max-width:900px;}
.sub-menu-title{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:10px;}
.sub-menu-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;}
.sub-menu-item{display:flex;align-items:center;gap:10px;padding:14px 16px;border:1px solid var(--line);border-radius:10px;background:var(--surface);color:var(--ink);text-decoration:none;font-size:13.5px;font-weight:600;transition:.15s;}
.sub-menu-item:hover{border-color:var(--emerald);box-shadow:0 4px 14px rgba(0,0,0,.08);transform:translateY(-1px);}
.sub-menu-item.active{border-color:var(--emerald);}
.sub-menu-item svg{width:18px;height:18px;flex:none;color:var(--emerald);}
.sub-menu-item .sub-menu-label{flex:1;min-width:0;}
.sub-menu-item .nav-badge{flex:none;}
</style>
@foreach($settingsNav->settingsGroups() as $group)
<nav class="sub-menu" data-settings-group="{{ $group->key }}" aria-label="{{ $group->label }}">
    <div class="sub-menu-title">{{ $group->label }}</div>
    <div class="sub-menu-grid">
        @foreach($group->items as $item)
        <a href="{{ $item->url }}" class="sub-menu-item {{ $item->isActive() ? 'active' : '' }}"
           data-nav-key="{{ $item->key }}" @if($item->isActive()) aria-current="page" @endif>
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                @foreach(\App\Support\Navigation\NavIcons::paths($item->icon) as $d)
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $d }}"/>
                @endforeach
            </svg>
            <span class="sub-menu-label">{{ $item->label }}</span>
            @if($item->hasBadge())
                <span class="nav-badge nav-badge-{{ $item->badgeTone }}"
                      title="{{ $item->badge }} offen – wartet auf Bearbeitung">{{ $item->badge > 99 ? '99+' : $item->badge }}</span>
            @endif
        </a>
        @endforeach
    </div>
</nav>
@endforeach
@endif
