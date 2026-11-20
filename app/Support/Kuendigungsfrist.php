<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Letzter Tag, an dem eine Kuendigung beim Versicherer EINGEGANGEN sein
 * muss, damit der Vertrag zum Verlaengerungstag endet.
 *
 * Regel: Die Versicherungsperiode endet am Tag VOR dem Verlaengerungstag
 * (Verlaengerung 01.01.2027 -> Periode endet 31.12.2026). Davon wird die
 * Kuendigungsfrist in Monaten abgezogen, ohne Monatsueberlauf: gibt es den
 * entsprechenden Tag im Zielmonat nicht, gilt der letzte Tag des Monats.
 *
 *   01.01.2027, 1 Monat -> 31.12.2026 - 1 Monat -> 30.11.2026
 *   31.03.2027, 1 Monat -> 30.03.2027 - 1 Monat -> 28.02.2027
 *   31.03.2028, 1 Monat -> 30.03.2028 - 1 Monat -> 29.02.2028 (Schaltjahr)
 *   01.01.2027, 3 Monate -> 30.09.2026
 *
 * In Zweifelsfaellen faellt das Ergebnis damit auf das FRUEHERE Datum -
 * die sichere Seite fuer den Kunden. Massgeblich bleiben die Bedingungen
 * des Vertrags; so steht es auch in der Mail.
 */
final class Kuendigungsfrist
{
    public static function eingangBis(CarbonInterface $verlaengerung, int $monate = 1): CarbonImmutable
    {
        $monate = max(0, $monate);

        return CarbonImmutable::instance($verlaengerung)
            ->startOfDay()
            ->subDay()
            ->subMonthsNoOverflow($monate);
    }
}
