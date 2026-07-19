<?php
namespace App\Support;

/**
 * Kleine Hilfsklasse zur Unterscheidung deutscher Mobil- und Festnetznummern.
 * Ziel: verhindern, dass eine Mobilnummer im Feld "Telefon" (Festnetz) landet
 * oder umgekehrt. Bewusst tolerant - nur EINDEUTIGE deutsche Nummern werden
 * einem Typ zugeordnet; internationale/ungewoehnliche Formate bleiben erlaubt.
 */
class GermanPhone
{
    /** Auf Ziffern (und fuehrendes +) reduzieren, Laendervorwahl -> 0. */
    public static function normalize(?string $raw): string
    {
        $s = preg_replace('/[^\d+]/', '', (string) $raw) ?? '';
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/^\+49/', '0', $s);
        $s = preg_replace('/^0049/', '0', $s);
        // "49176..." (ohne +, mit Laendercode) ebenfalls normalisieren.
        if (preg_match('/^49\d{9,}$/', $s)) {
            $s = '0' . substr($s, 2);
        }
        return $s;
    }

    /** Eindeutige deutsche Mobilnummer (015x/016x/017x)? */
    public static function isMobile(?string $raw): bool
    {
        return (bool) preg_match('/^01[567]\d{5,}$/', self::normalize($raw));
    }

    /** Eindeutige deutsche Festnetznummer (Ortsvorwahl 02x-09x)? */
    public static function isLandline(?string $raw): bool
    {
        return (bool) preg_match('/^0[2-9]\d{3,}$/', self::normalize($raw));
    }
}
