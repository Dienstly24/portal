<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\RepairsOcrText;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Foto/Scan einer BANKKARTE (girocard/Debitkarte von Sparkasse, Volksbank,
 * Postbank ...), Betreiber-Meldung 20.11.2026: die Karte wird zusammen mit
 * Ausweis und Gesundheitskarte hochgeladen, damit die IBAN in die Akte kommt.
 * Es gab dafuer keinen Vorlagen-Parser - die Karte landete jedes Mal in der
 * kostenpflichtigen KI-Analyse, obwohl sie nur aus Bankname, IBAN,
 * Karteninhaber und Ablaufdatum besteht.
 *
 * STRENG, damit nie ein echtes Dokument hier landet: KURZER Text, ein
 * Kartenmerkmal (girocard, Debit, Maestro, V PAY, Visa, Mastercard) UND eine
 * deutsche IBAN mit GUELTIGER Pruefziffer (Mod 97; typische OCR-Verwechslungen
 * werden ueber RepairsOcrText zurueckgesetzt, uebernommen wird nur eine
 * stimmige IBAN). Ohne gueltige IBAN greift der Parser nicht - dann hat die
 * Karte ihren Zweck nicht erfuellt und die normale Kette entscheidet.
 * Der Karteninhaber ist der KONTOINHABER; als Person zaehlt er nur, wenn er
 * sicher als Name gelesen wurde (2-4 Woerter, nur Buchstaben, kein Bankwort).
 */
class BankkarteParser implements DocumentTemplateParser
{
    use RepairsOcrText;
    use ValidatesExtractedFields;

    /** Laenger als das ist es keine Karte mehr, sondern ein Schreiben. */
    private const MAX_CHARS = 700;

    private const KARTEN_MERKMALE = '/\b(girocard|giro\s*card|debit(?:karte)?|maestro|v\s?pay|visa|mastercard|bankcard|sparkassen?card|banking\s*card)\b/iu';

    /**
     * Aufdruck der Kartenrueckseite/-vorderseite "IBAN ... Gueltig bis":
     * genau diese zwei Beschriftungen stehen auf jeder girocard. Am Foto der
     * Berliner-Sparkasse-Karte (20.11.2026) fehlte im OCR-Text jedes
     * Logo-Wort ("girocard"/"V PAY" sind Grafiken) - nur die Beschriftungen
     * kamen an.
     */
    private const KARTEN_BESCHRIFTUNG = '/\bIBAN\b.{0,40}\b(?:G(?:[üu]|ue)ltig\s+bis|valid\s+thru)\b/iu';

    /** Woerter, die ein Schreiben/Formular verraten - dann ist es keine Karte. */
    private const DOKUMENT_WOERTER = [
        'sehr geehrte', 'mandat', 'lastschrift', 'rechnung', 'antrag', 'vertrag',
        'versicherung', 'beitrag', 'kontoauszug', 'unterschrift', 'seite 1',
    ];

    /** Zeilen mit diesen Woertern sind Bank-/Kartenaufdruck, nie der Name. */
    private const KEIN_NAME = '/\b(sparkasse|volksbank|raiffeisen|bank|postbank|commerzbank|deutsche|comdirect|ing|dkb|targo|hypo|kreissparkasse|stadtsparkasse|girocard|debit|maestro|vpay|visa|mastercard|card|karte|gueltig|gültig|valid|thru|bis|iban|bic|kontonummer|blz|emv|international|electronic|berliner|hamburger)\b/iu';

    public function parse(string $text): ?array
    {
        if (mb_strlen($text) > self::MAX_CHARS
            || (! preg_match(self::KARTEN_MERKMALE, $text) && ! preg_match(self::KARTEN_BESCHRIFTUNG, $text))) {
            return null;
        }
        $lower = mb_strtolower($text);
        foreach (self::DOKUMENT_WOERTER as $wort) {
            if (str_contains($lower, $wort)) {
                return null;
            }
        }
        $iban = $this->ocrGermanIban($text);
        if ($iban === null) {
            return null;
        }

        $inhaber = $this->karteninhaber($text);
        $bankName = $this->bankName($text);
        $gueltig = preg_match('/\b(0[1-9]|1[0-2])\s*\/\s*(\d{2})\b/', $text, $m) ? $m[1].'/'.$m[2] : null;

        $person = [];
        if ($inhaber !== null) {
            $teile = explode(' ', $inhaber);
            $person = $this->validatedPerson([
                'last_name' => array_pop($teile),
                'first_name' => implode(' ', $teile),
            ]);
        }

        return [
            'type' => 'sepa_mandat',
            'confidence' => $inhaber !== null ? 72 : 60,
            'summary' => 'Bankkarte'.($bankName !== null ? ' '.$bankName : '')
                .($inhaber !== null ? ' - Karteninhaber '.$inhaber : '')
                .' - IBAN gelesen (Pruefziffer gueltig)'
                .($gueltig !== null ? ' - gueltig bis '.$gueltig : '')
                .' - Felder gratis von der Karte gelesen (ohne KI).'
                .($inhaber === null ? ' Karteninhaber nicht sicher gelesen - bitte pruefen.' : ''),
            'title' => 'Bankkarte'.($inhaber !== null ? ' '.$inhaber : ''),
            'data' => [
                'person' => $person,
                'bank' => $this->validatedBank(array_filter([
                    'iban' => $iban,
                    'account_holder' => $inhaber,
                ])),
                'versicherung' => [],
                'gesundheit' => [],
                'kfz' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Karteninhaber: eine Zeile aus 2-4 Woertern, nur Buchstaben (auch
     * Bindestrich/Apostroph), ohne Bank-/Kartenwort. Karten drucken in
     * Versalien -> Schreibweise normalisieren. Mehrere Kandidaten = unsicher
     * = kein Name (geraten wird nie).
     */
    private function karteninhaber(string $text): ?string
    {
        $kandidaten = [];
        $vorIban = null;
        $letzte = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $zeile) {
            $zeile = trim((string) preg_replace('/\s+/u', ' ', $zeile), " \t|.,");
            if ($zeile === '') {
                continue;
            }
            // Die Karte druckt den Inhaber DIREKT ueber die Beschriftung
            // "IBAN" - diese Stelle schlaegt jedes Rauschen weiter oben
            // (Berliner-Sparkasse-Foto: "NER TR" aus dem Kartenmotiv).
            if ($vorIban === null && preg_match('/^IBAN\b/iu', $zeile) && $letzte !== null) {
                $vorIban = $letzte;
            }
            $letzte = $this->namenskandidat($zeile);
            if ($letzte !== null) {
                $kandidaten[] = $letzte;
            }
        }
        $kandidaten = array_values(array_unique($kandidaten));
        $name = $vorIban ?? (count($kandidaten) === 1 ? $kandidaten[0] : null);

        return $name === null ? null : mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
    }

    /** Die Zeile als Name (2-4 Woerter, nur Buchstaben, kein Bankwort) - sonst null. */
    private function namenskandidat(string $zeile): ?string
    {
        if (preg_match(self::KEIN_NAME, $zeile)
            || ! preg_match('/^\p{Lu}[\p{L}\'-]+(?: \p{Lu}[\p{L}\'-]+){1,3}$/u', $zeile)
            // Jedes Wort mind. 2 Buchstaben (einzelne Buchstaben sind OCR-Rauschen).
            || preg_match('/(^| )\p{L}( |$)/u', $zeile)) {
            return null;
        }

        return $zeile;
    }

    private function bankName(string $text): ?string
    {
        if (preg_match('/\b((?:Berliner |Hamburger |Kreis|Stadt)?Sparkasse(?: [\p{Lu}][\p{L}-]+){0,2}|Volksbank(?: [\p{Lu}][\p{L}-]+){0,2}|Raiffeisenbank(?: [\p{Lu}][\p{L}-]+){0,2}|Postbank|Commerzbank|Deutsche Bank|Targobank|Comdirect|ING|DKB|HypoVereinsbank)\b/u', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
