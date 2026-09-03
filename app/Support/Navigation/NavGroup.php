<?php

namespace App\Support\Navigation;

/**
 * Eine aufklappbare Gruppe der Beraterwelt-Navigation.
 *
 * `$openByDefault` trennt die TAEGLICHE Arbeit von der Verwaltung: was ein
 * Mitarbeiter jeden Tag braucht, steht offen da; Marketing, Vertrieb und
 * Administration sind zugeklappt, bis sie jemand braucht. Der Nutzer
 * ueberschreibt das pro Gruppe, sein Zustand wird gemerkt.
 */
final class NavGroup
{
    /** @param NavItem[] $items */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $items,
        public readonly bool $openByDefault = true,
    ) {
    }

    /** Die aktive Seite haelt ihre Gruppe IMMER offen - sonst waere der aktive Punkt unsichtbar. */
    public function hasActiveItem(): bool
    {
        foreach ($this->items as $item) {
            if ($item->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Summe der Handlungs-Zahlen. Sie wird NUR im eingeklappten Zustand
     * gezeigt: eingeklappt darf keine offene Aufgabe verschwinden.
     */
    public function badgeSum(): int
    {
        return array_sum(array_map(fn (NavItem $i) => $i->badge, $this->items));
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
