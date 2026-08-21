<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\RepairsOcrText;
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
 * Block. Der Parser greift nur, wenn mehrere starke Signale zusammenkommen:
 * ZWEI von {E-Mail, IBAN, PLZ+Ort, Telefonnummer} in einem KURZEN Text, davon
 * mindestens eines persoenlich (E-Mail oder IBAN), und kein Wort, das ein
 * echtes Dokument verraet. Weil solche Bloecke
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
    use RepairsOcrText;
    use ValidatesExtractedFields;

    /** Laenger als das ist es kein kompakter Kontaktblock mehr. */
    private const MAX_CHARS = 600;
    private const MAX_LINES = 14;

    /**
     * Woerter, die ein ECHTES Dokument verraten (Brief, Rechnung, Police).
     * Ein Briefkopf traegt ebenfalls Anschrift und Telefonnummer - er darf
     * aber nie als Kontaktzettel des Kunden gelesen werden.
     */
    private const DOKUMENT_WOERTER = [
        'rechnung', 'versicherungsschein', 'police', 'antrag', 'vertrag',
        'beitrag', 'mahnung', 'angebot', 'bescheinigung', 'kündigung',
        'kuendigung', 'sehr geehrte', 'seite 1', 'ust-id', 'steuernummer',
    ];

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

        // Ein echtes Dokument (Brief/Rechnung/Police) ist kein Kontaktzettel -
        // auch wenn Anschrift und Telefonnummer darin stehen.
        $klein = mb_strtolower($joined);
        foreach (self::DOKUMENT_WOERTER as $wort) {
            if (str_contains($klein, $wort)) {
                return null;
            }
        }

        $email = $this->ocrEmail($joined);
        $iban = $this->ocrGermanIban($joined);
        $zipCity = $this->firstZipCity($lines);
        $phone = $this->firstPhone($lines);

        // Starke Signale muessen zusammenkommen (deliberater Kontaktblock).
        // Frueher waren E-Mail UND IBAN UND PLZ+Ort Pflicht - EIN von der OCR
        // verlesenes Zeichen (heller Hintergrund, andere Schrift, farbiger
        // Link) liess damit den ganzen Block durchfallen, und derselbe Zettel
        // wurde einmal erkannt und einmal nicht. Jetzt genuegen ZWEI Signale,
        // davon mindestens eines persoenlich (E-Mail oder IBAN): ein blosser
        // Briefkopf (nur Anschrift + Telefon) loest weiterhin nicht aus.
        $persoenlich = ($email !== null ? 1 : 0) + ($iban !== null ? 1 : 0);
        $signale = $persoenlich + ($zipCity !== null ? 1 : 0) + ($phone !== null ? 1 : 0);
        if ($persoenlich < 1 || $signale < 2) {
            return null;
        }

        $person = $this->parsePerson($lines, $email, $zipCity, $phone);
        if (($person['first_name'] ?? null) === null && ($person['last_name'] ?? null) === null) {
            return null; // ohne Namen der normalen Analyse ueberlassen
        }

        // Zweites Datum NEBEN dem Geburtsdatum ("04.08.75/05.09.17"): der
        // Betrieb notiert dort oft das Datum der Bescheinigung/des Aufenthalts-
        // titels. Das Geburtsdatum bleibt das ERSTE Datum; das zweite geht nicht
        // verloren, sondern wird sichtbar in der Zusammenfassung genannt.
        $secondDate = $this->secondDateBesideBirth($joined);

        // Vollstaendiger Block (E-Mail + IBAN + PLZ/Ort) bleibt bei 70; ein
        // Block, bei dem ein Signal fehlt oder unlesbar war, wird ehrlich
        // niedriger bewertet - der Mitarbeiter sieht im Review, dass er
        // genauer hinschauen soll.
        $vollstaendig = $email !== null && $iban !== null && $zipCity !== null;

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'kontaktdaten',
            'confidence' => $vollstaendig ? 70 : 58,
            'summary' => 'Kontaktdaten' . ($name !== '' ? ' - ' . $name : '')
                . (isset($person['birth_date']) ? ' - geb. ' . $this->displayDate($person['birth_date']) : '')
                . ($secondDate !== null ? ' - weiteres Datum ' . $secondDate . ' (z.B. Aufenthaltstitel/Bescheinigung)' : '')
                . ' (' . implode(', ', $this->gelesenFelder($person, $iban)) . ' gratis gelesen).'
                . ($iban === null ? ' Keine gueltige IBAN erkannt - bitte pruefen.' : ''),
            'title' => 'Kontaktdaten' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'bank' => $iban === null ? [] : $this->validatedBank(['iban' => $iban]),
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
     * @param array{0:int,1:string,2:string}|null $zipCity [Zeilenindex, PLZ, Ort]
     * @return array<string,mixed>
     */
    private function parsePerson(array $lines, ?string $email, ?array $zipCity, ?string $phone): array
    {
        $raw = [
            'email' => $email,
            'zip' => $zipCity[1] ?? null,
            'city' => $zipCity[2] ?? null,
            'phone' => $phone,
        ];

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
            if ($i === $nameIdx || str_contains($line, '@') || $this->ocrGermanIban($line) !== null) {
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
                && mb_strtolower(trim($m[1])) !== mb_strtolower((string) ($zipCity[2] ?? ''))) {
                $raw['street'] = trim($m[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
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
        // Datumsangaben am Ende abschneiden - auch MEHRERE nebeneinander
        // ("08.07.92 & 12.11.24": Geburtsdatum + z.B. Fuehrerschein-/
        // Bescheinigungsdatum), mit "/", "-", "&" oder Leerzeichen getrennt.
        $line = trim((string) preg_replace(
            '/\s+\d{2}\.\d{2}\.(?:\d{4}|\d{2})(?:\s*[\/\-&]?\s*\d{2}\.\d{2}\.(?:\d{4}|\d{2}))*\s*$/u',
            '',
            $line
        ));

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

    /**
     * Ein ZWEITES Datum, das direkt neben dem Geburtsdatum steht - getrennt
     * durch "/", "-", "&" oder nur Leerzeichen (z.B. "04.08.75/05.09.17" oder
     * "00.00.00 & 00.00.00"). Der Betrieb schreibt dort oft das Datum der
     * Bescheinigung / des Aufenthaltstitels. Rueckgabe im Anzeigeformat
     * (TT.MM.JJJJ) oder null. Das Geburtsdatum (erstes Datum) bleibt unberuehrt.
     */
    private function secondDateBesideBirth(string $text): ?string
    {
        if (!preg_match('#\b\d{2}\.\d{2}\.(?:\d{4}|\d{2})\s*[/\-&]\s*(\d{2})\.(\d{2})\.(\d{4}|\d{2})\b#u', $text, $m)) {
            return null;
        }
        $yy = $m[3];
        $year = mb_strlen($yy) === 2
            ? ((int) $yy <= 30 ? 2000 + (int) $yy : 1900 + (int) $yy)
            : (int) $yy;
        return sprintf('%02d.%02d.%04d', (int) $m[1], (int) $m[2], $year);
    }

    /** ISO-Datum ("1975-08-04") -> Anzeige "04.08.1975". */
    private function displayDate(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $iso;
    }

    /**
     * Telefon/Handy: erstes Token, das wie eine deutsche Nummer aussieht - als
     * 0-Nummer ODER im internationalen Format ("+4915226593331" ->
     * 015226593331). Auch in einer Zeile mit Ort/PLZ, und auch in Gruppen
     * geschrieben ("0179 698 6119"): steht eine Zeile ausschliesslich aus
     * Ziffern und Leerzeichen, werden diese zusammengezogen.
     *
     * @param list<string> $lines
     */
    private function firstPhone(array $lines): ?string
    {
        $kandidaten = [];
        foreach ($lines as $line) {
            // Reine Ziffern-/Trennzeichen-Zeile: als EINE Nummer lesen.
            if (preg_match('/^[\d +\/()\-]{9,25}$/u', trim($line))) {
                $kandidaten[] = trim($line);
            }
            foreach (preg_split('/\s+/', $line) ?: [] as $token) {
                $kandidaten[] = $token;
            }
        }

        foreach ($kandidaten as $kandidat) {
            $digits = (string) preg_replace('/[\s\/()\-]/u', '', $kandidat);
            // +49/0049 in fuehrende 0 normalisieren.
            $digits = (string) preg_replace('/^(?:\+|00)49/', '0', $digits);
            $digits = str_replace('+', '', $digits);
            if (preg_match('/^0\d{9,14}$/', $digits)) {
                return $digits;
            }
        }

        return null;
    }

    /**
     * Nennt in der Zusammenfassung nur die Felder, die WIRKLICH gelesen
     * wurden - frueher stand dort pauschal "Name, Anschrift, Telefon, E-Mail,
     * IBAN", auch wenn die Haelfte fehlte.
     *
     * @param array<string,mixed> $person
     * @return list<string>
     */
    private function gelesenFelder(array $person, ?string $iban): array
    {
        $felder = ['Name'];
        if (($person['street'] ?? null) !== null || ($person['zip'] ?? null) !== null) {
            $felder[] = 'Anschrift';
        }
        if (($person['phone'] ?? null) !== null) {
            $felder[] = 'Telefon';
        }
        if (($person['email'] ?? null) !== null) {
            $felder[] = 'E-Mail';
        }
        if ($iban !== null) {
            $felder[] = 'IBAN';
        }

        return $felder;
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
