<?php
namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Fachregeln der E-Scooter-Versicherung (Betreiber-Vorgabe).
 *
 * E-Scooter (und andere Kleinstfahrzeuge mit Versicherungskennzeichen) laufen
 * ueber ein festes Verkehrsjahr: Das Versicherungskennzeichen ist immer vom
 * 1. Maerz bis zum Ende des Februars des Folgejahres gueltig (GDV-/Kennzeichen-
 * jahr). Ein Vertrag endet daher IMMER am Ende des Februars der Saison, in die
 * sein Beginn faellt - und braucht keine Kuendigung ("bedarf keiner
 * Kuendigung"). Der Beitrag wird EINMALIG faellig, nicht laufend.
 *
 * Diese Regeln sind hier zentral gebuendelt, damit jeder Weg (Formular,
 * Dokumenten-Eingang, Import) dasselbe Ablaufdatum berechnet.
 */
class EscooterInsurance
{
    /**
     * Ende der E-Scooter-Saison zu einem Vertragsbeginn: der letzte Tag des
     * Februars der laufenden Saison.
     *
     * Beginn im Maerz-Dezember  -> Ende Februar des FOLGEjahres.
     * Beginn im Januar/Februar  -> Ende Februar DESSELBEN Jahres
     *                              (der Beginn faellt noch in die alte Saison).
     *
     * Schaltjahre werden korrekt beruecksichtigt (28./29. Februar).
     */
    public static function seasonEnd(CarbonInterface|string $start): CarbonImmutable
    {
        $s = $start instanceof CarbonInterface
            ? CarbonImmutable::parse($start->format('Y-m-d'))
            : CarbonImmutable::parse($start);

        $year = (int) $s->format('n') >= 3 ? (int) $s->format('Y') + 1 : (int) $s->format('Y');

        return CarbonImmutable::createFromDate($year, 2, 1)->endOfMonth()->startOfDay();
    }

    /** Ende der Saison als ISO-Datum (JJJJ-MM-TT) - bequem fuers Speichern. */
    public static function seasonEndDate(CarbonInterface|string $start): string
    {
        return self::seasonEnd($start)->format('Y-m-d');
    }
}
