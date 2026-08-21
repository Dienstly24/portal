<?php
namespace App\Services\Vermittler;

use App\Models\Contract;

/**
 * Uebersetzung der Status-Codes des Vermittlers in unseren
 * Abrechnungsstatus. Die Codes stammen aus der Export-Datei des Vermittlers
 * (TARIFCHECK24: 1/2/4) und sind hier an EINER Stelle hinterlegt.
 *
 * WICHTIG: Ein UNBEKANNTER Code wird nie geraten - er fuehrt zu
 * "Prüfung erforderlich". Lieber eine Zeile zur Ansicht als ein falscher
 * Status, auf den sich niemand verlassen kann.
 */
class VermittlerStatusMap
{
    /**
     * code => Abrechnungsstatus des Vertrags.
     *  1 = bestaetigt, steht zur Abrechnung an ("In Abrechnung gefunden")
     *  2 = storniert (dazu liefert der Vermittler einen Stornogrund)
     *  4 = abgerechnet/ausgezahlt (taucht in der Gutschrift auf)
     */
    public const CODES = [
        '1' => Contract::VERMITTLER_IN_ABRECHNUNG,
        '2' => Contract::VERMITTLER_STORNIERT,
        '4' => Contract::VERMITTLER_ABGERECHNET,
    ];

    /**
     * TEXT-Status aus der Vorgangsliste des Portals (nicht aus der
     * Abrechnungsdatei). "offen" heisst: der Vorgang liegt beim Vermittler,
     * ueber die Provision ist noch NICHTS entschieden - das entspricht bei
     * uns genau "ID zugeordnet". Bewusst NICHT "In Abrechnung gefunden":
     * eine offene Position ist keine Bestaetigung.
     */
    public const TEXT_STATUSES = [
        'offen' => Contract::VERMITTLER_ID_ZUGEORDNET,
        'in bearbeitung' => Contract::VERMITTLER_ID_ZUGEORDNET,
        'bestaetigt' => Contract::VERMITTLER_IN_ABRECHNUNG,
        'bestätigt' => Contract::VERMITTLER_IN_ABRECHNUNG,
        'storniert' => Contract::VERMITTLER_STORNIERT,
        'abgerechnet' => Contract::VERMITTLER_ABGERECHNET,
    ];

    /** Abrechnungsstatus zu einem TEXT-Status der Vorgangsliste. */
    public static function forText(?string $text): ?string
    {
        $key = mb_strtolower(trim((string) $text));
        return self::TEXT_STATUSES[$key] ?? null;
    }

    /** Klartext des Codes, wie ihn der Vermittler meint. */
    public const CODE_LABELS = [
        '1' => 'Bestätigt (zur Abrechnung)',
        '2' => 'Storniert',
        '4' => 'Abgerechnet / ausgezahlt',
    ];

    /**
     * Abrechnungsstatus zu einem Status-Token - egal ob Zahlencode aus der
     * Abrechnungsdatei oder Klartext aus der Vorgangsliste. Beides sind
     * Angaben DESSELBEN Vermittlers ueber DENSELBEN Vorgang, deshalb eine
     * Uebersetzung statt zweier.
     */
    public static function forCode(?string $code): string
    {
        $code = trim((string) $code);
        return self::CODES[$code] ?? self::forText($code) ?? Contract::VERMITTLER_PRUEFUNG;
    }

    public static function isKnown(?string $code): bool
    {
        $code = trim((string) $code);
        return isset(self::CODES[$code]) || self::forText($code) !== null;
    }

    /**
     * Rangfolge der Abrechnungs-Zustaende. Eine spaetere Meldung darf einen
     * Vertrag nur VORWAERTS bewegen: die Vorgangsliste ("offen") ist immer
     * aelter als eine Abrechnung und darf ein "abgerechnet" nie
     * zuruecknehmen. Prüfung steht ueber allem - ein Widerspruch soll nicht
     * von der naechsten Datei stillschweigend geglaettet werden.
     */
    private const RANK = [
        Contract::VERMITTLER_NEU => 0,
        Contract::VERMITTLER_NICHT_GEFUNDEN => 1,
        Contract::VERMITTLER_REFERENZ => 2,
        Contract::VERMITTLER_ID_ZUGEORDNET => 3,
        Contract::VERMITTLER_IN_ABRECHNUNG => 4,
        Contract::VERMITTLER_ABGERECHNET => 5,
        Contract::VERMITTLER_STORNIERT => 5,
        Contract::VERMITTLER_PRUEFUNG => 9,
    ];

    /** Darf $new den bisherigen Zustand $current ersetzen? */
    public static function mayAdvance(string $current, string $new): bool
    {
        return (self::RANK[$new] ?? 0) >= (self::RANK[$current] ?? 0);
    }

    public static function codeLabel(?string $code): string
    {
        $code = trim((string) $code);
        return self::CODE_LABELS[$code] ?? ('Unbekannter Status "' . $code . '"');
    }
}
