<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die Meldebestaetigung/Meldebescheinigung eines deutschen
 * Buergerbueros/Einwohnermeldeamts. Diese Behoerden-Schreiben tragen die
 * bestaetigte Meldeadresse des Kunden in klar beschrifteten Feldern
 * (Familienname, Vorname, Geburtsdatum, Anschrift) - besonders wertvoll, weil
 * die aktuelle deutsche Wohnadresse amtlich bestaetigt ist.
 *
 * Zwei Schreibweisen sind verbreitet und werden beide gelesen:
 * "Familienname: Najm" (Doppelpunkt) und das Spaltenlayout ohne Doppelpunkt
 * ("Familienname        Najm", Stadt Backnang u.a.). Die Beschriftungen
 * duerfen einen Zusatz in Klammern tragen ("Vorname(n)").
 *
 * Zusaetzlich wird der UMZUG festgehalten: Wohnungsstatus, EINZUGSDATUM (ab
 * wann die neue Anschrift gilt) und Anmeldedatum stehen in der
 * Zusammenfassung, damit der Mitarbeiter die Adressaenderung mit Stichtag
 * uebernehmen kann.
 *
 * Bewusst NICHT uebernommen werden die Kontaktdaten der Behoerde selbst
 * (Sachbearbeitung, Telefon, E-Mail des Buergerbueros) und deren
 * Bankverbindung im Fussbereich - das ist NICHT die Bank des Kunden. Alle
 * Werte durchlaufen die harte Feldvalidierung; unsichere Felder bleiben leer
 * statt falsch.
 */
class MeldebestaetigungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        // Behoerden setzen die Ueberschrift gern GESPERRT ("M e l d e b e s t
        // ä t i g u n g"). Fuer die Typ-Erkennung daher auf einer Fassung ohne
        // jeden Zwischenraum suchen - sonst bleibt genau das Dokument
        // unerkannt, dessen Titel am deutlichsten dasteht.
        $compact = (string) preg_replace('/\s+/u', '', $upper);
        if (!str_contains($compact, 'MELDEBEST') && !str_contains($compact, 'MELDEBESCH')) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $raw = [];
        $raw['last_name'] = $this->labelValue('Familienname');
        // "Vorname" bevorzugt; sonst der gebraeuchliche Vorname.
        $raw['first_name'] = $this->labelValue('Vorname') ?? $this->labelValue('Gebräuchlicher Vorname');
        $birth = $this->labelValue('Geburtsdatum');
        if ($birth !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $birth, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Geschlecht aus der Anrede im Kopf ("Frau"/"Herr").
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*(Herrn|Herr|Frau)\s*$/u', trim($line), $g)) {
                $raw['gender'] = mb_strtolower($g[1]) === 'frau' ? 'female' : 'male';
                break;
            }
        }

        // Anschrift (Kunde, NICHT die "Hausanschrift" der Behoerde): Strasse +
        // Hausnummer in der Label-Zeile, PLZ + Ort in der Folgezeile.
        $this->fillAddress($raw);
        // Handyfoto-Fallback: liest die OCR das Feld "Anschrift" nicht (Label
        // verlesen, Spalte zerrissen), steht dieselbe NEUE Anschrift im
        // Anschriftfeld des Briefes - direkt unter dem Namen des Kunden.
        $this->fillAddressFromLetterWindow($raw);

        $person = $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));

        if (($person['last_name'] ?? null) === null && ($person['first_name'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $regDate = $this->labelValue('Anmeldedatum');
        $moveIn = $this->labelValue('Einzugsdatum');
        $status = $this->labelValue('Wohnungsstatus');
        // Minderjaehrig? Das Alter entscheidet die Vertragsanlage darueber, ob
        // das Kind mit dem Haushalt verknuepft wird - hier nur als Hinweis
        // fuer den Mitarbeiter.
        $minor = isset($person['birth_date']) && $this->isMinor($person['birth_date']);

        return [
            'type' => 'meldebescheinigung',
            'confidence' => 74,
            'summary' => 'Meldebestaetigung (Buergerbuero/Einwohnermeldeamt)'
                . ($name !== '' ? ' - ' . $name : '')
                . ($minor ? ' - MINDERJAEHRIG (Kind)' : '')
                . (isset($person['street'])
                    ? ' - neue Anschrift: ' . $person['street'] . ' ' . ($person['house_number'] ?? '')
                    : '')
                . (isset($person['zip']) ? ', ' . $person['zip'] . ' ' . ($person['city'] ?? '') : '')
                . ($moveIn !== null ? ' - eingezogen ' . $moveIn : '')
                . ($regDate !== null ? ' - angemeldet ' . $regDate : '')
                . ($status !== null ? ' - ' . $status : '')
                . ' - Felder gratis aus dem Schreiben gelesen (ohne KI).',
            'title' => 'Meldebestaetigung' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => [],
                'kfz' => [],
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** Ist die Person am Ausstellungstag noch keine 18? */
    private function isMinor(string $birthDate): bool
    {
        try {
            $birth = new \DateTimeImmutable($birthDate);
        } catch (\Throwable) {
            return false;
        }
        // Stichtag ist das Datum des Schreibens, sonst heute.
        $issued = null;
        if (preg_match('/Datum\s*:?\s*(\d{2})\.(\d{2})\.(\d{4})/u', implode("\n", $this->lines), $m)) {
            $issued = @\DateTimeImmutable::createFromFormat('!d.m.Y', $m[1] . '.' . $m[2] . '.' . $m[3]);
        }
        $ref = $issued ?: new \DateTimeImmutable('today');

        return $birth->diff($ref)->y < 18;
    }

    /**
     * Anschrift des KUNDEN (nicht die "Hausanschrift" der Behoerde): Strasse +
     * Hausnummer stehen beim Label, PLZ + Ort in einer der Folgezeilen.
     *
     * @param array<string,mixed> $raw
     */
    private function fillAddress(array &$raw): void
    {
        $i = $this->labelLineIndex('Anschrift');
        $street = $this->labelValue('Anschrift');
        if ($i === null || $street === null || preg_match('/Hausanschrift/u', $this->lines[$i])) {
            return;
        }

        if (preg_match('/^(.*\D)\s+(\d+(?:\s*[a-zA-Z])?)\s*$/u', $street, $s)) {
            $raw['street'] = trim($s[1]);
            $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
        } elseif (preg_match('/\p{L}/u', $street)) {
            $raw['street'] = $street;
        }

        for ($j = $i + 1; $j < count($this->lines) && $j <= $i + 4; $j++) {
            if (preg_match('/(?<!\d)(\d{5})\s+([A-ZÄÖÜ][\p{L}.\-]+(?:[ \-][A-ZÄÖÜ]?[\p{L}.\-]+)*)/u', $this->lines[$j], $z)) {
                $raw['zip'] = $z[1];
                $raw['city'] = trim((string) preg_replace('/\s{2,}.*$/u', '', $z[2]));
                break;
            }
        }
    }

    /**
     * Fallback fuer Handyfotos: das ANSCHRIFTFELD des Briefes traegt dieselbe
     * neue Meldeadresse (die Bestaetigung wird an die frisch angemeldete
     * Wohnung geschickt). Uebernommen wird NUR mit Namens-Anker - die Zeile
     * ueber der Strasse muss exakt der erkannte Kundenname sein - damit nie
     * die Absender-/Behoerdenadresse (z.B. "Im Biegel 13") in der Akte landet.
     * Die rechte Briefkopf-Spalte (Sachbearbeitung/Telefon/...) wird
     * abgeschnitten, auch wenn die Foto-OCR sie mit nur EINEM Leerzeichen an
     * die linke Spalte klebt.
     *
     * @param array<string,mixed> $raw
     */
    private function fillAddressFromLetterWindow(array &$raw): void
    {
        if (isset($raw['street']) || isset($raw['zip'])) {
            return;
        }
        $first = trim((string) ($raw['first_name'] ?? ''));
        $last = trim((string) ($raw['last_name'] ?? ''));
        if ($first === '' || $last === '') {
            return; // ohne vollen Namen kein sicherer Anker
        }
        $namen = [mb_strtolower($first . ' ' . $last), mb_strtolower($last . ' ' . $first)];

        foreach ($this->lines as $i => $line) {
            if (!in_array(mb_strtolower($this->leftCell($line)), $namen, true)) {
                continue;
            }
            $streetIdx = null;
            $end = min(count($this->lines), $i + 4);
            for ($j = $i + 1; $j < $end; $j++) {
                $cand = $this->leftCell($this->lines[$j]);
                if ($cand === '') {
                    continue;
                }
                if ($streetIdx === null
                    && preg_match('/^(.*\D)\s+(\d+(?:\s*[a-zA-Z])?)$/u', $cand, $s)
                    && preg_match('/\p{L}{3,}/u', $s[1])
                    && !preg_match('/(?<!\d)\d{5}\s/u', $cand)) {
                    $raw['street'] = trim($s[1]);
                    $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                    $streetIdx = $j;
                    $end = min(count($this->lines), $j + 4);
                    continue;
                }
                if ($streetIdx !== null
                    && preg_match('/(?<!\d)(\d{5})\s+([A-ZÄÖÜ][\p{L}.\-]+(?:[ \-][A-ZÄÖÜ]?[\p{L}.\-]+)*)/u', $cand, $z)) {
                    $raw['zip'] = $z[1];
                    $raw['city'] = trim((string) preg_replace('/\s{2,}.*$/u', '', $z[2]));
                    return;
                }
            }
            if ($streetIdx !== null) {
                return; // Strasse gefunden, PLZ blieb unleserlich
            }
        }
    }

    /**
     * Linke Spalte einer Briefkopf-Zeile: am Spaltenabstand (2+ Leerzeichen)
     * getrennt; klebt die Foto-OCR die rechte Spalte mit nur EINEM
     * Leerzeichen an, wird ab deren bekannten Beschriftungen abgeschnitten.
     */
    private function leftCell(string $line): string
    {
        $cell = trim((string) (preg_split('/\s{2,}/', trim($line))[0] ?? ''));

        return trim((string) preg_replace(
            '/\s*(?:Sachbearbeitung|Telefon|Telefax|Fax|E-Mail|Unser Zeichen|Zimmer|Datum)\b.*$/ui',
            '',
            $cell
        ));
    }

    /**
     * Beschriftungen dieser Bescheinigungen. Steht statt eines Wertes die
     * NAECHSTE Beschriftung in der Folgezeile, war das Feld leer.
     */
    private const LABELS = [
        'Familienname', 'Geburtsname', 'Vorname', 'Vornamen', 'Geburtsdatum',
        'Geburtsort', 'Wohnungsstatus', 'Einzugsdatum', 'Anmeldedatum',
        'Auszugsdatum', 'Anschrift', 'Hausanschrift', 'Gemeindeschlüssel',
        'Staatsangehörigkeit', 'Familienstand', 'Angemeldete Wohnung',
    ];

    /**
     * Beschriftung am Zeilenanfang - mit Doppelpunkt ("Familienname: Najm"),
     * im Spaltenlayout ("Familienname        Najm") ODER mit nur EINEM
     * Leerzeichen. Letzteres ist der Normalfall, wenn das Schreiben als
     * HANDYFOTO ankommt: die OCR eines Fotos kennt keine Spaltenraster und
     * setzt zwischen Beschriftung und Wert oft nur ein Leerzeichen. Ein
     * Klammer-Zusatz ("Vorname(n)") wird toleriert, auch mit von der OCR
     * verlesenen Klammern.
     */
    private function labelRegex(string $label): string
    {
        return '/^\s*' . preg_quote($label, '/')
            . '\s*(?:[\(\[\{][^\)\]\}\n]{0,12}[\)\]\}]?)?\s*:?/u';
    }

    /**
     * Wert zu einer Beschriftung - in derselben Zeile dahinter oder, wenn die
     * Zeile nur die Beschriftung traegt, in der naechsten nicht-leeren Zeile.
     * Folgt dort die naechste Beschriftung, war das Feld leer und bleibt leer.
     */
    private function labelValue(string $label): ?string
    {
        $re = $this->labelRegex($label);
        foreach ($this->lines as $i => $line) {
            if (!preg_match($re, $line, $m)) {
                continue;
            }
            $rest = trim(mb_substr($line, mb_strlen($m[0])));
            if ($rest !== '') {
                return $rest;
            }
            for ($j = $i + 1; $j < count($this->lines); $j++) {
                $next = trim($this->lines[$j]);
                if ($next === '') {
                    continue;
                }
                return $this->isLabelLine($next) ? null : $next;
            }
            return null;
        }
        return null;
    }

    /** Zeilennummer der Beschriftung (fuer mehrzeilige Werte wie die Anschrift). */
    private function labelLineIndex(string $label): ?int
    {
        $re = $this->labelRegex($label);
        foreach ($this->lines as $i => $line) {
            if (preg_match($re, $line)) {
                return $i;
            }
        }
        return null;
    }

    /** Beginnt die Zeile mit einer bekannten Beschriftung? */
    private function isLabelLine(string $line): bool
    {
        foreach (self::LABELS as $label) {
            if (preg_match($this->labelRegex($label), $line)) {
                return true;
            }
        }
        return false;
    }
}
