<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\Customer;
use Illuminate\Support\Facades\Crypt;

/**
 * Signierter, befristeter Bezug in Chat-Links aus E-Mails
 * (`/chat?ref=<token>`).
 *
 * Er dient AUSSCHLIESSLICH dem Team: eine Unterhaltung, die ueber einen
 * solchen Link beginnt, bekommt eine INTERNE Notiz "kam ueber den Link an
 * Kunde X / Vertrag Y". Er
 *  - zeigt dem Besucher keine Kunden- oder Vertragsdaten,
 *  - meldet niemanden an und oeffnet keinen Portalbereich,
 *  - ordnet die Unterhaltung NICHT automatisch der Kundenakte zu (eine
 *    weitergeleitete Mail beweist nicht, wer schreibt - und mit einer
 *    Kundenakte wuerde der KI-Assistent mit Kundendaten antworten).
 *
 * Verschluesselt (nicht nur signiert): im Link steht keine Datenbank-ID.
 * Der Zweck `p` bindet das Token an den Chat; ein anderes verschluesseltes
 * Token der Anwendung (z.B. das Hilfe-Formular) passt hier nicht.
 */
final class ChatReferenz
{
    private const ZWECK = 'chat';

    public static function erzeugen(Customer $customer, ?Contract $contract = null, ?int $tage = null): string
    {
        $tage ??= max(1, (int) config('portal.chat_ref_days', 30));

        return Crypt::encryptString(json_encode([
            'p' => self::ZWECK,
            'c' => $customer->id,
            'k' => $contract?->id,
            'e' => now()->addDays($tage)->timestamp,
        ]));
    }

    /**
     * @return array{customer_id: string|int, contract_id: string|int|null}|null
     *         null bei ungueltigem, fremdem oder abgelaufenem Token.
     */
    public static function pruefen(?string $token): ?array
    {
        if ($token === null || $token === '' || strlen($token) > 2000) {
            return null;
        }
        try {
            $daten = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($daten)
            || ($daten['p'] ?? null) !== self::ZWECK
            || empty($daten['c'])
            || (int) ($daten['e'] ?? 0) < now()->timestamp) {
            return null;
        }

        return ['customer_id' => $daten['c'], 'contract_id' => $daten['k'] ?? null];
    }
}
