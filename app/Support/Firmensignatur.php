<?php

namespace App\Support;

use App\Models\CompanySignatureAsset;
use App\Models\SystemSetting;

/**
 * WER unterschreibt hier als Betrieb?
 *
 * DAS PROBLEM, DAS DIESE KLASSE LOEST (Betreiber-Meldung 14.09.2026):
 * Eine Unternehmenssignatur war bis hierher NUR EIN BILD. Im fertigen PDF
 * stand eine Grafik ohne jeden Bezug - kein Name, keine Zuordnung. Wer den
 * Vertrag spaeter las, sah ein Logo und konnte nicht sagen, WELCHE Firma
 * unterschrieben hat. Ein Logo ist ein Wiedererkennungszeichen, keine
 * Signatur: die Signatur braucht den NAMEN.
 *
 * Die Antwort steht laengst im System (Einstellungen -> Firmenname), sie
 * wurde nur nie gefragt. Diese Klasse ist die EINE Stelle, die sie
 * beantwortet - kein neues Feld, keine Migration, keine zweite Wahrheit.
 *
 * WAS SICH NICHT AENDERT: ein Firmenbild bleibt KEIN Unterzeichner. Es hat
 * keine E-Mail, kein Token, keine Zustimmung; im Protokoll steht weiterhin
 * "eingesetzt von", nie "unterschrieben von". Die Signatur des Betriebs
 * wird von einem berechtigten Mitarbeiter aufgebracht - genau wie der
 * Stempel auf Papier.
 */
final class Firmensignatur
{
    /**
     * Der Name des Betriebs, wie er unter der Signatur steht.
     *
     * Reihenfolge: gepflegte Einstellung -> Anwendungsname. Leer wird er
     * nie - ein Block ohne Namen waere wieder nur ein Logo.
     */
    public static function name(): string
    {
        $gepflegt = trim((string) SystemSetting::get('company_name', ''));

        return $gepflegt !== '' ? $gepflegt : (string) config('app.name', 'Dienstly24');
    }

    /** Ist ein Firmenname ausdruecklich gepflegt (und nicht nur der Notbehelf)? */
    public static function namePflegt(): bool
    {
        return trim((string) SystemSetting::get('company_name', '')) !== '';
    }

    /**
     * Das Standardbild: ausdrueckliche Vorgabe, sonst die erste
     * Unternehmenssignatur, sonst irgendein aktives Bild. Ein Logo ist die
     * LETZTE Wahl - es ist eine Marke, keine Signatur.
     */
    public static function standardBild(): ?CompanySignatureAsset
    {
        $aktive = CompanySignatureAsset::where('active', true)->get();
        if ($aktive->isEmpty()) {
            return null;
        }

        return $aktive->firstWhere('is_default', true)
            ?? $aktive->firstWhere('type', CompanySignatureAsset::UNTERSCHRIFT)
            ?? $aktive->firstWhere('type', CompanySignatureAsset::STEMPEL)
            ?? $aktive->first();
    }

    /**
     * Sind die Unternehmensdaten vollstaendig genug fuer eine Signatur?
     *
     * Zwei Dinge, und beide sind noetig: ein Name (sonst steht nichts
     * unter dem Bild) und ein Bild (sonst gibt es nichts zu setzen).
     */
    public static function vollstaendig(): bool
    {
        return self::namePflegt() && self::standardBild() !== null;
    }

    /**
     * Was fehlt - im Klartext, nie als technische Meldung.
     *
     * @return list<string>
     */
    public static function fehlendes(): array
    {
        $fehlt = [];
        if (! self::namePflegt()) {
            $fehlt[] = 'Der Firmenname ist nicht hinterlegt (Einstellungen → Firmenname).';
        }
        if (self::standardBild() === null) {
            $fehlt[] = 'Es ist kein Firmenbild hinterlegt (Einstellungen → Signaturen).';
        }

        return $fehlt;
    }
}
