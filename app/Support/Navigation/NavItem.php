<?php

namespace App\Support\Navigation;

/**
 * EIN Menuepunkt der Beraterwelt.
 *
 * Der Punkt kennt seinen Ziel-Link, seine Aktiv-Muster und - falls
 * vorhanden - genau EINE Zahl, die eine Handlung verlangt. Bewusst
 * unveraenderlich: die Navigation wird einmal gebaut und danach nur noch
 * gelesen; ein Menuepunkt, den eine View nachtraeglich umbiegt, waere in
 * keiner Uebersicht mehr wiederzufinden.
 */
final class NavItem
{
    /**
     * @param string[] $activePatterns Route-Muster (request()->routeIs)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $url,
        public readonly string $icon,
        public readonly array $activePatterns = [],
        public readonly int $badge = 0,
        public readonly string $badgeTone = NavBadges::TONE_ATTENTION,
        public readonly ?string $hint = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->activePatterns !== [] && request()->routeIs(...$this->activePatterns);
    }

    public function hasBadge(): bool
    {
        return $this->badge > 0;
    }
}
