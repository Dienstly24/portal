<?php

namespace App\Services\Matching;

/**
 * Zusammenfuehren verweigert: die beiden Akten sind vermutlich verschiedene
 * Personen (abweichendes Geburtsdatum/Vorname) oder beide haben einen
 * aktiven Portalzugang. Nur ein Admin mit Begruendung kann uebersteuern
 * (KI-063, KI-065).
 */
class MergeBlockedException extends \RuntimeException
{
    /** @param list<string> $gruende */
    public function __construct(public readonly array $gruende)
    {
        parent::__construct('Zusammenfuehren gesperrt: '.implode('; ', $gruende));
    }
}
