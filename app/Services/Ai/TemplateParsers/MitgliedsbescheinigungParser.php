<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\LiestSpalten;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Bestaetigung/Mitgliedsbescheinigung einer gesetzlichen Krankenkasse
 * ("Bestaetigung: <Name> ist bei uns versichert", KKH/AOK/BKK/IKK/TK ...).
 *
 * DIE BESONDERHEIT, UM DIE ES GEHT - und der Grund fuer einen eigenen Parser:
 * dieser Brief ist an den ARBEITGEBER gerichtet, nicht an den Kunden. Im
 * Empfaengerblock steht also die FIRMA; der Kunde steht ausschliesslich im
 * FLIESSTEXT ("... dass Frau <Name>, geboren am <Datum>, ... bei uns
 * versichert ist"). In jedem anderen Schreiben gilt die umgekehrte Regel.
 * Wer hier "Empfaenger = Kunde" anwendet, legt den ARBEITGEBER als Kunden an -
 * mit dessen Anschrift, und der echte Kunde fehlt ganz.
 *
 * Die Zuordnung Empfaenger -> Arbeitgeber wird NICHT vermutet, sondern aus dem
 * Brief belegt: er verlangt die Meldung nach Paragraph 6 DEUEV bzw. nennt die
 * Betriebsnummer/Sozialversicherungsbeitraege. Fehlt dieser Beleg und traegt
 * der Empfaengerblock den NAMEN DES MITGLIEDS, ist es die Ausfertigung an den
 * Versicherten selbst - dann ist es seine eigene Anschrift. Laesst sich keines
 * von beidem belegen, bleibt das Feld LEER.
 *
 * NIE UEBERNOMMEN: die "Krankenkassennummer" (Betriebsnummer der KASSE, bei
 * allen Versicherten dieselbe) ist KEINE Versichertennummer - dieselbe Regel
 * wie bei der Traeger-Kennnummer der Gesundheitskarte. Eine
 * Krankenversichertennummer nennt dieser Brief gar nicht; das Feld bleibt leer.
 */
class MitgliedsbescheinigungParser implements DocumentTemplateParser
{
    use LiestSpalten;
    use ValidatesExtractedFields;

    /** Belege dafuer, dass der Empfaenger der ARBEITGEBER ist. */
    private const ARBEITGEBER_BELEGE = [
        'DEUEV', 'DEÜV', 'VERSICHERUNGSPFLICHTIGEN BESCHAEFTIGUNG',
        'VERSICHERUNGSPFLICHTIGEN BESCHÄFTIGUNG', 'BETRIEBSNUMMER',
        'SOZIALVERSICHERUNGSBEITRAEGE', 'SOZIALVERSICHERUNGSBEITRÄGE',
    ];

    /** @var list<string> */
    private array $lines = [];

    private string $fluss = '';

    /** Geburtsdatum des Mitglieds (ISO) - Gegenprobe fuer die RVNR. */
    private ?string $geburtsdatum = null;

    public function parse(string $text): ?array
    {
        $this->lines = array_map('trim', preg_split('/\R/', $text) ?: []);
        // Fliesstext: der Kernsatz laeuft ueber den Zeilenumbruch hinweg
        // ("... RVNR\n\n65140304V017, ab dem ..."). Ohne diese Fassung findet
        // kein Ausdruck die Nummer - sie steht eine LEERZEILE tiefer.
        $this->fluss = (string) preg_replace('/\s+/u', ' ', implode(' ', $this->lines));

        $upper = mb_strtoupper($this->fluss);
        if (! $this->istKrankenkassenbrief($upper)) {
            return null;
        }

        $mitglied = $this->mitglied();
        if ($mitglied === null) {
            // Ohne Name UND Geburtsdatum des Mitglieds lieber der normalen
            // Analyse ueberlassen, als den Empfaenger zum Kunden zu machen.
            return null;
        }

        $this->geburtsdatum = $mitglied['birth_date'];
        $health = $this->parseHealth();
        $person = $this->parsePerson($mitglied, $upper);
        $versicherung = $this->parseInsurance($health);

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));

        return [
            'type' => 'mitgliedsbescheinigung',
            'confidence' => 72,
            'summary' => $this->summary($name, $health, $versicherung, $person),
            'title' => 'Mitgliedsbescheinigung Krankenkasse'.($name !== '' ? ' '.$name : ''),
            'data' => [
                'person' => $person,
                'gesundheit' => $health,
                'versicherung' => $versicherung,
                'kfz' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    private function istKrankenkassenbrief(string $upper): bool
    {
        $kasse = preg_match('/\bKRANKENKASSE\b|\bKRANKENVERSICHERUNG\b|\bGESUNDHEITSKASSE\b/u', $upper) === 1;
        // Der Bestaetigungs-Satz ist der eigentliche Anker. "Mitgliedsbe-
        // scheinigung" allein genuegt bewusst nicht: das Wort steht auch in
        // Formularen, die etwas ganz anderes sind.
        $bestaetigt = preg_match('/BEI UNS VERSICHERT|MITGLIEDSBESCHEINIGUNG|BESCHEINIGUNG\s+UEBER\s+DIE\s+MITGLIEDSCHAFT|BESCHEINIGUNG\s+ÜBER\s+DIE\s+MITGLIEDSCHAFT/u', $upper) === 1;

        return $kasse && $bestaetigt;
    }

    /**
     * Das Mitglied steht im FLIESSTEXT, nicht im Empfaengerblock.
     *
     * @return array{anrede:?string,name:string,birth_date:string}|null
     */
    private function mitglied(): ?array
    {
        // 1. Der Kernsatz mit Anrede UND Geburtsdatum - die beste Quelle.
        if (preg_match('/\bdass\s+(Frau|Herrn|Herr)?\s*([\p{L}\p{M}\-\' ]{3,60}?),\s*geb(?:oren)?(?:\s*am)?\s*(?:den\s*)?(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $this->fluss, $m)) {
            return [
                'anrede' => $m[1] !== '' ? $m[1] : null,
                'name' => trim($m[2]),
                'birth_date' => sprintf('%04d-%02d-%02d', (int) $m[5], (int) $m[4], (int) $m[3]),
            ];
        }
        // 2. Betreffzeile + Geburtsdatum irgendwo im Text.
        if (preg_match('/Bestätigung\s*:\s*(.+?)\s+ist\s+bei\s+uns\s+versichert/u', $this->fluss, $m)
            && preg_match('/geb(?:oren)?(?:\s*am)?\s*(?:den\s*)?(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $this->fluss, $g)) {
            return [
                'anrede' => null,
                'name' => trim($m[1]),
                'birth_date' => sprintf('%04d-%02d-%02d', (int) $g[3], (int) $g[2], (int) $g[1]),
            ];
        }

        return null;
    }

    /**
     * @param  array{anrede:?string,name:string,birth_date:string}  $mitglied
     * @return array<string,mixed>
     */
    private function parsePerson(array $mitglied, string $upper): array
    {
        $raw = ['birth_date' => $mitglied['birth_date']];

        $teile = preg_split('/\s+/u', trim($mitglied['name'])) ?: [];
        $teile = array_values(array_filter($teile, fn ($t) => $t !== ''));
        if (count($teile) >= 2) {
            // Projektweite Regel: letztes Wort = Nachname.
            $raw['last_name'] = (string) array_pop($teile);
            $raw['first_name'] = implode(' ', $teile);
        } elseif (count($teile) === 1) {
            $raw['last_name'] = $teile[0];
        }

        if ($mitglied['anrede'] !== null) {
            $raw['gender'] = mb_strtolower($mitglied['anrede']) === 'frau' ? 'female' : 'male';
        }

        $empfaenger = $this->empfaengerBlock();
        if ($empfaenger !== null) {
            $istArbeitgeber = false;
            foreach (self::ARBEITGEBER_BELEGE as $beleg) {
                if (str_contains($upper, $beleg)) {
                    $istArbeitgeber = true;
                    break;
                }
            }

            if ($istArbeitgeber) {
                $raw['employer_name'] = $empfaenger['name'];
                $raw['employer_address'] = $empfaenger['anschrift'];
            } elseif ($this->gleicherName($empfaenger['name'], $mitglied['name'])) {
                // Ausfertigung an den Versicherten selbst -> seine Anschrift.
                $raw['street'] = $empfaenger['strasse'];
                $raw['house_number'] = $empfaenger['hausnummer'];
                $raw['zip'] = $empfaenger['plz'];
                $raw['city'] = $empfaenger['ort'];
            }
            // Sonst: weder belegt noch zuordenbar -> KEIN Feld. Der Block
            // steht in der Zusammenfassung, damit nichts verloren geht.
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseHealth(): array
    {
        $raw = ['health_insurance_type' => 'gesetzlich'];

        $kasse = $this->kasse();
        if ($kasse !== null) {
            $raw['health_insurance_company'] = $kasse;
        }
        $rvnr = $this->rentenversicherungsnummer($this->geburtsdatum);
        if ($rvnr !== null) {
            $raw['pension_number'] = $rvnr;
        }

        // health_insurance_number bleibt BEWUSST leer: die genannte
        // "Krankenkassennummer" gehoert der Kasse, nicht dem Mitglied.
        return $this->validatedHealth(array_filter($raw, fn ($v) => $v !== ''));
    }

    /** @param array<string,mixed> $health @return array<string,mixed> */
    private function parseInsurance(array $health): array
    {
        $raw = [
            'sparte' => 'krankenversicherung',
            // Die Bescheinigung BELEGT die bestehende Mitgliedschaft - sie ist
            // kein Antrag. Damit ergaenzt sie den Antrags-Vertrag aus der
            // Beitrittserklaerung, statt einen zweiten anzulegen.
            'document_stage' => 'vertrag',
        ];
        if (isset($health['health_insurance_company'])) {
            $raw['insurer'] = $health['health_insurance_company'];
        }
        $beginn = $this->versicherungsbeginn();
        if ($beginn !== null) {
            $raw['start_date'] = $beginn;
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== ''));
    }

    private function versicherungsbeginn(): ?string
    {
        if (preg_match('/\bab\s+dem\s+(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $this->fluss, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/(?:Beginn der Mitgliedschaft|versichert\s+seit)\D{0,20}(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $this->fluss, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    /**
     * Rentenversicherungsnummer - 12 Zeichen, die ihr eigenes Geburtsdatum
     * tragen (Bereich 2, TTMMJJ, Anfangsbuchstabe, Serie 2, Pruefziffer).
     * Sie wird nur uebernommen, wenn dieses Datum zum gelesenen Geburtsdatum
     * passt: eine verlesene Ziffer faellt damit auf, statt in die Akte zu
     * wandern.
     */
    private function rentenversicherungsnummer(?string $geburtsdatum): ?string
    {
        if (! preg_match('/\b(?:RVNR|Rentenversicherungsnummer|Versicherungsnummer|SV-?Nummer)\b\D{0,12}?([0-9]{2}\s?[0-9]{6}\s?[A-Z]\s?[0-9]{3})\b/u', $this->fluss, $m)) {
            return null;
        }
        $nr = (string) preg_replace('/\s+/', '', $m[1]);
        if (! preg_match('/^\d{2}(\d{2})(\d{2})(\d{2})[A-Z]\d{3}$/', $nr, $t)) {
            return null;
        }
        // Die Nummer traegt ihr eigenes Geburtsdatum. Widerspricht es dem
        // gelesenen, hat die Erkennung eine Ziffer verlesen - dann lieber
        // KEINE Nummer als eine falsche in der Akte.
        if ($geburtsdatum !== null) {
            $erwartet = substr($geburtsdatum, 8, 2).substr($geburtsdatum, 5, 2).substr($geburtsdatum, 2, 2);
            if ($erwartet !== $t[1].$t[2].$t[3]) {
                return null;
            }
        }

        return $nr;
    }

    /** Name der Krankenkasse aus dem Briefkopf bzw. der Absenderzeile. */
    private function kasse(): ?string
    {
        foreach ($this->lines as $line) {
            if ($line === '' || mb_stripos($line, 'krankenkasse') === false) {
                continue;
            }
            // "KKH Kaufmaennische Krankenkasse 30125 Hannover" -> die PLZ und
            // alles dahinter gehoert zur Anschrift des Absenders.
            $name = (string) preg_replace('/\s+\d{5}\s+\p{L}[\p{L}\-\s]*$/u', '', $line);
            $name = trim((string) preg_replace('/^[^\p{L}]+/u', '', $name));
            if ($name !== '' && mb_strlen($name) <= 80 && preg_match('/\p{L}{3,}/u', $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Empfaengerblock: Name(n), Strasse, PLZ/Ort - an der SPALTENPOSITION
     * gelesen. Rechts steht die Service-Spalte des Absenders auf denselben
     * Zeilen; die am weitesten LINKS stehende Spalte gewinnt.
     *
     * @return array{name:string,strasse:string,hausnummer:string,plz:string,ort:string,anschrift:string,zeilen:list<string>}|null
     */
    private function empfaengerBlock(): ?array
    {
        $grenze = $this->endeDesBriefkopfs();

        foreach ($this->spaltenAus($this->lines) as $spalte) {
            $zellen = array_values(array_filter(
                $spalte,
                fn ($z) => $z['zeile'] <= $grenze
            ));
            if (count($zellen) < 3) {
                continue;
            }

            foreach ($zellen as $index => $zelle) {
                if ($index < 2 || ! preg_match('/^(\d{5})\s+(\p{Lu}[\p{L}\-\. \/]{1,40})$/u', $this->ohneRandzeichen($zelle['text']), $plz)) {
                    continue;
                }
                // OCR setzt am Zeilenanfang gern ein Satzzeichen ('‚Deichweg 8').
                // Ohne es abzustreifen faellt die ganze Anschrift durchs Raster.
                $strasseZelle = $this->ohneRandzeichen($zellen[$index - 1]['text']);
                if (! preg_match('/^(\p{L}[\p{L}\.\-\s]*?)\s+(\d{1,4}\s?[a-zA-Z]?(?:\s?[-\/]\s?\d+[a-zA-Z]?)?)$/u', $strasseZelle, $s)) {
                    continue;
                }
                // Namenszeilen oberhalb der Strasse; die ABSENDERzeile der
                // Kasse gehoert nicht dazu.
                $namen = [];
                for ($j = $index - 2; $j >= 0 && count($namen) < 2; $j--) {
                    $kandidat = $this->ohneRandzeichen($zellen[$j]['text']);
                    if (mb_stripos($kandidat, 'krankenkasse') !== false
                        || preg_match('/\d{5}\s+\p{Lu}/u', $kandidat)) {
                        break;
                    }
                    // Eine alleinstehende Anrede ist KEIN Name - sie waere
                    // sonst der "Empfaenger" und die Anschrift fiele weg.
                    if (preg_match('/^(Herrn?|Frau|Firma|An)$/u', $kandidat)) {
                        continue;
                    }
                    array_unshift($namen, $kandidat);
                }
                if ($namen === []) {
                    continue;
                }

                $strasse = trim($s[1]);
                $hausnummer = trim((string) preg_replace('/\s+/', '', $s[2]));

                return [
                    // NUR die erste Zeile ist der Name. Eine zweite Zeile ist
                    // oft ein Standort oder eine Person ("Bahnhof - Eric
                    // Nellen"); sie mit in den Firmennamen zu schreiben waere
                    // geraten - sie steht in der Zusammenfassung.
                    'name' => $namen[0],
                    'strasse' => $strasse,
                    'hausnummer' => $hausnummer,
                    'plz' => $plz[1],
                    'ort' => trim($plz[2]),
                    'anschrift' => $strasse.' '.$hausnummer.', '.$plz[1].' '.trim($plz[2]),
                    'zeilen' => $namen,
                ];
            }
        }

        return null;
    }

    /**
     * Der Empfaengerblock steht IMMER oberhalb von Betreff und Anrede
     * (DIN 5008). Die Suche endet dort - sonst wuerde die Anschrift im
     * Brieffuss ("Postanschrift ... 30125 Hannover") als Empfaenger gelesen.
     */
    private function endeDesBriefkopfs(): int
    {
        foreach ($this->lines as $i => $line) {
            if (preg_match('/^(Guten Tag|Sehr geehrte|Hallo)\b/u', $line)
                || preg_match('/\bgern teilen wir Ihnen mit\b/u', $line)) {
                return $i;
            }
        }

        return min(20, count($this->lines) - 1);
    }

    /** OCR-Rauschen am Rand einer Zelle entfernen (fuehrende Satzzeichen). */
    private function ohneRandzeichen(string $wert): string
    {
        return trim((string) preg_replace('/^[^\p{L}\d]+/u', '', trim($wert)));
    }

    private function gleicherName(string $a, string $b): bool
    {
        $norm = static function (string $v): array {
            $v = mb_strtolower((string) preg_replace('/[^\p{L}\s]/u', ' ', $v));
            $teile = array_filter(preg_split('/\s+/u', $v) ?: [], fn ($t) => mb_strlen($t) >= 3);

            return array_values($teile);
        };
        $x = $norm($a);
        $y = $norm($b);
        if ($x === [] || $y === []) {
            return false;
        }

        return count(array_intersect($x, $y)) >= min(2, min(count($x), count($y)));
    }

    /**
     * @param  array<string,mixed>  $health
     * @param  array<string,mixed>  $versicherung
     * @param  array<string,mixed>  $person
     */
    private function summary(string $name, array $health, array $versicherung, array $person): string
    {
        $teile = ['Bestaetigung der Krankenversicherung'];
        if (isset($health['health_insurance_company'])) {
            $teile[] = $health['health_insurance_company'];
        }
        if ($name !== '') {
            $teile[] = $name;
        }
        if (isset($versicherung['start_date'])) {
            $teile[] = 'versichert ab '.date('d.m.Y', strtotime((string) $versicherung['start_date']));
        }
        if (isset($person['employer_name'])) {
            $teile[] = 'Arbeitgeber '.$person['employer_name'];
        }

        $hinweise = [];
        $empfaenger = $this->empfaengerBlock();
        if ($empfaenger !== null && count($empfaenger['zeilen']) > 1) {
            $hinweise[] = 'Empfaengerblock weitere Zeile: '.implode(' / ', array_slice($empfaenger['zeilen'], 1));
        }
        if (preg_match('/Krankenkassennummer\s*:?\s*(\d{6,})/u', $this->fluss, $m)) {
            $hinweise[] = 'Krankenkassennummer '.$m[1].' (Betriebsnummer der KASSE, keine Versichertennummer)';
        }
        if (preg_match('/Servicezeichen\s*:?\s*(\d{6,})/u', $this->fluss, $m)) {
            $hinweise[] = 'Servicezeichen '.$m[1];
        }
        if (! isset($health['health_insurance_number'])) {
            $hinweise[] = 'Krankenversichertennummer steht nicht in diesem Schreiben';
        }

        return implode(' - ', $teile).'.'
            .($hinweise !== [] ? ' '.implode('; ', $hinweise).'.' : '')
            .' Felder gratis aus dem Schreiben gelesen (ohne KI).';
    }
}
