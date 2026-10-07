<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Die zwei Altersgrenzen fuer Kinder in der Kundenkartei - an EINER Stelle
 * und als EINSTELLUNG, nicht als Zahl im Code (Betreiber-Auftrag 07.10.2026).
 *
 *  - SELBSTSTAENDIGKEIT (Standard 16): darunter bekommt niemand eine eigene
 *    Kundennummer, keinen Vertrag und kein Portal. Das Kind ist ABHAENGIG
 *    und wird unter der Akte des Elternteils gefuehrt.
 *  - ERINNERUNG (Standard 15): ab hier erinnert das System das Team, den
 *    eigenen Zugang vorzubereiten.
 *
 * Die Erinnerung liegt immer VOR der Selbststaendigkeit - eine Erinnerung,
 * die erst danach kaeme, waere keine Vorbereitung mehr. Ungueltige Werte
 * fallen deshalb auf die Voreinstellung zurueck, statt still eine
 * widerspruechliche Regel zu ergeben.
 */
final class FamilienAlter
{
    public const SETTING_ERINNERUNG = 'familie_erinnerung_alter';
    public const SETTING_SELBSTSTAENDIG = 'familie_selbststaendig_alter';

    public const STANDARD_ERINNERUNG = 15;
    public const STANDARD_SELBSTSTAENDIG = 16;

    /** Zulaessiger Bereich fuer beide Werte (Plausibilitaet, kein Rechtsrat). */
    public const MIN = 10;
    public const MAX = 18;

    /** Ab diesem Alter: eigene Kundennummer, Vertraege, Portal. */
    public static function selbststaendig(): int
    {
        $wert = (int) SystemSetting::get(self::SETTING_SELBSTSTAENDIG, (string) self::STANDARD_SELBSTSTAENDIG);

        return ($wert >= self::MIN && $wert <= self::MAX) ? $wert : self::STANDARD_SELBSTSTAENDIG;
    }

    /** Ab diesem Alter: Erinnerung an das Team, den Zugang vorzubereiten. */
    public static function erinnerung(): int
    {
        $wert = (int) SystemSetting::get(self::SETTING_ERINNERUNG, (string) self::STANDARD_ERINNERUNG);
        $grenze = self::selbststaendig();

        if ($wert < self::MIN || $wert >= $grenze) {
            return min(self::STANDARD_ERINNERUNG, $grenze - 1);
        }

        return $wert;
    }

    /**
     * Beide Werte speichern. Liefert eine Fehlermeldung oder null.
     * Geprueft wird hier UND im Formular - die Regel "Erinnerung vor
     * Selbststaendigkeit" darf an keinem Weg vorbei.
     */
    public static function setze(int $erinnerung, int $selbststaendig): ?string
    {
        if ($selbststaendig < self::MIN || $selbststaendig > self::MAX
            || $erinnerung < self::MIN || $erinnerung > self::MAX) {
            return 'Die Altersgrenzen muessen zwischen '.self::MIN.' und '.self::MAX.' liegen.';
        }
        if ($erinnerung >= $selbststaendig) {
            return 'Die Erinnerung muss vor dem Alter der Selbststaendigkeit liegen.';
        }

        SystemSetting::set(self::SETTING_ERINNERUNG, (string) $erinnerung);
        SystemSetting::set(self::SETTING_SELBSTSTAENDIG, (string) $selbststaendig);

        return null;
    }
}
