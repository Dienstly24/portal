<?php

namespace App\Services;

/**
 * Inhaltsbasierte Spam-Erkennung fuer oeffentliche Formulare
 * (Website-Lead-API, Hilfe-Formular, Leistungs-Anfragen).
 *
 * Hintergrund: Ueber das Kontaktformular der oeffentlichen Website trafen
 * Bot-Nachrichten ein (Gluecksspiel-Werbung "888starz apk download",
 * teils mit kaputtem Zeichensatz / Mojibake). Honeypot und Throttle allein
 * stoppen solche Bots nicht zuverlaessig. Diese Klasse bewertet den Inhalt
 * einer Anfrage anhand einfacher, bewusst konservativer Heuristiken und
 * meldet nur bei deutlichem Verdacht "Spam", damit echte Kundenanfragen
 * (Deutsch wie Arabisch) nicht faelschlich verworfen werden.
 *
 * Die aufrufenden Controller verwerfen erkannten Spam still (kein Ticket,
 * keine Mail) und tun so, als sei das Absenden erfolgreich - genau wie beim
 * Honeypot -, damit Bots keine Rueckmeldung zum Umgehen erhalten.
 */
class SpamFilter
{
    /**
     * Eindeutige Gluecksspiel-/Adult-/Pillen-Signalwoerter. Bei einem Makler
     * fuer Versicherungen und Energie kommen diese in echten Anfragen nicht
     * vor -> ein Treffer (3 Punkte) reicht bereits. Kleinschreibung;
     * Vergleich case-insensitive.
     */
    private const STRONG_KEYWORDS = [
        '888starz', '1xbet', 'melbet', 'mostbet', 'parimatch', 'pin-up',
        'casino', 'kasino', 'gambling', 'gluecksspiel', 'sportwetten',
        'betting bonus', 'wett-bonus', 'jackpot', 'roulette',
        'viagra', 'cialis', 'nolvadex', 'escort', 'porn', 'sex video',
    ];

    /**
     * Schwaechere Signalwoerter (SEO-/Crypto-/Werbe-Spam), die in seltenen
     * Faellen auch legitim auftauchen koennten -> nur 2 Punkte, brauchen ein
     * weiteres Signal (Link, zweites Wort) zum Ausloesen.
     */
    private const WEAK_KEYWORDS = [
        'binary option', 'payday loan', 'seo services', 'buy backlinks',
        'cheap followers', 'forex signals', 'crypto trading',
    ];

    /**
     * Schwellenwert der Punktesumme, ab dem eine Nachricht als Spam gilt.
     * Ein starkes Signalwort (3 Punkte) loest allein aus; schwache Signale
     * (je 2) muessen sich kombinieren.
     */
    private const THRESHOLD = 3;

    /**
     * Prueft die uebergebenen Textbestandteile (Name, Nachricht, Betreff ...)
     * und gibt einen kurzen Grund zurueck, falls die Anfrage als Spam gilt -
     * andernfalls null. Der Grund dient nur dem internen Log.
     *
     * @param array<int|string, string|null> $parts
     */
    public static function reason(array $parts): ?string
    {
        $text = trim(implode("\n", array_map(fn ($p) => (string) $p, $parts)));
        if ($text === '') {
            return null;
        }
        $lower = mb_strtolower($text);

        $score = 0;
        $hits = [];

        // 1) Signalwoerter: starke (3 Punkte) und schwache (2 Punkte).
        foreach (self::STRONG_KEYWORDS as $word) {
            if (str_contains($lower, $word)) {
                $score += 3;
                $hits[] = $word;
            }
        }
        foreach (self::WEAK_KEYWORDS as $word) {
            if (str_contains($lower, $word)) {
                $score += 2;
                $hits[] = $word;
            }
        }

        // 2) Viele Links deuten auf Werbe-Spam (2+ -> 2 Punkte, 4+ -> 4).
        $links = preg_match_all('#https?://|www\.#i', $text);
        if ($links >= 4) {
            $score += 4;
            $hits[] = $links . ' Links';
        } elseif ($links >= 2) {
            $score += 2;
            $hits[] = $links . ' Links';
        }

        // 3) Kaputter Zeichensatz / Mojibake (UTF-8 als Latin-1 fehlgedeutet,
        //    z. B. "Ù�Ù�Ø«Ù�"). Korrekt uebermittelte Anfragen - auch
        //    arabische - enthalten diese Sequenzen nicht. Erst ab vielen
        //    Vorkommen werten, um Einzelzeichen (z. B. "Ø" im Namen) zu
        //    verschonen.
        $mojibake = preg_match_all('/[ÃÂ][\x80-\xBF]|[ØÙ][\x80-\xBF]|â€|ï¿½|�/u', $text);
        if ($mojibake >= 15) {
            $score += 4;
            $hits[] = 'Mojibake(' . $mojibake . ')';
        }

        if ($score < self::THRESHOLD) {
            return null;
        }

        return 'score=' . $score . ' [' . implode(', ', $hits) . ']';
    }

    /**
     * Bequemer Boolean-Wrapper um reason().
     *
     * @param array<int|string, string|null> $parts
     */
    public static function isSpam(array $parts): bool
    {
        return self::reason($parts) !== null;
    }
}
