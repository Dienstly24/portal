<?php

namespace App\Services\Pdf;

/**
 * Die WENIGEN Regeln der PDF-Syntax, die dieses Projekt braucht - an EINER
 * Stelle, damit Leser (PdfDocument) und Schreiber (PdfStamper) dieselbe
 * Auffassung davon haben, wo ein Wert endet.
 *
 * Bewusst KEIN vollstaendiger PDF-Parser und kein Fremdpaket: gebraucht wird
 * nur, was fuer "Seite finden, Groesse lesen, Inhalt ergaenzen" noetig ist.
 * Ein Tabellen-/PDF-Framework im Sicherheitsupdate-Pfad einer Anwendung mit
 * Kundendaten waere fuer diesen Zweck ein schlechter Tausch (dieselbe
 * Abwaegung wie beim XLSX-Leser des Provisions-Imports).
 *
 * WICHTIG bei jeder Suche: eine Zeichenkette "( ... )" darf NIE mitgelesen
 * werden. In ihr steht regelmaessig ">>" oder "]" als reiner Text - wer
 * Klammern zaehlt, ohne Zeichenketten zu ueberspringen, bricht an genau den
 * Dokumenten ab, die Freitext enthalten.
 */
final class PdfSyntax
{
    /** Zeichen, die in PDF ein Token beenden. */
    public const DELIMITERS = "()<>[]{}/%";

    public const WHITESPACE = "\x00\t\n\x0C\r ";

    /** Steht an $i ein Leerzeichen/Zeilenumbruch? */
    public static function isWhitespace(string $s, int $i): bool
    {
        return $i < strlen($s) && str_contains(self::WHITESPACE, $s[$i]);
    }

    /** Naechste Position, an der kein Leerraum und kein Kommentar mehr steht. */
    public static function skipWhitespace(string $s, int $i): int
    {
        $len = strlen($s);
        while ($i < $len) {
            $c = $s[$i];
            if (str_contains(self::WHITESPACE, $c)) {
                $i++;

                continue;
            }
            if ($c === '%') { // Kommentar bis Zeilenende
                while ($i < $len && $s[$i] !== "\n" && $s[$i] !== "\r") {
                    $i++;
                }

                continue;
            }
            break;
        }

        return $i;
    }

    /**
     * Ende einer literalen Zeichenkette "( ... )", beginnend AUF der
     * oeffnenden Klammer. Klammern duerfen verschachtelt und mit "\"
     * maskiert sein.
     *
     * @return int Position NACH der schliessenden Klammer
     */
    public static function endOfString(string $s, int $i): int
    {
        $len = strlen($s);
        $depth = 0;
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '\\') {
                $i += 2;

                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
            $i++;
        }

        return $len;
    }

    /**
     * Ende einer geklammerten Struktur - "<<...>>" oder "[...]" -, beginnend
     * AUF dem oeffnenden Zeichen.
     *
     * @return int Position NACH dem schliessenden Zeichen
     */
    public static function endOfComposite(string $s, int $i): int
    {
        $len = strlen($s);
        $isDict = substr($s, $i, 2) === '<<';
        $depth = 0;
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '(') {
                $i = self::endOfString($s, $i);

                continue;
            }
            if ($c === '<' && ($s[$i + 1] ?? '') !== '<') {
                // Hex-Zeichenkette <ABCD>
                $end = strpos($s, '>', $i);
                $i = $end === false ? $len : $end + 1;

                continue;
            }
            if ($isDict) {
                if (substr($s, $i, 2) === '<<') {
                    $depth++;
                    $i += 2;

                    continue;
                }
                if (substr($s, $i, 2) === '>>') {
                    $depth--;
                    $i += 2;
                    if ($depth === 0) {
                        return $i;
                    }

                    continue;
                }
            } else {
                if ($c === '[') {
                    $depth++;
                } elseif ($c === ']') {
                    $depth--;
                    if ($depth === 0) {
                        return $i + 1;
                    }
                }
            }
            $i++;
        }

        return $len;
    }

    /**
     * Liest EIN Objekt (Wert) ab Position $i.
     *
     * @return array{0: string, 1: int} Rohtext des Wertes und Position dahinter
     */
    public static function readValue(string $s, int $i): array
    {
        $i = self::skipWhitespace($s, $i);
        $len = strlen($s);
        if ($i >= $len) {
            return ['', $i];
        }
        $c = $s[$i];

        if ($c === '(') {
            $end = self::endOfString($s, $i);

            return [substr($s, $i, $end - $i), $end];
        }
        if (substr($s, $i, 2) === '<<' || $c === '[') {
            $end = self::endOfComposite($s, $i);

            return [substr($s, $i, $end - $i), $end];
        }
        if ($c === '<') {
            $end = strpos($s, '>', $i);
            $end = $end === false ? $len : $end + 1;

            return [substr($s, $i, $end - $i), $end];
        }

        // Name (/Foo), Zahl, Schluesselwort. Referenzen "12 0 R" gehoeren
        // zusammen - wer nur die erste Zahl liest, verliert das Ziel.
        $start = $i;
        if ($c === '/') {
            $i++;
        }
        while ($i < $len && ! str_contains(self::WHITESPACE, $s[$i]) && ! str_contains(self::DELIMITERS, $s[$i])) {
            $i++;
        }
        $token = substr($s, $start, $i - $start);

        if (preg_match('/^\d+$/', $token)) {
            $rest = substr($s, $i, 40);
            if (preg_match('/^[\x00\t\n\x0C\r ]+(\d+)[\x00\t\n\x0C\r ]+R(?![a-zA-Z])/', $rest, $m, PREG_OFFSET_CAPTURE)) {
                $consumed = strlen($m[0][0]);

                return [$token.' '.$m[1][0].' R', $i + $consumed];
            }
        }

        return [$token, $i];
    }

    /**
     * Zerlegt einen Woerterbuch-Rohtext "<< /A 1 /B 2 >>" in Schluessel =>
     * Rohwert. Verschachtelte Woerterbuecher bleiben als Rohtext stehen.
     *
     * @return array<string, string>
     */
    public static function dictEntries(string $dict): array
    {
        $i = strpos($dict, '<<');
        if ($i === false) {
            return [];
        }
        $i += 2;
        $end = strlen($dict);
        $out = [];
        while ($i < $end) {
            $i = self::skipWhitespace($dict, $i);
            if ($i >= $end || substr($dict, $i, 2) === '>>') {
                break;
            }
            if ($dict[$i] !== '/') { // defekt - lieber abbrechen als raten
                break;
            }
            [$key, $i] = self::readValue($dict, $i);
            [$value, $i] = self::readValue($dict, $i);
            $out[substr($key, 1)] = $value;
        }

        return $out;
    }

    /** Ist der Wert eine indirekte Referenz "12 0 R"? */
    public static function isReference(string $value): bool
    {
        return (bool) preg_match('/^\d+\s+\d+\s+R$/', trim($value));
    }

    /** Objektnummer einer Referenz, sonst null. */
    public static function referenceNumber(string $value): ?int
    {
        return preg_match('/^(\d+)\s+\d+\s+R$/', trim($value), $m) ? (int) $m[1] : null;
    }

    /** Zahlen aus einem Array-Rohtext "[0 0 595 842]". */
    public static function numbers(string $array): array
    {
        preg_match_all('/-?\d+(?:\.\d+)?/', $array, $m);

        return array_map('floatval', $m[0]);
    }

    /**
     * Eine Zeichenkette fuer die PDF-Ausgabe maskieren (literale Form).
     * Der Text wird zuvor nach WinAnsi (CP1252) umgesetzt - die
     * Standardschrift Helvetica kennt kein UTF-8, ohne Umsetzung stuende
     * statt "Müller" ein Kauderwelsch im fertigen Dokument.
     */
    public static function escapeString(string $text): string
    {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        return '('.str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $converted).')';
    }

    /** Zahl in der knappen, gebietsschema-unabhaengigen PDF-Schreibweise. */
    public static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Position des WERTES zu /Key innerhalb eines Woerterbuch-Rohtextes -
     * nur auf der obersten Ebene. Ein gleichnamiger Schluessel in einem
     * verschachtelten Woerterbuch darf nie getroffen werden.
     *
     * @return array{0: int, 1: int}|null Anfang und Ende des Wertes
     */
    public static function entryRange(string $dict, string $key): ?array
    {
        $i = strpos($dict, '<<');
        if ($i === false) {
            return null;
        }
        $i += 2;
        $end = strlen($dict);
        while ($i < $end) {
            $i = self::skipWhitespace($dict, $i);
            if ($i >= $end || substr($dict, $i, 2) === '>>' || $dict[$i] !== '/') {
                return null;
            }
            [$name, $i] = self::readValue($dict, $i);
            $valueStart = self::skipWhitespace($dict, $i);
            [, $i] = self::readValue($dict, $i);
            if (substr($name, 1) === $key) {
                return [$valueStart, $i];
            }
        }

        return null;
    }

    /** Setzt /Key auf einen neuen Wert; fehlt der Schluessel, wird er ergaenzt. */
    public static function replaceEntry(string $dict, string $key, string $value): string
    {
        $range = self::entryRange($dict, $key);
        if ($range === null) {
            return self::insertEntries($dict, '/'.$key.' '.$value);
        }

        return substr($dict, 0, $range[0]).$value.substr($dict, $range[1]);
    }

    /** Schreibt Eintraege unmittelbar hinter das oeffnende "<<". */
    public static function insertEntries(string $dict, string $entries): string
    {
        $i = strpos($dict, '<<');
        if ($i === false) {
            return $dict;
        }

        return substr($dict, 0, $i + 2)."\n".$entries."\n".substr($dict, $i + 2);
    }

    /** Haengt Elemente an ein Array "[ ... ]" an. */
    public static function appendToArray(string $array, string $items): string
    {
        $close = strrpos($array, ']');
        if ($close === false) {
            return $array;
        }

        return substr($array, 0, $close).' '.$items.' '.substr($array, $close);
    }
}
