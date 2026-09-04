<?php

namespace App\Services\Commission;

use App\Support\Commission\CommissionEntry;
use Illuminate\Support\Collection;

/**
 * ARCH-3: eine Provisionsquelle, aus der ein Bericht lesen kann.
 *
 * Die Schnittstelle beschreibt AUSSCHLIESSLICH Lesen. Geschrieben wird
 * weiterhin ueber die jeweiligen Fachdienste - der Sinn der Uebung ist,
 * die Doppelungen in der LESE- und Auswertungsschicht zu beseitigen, nicht
 * die drei Fachbereiche zu verschmelzen.
 */
interface CommissionSource
{
    /** Stabiler Schluessel, z. B. 'provisions'. */
    public function key(): string;

    /** Anzeigename fuer Berichte. */
    public function label(): string;

    /** Eingang (Geld kommt rein) oder Ausgang (Geld geht raus). */
    public function direction(): string;

    /** @return Collection<int, CommissionEntry> */
    public function entries(CommissionQuery $query): Collection;
}
