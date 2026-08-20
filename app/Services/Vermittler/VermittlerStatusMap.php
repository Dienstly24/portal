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

    /** Klartext des Codes, wie ihn der Vermittler meint. */
    public const CODE_LABELS = [
        '1' => 'Bestätigt (zur Abrechnung)',
        '2' => 'Storniert',
        '4' => 'Abgerechnet / ausgezahlt',
    ];

    public static function forCode(?string $code): string
    {
        $code = trim((string) $code);
        return self::CODES[$code] ?? Contract::VERMITTLER_PRUEFUNG;
    }

    public static function isKnown(?string $code): bool
    {
        return isset(self::CODES[trim((string) $code)]);
    }

    public static function codeLabel(?string $code): string
    {
        $code = trim((string) $code);
        return self::CODE_LABELS[$code] ?? ('Unbekannter Status "' . $code . '"');
    }
}
