<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer die elektronische Gesundheitskarte - BEIDE Seiten und,
 * wichtig fuer den Alltag, MEHRERE Karten auf EINER Aufnahme
 * (Betreiber-Vorgabe 05.08.2026): Familien bringen ihre Karten als Stapel und
 * fotografieren sie zusammen. Jede erkannte Karte wird eine eigene Person, aus
 * der der Mitarbeiter mit einem Klick Kunden anlegen kann.
 *
 * Rueckseite (Europaeische Krankenversicherungskarte / EHIC), genormte Felder:
 *
 *   3. Name                     -> Nachname
 *   4. Vornamen                 -> Vorname(n)
 *   5. Geburtsdatum             -> TT/MM/JJJJ
 *   6. Persoenliche Kennnummer  -> Krankenversichertennummer (1 Buchstabe + 9 Ziffern)
 *   7. Kennnummer des Traegers  -> NUR fuer den Kassennamen dahinter
 *
 * Vorderseite: "Vorname Nachname" ueber dem Kassennamen, darunter die
 * Versichertennummer.
 *
 * WICHTIG: Die Nummer bei "Versicherung"/"Kennnummer des Traegers"
 * (z.B. 104491707) ist die Institutionsnummer der KASSE - sie ist bei allen
 * Versicherten derselben Kasse identisch und darf niemals als
 * Versichertennummer uebernommen werden. Uebernommen wird ausschliesslich das
 * eindeutige Format "1 Buchstabe + 9 Ziffern".
 *
 * Eine Karte zaehlt nur, wenn Name UND Versichertennummer im SELBEN Block
 * stehen - sonst koennte die Nummer der einen Person am Namen der anderen
 * landen. Lieber eine Karte weniger als eine falsche Zuordnung.
 */
class GesundheitskarteParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    public function parse(string $text): ?array
    {
        if (! $this->istGesundheitskarte($text)) {
            return null;
        }

        $cards = [];
        foreach ($this->cardBlocks($text) as $block) {
            $card = $this->parseCard($block);
            if ($card !== null) {
                $cards[] = $card;
            }
        }
        if ($cards === []) {
            return null;
        }

        // Dieselbe Karte kann in einer Aufnahme zweimal vorkommen (Vorder- und
        // Rueckseite nebeneinander) - die Versichertennummer entscheidet.
        $cards = $this->mergeDuplicates($cards);

        $primary = $cards[0];
        $weitere = array_slice($cards, 1);

        $name = trim(($primary['person']['first_name'] ?? '').' '.($primary['person']['last_name'] ?? ''));
        $namen = array_values(array_filter(array_map(
            fn ($c) => trim(($c['person']['first_name'] ?? '').' '.($c['person']['last_name'] ?? '')),
            $cards
        )));

        return [
            'type' => 'gesundheitskarte',
            'confidence' => 70,
            'summary' => count($cards) > 1
                ? 'Gesundheitskarten: '.count($cards).' Personen erkannt - '.implode(', ', $namen)
                    .' - Felder gratis gelesen (ohne KI).'
                : 'Gesundheitskarte (Krankenversicherungskarte)'
                    .($name !== '' ? ' - '.$name : '')
                    .(isset($primary['gesundheit']['health_insurance_number'])
                        ? ' - Vers.-Nr. '.$primary['gesundheit']['health_insurance_number'] : '')
                    .' - Felder gratis gelesen (ohne KI).',
            'title' => count($cards) > 1
                ? 'Gesundheitskarten ('.count($cards).' Personen)'
                : 'Gesundheitskarte'.($name !== '' ? ' '.$name : ''),
            'data' => [
                'person' => $primary['person'],
                'gesundheit' => $primary['gesundheit'],
                // Jede weitere Karte als eigene Person - daraus legt der
                // Mitarbeiter die uebrigen Kunden mit einem Klick an.
                'personen' => $this->validatedPersons(array_map(
                    fn ($c) => $c['person'] + array_filter([
                        'health_insurance_number' => $c['gesundheit']['health_insurance_number'] ?? null,
                    ]),
                    $weitere
                )),
                'versicherung' => [],
                'kfz' => [],
                'bank' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Text in Karten-Bloecke zerlegen. Jede Karte beginnt mit einer ihrer
     * Ueberschriften; liegt nur EIN Block vor, ist es der ganze Text.
     *
     * @return list<list<string>>
     */
    private function cardBlocks(string $text): array
    {
        $lines = array_map('trim', preg_split('/\R/', $text) ?: []);

        $starts = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/EUROP[ÄA]ISCHE\s+KRANKENVERSICHERUNGSKARTE/iu', $line)
                || preg_match('/^\s*3[.\s]*Name\b/iu', $line)
                || preg_match('/Gesundheitskarte/iu', $line)) {
                $starts[] = $i;
            }
        }
        if ($starts === []) {
            return [$lines];
        }

        $blocks = [];
        foreach ($starts as $k => $from) {
            $to = $starts[$k + 1] ?? count($lines);
            $blocks[] = array_slice($lines, $from, $to - $from);
        }

        return $blocks;
    }

    /**
     * Eine einzelne Karte lesen. Null, wenn Name oder Versichertennummer
     * fehlen - eine halbe Karte waere eine Einladung zur Fehlzuordnung.
     *
     * @param list<string> $lines
     * @return array{person: array<string,mixed>, gesundheit: array<string,mixed>}|null
     */
    private function parseCard(array $lines): ?array
    {
        $text = implode("\n", $lines);

        // Versichertennummer: 1 Buchstabe + 9 Ziffern. Die Institutionsnummer
        // der Kasse (nur Ziffern) faellt durch dieses Muster.
        if (! preg_match('/\b([A-Z]\d{9})\b/', $text, $m)) {
            return null;
        }
        $health = ['health_insurance_number' => $m[1], 'health_insurance_type' => 'gesetzlich'];

        // Kassenname: hinter der Traeger-Kennnummer ("104491707 - novitas bkk")
        // oder als eigene Zeile unter dem Namen (Vorderseite).
        if (preg_match('/\d{7,10}\s*[-–]\s*([\p{L}][\p{L} .+&\-]{1,60})/u', $text, $k)) {
            $health['health_insurance_company'] = trim($k[1]);
        } elseif (preg_match('/^\s*((?!Deine\b)[\p{L}][\p{L} .+&\-]{1,40}\b(?:BKK|AOK|IKK|KKH)\b[\p{L} .+&\-]{0,20})\s*$/imu', $text, $k)) {
            // "Deine Krankenkasse" ist der Werbespruch der Karte, kein Kassenname.
            $health['health_insurance_company'] = trim($k[1]);
        }

        $raw = [];
        // Rueckseite (EHIC): Name und Vornamen stehen unter ihren Feldnummern.
        // Die Rueckseite steht in ZWEI Spalten (Vornamen | Geburtsdatum), die
        // OCR setzt beide Werte in EINE Zeile ("Mohamad 01/01/1996") - der
        // Namensteil ist deshalb das, was VOR dem Datum steht.
        $istName = fn (string $v): ?string => $this->namensteil($v);
        $istDatum = fn (string $v): ?string => preg_match('#\b\d{2}[./]\d{2}[./]\d{4}\b#', $v) ? $v : null;
        $last = $this->valueBelow($lines, '/^\s*3[.\s]*Name\b/iu', $istName);
        $first = $this->valueBelow($lines, '/Vornamen/iu', $istName);
        $birth = $this->valueBelow($lines, '/Geburtsdatum/iu', $istDatum);

        if ($last !== null) {
            $raw['last_name'] = $this->tidyName($last);
        }
        if ($first !== null) {
            $raw['first_name'] = $this->tidyName($first);
        }
        // Vorderseite: "Vorname Nachname" in EINER Zeile ueber dem Kassennamen.
        if (($raw['last_name'] ?? null) === null && ($raw['first_name'] ?? null) === null) {
            foreach ($lines as $line) {
                $line = trim($line);
                if (! preg_match('/^\p{Lu}[\p{L}\-\']+(?:\s+\p{Lu}[\p{L}\-\']+)+$/u', $line)
                    || preg_match('/\b(BKK|AOK|IKK|KKH|Krankenkasse|Kasse|Service|Gesundheitskarte|Bundesamt|Sicherheit|Informationstechnik)\b/iu', $line)) {
                    continue;
                }
                $parts = preg_split('/\s+/', $line) ?: [];
                $raw['last_name'] = array_pop($parts);
                $raw['first_name'] = implode(' ', $parts) ?: null;
                break;
            }
        }

        // Geburtsdatum (Feld 5): TT/MM/JJJJ oder TT.MM.JJJJ. Nur aus der
        // Feldzeile - ein Datum im Fliesstext (Ablaufdatum) zaehlt nicht.
        if ($birth !== null && preg_match('#\b(\d{2})[./](\d{2})[./](\d{4})\b#', $birth, $b)) {
            $raw['birth_date'] = $b[3].'-'.$b[2].'-'.$b[1];
        }

        $person = $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
        if (($person['last_name'] ?? null) === null && ($person['first_name'] ?? null) === null) {
            return null;
        }

        return ['person' => $person, 'gesundheit' => $this->validatedHealth($health)];
    }

    /**
     * Karten mit derselben Versichertennummer zusammenfuehren (Vorder- und
     * Rueckseite derselben Karte auf einer Aufnahme): die vollstaendigeren
     * Angaben gewinnen.
     *
     * @param list<array{person: array<string,mixed>, gesundheit: array<string,mixed>}> $cards
     * @return list<array{person: array<string,mixed>, gesundheit: array<string,mixed>}>
     */
    private function mergeDuplicates(array $cards): array
    {
        $byNumber = [];
        foreach ($cards as $card) {
            $key = $card['gesundheit']['health_insurance_number'] ?? null;
            if ($key === null) {
                continue;
            }
            if (! isset($byNumber[$key])) {
                $byNumber[$key] = $card;
                continue;
            }
            // Fehlende Felder aus der anderen Seite ergaenzen.
            $byNumber[$key]['person'] += $card['person'];
            $byNumber[$key]['gesundheit'] += $card['gesundheit'];
        }

        return array_values($byNumber);
    }

    /**
     * Wert zu einer Beschriftung: ZUERST die naechste nicht-leere Zeile, dann
     * der Rest HINTER der Beschriftung ("4. Vornamen   Jana") - jeweils nur,
     * wenn $pruefen ihn als passenden Wert erkennt (Rueckgabe = bereinigter
     * Wert). Die Reihenfolge ist Absicht (Betreiber-Meldung 20.11.2026, BIG-
     * Karte): die OCR haengt an die Beschriftung oft Rauschen an
     * ("3. Name Bane ee |") - das sieht aus wie ein Wort und gewann frueher
     * vor dem echten Namen in der Zeile darunter.
     *
     * @param callable(string): ?string $pruefen
     */
    private function valueBelow(array $lines, string $pattern, callable $pruefen): ?string
    {
        foreach ($lines as $i => $line) {
            if (! preg_match($pattern, $line, $mm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            for ($j = $i + 1; $j < count($lines); $j++) {
                $v = trim($lines[$j]);
                if ($v !== '') {
                    if (($wert = $pruefen($v)) !== null) {
                        return $wert;
                    }
                    break;
                }
            }
            $inline = trim(mb_substr($line, (int) $mm[0][1] + mb_strlen($mm[0][0])));

            return $inline !== '' ? $pruefen($inline) : null;
        }
        return null;
    }

    /**
     * Namensteil eines Feldwerts: alles VOR einem Datum bzw. einer Ziffer,
     * und nur, wenn es wie ein Name aussieht. Sonst null - nie geraten.
     */
    private function namensteil(string $value): ?string
    {
        $teil = trim((string) preg_replace('/\s*\d.*$/u', '', trim($value)));

        return $teil !== '' && $this->looksLikeNamePart($teil) ? $teil : null;
    }

    /**
     * Erkennung der Karte. Die Ueberschrift allein genuegt nicht: auf dem
     * Foto der BIG-Karte (20.11.2026) kam sie als "KRANKENVERSICRERUNGSKARTE"
     * an - ein verlesener Buchstabe liess die ganze Karte zur KI laufen.
     * Akzeptiert werden deshalb auch die verlesene Ueberschrift
     * (KRANKENVERS...KARTE) und die genormten EHIC-Felder (Traeger-Kennnummer
     * UND Geburtsdatum), die kein anderes Dokument zusammen traegt.
     */
    private function istGesundheitskarte(string $text): bool
    {
        return mb_stripos($text, 'KRANKENVERSICHERUNGSKARTE') !== false
            || mb_stripos($text, 'GESUNDHEITSKARTE') !== false
            || preg_match('/KRANKENVERS\p{L}{0,10}KARTE/iu', $text)
            || (preg_match('/des\s+Tr(?:[äa]|ae)gers/iu', $text) && preg_match('/Geburtsdatum/iu', $text));
    }

    private function looksLikeNamePart(string $value): bool
    {
        return (bool) preg_match('/^\p{Lu}[\p{L}\-\' ]{1,60}$/u', trim($value));
    }

    /** "ALABDULLAH" -> "Alabdullah" (Karten schreiben den Namen in Versalien). */
    private function tidyName(string $value): string
    {
        $value = trim($value);
        if ($value !== mb_strtoupper($value)) {
            return $value;
        }

        return implode(' ', array_map(
            fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)).mb_strtolower(mb_substr($w, 1)),
            preg_split('/\s+/', $value) ?: []
        ));
    }
}
