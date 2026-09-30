<?php

namespace App\Support;

/**
 * Eine vollstaendige Anschrift, die auf EINER Zeile steht.
 *
 * Der wichtigste Fall ist die ABSENDERZEILE eines Geschaeftsbriefs: im
 * Fensterumschlag steht direkt ueber dem Empfaengerblock, klein und
 * einzeilig, der Absender mit voller Anschrift - "SHV KV OS Mitte Nord
 * Holstenkamp 7b 24105 Kiel". Auf einer Entgeltabrechnung ist das der
 * ARBEITGEBER, und oft die einzige Stelle, an der er vollstaendig steht:
 * viele Traeger und Einrichtungen fuehren gar keine Rechtsform im Namen,
 * eine Suche nach "GmbH/AG/KG" findet sie deshalb nie.
 *
 * ZWEI FAELLE, die man NICHT an der Zeile selbst unterscheiden kann:
 * "Preetzer Str. 207 · 24147 Kiel" ist eine reine Anschrift (der Name steht
 * eine Zeile darueber), "SHV KV OS Mitte Nord Holstenkamp 7b 24537 Neumuenster"
 * traegt den Namen mit. Beide beginnen mit Grossbuchstaben und enden mit
 * PLZ und Ort. Deshalb gibt es ZWEI Methoden, und welche gilt, entscheidet
 * der AUFRUFER aus dem Umfeld (steht darueber ein Name?) - nicht ein Raten
 * an der Zeichenkette.
 *
 * Anker ist immer die PLZ am Zeilenende. Laesst sich die Strasse nicht
 * sicher bestimmen, wird NULL geliefert: ein leeres Feld ist besser als ein
 * Firmenname, in dem ein Stueck Anschrift steckt - und umgekehrt (Lehre aus
 * der eAT-Rueckseite, "BRAUN OSTLANDSTRASSE").
 */
class Adresszeile
{
    /**
     * Endungen, an denen ein Wort als Strassenname erkennbar ist. EINE Liste
     * fuer alle Stellen, die eine Strasse aus Fliesstext loesen muessen.
     */
    public const STRASSEN_ENDUNGEN =
        'STRASSE|STRABE|STRA\x{00df}E|STR|WEG|ALLEE|PLATZ|RING|GASSE|DAMM|UFER|CHAUSSEE|STEIG|H\x{00d6}FE|HOF|GARTEN|BERG|KAMP|REDDER|TWIETE|STIEG|BROOK|KOPPEL';

    /**
     * Die Zeile ist NUR eine Anschrift ("Preetzer Str. 207 · 24147 Kiel").
     * Alles vor der Hausnummer ist dann die Strasse - mehrteilige Namen
     * ("Alte Kieler Landstrasse 141") bleiben dadurch vollstaendig.
     *
     * @return array{name:string,strasse:string,hausnummer:string,plz:string,ort:string,anschrift:string}|null
     */
    public static function anschrift(string $zeile): ?array
    {
        $teile = self::plzUndOrt($zeile);
        if ($teile === null) {
            return null;
        }
        [$plz, $ort, $vorn] = $teile;

        if (! preg_match('/^(?<strasse>\p{Lu}[\p{L}0-9 .\-]*?)[ ,]+(?<nr>\d{1,4} ?[a-zA-Z]?)$/u', $vorn, $m)) {
            return null;
        }
        return self::ergebnis('', trim($m['strasse']), trim($m['nr']), $plz, $ort);
    }

    /**
     * Die Zeile traegt NAME und Anschrift ("SHV KV OS Mitte Nord Holstenkamp
     * 59a 24105 Kiel"). Getrennt wird an der STRASSEN-ENDUNG - ein
     * Trennzeichen waere schoener, die gemessene Zeile hat keines.
     *
     * GRENZE, ehrlich benannt: bei einem MEHRTEILIGEN Strassennamen ohne
     * Trennzeichen ("… Alte Kieler Landstr. 141 …") bleibt das vordere Wort
     * beim Namen. Ohne Trennzeichen ist das nicht entscheidbar; der
     * Mitarbeiter sieht den Wert im Review.
     *
     * @return array{name:string,strasse:string,hausnummer:string,plz:string,ort:string,anschrift:string}|null
     */
    public static function mitName(string $zeile): ?array
    {
        $teile = self::plzUndOrt($zeile);
        if ($teile === null) {
            return null;
        }
        [$plz, $ort, $vorn] = $teile;

        // Trennzeichen schlaegt die Endungs-Regel, wo es eines gibt.
        if (preg_match('/^(?<name>.+?)\s*[·|]\s*(?<rest>.+)$/u', $vorn, $t)) {
            $adresse = self::anschrift(trim($t['rest']).' '.$plz.' '.$ort);
            if ($adresse !== null) {
                return self::ergebnis(trim($t['name']), $adresse['strasse'], $adresse['hausnummer'], $plz, $ort);
            }
        }

        if (! preg_match(
            '/(?<=[ ,;])(?<strasse>\p{L}*(?:'.self::STRASSEN_ENDUNGEN.')\.?)[ ,]+(?<nr>\d{1,4} ?[a-zA-Z]?)$/iu',
            $vorn,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }
        $name = trim(substr($vorn, 0, (int) $m[0][1]), " \t,;-·|");
        if ($name === '') {
            return null;   // nur eine Anschrift - dafuer gibt es anschrift()
        }

        return self::ergebnis($name, trim($m['strasse'][0]), trim($m['nr'][0]), $plz, $ort);
    }

    /**
     * PLZ + Ort am ZEILENENDE abtrennen.
     *
     * @return array{0:string,1:string,2:string}|null [PLZ, Ort, Text davor]
     */
    private static function plzUndOrt(string $zeile): ?array
    {
        $zeile = trim((string) preg_replace('/\h+/u', ' ', $zeile));
        if ($zeile === '') {
            return null;
        }
        if (! preg_match('/(?<![\d.\-])(\d{5}) (\p{Lu}[\p{L}.\-]*(?:[ \-]\p{L}[\p{L}.\-]*)*)$/u', $zeile, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        return [
            $m[1][0],
            $m[2][0],
            rtrim(substr($zeile, 0, (int) $m[0][1]), " \t,;-·|"),
        ];
    }

    /** @return array{name:string,strasse:string,hausnummer:string,plz:string,ort:string,anschrift:string} */
    private static function ergebnis(string $name, string $strasse, string $nummer, string $plz, string $ort): array
    {
        return [
            'name' => $name,
            'strasse' => $strasse,
            'hausnummer' => $nummer,
            'plz' => $plz,
            'ort' => $ort,
            // Schreibweise wie employer_address: "Strasse Nr, PLZ Ort".
            'anschrift' => trim($strasse.' '.$nummer).', '.$plz.' '.$ort,
        ];
    }
}
