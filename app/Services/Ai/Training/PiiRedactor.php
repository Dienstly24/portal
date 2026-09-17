<?php

namespace App\Services\Ai\Training;

/**
 * Schwaerzt personenbezogene Angaben aus einem Gespraechstext
 * (Auftrag Abschnitt 18).
 *
 * WOFUER: aus echten Gespraechen sollen Frage-Antwort-Paare fuer die
 * Wissensbasis entstehen. Ein solcher Eintrag ist fuer JEDEN Kunden
 * sichtbar, der danach fragt - es darf also kein einziger Name, keine
 * Adresse und keine Nummer eines anderen Menschen darin stehen. Eine
 * durchgerutschte IBAN waere kein Schoenheitsfehler, sondern ein
 * Datenschutzvorfall.
 *
 * DETERMINISTISCH, NIE MIT EINEM MODELL. Ein Modell zum Schwaerzen zu
 * benutzen hiesse, genau die Daten an genau den Dienst zu schicken, vor
 * dem geschuetzt werden soll - und man wuesste nie, ob es etwas
 * uebersehen hat. Hier laeuft alles ueber Muster und ueber die BEKANNTEN
 * Werte der Kundenakte.
 *
 * DER NAME IST DER SONDERFALL. Namen allgemein zu erkennen ist Raten:
 * "Mai" ist ein Monat und ein Nachname, "Frank" ein Vorname und ein
 * Adjektiv. Deshalb werden AUSSCHLIESSLICH die Namen geschwaerzt, die
 * in der Kundenakte oder im Export als Absender wirklich stehen - das
 * ist eine Tatsache, keine Vermutung. Wer zusaetzlich auf Verdacht
 * schwaerzt, macht aus einer Antwort unleserlichen Text.
 *
 * WAS NICHT SICHER ERKANNT WIRD, WIRD GEMELDET. `report.warnings`
 * nennt uebrige lange Zahlenfolgen, damit der Mensch bei der Freigabe
 * hinsieht - ein stilles "ist wohl nichts" waere die gefaehrlichste
 * Variante.
 */
class PiiRedactor
{
    public const NAME = '[NAME]';
    public const EMAIL = '[EMAIL]';
    public const PHONE = '[TELEFON]';
    public const IBAN = '[IBAN]';
    public const DATE = '[DATUM]';
    public const CUSTOMER_NO = '[KUNDENNUMMER]';
    public const CONTRACT_NO = '[VERTRAGSNUMMER]';
    public const PLATE = '[KENNZEICHEN]';
    public const VIN = '[FIN]';
    public const INSURANCE_NO = '[VERSICHERTENNUMMER]';
    public const URL = '[LINK]';

    /** Ab dieser Laenge gilt eine uebrige Zahlenfolge als verdaechtig. */
    private const WARN_DIGITS = 6;

    /**
     * @param  array<int,string>  $namen  bekannte Namen (Kundenakte, Absender im Export)
     * @return array{text:string, report:array{counts:array<string,int>, warnings:array<int,string>}}
     */
    public function redact(string $text, array $namen = []): array
    {
        $zaehler = [];
        $ergebnis = $text;

        $ersetze = function (string $muster, string $platzhalter) use (&$ergebnis, &$zaehler): void {
            $ergebnis = preg_replace_callback($muster, function () use ($platzhalter, &$zaehler) {
                $zaehler[$platzhalter] = ($zaehler[$platzhalter] ?? 0) + 1;

                return $platzhalter;
            }, $ergebnis) ?? $ergebnis;
        };

        /*
         * REIHENFOLGE IST PROGRAMMLOGIK, nicht Kosmetik - vom
         * spezifischsten zum allgemeinsten. Wer die Telefonnummer vor
         * der IBAN schwaerzt, zerlegt die IBAN in ihre Ziffernbloecke
         * und der Rest entgeht der Pruefung. Dieselbe Lehre wie bei der
         * Parser-Reihenfolge (13.09.2026).
         */

        // 1. IBAN - zuerst, weil sie Ziffern UND Buchstaben mischt.
        $ersetze('/\b[A-Z]{2}\s?\d{2}(?:\s?[A-Z0-9]{2,4}){2,8}\b/u', self::IBAN);

        // 2. Adressen und Links, bevor der Punkt in einer Domain als
        //    Datumstrenner missdeutet wird.
        $ersetze('/\bhttps?:\/\/\S+/iu', self::URL);
        $ersetze('/\b[\w.+-]+@[\w-]+\.[A-Za-z]{2,}\b/u', self::EMAIL);

        // 3. FIN: genau 17 Zeichen, ohne I/O/Q - eindeutig genug, um vor
        //    allen anderen Nummern zu stehen.
        $ersetze('/\b[A-HJ-NPR-Z0-9]{17}\b/u', self::VIN);

        // 4. Versichertennummer der Krankenkasse: 1 Buchstabe + 9 Ziffern.
        $ersetze('/\b[A-Z]\d{9}\b/u', self::INSURANCE_NO);

        // 5. Kennzeichen: 1-3 Buchstaben, Trenner, 1-2 Buchstaben, Ziffern.
        $ersetze('/\b[A-ZÄÖÜ]{1,3}[\s-]?[A-Z]{1,2}[\s-]?\d{1,4}[EH]?\b/u', self::PLATE);

        // 6. Kundennummer dieses Hauses: JJ + 5 Stellen, oder die
        //    Altform C-… (siehe CustomerNumberGenerator).
        $ersetze('/\b(?:2[0-9]\d{5}|C-\d{3,8})\b/u', self::CUSTOMER_NO);

        // 7. Datum - VOR der Telefonnummer: "17.09.2026" besteht sonst
        //    aus Ziffern und Trennern wie eine Rufnummer.
        $ersetze('/\b\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}\b/u', self::DATE);

        // 8. Telefonnummer: mindestens sieben Ziffern, Trenner erlaubt.
        $ersetze('/(?:\+|00)?\d(?:[\s\/-]?\d){6,17}\b/u', self::PHONE);

        // 9. Vertragsnummer: was jetzt noch als lange Kennung dasteht.
        $ersetze('/\b[A-Z]{1,4}[-\/]?\d{5,12}\b/u', self::CONTRACT_NO);

        // 10. Die BEKANNTEN Namen - zuletzt, damit sie nicht Teile von
        //     Kennungen zerschneiden. Laengste zuerst: sonst schwaerzt
        //     "Ali" das "Ali" in "Ali Hassan" und "Hassan" bleibt stehen.
        $sortiert = array_values(array_unique(array_filter(array_map('trim', $namen))));
        usort($sortiert, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($sortiert as $name) {
            // Einzelne Buchstaben und sehr kurze Namen werden NICHT
            // geschwaerzt - sie treffen zu viel gewoehnlichen Text.
            if (mb_strlen($name) < 3) {
                continue;
            }
            $ersetze('/\b'.preg_quote($name, '/').'\b/iu', self::NAME);
        }

        return [
            'text' => trim($ergebnis),
            'report' => [
                'counts' => $zaehler,
                'warnings' => $this->warnungen($ergebnis),
            ],
        ];
    }

    /**
     * Was nach dem Schwaerzen noch verdaechtig aussieht.
     *
     * Die Meldung ist bewusst grob und nennt den Fund im Klartext: sie
     * geht an einen MENSCHEN, der ueber die Freigabe entscheidet, und
     * nicht an einen Automatismus. Eine Warnung, die den Fund nicht
     * nennt, kann niemand pruefen.
     *
     * @return array<int,string>
     */
    private function warnungen(string $text): array
    {
        $warnungen = [];

        if (preg_match_all('/\b\d{'.self::WARN_DIGITS.',}\b/u', $text, $m)) {
            foreach (array_unique($m[0]) as $fund) {
                $warnungen[] = 'Zahlenfolge nicht zugeordnet: '.$fund;
            }
        }

        return array_slice($warnungen, 0, 10);
    }
}
