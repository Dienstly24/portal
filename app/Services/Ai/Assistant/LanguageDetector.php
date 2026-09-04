<?php

namespace App\Services\Ai\Assistant;

/**
 * Sprache der Kundennachricht erkennen (Spezifikation Abschnitt 17:
 * "Die Sprache des Kunden soll automatisch erkannt werden").
 *
 * Bewusst schlank und deterministisch: arabische Schrift ist eindeutig am
 * Unicode-Block erkennbar; Deutsch und Englisch werden ueber haeufige
 * Funktionswoerter unterschieden. Erkennt die Heuristik nichts, gilt die im
 * Portal hinterlegte Sprache des Kunden und zuletzt Deutsch - nie eine
 * geratene dritte Sprache.
 */
class LanguageDetector
{
    /** Haeufige deutsche Funktionswoerter (kommen in fast jedem Satz vor). */
    private const GERMAN = [
        ' ich ', ' mein', ' meine', ' nicht', ' und ', ' ist ', ' der ', ' die ', ' das ',
        ' wie ', ' was ', ' kann ', ' bitte', ' haben', ' habe ', ' sie ', ' wir ', ' fuer ',
        ' für ', ' noch ', ' schon ', ' welche', ' wo ', ' wann ',
    ];

    /** Haeufige englische Funktionswoerter. */
    private const ENGLISH = [
        ' the ', ' my ', ' is ', ' are ', ' can ', ' please', ' what ', ' how ', ' i ',
        ' do ', ' does ', ' need ', ' have ', ' would ', ' your ', ' documents ', ' when ',
    ];

    public function detect(string $message, ?string $fallback = null): string
    {
        $text = trim($message);
        if ($text === '') {
            return $this->normalizeFallback($fallback);
        }

        // Arabische Schrift (inkl. arabisch-persischer Ziffern/Erweiterungen).
        if (preg_match('/\p{Arabic}/u', $text)) {
            return 'ar';
        }

        // Wortvergleich mit Randleerzeichen, damit " ist " nicht in
        // "Leistung" trifft.
        $padded = ' '.mb_strtolower($text).' ';
        $german = $this->count($padded, self::GERMAN);
        $english = $this->count($padded, self::ENGLISH);

        if ($german === 0 && $english === 0) {
            return $this->normalizeFallback($fallback);
        }

        return $english > $german ? 'en' : 'de';
    }

    private function count(string $text, array $needles): int
    {
        $hits = 0;
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                $hits++;
            }
        }

        return $hits;
    }

    /** Nur unterstuetzte Sprachen; alles andere wird Deutsch. */
    private function normalizeFallback(?string $fallback): string
    {
        return in_array($fallback, ['de', 'ar', 'en'], true) ? $fallback : 'de';
    }
}
