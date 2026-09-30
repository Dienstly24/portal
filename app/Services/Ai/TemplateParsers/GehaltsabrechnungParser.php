<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Support\Adresszeile;

/**
 * Gratis-Parser fuer die Entgelt-/Gehaltsabrechnung (Lohnabrechnung). Diese
 * Abrechnungen tragen die verlaesslichsten Personendaten des Arbeitnehmers
 * (Name, Anschrift, Geburtsdatum) - ideal, um das Dokument dem richtigen
 * Kunden zuzuordnen - sowie weitere wertvolle Angaben: die Krankenkasse, das
 * Ueberweisungskonto (IBAN des Arbeitnehmers) und die Einkommenshoehe
 * (Brutto/Netto), die der Betrieb bisher von Hand nachtragen musste.
 *
 * Das Layout ist zweispaltig: links der Arbeitnehmer-Anschriftenblock,
 * rechts die Steuer-/SV-Merkmale (Geburtsdatum, Krankenkasse ...). Alle Werte
 * durchlaufen die harte Feldvalidierung; unsichere Felder bleiben leer statt
 * falsch. Die Einkommenshoehe steht (mangels eigenem Feld) in der
 * Zusammenfassung; der ARBEITGEBER geht in die Kundenakte
 * (employer_name/employer_address, dieselben Felder wie beim Arbeitsvertrag).
 *
 * GELESEN WIRD AN DER SPALTENPOSITION, nicht an "der ersten Zelle der Zeile":
 * im Empfaengerblock bleibt regelmaessig eine Zeile leer, und dort steht dann
 * die Merkmalsspalte - "Telefon" waere zur Strasse geworden (dieselbe Lehre
 * wie beim Gruenwelt-Briefkopf und bei der eAT-Rueckseite). Und es gibt KEINE
 * Anrede: der Block wird ueber die FORM seiner Zellen gefunden (eine Zelle
 * "PLZ Ort", darueber Strasse und Name).
 */
class GehaltsabrechnungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /**
     * Ueberschriften, unter denen eine Entgeltabrechnung erscheint. Jedes
     * Abrechnungsprogramm nennt sie anders - "Verdienstabrechnung" (gemessen
     * 30.09.2026), "Bezuegemitteilung" im oeffentlichen Dienst. Ein Wort, das
     * hier fehlt, laesst den Parser stumm bleiben, und es gibt dafuer keine
     * Fehlermeldung: das Dokument landet als "Sonstiges" im Eingang.
     *
     * @var list<string>
     */
    private const TITEL = [
        'ENTGELTABRECHNUNG', 'GEHALTSABRECHNUNG', 'LOHNABRECHNUNG',
        'ENTGELTBESCHEINIGUNG', 'VERDIENSTABRECHNUNG', 'VERDIENSTBESCHEINIGUNG',
        'ENTGELTNACHWEIS', 'BEZUEGEMITTEILUNG', 'BEZÜGEMITTEILUNG',
        'LOHN- UND GEHALTSABRECHNUNG', 'VERGUETUNGSABRECHNUNG', 'VERGÜTUNGSABRECHNUNG',
    ];

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);
        $erkannt = false;
        foreach (self::TITEL as $titel) {
            if (str_contains($upper, $titel)) {
                $erkannt = true;
                break;
            }
        }
        if (! $erkannt) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $person = $this->parsePerson();
        if (($person['first_name'] ?? null) === null && ($person['last_name'] ?? null) === null) {
            return null; // ohne Namen der normalen Analyse/KI ueberlassen
        }

        // Krankenkasse (rechte Merkmalsspalte).
        $health = [];
        $kk = $this->labelValue('Krankenkasse');
        if ($kk !== null && mb_strlen($kk) >= 2 && ! preg_match('/beitrag/i', $kk)) {
            $health['health_insurance_company'] = $kk;
            $health['health_insurance_type'] = 'gesetzlich';
        }
        $health = $this->validatedHealth($health);

        // Ueberweisungskonto = IBAN des Arbeitnehmers.
        $bank = [];
        // Zwischen "IBAN" und dem Wert steht je nach Programm ") : " oder ":".
        if (preg_match('/IBAN[^A-Z0-9]{0,8}(DE\d{2}(?:\s?\d){18})\b/u', $this->text(), $m)) {
            $bank['iban'] = strtoupper((string) preg_replace('/\s+/', '', $m[1]));
            $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
            if ($name !== '') {
                $bank['account_holder'] = $name;
            }
        }
        $bank = $this->validatedBank($bank);

        $employer = $person['employer_name'] ?? $this->employerByLegalForm();
        $brutto = $this->amountAfter('Gesamtbrutto');
        $netto = $this->firstAmount(['Gesamtnetto', 'Gesetzliches Netto', 'Netto-Verdienst', 'Auszahlungsbetrag']);
        $month = $this->periodLabel();

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        return [
            'type' => 'gehaltsabrechnung',
            'confidence' => 72,
            'summary' => 'Entgeltabrechnung'
                .($month !== null ? ' '.$month : '')
                .($name !== '' ? ' - '.$name : '')
                .($employer !== null ? ' - Arbeitgeber '.$employer : '')
                .($brutto !== null ? ' - Brutto '.number_format($brutto, 2, ',', '.').' EUR' : '')
                .($netto !== null ? ' / Netto '.number_format($netto, 2, ',', '.').' EUR' : '')
                .(isset($health['health_insurance_company']) ? ' - Krankenkasse '.$health['health_insurance_company'] : '')
                .' - Felder gratis aus der Abrechnung gelesen (ohne KI).',
            'title' => 'Entgeltabrechnung'.($name !== '' ? ' '.$name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => [],
                'kfz' => [],
                'gesundheit' => $health,
                'bank' => $bank,
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Arbeitnehmer-Anschrift UND Arbeitgeber aus dem Briefkopf.
     *
     * @return array<string,mixed>
     */
    private function parsePerson(): array
    {
        $raw = [];

        // Spalten von links nach rechts: der Empfaengerblock steht links.
        // Rechts steht auf der Abrechnung derselbe Aufbau fuer den
        // Arbeitgeber - wer "die erste passende Spalte" nimmt, macht sonst
        // den Arbeitgeber zum Kunden.
        foreach ($this->spalten() as $zellen) {
            $block = $this->empfaengerBlock($zellen);
            if ($block === null) {
                continue;
            }
            $raw = $block['person'];
            $arbeitgeber = $this->arbeitgeber($zellen, $block['index']);
            if ($arbeitgeber !== null) {
                $raw['employer_name'] = $arbeitgeber['name'];
                if ($arbeitgeber['anschrift'] !== '') {
                    $raw['employer_address'] = $arbeitgeber['anschrift'];
                }
            }
            break;
        }

        // Geburtsdatum aus der rechten Merkmalsspalte. Es traegt sich SELBST
        // (TT.MM.JJJJ) und haengt deshalb nicht am Spaltenabstand hinter der
        // Beschriftung - "Geburtsdatum: 12.03.1990" hat nur EIN Leerzeichen.
        if (preg_match('/Geburtsdatum\s*:?\s+(\d{2})\.(\d{2})\.(\d{4})/u', $this->text(), $m)) {
            $raw['birth_date'] = $m[3].'-'.$m[2].'-'.$m[1];
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Der Empfaengerblock einer Spalte: eine Zelle "PLZ Ort", darueber
     * Strasse und Name. Eine Anrede ("Herrn"/"Frau") DARF davor stehen, muss
     * aber nicht - die gemessene Abrechnung hat keine.
     *
     * @param  list<array{zeile:int,spalte:int,text:string}>  $zellen
     * @return array{index:int,person:array<string,mixed>}|null
     */
    private function empfaengerBlock(array $zellen): ?array
    {
        foreach ($zellen as $k => $zelle) {
            if ($k < 2 || ! preg_match('/^(\d{5}) (\p{Lu}[\p{L}.\-]*(?:[ \-]\p{L}[\p{L}.\-]*)*)$/u', $zelle['text'], $o)) {
                continue;
            }
            $strasse = $zellen[$k - 1];
            $name = $zellen[$k - 2];

            // Der Block steht beieinander - sonst waeren es drei Zellen, die
            // zufaellig in derselben Spalte stehen.
            if ($zelle['zeile'] - $name['zeile'] > 6) {
                continue;
            }
            if (! preg_match('/^(?<s>\p{Lu}[\p{L}0-9 .\-]*?)[ ,]+(?<n>\d{1,4} ?[a-zA-Z]?)$/u', $strasse['text'], $st)) {
                continue;
            }
            if (! preg_match('/^\p{Lu}[\p{L}\-\x{2019}\']+(?: \p{Lu}?[\p{L}\-\x{2019}\']+)+$/u', $name['text'])) {
                continue;
            }

            $teile = preg_split('/\s+/', $name['text']) ?: [];
            $person = [
                'last_name' => array_pop($teile),
                'first_name' => implode(' ', $teile) ?: null,
                'street' => trim($st['s']),
                'house_number' => trim((string) preg_replace('/\s+/', ' ', $st['n'])),
                'zip' => $o[1],
                'city' => trim($o[2]),
            ];

            // Anrede eine Zelle darueber - wenn sie da ist.
            $anrede = $zellen[$k - 3]['text'] ?? '';
            if (preg_match('/^(Herrn|Herr|Frau)$/u', $anrede)) {
                $person['gender'] = mb_strtolower($anrede) === 'frau' ? 'female' : 'male';
            }

            return ['index' => $k - 2, 'person' => $person];
        }

        return null;
    }

    /**
     * Der Arbeitgeber steht im Briefkopf UEBER dem Empfaengerblock - als
     * einzeilige ABSENDERZEILE ("SHV KV OS Mitte Nord Holstenkamp 7b 24105
     * Kiel") oder als Block (Name, darunter die Anschrift).
     *
     * Welcher Fall gilt, entscheidet das UMFELD, nicht die Zeichenkette:
     * steht ueber der Anschrift-Zelle ein Name, ist die Zelle nur die
     * Anschrift. Steht dort nichts, traegt sie den Namen selbst.
     *
     * @param  list<array{zeile:int,spalte:int,text:string}>  $zellen
     * @return array{name:string,anschrift:string}|null
     */
    private function arbeitgeber(array $zellen, int $nameIndex): ?array
    {
        for ($k = $nameIndex - 1; $k >= 0 && $k >= $nameIndex - 5; $k--) {
            $text = $zellen[$k]['text'];
            if (preg_match('/^(Herrn|Herr|Frau)$/u', $text)) {
                continue;   // Anrede des Empfaengers
            }
            if (! preg_match('/\b\d{5}\b/', $text)) {
                continue;
            }

            $oben = $zellen[$k - 1] ?? null;
            if ($oben !== null && $zellen[$k]['zeile'] - $oben['zeile'] <= 2 && self::istName($oben['text'])) {
                $adresse = Adresszeile::anschrift($text);
                if ($adresse !== null) {
                    return ['name' => $oben['text'], 'anschrift' => $adresse['anschrift']];
                }
            }

            $mit = Adresszeile::mitName($text);
            if ($mit !== null) {
                return ['name' => $mit['name'], 'anschrift' => $mit['anschrift']];
            }

            // Anschrift ohne Namen und ohne Namen darueber: nichts behaupten.
            return null;
        }

        return null;
    }

    /** Sieht die Zelle nach einem Firmen-/Personennamen aus (und nicht nach einer Anschrift)? */
    private static function istName(string $text): bool
    {
        return (bool) preg_match('/^\p{Lu}[\p{L}0-9 .,&\-\/]{1,79}$/u', $text)
            && ! preg_match('/\b\d{5}\b/', $text)
            && ! preg_match('/\d{1,4} ?[a-zA-Z]?$/u', $text);
    }

    /**
     * Rueckfallebene fuer Layouts ohne Absenderzeile: erste Kopfzeile mit
     * Rechtsform. Viele Traeger fuehren KEINE Rechtsform im Namen - deshalb
     * ist das die Rueckfallebene und nicht mehr der Hauptweg.
     */
    private function employerByLegalForm(): ?string
    {
        foreach (array_slice($this->lines, 0, 20) as $line) {
            $col = $this->columns($line)[0] ?? '';
            if (preg_match('/^[A-ZÄÖÜ][\p{L}0-9 .,&\-]{2,60}\s(GmbH|AG|KG|GbR|mbH|e\.K\.|OHG|SE|UG)\b/u', trim($col), $m)) {
                return trim($m[0]);
            }
        }

        return null;
    }

    /**
     * Alle Zellen mit ihrer SPALTENPOSITION, nach Spalten gruppiert (links
     * zuerst). Position in ZEICHEN, nicht in Bytes - ein Umlaut weiter links
     * wuerde die Spalte sonst verschieben.
     *
     * @return list<list<array{zeile:int,spalte:int,text:string}>>
     */
    private function spalten(): array
    {
        $zellen = [];
        foreach ($this->lines as $i => $line) {
            if (! preg_match_all('/\S(?:.*?\S)?(?=\h{2,}|$)/u', $line, $mm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($mm[0] as $treffer) {
                $text = trim($treffer[0]);
                if ($text === '') {
                    continue;
                }
                $zellen[] = [
                    'zeile' => $i,
                    'spalte' => mb_strlen(substr($line, 0, (int) $treffer[1])),
                    'text' => $text,
                ];
            }
        }

        // Nach Spalte gruppieren, kleine Abweichungen (+/- 2 Zeichen) gelten
        // als dieselbe Spalte.
        $gruppen = [];
        foreach ($zellen as $zelle) {
            foreach ($gruppen as $position => $liste) {
                if (abs($position - $zelle['spalte']) <= 2) {
                    $gruppen[$position][] = $zelle;

                    continue 2;
                }
            }
            $gruppen[$zelle['spalte']] = [$zelle];
        }
        ksort($gruppen);

        return array_values($gruppen);
    }

    /** Betrag nach einem Label ("Gesamtbrutto ... 2.512,00") - der erste Wert. */
    private function amountAfter(string $label): ?float
    {
        if (preg_match('/'.preg_quote($label, '/').'\s+([\d.]+,\d{2})/u', $this->text(), $m)) {
            return (float) str_replace(['.', ','], ['', '.'], $m[1]);
        }
        return null;
    }

    /**
     * Der erste Betrag, den eine dieser Beschriftungen liefert. Die Programme
     * nennen das Netto verschieden ("Gesamtnetto", "Gesetzliches Netto").
     *
     * @param  list<string>  $labels
     */
    private function firstAmount(array $labels): ?float
    {
        foreach ($labels as $label) {
            $betrag = $this->amountAfter($label);
            if ($betrag !== null) {
                return $betrag;
            }
        }

        return null;
    }

    /** Abrechnungszeitraum ("Mai 2026") aus der Kopfzeile. */
    private function periodLabel(): ?string
    {
        if (preg_match('/\b(Januar|Februar|März|Maerz|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)\s+(\d{4})\b/u', $this->text(), $m)) {
            return $m[1].' '.$m[2];
        }
        return null;
    }

    /** Wert nach "Label" bis zur naechsten Spalte/Zeilenende (rechte Merkmalsspalte). */
    private function labelValue(string $label): ?string
    {
        $pattern = '/(?<![\p{L}\-])'.preg_quote($label, '/').'\s{2,}(\S.*?)(?:\s{2,}|$)/mu';
        return preg_match($pattern, $this->text(), $m) ? trim($m[1]) : null;
    }

    /** @return list<string> */
    private function columns(string $line): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\s{2,}/', trim($line)) ?: []),
            fn ($c) => $c !== ''
        ));
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }
}
