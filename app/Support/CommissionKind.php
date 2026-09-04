<?php

namespace App\Support;

/**
 * PROVISIONSARTEN (Betreiber-Vorgabe 02.09.2026, §12).
 *
 * Jede Quelle nennt dieselbe Sache anders: "AP", "Abschlussprovision",
 * "Abschluss-Courtage", "APStorno". Fuer die AUSWERTUNG braucht es einen
 * gemeinsamen Nenner - fuer den BELEG aber die Bezeichnung der Quelle.
 * Deshalb gilt hier dieselbe Trennung wie ueberall im Provisionsteil:
 * `commission_type` haelt den Originaltext, `commission_kind` unsere
 * Deutung. Wer die Deutung anzweifelt, sieht das Original daneben.
 *
 * UNBEKANNTES WIRD NIE GERATEN: was in keiner Liste steht, wird
 * `sonstige` - sichtbar, statt still einer Art zugeschlagen zu werden,
 * die dann in der Auswertung falsch mitzaehlt.
 */
class CommissionKind
{
    public const ABSCHLUSS = 'abschluss';
    public const FOLGE = 'folge';
    public const LAUFEND = 'laufend';
    public const BESTAND = 'bestand';
    public const SONDER = 'sonder';
    public const SERVICE = 'service';
    public const STORNO = 'storno';
    public const KORREKTUR = 'korrektur';
    public const SONSTIGE = 'sonstige';

    /** Art => Anzeige. */
    public const ALL = [
        self::ABSCHLUSS => 'Abschlussprovision',
        self::FOLGE => 'Folgeprovision',
        self::LAUFEND => 'Laufende Provision',
        self::BESTAND => 'Bestandsprovision',
        self::SONDER => 'Sonderprovision',
        self::SERVICE => 'Servicegebühr',
        self::STORNO => 'Storno',
        self::KORREKTUR => 'Korrektur',
        self::SONSTIGE => 'Sonstige Provisionsart',
    ];

    /**
     * Schreibweisen der Quellen. Die STORNO-Erkennung steht bewusst vor der
     * Abschluss-Erkennung: "APStorno" enthaelt "AP", ist aber das genaue
     * Gegenteil einer Abschlussprovision.
     *
     * @var array<int,array{0:string,1:array<int,string>}>
     */
    private const PATTERNS = [
        [self::STORNO, ['storno', 'stornierung', 'ruecklastschrift', 'rueckbelastung', 'cancellation', 'chargeback']],
        [self::KORREKTUR, ['korrektur', 'korr.', 'berichtigung', 'nachbuchung', 'correction', 'adjustment']],
        [self::FOLGE, ['folgeprovision', 'folgecourtage', 'folgeprov', 'fp', 'anschlussprovision']],
        [self::BESTAND, ['bestandsprovision', 'bestandscourtage', 'bestandspflege', 'bp', 'bestand']],
        [self::LAUFEND, ['laufende provision', 'laufend', 'lp', 'ratierlich']],
        [self::SONDER, ['sonderprovision', 'bonus', 'sondervergütung', 'sondervergutung', 'incentive', 'zusatzprovision']],
        [self::SERVICE, ['servicegebuehr', 'servicegebühr', 'servicepauschale', 'betreuungsgebuehr', 'gebuehr']],
        [self::ABSCHLUSS, ['abschlussprovision', 'abschlusscourtage', 'abschluss', 'ap', 'neugeschaeft', 'neugeschäft', 'erstprovision']],
    ];

    /**
     * Art aus dem Text der Quelle - und aus dem BETRAG.
     *
     * Ein negativer Betrag ohne jede Bezeichnung ist im Provisionsgeschaeft
     * immer eine Rueckbuchung. Ihn als "sonstige" zu fuehren, hiesse: die
     * Stornoquote der Auswertung waere zu niedrig, und genau die ist die
     * Zahl, wegen der man hinschaut.
     */
    public static function detect(?string $text, ?float $amount = null): string
    {
        $key = mb_strtolower(trim((string) $text));
        $key = str_replace(['-', '_', '/'], ' ', $key);

        if ($key !== '') {
            foreach (self::PATTERNS as [$kind, $needles]) {
                foreach ($needles as $needle) {
                    // Kurzkuerzel ("ap", "bp") nur als ganzes Wort - sonst
                    // trifft "ap" in "Kapitalanlage".
                    $hit = mb_strlen($needle) <= 3
                        ? preg_match('/(^|\s)'.preg_quote($needle, '/').'(\s|$)/u', $key) === 1
                        : str_contains($key, $needle);
                    if ($hit) {
                        return $kind;
                    }
                }
            }
        }

        if ($amount !== null && $amount < 0) {
            return self::STORNO;
        }
        return self::SONSTIGE;
    }

    public static function label(?string $kind): string
    {
        return self::ALL[$kind] ?? self::ALL[self::SONSTIGE];
    }

    /** Zaehlt diese Art als Gegenbuchung (mindert also den Ertrag)? */
    public static function isNegative(?string $kind): bool
    {
        return in_array($kind, [self::STORNO, self::KORREKTUR], true);
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }
}
