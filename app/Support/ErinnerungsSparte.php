<?php

namespace App\Support;

/**
 * Sparten-Angaben fuer Vertrags-Erinnerungen: Anzeigename, Grammatik,
 * Bezeichnung des Vertragspartners, Standard-Kuendigungsfrist und - als
 * Freigabe - die Vorlage.
 *
 * NEUE SPARTE EINSTECKEN (Strom, Gas, Internet, GKV):
 *   1. hier `vorlage` setzen (Schluessel in ErinnerungsTexte),
 *   2. in ErinnerungsTexte::TEXTE die freigegebenen Absaetze A/B (DE + AR)
 *      eintragen - Entwuerfe: docs/ERINNERUNG_TEXTENTWUERFE.md,
 *   3. die Sparte in ERINNERUNG_SPARTEN_AKTIV aufnehmen.
 * Ohne Vorlage wird eine Sparte NIE mit dem neuen Weg verschickt, und der
 * Job-Schalter laesst sie aus - es geht also nie ein alter, nicht
 * freigegebener Text hinaus.
 *
 * `partner_de/ar` ist "Versicherer" bei Versicherungen und "Anbieter" bei
 * Strom, Gas, Internet (das sind keine Versicherungen).
 */
final class ErinnerungsSparte
{
    public const SPARTEN = [
        'kfz' => [
            'name_de' => 'Kfz-Versicherung',
            'name_ar' => 'تأمين السيارة',
            'ihre_de' => 'Ihre Kfz-Versicherung',
            'ihre_ar' => 'تأمين سيارتك',
            'meine_de' => 'meiner Kfz-Versicherung',
            'genus' => 'f',
            'partner_de' => 'Versicherer',
            'partner_ar' => 'شركة التأمين',
            'kuendigungsfrist_monate' => 1,
            'vorlage' => 'kfz',
        ],
        // Pausiert (Betreiber-Vorgabe 20.11.2026): Texte erst nach Freigabe.
        'strom' => [
            'name_de' => 'Stromvertrag', 'name_ar' => 'عقد الكهرباء',
            'ihre_de' => 'Ihr Stromvertrag', 'ihre_ar' => 'عقد الكهرباء الخاص بك',
            'meine_de' => 'meinem Stromvertrag', 'genus' => 'm',
            'partner_de' => 'Anbieter', 'partner_ar' => 'المزوّد',
            'kuendigungsfrist_monate' => 1, 'vorlage' => null,
        ],
        'gas' => [
            'name_de' => 'Gasvertrag', 'name_ar' => 'عقد الغاز',
            'ihre_de' => 'Ihr Gasvertrag', 'ihre_ar' => 'عقد الغاز الخاص بك',
            'meine_de' => 'meinem Gasvertrag', 'genus' => 'm',
            'partner_de' => 'Anbieter', 'partner_ar' => 'المزوّد',
            'kuendigungsfrist_monate' => 1, 'vorlage' => null,
        ],
        'internet' => [
            'name_de' => 'Internetvertrag', 'name_ar' => 'عقد الإنترنت',
            'ihre_de' => 'Ihr Internetvertrag', 'ihre_ar' => 'عقد الإنترنت الخاص بك',
            'meine_de' => 'meinem Internetvertrag', 'genus' => 'm',
            'partner_de' => 'Anbieter', 'partner_ar' => 'المزوّد',
            'kuendigungsfrist_monate' => 1, 'vorlage' => null,
        ],
        'krankenversicherung' => [
            'name_de' => 'gesetzliche Krankenversicherung', 'name_ar' => 'التأمين الصحي الحكومي',
            'ihre_de' => 'Ihre gesetzliche Krankenversicherung', 'ihre_ar' => 'تأمينك الصحي الحكومي',
            'meine_de' => 'meiner Krankenkasse', 'genus' => 'f',
            'partner_de' => 'Krankenkasse', 'partner_ar' => 'صندوق التأمين الصحي',
            'kuendigungsfrist_monate' => 0, 'vorlage' => null,
        ],
    ];

    /** @return array<string, mixed>|null */
    public static function fuer(?string $typ): ?array
    {
        if ($typ === 'strom_gas') {
            $typ = 'strom'; // Altdaten
        }

        return $typ !== null ? (self::SPARTEN[$typ] ?? null) : null;
    }

    public static function vorlage(?string $typ): ?string
    {
        return self::fuer($typ)['vorlage'] ?? null;
    }

    /**
     * Darf fuer diese Sparte JETZT verschickt werden? Job-Schalter UND
     * freigegebene Vorlage - beides muss stimmen.
     */
    public static function versandbereit(?string $typ): bool
    {
        if ($typ === null || self::vorlage($typ) === null) {
            return false;
        }

        return in_array($typ, (array) config('erinnerung.sparten_aktiv', []), true);
    }

    public static function kuendigungsfristMonate(?string $typ): int
    {
        return (int) (self::fuer($typ)['kuendigungsfrist_monate'] ?? 1);
    }
}
