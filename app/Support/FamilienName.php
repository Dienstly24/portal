<?php

namespace App\Support;

/**
 * Familienname einer Person und sein transkriptionstolerantes Skelett.
 *
 * Arabische Familiennamen kommen in vielen Schreibweisen an: "Al Hamoud",
 * "Al-Hamoud", "Alhamoud", "El Hamoud". Verglichen wird deshalb ein
 * SKELETT: klein, Umlaute umgeschrieben, Artikel vorn weg, erster
 * Buchstabe + Konsonanten ("Najm" = "Al-Najm" = "Najim"). Dieselbe Regel
 * wie bei der Meldebestaetigung (`DocumentIntakeService`), hier an EINER
 * Stelle, damit beide Wege gleich vergleichen.
 */
final class FamilienName
{
    /** Letztes Namenswort ohne vorangestellten Artikel ("Al Hamoud" -> "hamoud"). */
    public static function kern(?string $vollerName): string
    {
        $woerter = preg_split('/\s+/u', trim((string) $vollerName)) ?: [];
        $letztes = (string) end($woerter);
        $n = mb_strtolower($letztes);
        $n = strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e']);
        // "Al-Hamoud" als EIN Wort / "Alhamoud": Artikel nur abtrennen, wenn
        // danach noch ein richtiger Name bleibt (sonst wird "Ali" zu "i").
        // Beide Seiten eines Vergleichs laufen durch dieselbe Regel.
        if (preg_match('/^(al|el)\-?([a-z]{4,})$/u', (string) preg_replace('/[^a-z\-]/u', '', $n), $m)) {
            $n = $m[2];
        }

        return (string) preg_replace('/[^a-z]/u', '', $n);
    }

    /** Skelett zum Vergleich; leer, wenn kein verwertbarer Name. */
    public static function skelett(?string $vollerName): string
    {
        $kern = self::kern($vollerName);
        if (mb_strlen($kern) < 3) {
            return '';
        }

        return mb_substr($kern, 0, 1).preg_replace('/[aeiouy]/', '', mb_substr($kern, 1));
    }

    public static function gleich(?string $a, ?string $b): bool
    {
        $sa = self::skelett($a);

        return $sa !== '' && $sa === self::skelett($b);
    }
}
