<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fahrgestellnummer (FIN/VIN) nach ISO 3779 (Betreiber-Meldung 18.11.2026).
 *
 * Eine FIN enthaelt NIE die Buchstaben I, O und Q - sie sind wegen der
 * Verwechslung mit 1 und 0 ausgeschlossen. Gemeldet war: bei der Erfassung
 * wird "O" statt der Ziffer "0" getippt. Eine solche FIN passt zu keinem
 * Fahrzeug und faellt erst beim Versicherer auf. Deshalb wird sie
 * ABGELEHNT, nicht still korrigiert: der Mitarbeiter soll die Nummer
 * vom Fahrzeugschein abgleichen, statt sich auf eine Umdeutung zu verlassen.
 *
 * Bewusst KEINE Laengenpruefung: E-Scooter und Altfahrzeuge (vor 1981)
 * tragen kuerzere Nummern.
 */
class Fahrgestellnummer implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value)) {
            $fail('Die FIN ist ungültig.');
            return;
        }

        $verboten = array_values(array_unique(
            preg_match_all('/[IOQ]/', mb_strtoupper($value), $m) ? $m[0] : []
        ));
        if ($verboten !== []) {
            $fail('Die FIN darf die Buchstaben I, O und Q nicht enthalten (gefunden: '
                .implode(', ', $verboten).'). Bitte "0" (Null) statt "O" und "1" statt "I" eingeben.');
        }
    }
}
