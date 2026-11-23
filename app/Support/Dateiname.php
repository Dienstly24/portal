<?php

namespace App\Support;

/**
 * Dateinamen aus E-Mail-Anhaengen lesbar machen (KI-127).
 *
 * Mailprogramme kodieren Namen mit Nicht-ASCII-Zeichen nach RFC 2047
 * ("=?UTF-8?B?Q2FtU2Nhbm5lci...?="). Ohne Dekodierung stand genau diese
 * Zeichenkette als Dokumentname in der Kundenakte - unlesbar, und fuer die
 * Doppelt-Erkennung nach Namen wertlos.
 */
final class Dateiname
{
    public static function dekodiere(?string $name): string
    {
        $name = (string) $name;
        if (! str_contains($name, '=?')) {
            return $name;
        }
        try {
            $dekodiert = mb_decode_mimeheader($name);
        } catch (\Throwable $e) {
            return $name;
        }
        // Unsichtbare Richtungszeichen (z.B. U+200F aus arabischen Datumsangaben
        // in Scanner-Apps) entfernen - sie verwirren Anzeige und Vergleich.
        $dekodiert = (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $dekodiert);

        return trim($dekodiert) !== '' && mb_check_encoding($dekodiert, 'UTF-8') ? trim($dekodiert) : $name;
    }
}
