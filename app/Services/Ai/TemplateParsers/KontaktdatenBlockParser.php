<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer einen kompakten KONTAKTDATEN-Block, wie ihn Makler oft als
 * Foto/Screenshot (z.B. aus einer Chat-Nachricht) hochladen:
 *
 *   Herr Ibrahim Al-Ali Al-Sharaa
 *   01.01.88 Falkenweg 40 71634
 *   Ludwigsburg 015560360109
 *   alalialsharaa.ibrahim@gmail.com
 *   DE44 1001 0010 0461 1063 8
 *
 * Anders als bei laufendem Freitext ist das ein klar strukturierter, kurzer
 * Block. Der Parser greift nur, wenn mehrere starke Signale zusammenkommen
 * (E-Mail UND IBAN UND eine PLZ+Ort in einem KURZEN Text). Weil solche Bloecke
 * (per OCR) oft "verrutschen" - mehrere Felder in einer Zeile, PLZ am Zeilenende
 * und Ort in der naechsten - liest der Parser die Felder robust heraus statt
 * strikt "ein Feld je Zeile" zu erwarten:
 *  - Anrede (Herr/Frau) -> Geschlecht, NICHT als Vorname;
 *  - Namen mit Bindestrich ("Al-Ali") und aus mehreren Teilen ("Ibrahim Al-Ali
 *    Al-Sharaa" -> Vorname Ibrahim, Nachname "Al-Ali Al-Sharaa");
 *  - Geburtsdatum auch mit 2-stelligem Jahr ("01.01.88").
 * Die endgueltige Anlage bestaetigt ohnehin ein Mitarbeiter im Review.
 */
class KontaktdatenBlockParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** Laenger als das ist es kein kompakter Kontaktblock mehr. */
    private const MAX_CHARS = 600;
    private const MAX_LINES = 14;

    public function parse(string $text): ?array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $text) ?: []),
            fn ($l) => $l !== ''
        ));

        // Nur kurze, kompakte Bloecke.
        if ($lines === [] || count($lines) > self::MAX_LINES || mb_strlen(implode("\n", $lines)) > self::MAX_CHARS) {
            return null;
        }

        $joined = implode("\n", $lines);
        $email = $this->firstEmail($joined);
        $iban = $this->firstIban($joined);
        $zipCity = $this->firstZipCity($lines);

        // Starke Signale muessen zusammenkommen (deliberater Kontaktblock).
        if ($email === null || $iban === null || $zipCity === null) {
            return null;
        }

        $person = $this->parsePerson($lines, $email, $zipCity);
        if (($person['first_name'] ?? null) === null && ($person['last_name'] ?? null) === null) {
            return null; // ohne Namen der normalen Analyse ueberlassen
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'kontaktdaten',
            'confidence' => 70,
            'summary' => 'Kontaktdaten' . ($name !== '' ? ' - ' . $name : '')
                . ' (Name, Anschrift, Telefon, E-Mail, IBAN gratis gelesen).',
            'title' => 'Kontaktdaten' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'bank' => $this->validatedBank(['iban' => $iban]),
                'versicherung' => [],
                'gesundheit' => [],
                'kfz' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * @param list<string> $lines
     * @param array{0:int,1:string,2:string} $zipCity [Zeilenindex, PLZ, Ort]
     * @return array<string,mixed>
     */
    private function parsePerson(array $lines, string $email, array $zipCity): array
    {
        $raw = ['email' => $email, 'zip' => $zipCity[1], 'city' => $zipCity[2]];

        // Name: erste Zeile, die (ohne Anrede, ohne Datum) aus 2-5 Grosswoertern
        // besteht. Anrede -> Geschlecht (NICHT als Vorname).
        $nameIdx = -1;
        foreach ($lines as $i => $line) {
            [$first, $last, $gender] = $this->parseNameLine($line);
            if ($first !== null || $last !== null) {
                $raw['first_name'] = $first;
                $raw['last_name'] = $last;
                if ($gender !== null) {
                    $raw['gender'] = $gender;
                }
                $nameIdx = $i;
                break;
            }
        }

        // Geburtsdatum: erstes Datum im Block (auch 2-stelliges Jahr).
        $raw['birth_date'] = $this->firstBirthDate(implode("\n", $lines));

        // Strasse + Hausnummer: Kandidatenzeilen von Datum/PLZ bereinigen (im
        // Block stehen Datum, Strasse und PLZ oft in EINER Zeile), dann
        // "<Strasse> <Hausnr>" lesen. Name-/E-Mail-Zeilen auslassen.
        foreach ($lines as $i => $line) {
            if ($i === $nameIdx || str_contains($line, '@') || $this->firstIban($line) !== null) {
                continue;
            }
            $clean = (string) preg_replace('/\d{2}\.\d{2}\.(?:\d{4}|\d{2})/u', ' ', $line); // Datum entfernen
            $clean = (string) preg_replace('/(?<!\d)\d{5}(?!\d)/u', ' ', $clean);           // PLZ entfernen
            $clean = trim((string) preg_replace('/[&]+/', ' ', $clean));
            $clean = trim((string) preg_replace('/\s+/', ' ', $clean));
            if ($clean === '') {
                continue;
            }
            if (preg_match('/([A-ZÄÖÜ][\p{L}.\-]+(?:\s+[A-ZÄÖÜ][\p{L}.\-]+)?)\s+(\d{1,4}\s*[a-zA-Z]?)\b/u', $clean, $m)
                && preg_match('/\p{L}{3,}/u', $m[1])
                && mb_strtolower(trim($m[1])) !== mb_strtolower($zipCity[2])) {
                $raw['street'] = trim($m[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
                break;
            }
        }

        // Telefon/Handy: erstes Token, das (ohne Trennzeichen) wie eine deutsche
        // 0-Nummer aussieht - auch wenn es in einer Zeile mit Ort/PLZ steht.
        foreach (preg_split('/\s+/', implode(' ', $lines)) ?: [] as $token) {
            $digits = preg_replace('/[\/()+\-]/', '', $token);
            if (preg_match('/^0\d{9,14}$/', (string) $digits)) {
                $raw['phone'] = $digits;
                break;
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Zerlegt eine mutmassliche Namenszeile in [Vorname, Nachname, Geschlecht].
     * Anrede (Herr/Frau) wird abgetrennt und liefert das Geschlecht; ein Datum
     * am Ende wird ignoriert. Nachname darf mehrteilig und mit Bindestrich sein.
     *
     * @return array{0:?string,1:?string,2:?string}
     */
    private function parseNameLine(string $line): array
    {
        $line = trim($line);
        $gender = null;
        if (preg_match('/^(Herrn?|Frau|Fr\.|Hr\.)\s+/u', $line, $m)) {
            $gender = stripos($m[1], 'Fr') === 0 ? 'female' : 'male';
            $line = trim(mb_substr($line, mb_strlen($m[0])));
        }
        // Datum am Ende (dd.mm.yyyy oder dd.mm.yy) abschneiden.
        $line = trim((string) preg_replace('/\s+\d{2}\.\d{2}\.(?:\d{4}|\d{2})\s*$/u', '', $line));

        // 2-5 Grosswoerter (Buchstaben + Bindestrich), nichts anderes.
        if (!preg_match('/^([A-ZÄÖÜ][\p{L}\-]+)((?:\s+[A-ZÄÖÜ][\p{L}\-]+){1,4})$/u', $line, $m)) {
            return [null, null, null];
        }
        $first = trim($m[1]);
        $last = trim($m[2]) !== '' ? trim($m[2]) : null;
        return [$first, $last, $gender];
    }

    /** Erstes Geburtsdatum im Text; 2-stelliges Jahr wird sinnvoll ergaenzt. */
    private function firstBirthDate(string $text): ?string
    {
        if (preg_match('/\b(\d{2})\.(\d{2})\.(\d{4})\b/', $text, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (preg_match('/\b(\d{2})\.(\d{2})\.(\d{2})\b/', $text, $m)) {
            $yy = (int) $m[3];
            // 00-30 -> 20xx, sonst 19xx (Geburtsjahr-Pivot).
            $year = $yy <= 30 ? 2000 + $yy : 1900 + $yy;
            return $year . '-' . $m[2] . '-' . $m[1];
        }
        return null;
    }

    private function firstEmail(string $text): ?string
    {
        return preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m)
            ? strtolower($m[0]) : null;
    }

    /**
     * Deutsche IBAN (DE + 20 Ziffern), auch mit unregelmaessigen Leerzeichen-
     * Gruppen aus dem OCR ("DE44 1001 0010 0461 1063 8"). Ziffernanzahl wird
     * grosszuegig zugelassen; die harte Validierung prueft danach das Format.
     */
    private function firstIban(string $text): ?string
    {
        if (preg_match('/\bDE\d{2}(?:[ ]?\d){12,26}\b/', $text, $m)) {
            return strtoupper((string) preg_replace('/\s+/', '', $m[0]));
        }
        return null;
    }

    /**
     * Erste "PLZ Ort"-Angabe. Robust gegen zwei Faelle:
     *  - PLZ und Ort in derselben Zeile (evtl. mit angehaengter Telefonnummer);
     *  - PLZ am Zeilenende, Ort am Anfang der naechsten Zeile.
     *
     * @param list<string> $lines
     * @return array{0:int,1:string,2:string}|null
     */
    private function firstZipCity(array $lines): ?array
    {
        foreach ($lines as $i => $line) {
            // PLZ + Ort (1-2 Grosswoerter) in derselben Zeile.
            if (preg_match('/(?<!\d)(\d{5})(?!\d)\s+([A-ZÄÖÜ][\p{L}\-.]+(?:\s+[A-ZÄÖÜ][\p{L}\-.]+)?)/u', $line, $m)) {
                return [$i, $m[1], trim($m[2])];
            }
            // PLZ am Zeilenende -> Ort am Anfang der naechsten Zeile.
            if (preg_match('/(?<!\d)(\d{5})(?!\d)\s*$/u', $line, $m)
                && isset($lines[$i + 1])
                && preg_match('/^([A-ZÄÖÜ][\p{L}\-.]+)/u', $lines[$i + 1], $c)) {
                return [$i, $m[1], trim($c[1])];
            }
        }
        return null;
    }
}
