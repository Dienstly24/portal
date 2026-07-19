<?php
namespace App\Services\Ai;

/**
 * Leitet die Versicherungsart (gesetzlich/privat) aus dem Namen der
 * Krankenkasse ab. Ist der Name eindeutig einer gesetzlichen Kasse (GKV) oder
 * einem privaten Versicherer (PKV) zuzuordnen, wird der Typ automatisch gesetzt
 * - andernfalls bleibt er leer (kein Raten; der Mitarbeiter entscheidet).
 */
class KrankenkasseType
{
    /** Stichworte bekannter gesetzlicher Krankenkassen (GKV). */
    private const GKV = [
        'aok', 'barmer', 'dak', 'techniker krankenkasse', 'kkh', 'ikk', 'bkk',
        'knappschaft', 'hkk', 'sbk', 'viactiv', 'bahn-bkk', 'hek', 'mhplus',
        'novitas', 'pronova', 'securvita', 'handelskrankenkasse', 'kaufmaennische',
        'kaufmännische', 'big direkt', 'salus', 'vivida', 'actimonda',
        'gesetzliche kranken', 'betriebskrankenkasse', 'ersatzkasse',
    ];

    /** Stichworte bekannter privater Krankenversicherer (PKV). */
    private const PKV = [
        'debeka', 'allianz', 'axa', 'dkv', 'signal iduna', 'hansemerkur',
        'hanse merkur', 'huk-coburg', 'huk coburg', 'barmenia', 'gothaer',
        'hallesche', 'nuernberger', 'nürnberger', 'universa', 'central kranken',
        'inter kranken', 'ottonova', 'concordia', 'wuerttembergische',
        'württembergische', 'arag kranken', 'sueddeutsche kranken',
        'süddeutsche kranken', 'alte oldenburger', 'landeskrankenhilfe',
        'mecklenburgische', 'private kranken', 'krankenversicherung a.g.',
    ];

    public static function resolve(?string $company): ?string
    {
        if ($company === null || trim($company) === '') {
            return null;
        }
        // Mit Rand-Leerzeichen, damit kurze Marker nur als ganzes Wort greifen.
        $c = ' ' . mb_strtolower(trim($company)) . ' ';

        // GKV zuerst: viele Betriebskrankenkassen tragen einen Firmennamen, der
        // auch privat vorkommt (z.B. "... BKK") - "bkk" gewinnt dann korrekt.
        foreach (self::GKV as $needle) {
            if (str_contains($c, $needle)) {
                return 'gesetzlich';
            }
        }
        foreach (self::PKV as $needle) {
            if (str_contains($c, $needle)) {
                return 'privat';
            }
        }

        // 'TK' nur als eigenstaendiges Wort (Abkuerzung der Techniker Krankenkasse).
        if (str_contains($c, ' tk ')) {
            return 'gesetzlich';
        }

        return null; // unbekannt -> nicht raten
    }
}
