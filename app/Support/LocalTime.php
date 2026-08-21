<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Anzeige-Zeitzone: gespeichert wird UTC, GEZEIGT wird deutsche Ortszeit.
 *
 * Warum getrennt: der Betrieb sitzt in Deutschland, die Datenbank rechnet in
 * UTC. Ein Zeitstempel, der 14:30 anzeigt, obwohl der Vorgang um 16:30
 * stattfand, ist schlicht falsch - beim DSGVO-Einwilligungszeitpunkt sogar
 * rechtlich heikel.
 *
 * Warum NICHT einfach app.timezone auf Europe/Berlin stellen: Laravel
 * schreibt neue Zeitstempel in der Anwendungs-Zeitzone. Der Altbestand laege
 * dann in UTC, alles Neue in Ortszeit - und man saehe einer Zeile nicht an,
 * welche von beiden sie ist. Ein solcher Mischbestand ist hinterher nicht
 * mehr sauber zu reparieren. Deshalb: Speicherung unveraendert, Umrechnung
 * ausschliesslich bei der Ausgabe.
 *
 * Warum NICHT in den Model-Casts umrechnen: dann traegt auch jeder Wert, der
 * in eine WHERE-Bedingung geht, Ortszeit - und wuerde gegen eine
 * UTC-Spalte verglichen. Das waere ein stiller Zwei-Stunden-Fehler in
 * Abfragen statt in der Anzeige. Die Umrechnung gehoert an die Oberflaeche,
 * nicht in die Daten.
 */
class LocalTime
{
    /** Zeitzone, in der Zeitpunkte angezeigt werden. */
    public static function zone(): string
    {
        return (string) config('app.display_timezone', 'Europe/Berlin');
    }

    /**
     * Einen gespeicherten Zeitpunkt in die Anzeige-Zeitzone bringen.
     *
     * Nimmt alles entgegen, was in den Views vorkommt (Carbon, DateTime,
     * String aus einer Spalte, null) - eine Anzeige darf nie daran
     * scheitern, dass ein Feld leer ist.
     */
    public static function for(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // IMMER eine neue Instanz aufbauen - der uebergebene Wert darf
            // nicht veraendert werden. Sonst wuerde eine einzige Anzeige den
            // Zeitpunkt am Model-Attribut verschieben und alles, was im
            // selben Request noch damit rechnet (Fristen, Vergleiche, weitere
            // Ausgaben), laege zwei Stunden daneben - ohne Fehlermeldung.
            $moment = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse($value);

            return $moment->setTimezone(self::zone());
        } catch (\Throwable $e) {
            return null;
        }
    }
}
