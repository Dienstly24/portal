<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\RepairsOcrText;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Support\FieldRecognition;

/**
 * Parser fuer die AUFTRAGS-UEBERSICHT aus dem Vertriebsportal eines
 * Energie-Vergleichsportals (Bildschirmfoto, z.B. RheinEnergie AG "Fair
 * Ökostrom 24"). Der Betrieb arbeitet den Auftrag dort ab und laedt die
 * Uebersicht als SCREENSHOT hoch - alle Kern-Daten stehen beschriftet da:
 *
 *   Kopf        : "<Auftragsnummer> - <Anbieter> - <Produkt>" + "Herr <Name>"
 *   Tarif       : Anbieter / Produkt / Abnehmer / Tariftyp
 *   Tarifdaten  : Grundpreis, Arbeitspreis, Laufzeit, Preisgarantie
 *   Person      : Block "Belieferungsanschrift" (Name, Anschrift, geboren am,
 *                 Tel, Mail)
 *   Bank        : Block "Anschrift des Kontoinhaber" (IBAN/BIC)
 *   Belieferung : Auftragsnummer, Netzbetreiber, MaLo-ID, Vorjahresverbrauch,
 *                 Zaehlernummer, bish. Kundennummer, Vorversorger, Status
 *
 * LEHRE AUS DEM ECHTEN LAUF (16.08.2026): Screenshot-OCR erhaelt das
 * Spaltenraster NICHT. Die drei nebeneinander stehenden Spalten landen in
 * EINER Zeile - oft nur durch EIN Leerzeichen getrennt:
 *
 *   "Produkt Fair Ökostrom 24 IBAN: DE82..."      (Tarif + Bankspalte)
 *   "Herr Max Muster Herr Max Muster"             (Anschrift + Kontoinhaber)
 *   "1 Monat Kündigungsfrist MaLo-ID 51214126166" (Tarifdaten + Belieferung)
 *
 * Deshalb wird NICHT auf Spaltenabstaende vertraut, sondern auf das
 * BESCHRIFTUNGS-VOKABULAR dieser Ansicht: eine Beschriftung darf mitten in
 * der Zeile stehen, und ihr Wert endet dort, wo die naechste bekannte
 * Beschriftung beginnt. Doppelt gesetzte Texte ("X X" aus zwei Spalten)
 * werden zusammengefasst.
 *
 * Weitere Regeln (Betreiber-Vorgaben):
 *  - Ein AUFTRAG hat KEINE Vertragsnummer: die Auftragsnummer steht nur in
 *    der Zusammenfassung (Stufe 'antrag'); die spaetere Vertragsbestaetigung
 *    bringt die echte Nummer und findet ihren Vertrag ueber MaLo-ID/
 *    Zaehlernummer.
 *  - Kein geschaetzter Lieferbeginn - Ausnahme Stadtwerke-Wechsel: steht
 *    kein Datum ("schnellstmoeglich") und ist der Vorversorger ein
 *    Stadtwerk, gilt die 14-Tage-Frist + Bearbeitung (~20 Tage).
 *  - IBAN nur, wenn der Kontoinhaber-Block auf DIESELBE Person laeuft.
 *  - Der Grundpreis steht hier je JAHR; die Kundenakte fuehrt ihn je Monat -
 *    umgerechnet wird deterministisch (/12), beide Werte stehen in der
 *    Zusammenfassung.
 */
class EnergiePortalAuftragParser implements DocumentTemplateParser
{
    use RepairsOcrText;
    use ValidatesExtractedFields;

    /** Stadtwerke-Wechsel: 14 Tage Kuendigungsfrist + Bearbeitung. */
    private const EXPECTED_START_DAYS = 20;

    /**
     * Beschriftungen dieser Portal-Ansicht - LAENGSTE ZUERST, damit
     * "bish. Kundennummer" vor "Kundennummer" und "Anschrift des
     * Kontoinhaber" vor "Kontoinhaber" greift. Sie dienen doppelt: als
     * Suchbegriff und als ENDE-Marke des davorstehenden Wertes.
     *
     * Enthalten sind auch die BEDIENELEMENTE der Ansicht ("Übersicht",
     * "Dokumente", "Anfrage zum Vertrag") - sie tragen keinen Inhalt, stehen
     * aber in derselben OCR-Zeile und wuerden sonst als Anschrift gelesen.
     *
     * Und bewusst MEHRERE Schreibweisen je Angabe: dasselbe Feld heisst je
     * Portal/Anbieter "Lieferbeginn", "gew. Lieferdatum", "Neueinzug zum"
     * oder "Beginn der Belieferung". Eine Erkennung, die nur EINE Schreibweise
     * kennt, liest denselben Auftrag beim naechsten Anbieter nicht mehr.
     *
     * @var list<string>
     */
    private const KNOWN_LABELS = [
        'Anschrift des Kontoinhaber', 'Beginn der Belieferung',
        'bisherige Kundennummer', 'Belieferungsanschrift',
        'Anfrage zum Vertrag', 'Vertragskontonummer',
        'Belieferungsbeginn', 'Unterschriftsdatum', 'Vorjahresverbrauch',
        'bish. Kundennummer', 'gew. Lieferbeginn', 'gew. Lieferdatum',
        'Auftragseingang', 'Auftragsnummer', 'Referenznummer',
        'Tarifübersicht', 'Vertragsbeginn', 'Vertragsnummer',
        'Auftragsdatum', 'Bestellnummer', 'Eingangsdatum',
        'Marktlokation', 'Netzbetreiber', 'Neueinzug zum',
        'Arbeitspreis', 'Einzugsdatum', 'Geburtsdatum', 'Kontoinhaber',
        'Kundennummer', 'Lieferbeginn', 'Messlokation', 'Referenz-Nr.',
        'Vorversorger', 'Zählernummer', 'Belieferung', 'Lieferdatum',
        'Mobilnummer', 'Zusatzinfos', 'Einzug zum', 'Geburtstag',
        'Grundpreis', 'Tarifdaten', 'geboren am', 'Dokumente',
        'Neueinzug', 'Übersicht', 'Abnehmer', 'Anbieter', 'Tariftyp',
        'MaLo-ID', 'Produkt', 'Telefon', 'Zahlung', 'E-Mail', 'Status',
        'Handy', 'Konto', 'Mobil', 'IBAN', 'MaLo', 'Mail', 'MeLo', 'BIC',
        'BLZ', 'Fax', 'Tel',
    ];

    private string $text = '';

    /** @var list<string> */
    private array $lines = [];

    /** Hinweis zur Bankverbindung fuer die Zusammenfassung (Abweichung o.ae.). */
    private ?string $bankHinweis = null;

    /** Erkennungssicherheit je Feld - siehe App\Support\FieldRecognition. */
    private FieldRecognition $felder;

    /** Lieferbeginn stammt aus einem EINZUG (Neuanschluss), nicht aus einem Wechsel. */
    private bool $einzug = false;

    public function parse(string $text): ?array
    {
        $this->felder = new FieldRecognition;
        $this->text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($this->text);

        // Nur diese Portal-Uebersicht: die Auftragsnummer-Beschriftung UND
        // eine der Portal-Ueberschriften. Die PDF-Auftraege der Versorger
        // (EWE, LichtBlick, PLAN-B) haben eigene Parser und tragen diese
        // Kombination nicht.
        if (! str_contains($upper, 'AUFTRAGSNUMMER')
            || (! str_contains($upper, 'TARIFÜBERSICHT')
                && ! str_contains($upper, 'BELIEFERUNGSANSCHRIFT'))) {
            return null;
        }

        $this->lines = array_map('rtrim', preg_split('/\R/', $this->text) ?: []);

        $energie = $this->parseEnergy();
        $person = $this->parsePerson();
        if ($energie === [] && $person === []) {
            return null;
        }
        $bank = $this->parseBank($person);
        $insurance = $this->parseContract($energie);

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $art = ($insurance['sparte'] ?? 'strom') === 'gas' ? 'Gas' : 'Strom';

        return [
            'type' => 'energieauftrag',
            'confidence' => 72,
            'summary' => $art.'-Auftrag (Vertriebsportal)'
                .(isset($insurance['insurer']) ? ' - '.$insurance['insurer'] : '')
                .(isset($energie['tariff']) ? ' ('.$energie['tariff'].')' : '')
                .($name !== '' ? ' - '.$name : '')
                .$this->extras($energie, $insurance)
                .($bank !== [] ? ' Bankverbindung des Kunden uebernommen.' : ' Ohne Bankuebernahme.')
                .($this->bankHinweis !== null ? ' HINWEIS: '.$this->bankHinweis.'.' : '')
                .' Felder gratis aus der Auftragsuebersicht gelesen (ohne KI).',
            'title' => ($insurance['insurer'] ?? 'Energie').' '.$art.'-Auftrag'
                .($name !== '' ? ' '.$name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => $bank,
                'personen' => [],
                'energie' => $energie,
                // Erkennungssicherheit JE FELD - der Mitarbeiter soll im
                // Review nur die unsicheren Angaben kontrollieren muessen,
                // nicht alle. Bewusst eine eigene Gruppe: sie traegt keine
                // Kundendaten und wird von keiner Uebernahme angefasst.
                FieldRecognition::KEY => $this->felder->toArray(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function parsePerson(): array
    {
        $raw = [];

        // Name: "Herr <Vorname(n)> <Nachname>" - irgendwo in der Zeile, denn
        // links davon kann eine Zelle der Nachbarspalte stehen ("Tarifübersicht
        // Herr Max Muster"). Ein direkt folgendes zweites "Herr ..." ist die
        // Kontoinhaber-Spalte und gehoert NICHT zum Namen.
        $nameLine = null;
        foreach ($this->lines as $i => $line) {
            if (preg_match($this->nameRegex(), $line, $m)) {
                $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
                $parts = preg_split('/\s+/u', trim($m[2])) ?: [];
                $raw['last_name'] = array_pop($parts);
                $raw['first_name'] = implode(' ', $parts) ?: null;
                $nameLine = $i;
                break;
            }
        }

        // Anschrift: unter dem Anschriftenblock bzw. unter dem Namen. Beide
        // Anker werden probiert (die OCR ordnet die Spalten mal nebeneinander,
        // mal blockweise untereinander).
        $anker = [];
        foreach ($this->lines as $i => $line) {
            if (mb_stripos($line, 'Belieferungsanschrift') !== false
                || ($nameLine !== null && preg_match($this->nameRegex(), $line))) {
                $anker[] = $i;
            }
        }
        foreach ($anker as $start) {
            $found = [];
            $end = min(count($this->lines), $start + 7);
            for ($j = $start + 1; $j < $end; $j++) {
                foreach ($this->cells($this->lines[$j]) as $cell) {
                    if (! isset($found['street'])
                        && preg_match('/(\p{Lu}[\p{L}.\-\']*(?:\s+\p{L}[\p{L}.\-\']*){0,3})\s+(\d{1,4}\s*[a-zA-Z]?)(?![\p{L}\d])/u', $cell, $s)
                        && $this->looksLikeStreet($s[1])) {
                        $found['street'] = trim($s[1]);
                        $found['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
                        continue;
                    }
                    if (! isset($found['zip'])
                        && preg_match('/(?<!\d)(\d{5})\s+(\p{Lu}[\p{L}.\-]+(?:[ \-]\p{Lu}?[\p{L}.\-]+){0,2})/u', $cell, $z)) {
                        $found['zip'] = $z[1];
                        $found['city'] = trim($z[2]);
                    }
                }
                if (isset($found['street'], $found['zip'])) {
                    break;
                }
            }
            // Nur ein VOLLSTAENDIGER Block (Strasse UND PLZ/Ort) zaehlt -
            // sonst waere z.B. die Reiterleiste ("Dokumente 1") eine Adresse.
            if (isset($found['street'], $found['zip'])) {
                $raw += $found;
                break;
            }
        }

        // Geburtsdatum: je Portal "geboren am", "Geburtsdatum" oder
        // "Geburtstag" - alle drei meinen dasselbe Feld.
        foreach (['geboren am', 'Geburtsdatum', 'Geburtstag'] as $label) {
            if (($v = $this->labelValue($label)) !== null
                && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
                $raw['birth_date'] = $m[3].'-'.$m[2].'-'.$m[1];
                break;
            }
        }
        foreach (['Tel', 'Telefon', 'Mobil', 'Mobilnummer', 'Handy'] as $label) {
            if (($v = $this->labelValue($label)) !== null
                && ($nummer = $this->normalizePhone($v)) !== null) {
                $raw['phone'] = $nummer;
                break;
            }
        }

        // E-MAIL: die haeufigste Fehlstelle auf einem Screenshot - und sie
        // kann an ZWEI Stellen brechen, nicht nur an einer:
        //  (a) am WERT: das "@" liest Tesseract je nach Darstellung als
        //      "©"/"®"/"€" oder verdoppelt es;
        //  (b) an der BESCHRIFTUNG: "Mail:" steht im Portal unterstrichen,
        //      und ein Unterstrich verschmilzt beim Erkennen gern mit dem
        //      Wort ("Maii", "Mall", "MaiI"). Dann findet die Suche nach der
        //      Beschriftung gar nichts - obwohl die Adresse sauber dasteht.
        // Deshalb zuerst der beschriftete Weg, danach die Suche im ganzen
        // Dokument. Der zweite Weg ist bewusst der zweite: eine beschriftete
        // Adresse ist belegt, eine gefundene nur plausibel.
        [$email, $hinweis] = $this->email();
        if ($email !== null) {
            $raw['email'] = $email;
            if ($hinweis !== null) {
                $this->felder->pruefen('person.email', $hinweis);
            }
        }

        $person = $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
        $this->felder->gruppe('person', $person, [
            'first_name', 'last_name', 'birth_date', 'gender',
            'street', 'house_number', 'zip', 'city', 'phone', 'email',
        ]);

        return $person;
    }

    /**
     * IBAN/BIC nur, wenn der Block "Anschrift des Kontoinhaber" auf DIESELBE
     * Person laeuft - ein fremdes Konto gehoert nicht in die Kundenakte.
     * Verglichen werden ALLE Namen, die unter der Ueberschrift stehen: taucht
     * dort ein anderer Name auf, bleibt die Bankverbindung draussen (auch
     * wenn daneben - in der Nachbarspalte derselben Zeile - der Kundenname
     * steht).
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function parseBank(array $person): array
    {
        $voll = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        if ($voll === '' || ($person['last_name'] ?? '') === '') {
            return [];
        }

        $gehoertKunde = false;
        foreach ($this->lines as $i => $line) {
            if (mb_stripos($line, 'Kontoinhaber') === false) {
                continue;
            }
            $end = min(count($this->lines), $i + 7);
            for ($j = $i; $j < $end; $j++) {
                $namen = $this->namesIn($this->lines[$j], $j === $i);
                if ($namen === []) {
                    continue;
                }
                foreach ($namen as $n) {
                    if ($this->sameName($n, $voll)) {
                        $gehoertKunde = true;
                    } else {
                        return []; // dort steht ein ANDERER Kontoinhaber
                    }
                }
                break; // erste Namenszeile unter der Ueberschrift entscheidet
            }
            if ($gehoertKunde) {
                break;
            }
        }
        if (! $gehoertKunde) {
            return [];
        }

        $raw = [];
        $gedruckt = $this->ibanRoh();
        $gelesen = $this->ibanGelesen();
        $gerechnet = $this->ibanAusKontoUndBlz();

        // ZWEI unabhaengige Quellen fuer dieselbe Bankverbindung - das Portal
        // druckt die IBAN UND (separat) Kontonummer + BLZ. Eine deutsche IBAN
        // besteht genau daraus, also laesst sie sich aus der zweiten Quelle
        // NACHRECHNEN, Pruefziffern eingeschlossen. Das ist der Gewinn
        // gegenueber "22 Zeichen am Stueck fehlerfrei ablesen": zwei kurze
        // Zahlenfelder verliest die OCR selten beide zugleich.
        //
        // ABER: gerechnet ist nicht geprueft. Die Pruefziffer einer selbst
        // gerechneten IBAN stimmt IMMER - auch wenn die BLZ verlesen wurde.
        // Deshalb zaehlt eine gerechnete IBAN nur, wenn die abgedruckte sie
        // BESTAETIGT oder gar keine abgedruckt ist. Und wo sich zwei Quellen
        // widersprechen, wird NICHTS uebernommen: eine falsche Bankverbindung
        // kostet mehr als eine fehlende, und welche Angabe stimmt, entscheidet
        // kein Parser.
        $status = null;
        $iban = null;
        if ($gelesen !== null && $gerechnet !== null) {
            if ($gelesen === $gerechnet) {
                $iban = $gelesen;
                $status = [FieldRecognition::SICHER, null];
            } else {
                $this->bankHinweis = 'IBAN ('.$gelesen.') und die separat gedruckte Konto-/BLZ-Angabe ('
                    .$gerechnet.') widersprechen sich - keine Bankverbindung uebernommen, bitte manuell pruefen';
                $status = [FieldRecognition::WIDERSPRUCH, $this->bankHinweis];
            }
        } elseif ($gelesen !== null) {
            $iban = $gelesen;
            $status = [FieldRecognition::SICHER, null];
        } elseif ($gerechnet !== null) {
            if ($gedruckt === null) {
                $iban = $gerechnet;
                $status = [FieldRecognition::PRUEFEN, 'aus Kontonummer + BLZ berechnet (eine IBAN war nicht abgedruckt)'];
                $this->bankHinweis = 'IBAN aus Kontonummer + BLZ berechnet - bitte pruefen';
            } elseif ($this->gleicheKontoverbindung($gedruckt, $gerechnet)) {
                // Die abgedruckte IBAN war nur an einzelnen Zeichen unlesbar
                // und deckt sich sonst mit Konto + BLZ - zwei Quellen, ein
                // Ergebnis. Genau das ist eine belegte Reparatur, kein Raten.
                $iban = $gerechnet;
                $status = [FieldRecognition::PRUEFEN,
                    'abgedruckte IBAN war nicht sauber lesbar, deckt sich aber mit Kontonummer + BLZ'];
                $this->bankHinweis = 'IBAN war im Bild nicht sauber lesbar und wurde aus Kontonummer + BLZ berechnet - bitte pruefen';
            } else {
                $this->bankHinweis = 'abgedruckte IBAN und die Konto-/BLZ-Angabe passen nicht zusammen'
                    .' - keine Bankverbindung uebernommen, bitte manuell pruefen';
                $status = [FieldRecognition::WIDERSPRUCH, $this->bankHinweis];
            }
        } elseif ($gedruckt !== null) {
            $this->bankHinweis = 'IBAN im Bild nicht eindeutig lesbar (Pruefziffer stimmt nicht) - nicht uebernommen';
            $status = [FieldRecognition::PRUEFEN, $this->bankHinweis];
        } else {
            $status = [FieldRecognition::FEHLT, null];
        }
        $this->felder->set('bank.iban', $status[0], $status[1]);

        if ($iban === null) {
            return [];
        }
        $raw['iban'] = $iban;

        // BIC nur im offiziellen Format (4 Buchstaben Bank, 2 Land, 2 Ort,
        // optional 3 Filiale).
        if (($v = $this->labelValue('BIC')) !== null
            && preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/', strtoupper(trim($v)))) {
            $raw['bic'] = strtoupper(trim($v));
        }
        $raw['account_holder'] = $voll;

        return $this->validatedBank($raw);
    }

    /**
     * E-Mail des Kunden als [Adresse, Pruefhinweis].
     *
     * Reihenfolge ist Absicht: erst die Beschriftung ("Mail"/"E-Mail"), dann
     * - nur wenn die nichts liefert - die Suche im ganzen Dokument. Eine
     * beschriftete Adresse ist BELEGT; eine bloss gefundene ist plausibel und
     * wird deshalb immer als "bitte pruefen" gekennzeichnet.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function email(): array
    {
        foreach (['Mail', 'E-Mail'] as $label) {
            if (($v = $this->labelValue($label)) === null) {
                continue;
            }
            if (preg_match('/[\w.+\-]+@[\w.\-]+\.\w{2,}/u', $v, $m)) {
                return [mb_strtolower($m[0]), null];
            }
            if (($repariert = $this->ocrEmail($v)) !== null) {
                return [$repariert, 'Zeichen der Adresse waren nicht eindeutig lesbar und wurden korrigiert'];
            }
        }

        // Rueckfallebene: die erste Adresse im Dokument, die dem KUNDEN
        // gehoeren kann. Adressen des Versorgers oder des eigenen Hauses
        // werden ausgeschlossen - sonst stuende der Kundenservice des
        // Anbieters als Kontakt in der Kundenakte.
        foreach ($this->lines as $line) {
            $kandidat = $this->ocrEmail($line);
            if ($kandidat !== null && ! $this->istFremdadresse($kandidat)) {
                return [$kandidat, 'ohne Beschriftung im Dokument gefunden - bitte pruefen, ob sie dem Kunden gehoert'];
            }
        }

        return [null, null];
    }

    /**
     * Gehoert die Adresse erkennbar NICHT dem Kunden? Zwei Merkmale, beide
     * konservativ: ein typisches Sammelpostfach als Empfaenger, oder eine
     * Domain, die den Namen des Versorgers bzw. unseres eigenen Hauses traegt.
     * Im Zweifel gilt eine Adresse als Kundenadresse - der Mitarbeiter sieht
     * sie im Review ohnehin als "bitte pruefen".
     */
    private function istFremdadresse(string $email): bool
    {
        [$lokal, $domain] = array_pad(explode('@', mb_strtolower($email), 2), 2, '');
        if ($domain === '') {
            return true;
        }
        $sammelpostfach = [
            'info', 'service', 'kontakt', 'support', 'hilfe', 'kundenservice',
            'kundenbetreuung', 'post', 'mail', 'noreply', 'no-reply', 'datenschutz',
            'impressum', 'vertrieb', 'buchhaltung', 'widerruf', 'presse', 'team',
        ];
        if (in_array(preg_replace('/[^a-z\-]/', '', $lokal) ?? '', $sammelpostfach, true)) {
            return true;
        }

        // Domain-Kern ("plan-b-energie.de" -> "planbenergie") gegen den Namen
        // des Anbieters und gegen die eigene Domain halten.
        $kern = static fn (string $v): string => (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($v));
        $teile = explode('.', $domain);
        $domainKern = $kern($teile[count($teile) - 2] ?? $domain);
        if ($domainKern === '' || str_contains($domainKern, 'dienstly24')) {
            return true;
        }

        $anbieter = $kern((string) ($this->kopfzeile()['anbieter'] ?? $this->labelValue('Anbieter') ?? ''));
        if ($anbieter === '' || mb_strlen($domainKern) < 4) {
            return false;
        }

        return str_contains($anbieter, $domainKern) || str_contains($domainKern, $anbieter);
    }

    /**
     * Der ROHWERT der IBAN-Zeile (nur Buchstaben/Ziffern, ohne jede Pruefung).
     * Er beantwortet allein die Frage "stand ueberhaupt eine IBAN da?" - und
     * dient als Gegenprobe fuer eine gerechnete IBAN.
     */
    private function ibanRoh(): ?string
    {
        $v = $this->labelValue('IBAN');
        if ($v === null) {
            return null;
        }
        $roh = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $v));

        return mb_strlen($roh) >= 20 ? $roh : null;
    }

    /**
     * Beschreiben zwei IBAN dieselbe Kontoverbindung, wenn man typische
     * OCR-Verwechslungen (B/8, O/0, I/1 ...) zulaesst? Verglichen wird nur
     * der KONTOTEIL (BLZ + Kontonummer) - die beiden Pruefziffern sind bei
     * der gerechneten IBAN ohnehin neu bestimmt und taugen nicht zum Beleg.
     */
    private function gleicheKontoverbindung(string $a, string $b): bool
    {
        $bban = static function (string $iban): string {
            $ziffern = strtr(mb_substr($iban, 4), self::OCR_ZIFFERN);

            return (string) preg_replace('/\D/', '', $ziffern);
        };

        return $bban($a) !== '' && $bban($a) === $bban($b);
    }

    /**
     * Die ABGEDRUCKTE IBAN - mit Reparatur typischer OCR-Verwechslungen
     * (B/8, O/0, I/1, S/5 ...) und PFLICHT-Pruefziffer. Ohne gueltige
     * Pruefziffer gibt es keinen Rueckgabewert: eine geratene Bankverbindung
     * darf nie entstehen.
     */
    private function ibanGelesen(): ?string
    {
        $v = $this->labelValue('IBAN');
        if ($v === null) {
            return null;
        }
        $iban = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $v));
        if (preg_match('/^DE\d{20}$/', $iban) && $this->ibanChecksumValid($iban)) {
            return $iban;
        }

        // Zeichenweise Reparatur des Trait (gleiche Regel wie beim
        // Kontakt-Screenshot) - uebernommen wird auch hier nur, was die
        // Pruefziffer besteht.
        return $this->ocrGermanIban($v);
    }

    /**
     * IBAN aus den SEPARAT gedruckten Feldern "Konto" und "BLZ" berechnen.
     *
     * Eine deutsche IBAN ist rein rechnerisch: DE + zwei Pruefziffern +
     * 8-stellige BLZ + 10-stellige Kontonummer (links mit Nullen gefuellt).
     * Die Pruefziffern ergeben sich nach ISO 7064 aus dem Rest - sie werden
     * also NICHT abgelesen, sondern gerechnet. Damit haengt die
     * Bankverbindung nicht mehr daran, dass die OCR 22 Zeichen am Stueck
     * fehlerfrei liest.
     *
     * Die Klammerzusaetze der Zeile ("43050001 (Sparkasse Bochum)") stoeren
     * nicht: gelesen wird die erste Ziffernfolge passender Laenge.
     */
    private function ibanAusKontoUndBlz(): ?string
    {
        $blz = (string) preg_replace('/\D/', '', (string) $this->labelValue('BLZ'));
        $konto = (string) preg_replace('/\D/', '', (string) $this->labelValue('Konto'));
        if (strlen($blz) < 8 || $konto === '' || strlen($konto) > 10) {
            return null;
        }
        $bban = substr($blz, 0, 8).str_pad($konto, 10, '0', STR_PAD_LEFT);

        // Pruefziffer: 98 - (BBAN + "DE00" als Zahl) mod 97.
        $zahl = $bban.'131400';
        $rest = 0;
        foreach (str_split($zahl, 7) as $block) {
            $rest = (int) ((string) $rest.$block) % 97;
        }
        $iban = 'DE'.str_pad((string) (98 - $rest), 2, '0', STR_PAD_LEFT).$bban;

        return $this->ibanChecksumValid($iban) ? $iban : null;
    }

    /**
     * Kopfzeile der Ansicht: "<Auftragsnummer> - <Anbieter> - <Produkt>".
     * Sie ist gross gesetzt und damit die ZUVERLAESSIGSTE Quelle - in der
     * Tarif-Tabelle verliest die OCR den Produktnamen gern ("orodukt ea 2a").
     *
     * @return array{nummer: ?string, anbieter: ?string, produkt: ?string}
     */
    private function kopfzeile(): array
    {
        foreach (array_slice($this->lines, 0, 4) as $line) {
            if (preg_match('/(?:^|\s)(\d{5,12})\s*[-–]\s*([^-–]{3,60}?)\s*[-–]\s*(\S[^\n]{2,60})$/u', trim($line), $m)) {
                return [
                    'nummer' => $m[1],
                    'anbieter' => trim($m[2]),
                    'produkt' => trim($m[3]),
                ];
            }
        }
        return ['nummer' => null, 'anbieter' => null, 'produkt' => null];
    }

    /** @return array<string,mixed> */
    private function parseEnergy(): array
    {
        $raw = [];

        // Produktname bevorzugt aus der KOPFZEILE: sie ist gross gesetzt und
        // wird sauber gelesen, waehrend die kleine Tarif-Tabelle im Bild gern
        // verstuemmelt ankommt ("Fair ö 24" statt "Fair Ökostrom 24").
        $raw['tariff'] = $this->kopfzeile()['produkt'] ?? $this->labelValue('Produkt');
        $raw['grid_operator'] = $this->labelValue('Netzbetreiber');
        $raw['previous_provider'] = $this->labelValue('Vorversorger');

        if (($v = $this->labelValue('MaLo-ID') ?? $this->labelValue('MaLo')) !== null
            && preg_match('/\b(\d{11})\b/', $v, $m)) {
            $raw['malo_id'] = $m[1];
        }
        if (($v = $this->labelValue('Zählernummer')) !== null
            && preg_match('/^[\w\- ]{4,30}$/u', trim($v))) {
            $raw['meter_number'] = trim($v);
        }
        // Kundennummer beim BISHERIGEN Versorger (das leere Formularfeld
        // "Kundennummer" der Eingabemaske bleibt unberuehrt).
        if (($v = $this->labelValue('bish. Kundennummer') ?? $this->labelValue('bisherige Kundennummer')) !== null
            && preg_match('/^[\w\-]{4,40}$/u', trim($v))) {
            $raw['previous_customer_number'] = trim($v);
        }
        // Vorjahresverbrauch (ggf. mit Zaehlwerk-Kuerzel "HT"/"NT") - der
        // Wert steht je nach OCR mit einem oder mehreren Leerzeichen dahinter.
        if (preg_match('/Vorjahresverbrauch(?:\s+[A-Z]{2})?\s*:?\s+([\d.]+)\s*kWh/u', $this->text, $m)) {
            $raw['consumption_kwh'] = (int) str_replace('.', '', $m[1]);
        }
        // Arbeitspreis "32,45 ct / kWh".
        if (($v = $this->labelValue('Arbeitspreis')) !== null
            && preg_match('/([\d.]+,\d+)\s*(?:ct|cent)/iu', $v, $m)) {
            $raw['working_price'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
        }
        // Grundpreis: die Kundenakte fuehrt ihn je MONAT - "je Jahr" wird
        // deterministisch umgerechnet (beide Werte in der Zusammenfassung).
        [$monat] = $this->grundpreis();
        if ($monat !== null) {
            $raw['base_price'] = $monat;
        }
        // Kundennummer BEIM ANBIETER (nicht die des Vorversorgers und nicht
        // die Auftragsnummer) - sie ist eine der Kennungen, ueber die eine
        // spaetere Abrechnung ihren Vertrag wiederfindet. In der leeren
        // Eingabemaske des Portals steht das Feld ohne Wert; labelValue
        // liefert dann null und es wird nichts gesetzt.
        $auftrag = trim((string) ($this->labelValue('Auftragsnummer') ?? $this->kopfzeile()['nummer'] ?? ''));
        foreach (['Kundennummer', 'Vertragskontonummer'] as $label) {
            $v = trim((string) $this->labelValue($label));
            if ($v === '' || ! preg_match('/^[\w\-\/]{4,40}$/u', $v)) {
                continue;
            }
            // Nie die Auftragsnummer und nie die Nummer des Vorversorgers
            // recyceln - das waeren zwei verschiedene Dinge unter einem Namen.
            if ($v === $auftrag || $v === ($raw['previous_customer_number'] ?? null)) {
                continue;
            }
            $raw['customer_number'] = $v;
            break;
        }

        $energie = $this->validatedEnergy(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
        $this->felder->gruppe('energie', $energie, [
            'tariff', 'meter_number', 'malo_id', 'consumption_kwh',
            'working_price', 'base_price', 'grid_operator',
        ]);

        return $energie;
    }

    /**
     * Grundpreis als [EUR/Monat, Originaltext] - "167,80 € / Jahr" wird zu
     * 13,98 EUR/Monat, "13,35 €/Monat" bleibt unveraendert.
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function grundpreis(): array
    {
        $v = $this->labelValue('Grundpreis');
        if ($v === null || ! preg_match('/([\d.]+,\d{2})/', $v, $m)) {
            return [null, null];
        }
        $betrag = (float) str_replace(['.', ','], ['', '.'], $m[1]);
        if (preg_match('/Jahr/iu', $v)) {
            return [round($betrag / 12, 2), trim($v)];
        }
        return [$betrag, trim($v)];
    }

    /**
     * @param array<string,mixed> $energie
     * @return array<string,mixed>
     */
    private function parseContract(array $energie): array
    {
        $raw = [
            // Auftragsnummer des Portals = Referenz des Vorgangs (KEINE
            // Vertragsnummer) - die Bruecke zur spaeteren Vertragsbestaetigung.
            'reference_number' => $this->labelValue('Auftragsnummer') ?? $this->kopfzeile()['nummer'],
            'insurer' => $this->kopfzeile()['anbieter'] ?? $this->labelValue('Anbieter'),
            'tariff' => $energie['tariff'] ?? null,
            // Ein Auftrag ist noch keine Bestaetigung.
            'document_stage' => Contract::STAGE_ANTRAG,
        ];

        // Sparte aus dem Feld "Tariftyp"; verliest die OCR die Beschriftung
        // ("Tarityp"), entscheidet der Produktname ("Fair Ökostrom 24").
        $typ = mb_strtolower((string) ($this->labelValue('Tariftyp') ?? ''));
        $produkt = mb_strtolower((string) ($raw['tariff'] ?? ''));
        $raw['sparte'] = match (true) {
            str_contains($typ, 'gas'), str_contains($produkt, 'gas') => 'gas',
            str_contains($typ, 'strom'), str_contains($produkt, 'strom') => 'strom',
            default => 'strom',
        };

        // LIEFERBEGINN - NUR als echtes Datum ("schnellstmoeglich" ist keins).
        //
        // Und bewusst ueber MEHRERE Schreibweisen: derselbe Sachverhalt heisst
        // je nach Portal und je nach Vorgangsart anders. Beim WECHSEL steht
        // "gew. Lieferdatum"/"Lieferbeginn", beim EINZUG in eine neue Wohnung
        // dagegen "Neueinzug zum" - genau daran scheiterte der gemeldete
        // Auftrag: das Datum stand gut lesbar im Dokument, die Erkennung kannte
        // nur die Wechsel-Beschriftung und der Vertrag blieb ohne Beginn.
        $this->einzug = false;
        foreach (['gew. Lieferbeginn', 'gew. Lieferdatum', 'Beginn der Belieferung',
            'Belieferungsbeginn', 'Lieferbeginn', 'Lieferdatum',
            'Neueinzug zum', 'Neueinzug', 'Einzug zum', 'Einzugsdatum',
            'Vertragsbeginn'] as $label) {
            $v = $this->labelValue($label);
            if ($v === null || ! preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
                continue;
            }
            $raw['start_date'] = $m[3].'-'.$m[2].'-'.$m[1];
            $this->einzug = str_contains(mb_strtolower($label), 'einzug');
            break;
        }
        if (! isset($raw['start_date'])
            && preg_match('/stadtwerke/iu', (string) ($energie['previous_provider'] ?? ''))) {
            // Stadtwerke-Wechsel: 14 Tage Kuendigungsfrist + Bearbeitung.
            $raw['expected_start_within_days'] = self::EXPECTED_START_DAYS;
        }

        $insurance = $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
        $this->felder->gruppe('versicherung', $insurance, [
            'insurer', 'reference_number', 'sparte', 'tariff', 'start_date',
        ]);

        return $insurance;
    }

    /**
     * Zusatzangaben fuer die Zusammenfassung - darunter die Auftragsnummer,
     * die BEWUSST keine Vertragsnummer ist.
     *
     * @param array<string,mixed> $energie
     * @param array<string,mixed> $insurance
     */
    private function extras(array $energie, array $insurance): string
    {
        $out = '.';
        $nummer = $this->labelValue('Auftragsnummer') ?? $this->kopfzeile()['nummer'];
        if ($nummer !== null && preg_match('/^[\w\-]{4,30}$/u', trim($nummer))) {
            $out .= ' Auftragsnummer '.trim($nummer).' (keine Vertragsnummer - die bringt erst die Vertragsbestaetigung).';
        }
        if (isset($energie['malo_id'])) {
            $out .= ' MaLo-ID '.$energie['malo_id'].'.';
        }
        if (isset($energie['meter_number'])) {
            $out .= ' Zaehlernummer '.$energie['meter_number'].'.';
        }
        if (isset($energie['previous_provider'])) {
            $out .= ' Wechsel von '.$energie['previous_provider']
                .(isset($energie['previous_customer_number']) ? ' (Kd.-Nr. '.$energie['previous_customer_number'].')' : '')
                .'.';
        }
        if (isset($energie['consumption_kwh'])) {
            $out .= ' Vorjahresverbrauch '.number_format($energie['consumption_kwh'], 0, ',', '.').' kWh/Jahr.';
        }
        if (isset($energie['working_price'])) {
            $out .= ' Arbeitspreis '.number_format($energie['working_price'], 2, ',', '.').' ct/kWh.';
        }
        [$monat, $original] = $this->grundpreis();
        if ($monat !== null && $original !== null) {
            $out .= ' Grundpreis '.$original
                .(preg_match('/Jahr/iu', $original)
                    ? ' = '.number_format($monat, 2, ',', '.').' EUR/Monat (fuer die Kundenakte umgerechnet)'
                    : '')
                .'.';
        }
        foreach ([
            'Vertragslaufzeit' => '/(\d+)\s+Monate?\s+Vertragslaufzeit/u',
            'Preisgarantie' => '/(\d+)\s+Monate?\s+[\w\-]*Preisgarantie/u',
            'Kündigungsfrist' => '/(\d+)\s+Monate?\s+Kündigungsfrist/u',
        ] as $label => $re) {
            if (preg_match($re, $this->text, $m)) {
                $out .= ' '.$label.': '.$m[1].' Monat'.((int) $m[1] === 1 ? '' : 'e').'.';
            }
        }
        if (($v = $this->labelValue('Status')) !== null) {
            $out .= ' Portal-Status: '.$v.'.';
        }
        if (($v = $this->labelValue('Unterschriftsdatum')) !== null) {
            $out .= ' Unterschrieben am '.$v.'.';
        }
        if (($v = $this->labelValue('Abnehmer')) !== null) {
            $out .= ' Abnehmer: '.$v.'.';
        }
        if (($v = $this->labelValue('Zahlung')) !== null) {
            $out .= ' Zahlung: '.$v.'.';
        }
        if ($this->einzug && isset($insurance['start_date'])) {
            $out .= ' Neueinzug (Neuanschluss, kein Anbieterwechsel) zum '
                .date('d.m.Y', strtotime($insurance['start_date'])).'.';
        }
        if (($v = $this->labelValue('Auftragseingang') ?? $this->labelValue('Auftragsdatum')
            ?? $this->labelValue('Eingangsdatum')) !== null) {
            $out .= ' Auftragseingang: '.$v.'.';
        }
        if (isset($energie['customer_number'])) {
            $out .= ' Kundennummer beim Anbieter: '.$energie['customer_number'].'.';
        }
        if (isset($insurance['expected_start_within_days'])) {
            $out .= ' Lieferbeginn nicht angegeben: voraussichtlich binnen ~'
                .$insurance['expected_start_within_days']
                .' Tagen (Kuendigungsfrist Stadtwerke 14 Tage + Bearbeitung).';
        }
        return $out;
    }

    /**
     * Regex fuer "Herr/Frau <Vorname(n)> <Nachname>". Die Namensteile duerfen
     * KEINE weitere Anrede sein - sonst verschluckt der Ausdruck bei
     * zusammengeschobenen Spalten ("Herr Max Muster Herr Max Muster") den
     * zweiten Eintrag.
     */
    private function nameRegex(): string
    {
        return '/(?<![\p{L}])(Herrn?|Frau)\s+(\p{Lu}[\p{L}\-\']+(?:\s+(?!Herrn?\b|Frau\b)\p{Lu}[\p{L}\-\']+){1,3})/u';
    }

    /**
     * Alle Namen einer Zeile ("Herr A B  Frau C D"). Auf der Zeile MIT der
     * Ueberschrift zaehlt nur, was RECHTS davon steht - links steht die
     * Nachbarspalte (Belieferungsanschrift).
     *
     * @return list<string>
     */
    private function namesIn(string $line, bool $nurNachUeberschrift = false): array
    {
        if ($nurNachUeberschrift) {
            $pos = mb_stripos($line, 'Kontoinhaber');
            $line = $pos === false ? '' : mb_substr($line, $pos + mb_strlen('Kontoinhaber'));
        }
        if (! preg_match_all($this->nameRegex(), $line, $all, PREG_SET_ORDER)) {
            return [];
        }
        return array_map(fn (array $m) => trim($m[2]), $all);
    }

    /** Namensvergleich ohne Gross-/Kleinschreibung und Mehrfach-Leerzeichen. */
    private function sameName(string $a, string $b): bool
    {
        $norm = fn (string $v) => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $v)));
        return $norm($a) === $norm($b);
    }

    /**
     * Sieht die Zelle wie ein Strassenname aus? Entweder mit typischem
     * Grundwort (Strasse/Weg/Allee ...) oder mehrwortig - so wird die
     * Reiterleiste des Portals ("Dokumente 1") nie zur Anschrift.
     */
    private function looksLikeStreet(string $name): bool
    {
        $n = trim($name);
        if (preg_match('/(stra(?:ß|ss)e|str\.|weg|allee|platz|ring|gasse|damm|chaussee|ufer|steig|kamp|hof|feld)$/iu', $n)) {
            return true;
        }
        return preg_match('/\p{L}{3,}/u', $n) === 1 && count(preg_split('/\s+/u', $n) ?: []) >= 2;
    }

    /** "+49 0176 23681009" -> "017623681009"; sonst null. */
    private function normalizePhone(string $value): ?string
    {
        $d = (string) preg_replace('/[^\d+]/', '', $value);
        if (preg_match('/^(?:\+|00)49(\d+)$/', $d, $m)) {
            $d = '0'.ltrim($m[1], '0');
        }
        return preg_match('/^0\d{8,14}$/', $d) ? $d : null;
    }

    /**
     * Wert hinter einer Beschriftung. Die Beschriftung darf MITTEN in der
     * Zeile stehen (Nachbarspalte davor); der Wert endet an der naechsten
     * bekannten Beschriftung oder am naechsten Spaltenabstand. Steht in der
     * Zeile nichts mehr, gilt die naechste nicht-leere Zeile.
     */
    private function labelValue(string $label): ?string
    {
        $re = '/(?<![\p{L}\d])'.preg_quote($label, '/').'(?![\p{L}\d])\s*:?\s*(.*)$/u';
        foreach ($this->lines as $i => $line) {
            if (! preg_match($re, $line, $m)) {
                continue;
            }
            $wert = $this->cutAtNextLabel(trim($m[1]), $label);
            $wert = trim((string) (preg_split('/\s{2,}/u', $wert)[0] ?? ''));
            if ($wert !== '') {
                return $wert;
            }
            // Beschriftung allein in der Zeile -> Wert steht darunter (nur
            // wenn dort nicht schon die naechste Beschriftung beginnt).
            for ($j = $i + 1, $n = min(count($this->lines), $i + 3); $j < $n; $j++) {
                $next = trim($this->lines[$j]);
                if ($next === '') {
                    continue;
                }
                $kandidat = trim((string) (preg_split('/\s{2,}/u', $this->cutAtNextLabel($next, $label))[0] ?? ''));
                return ($kandidat === '' || $kandidat !== $next) ? null : $kandidat;
            }
        }
        return null;
    }

    /**
     * Schneidet den Wert dort ab, wo die naechste bekannte Beschriftung
     * beginnt - oder wo eine Anschrift der Nachbarspalte anfaengt ("… 24768
     * Rendsburg"): eine PLZ gehoert in dieser Ansicht nie zu einem Feldwert.
     */
    private function cutAtNextLabel(string $value, string $current): string
    {
        $ende = null;
        if (preg_match('/(?<![\d.,])\d{5}\s+\p{Lu}/u', $value, $plz, PREG_OFFSET_CAPTURE)) {
            $ende = mb_strlen(substr($value, 0, $plz[0][1]));
        }
        foreach (self::KNOWN_LABELS as $label) {
            if ($label === $current) {
                continue;
            }
            if (preg_match('/(?<![\p{L}\d])'.preg_quote($label, '/').'(?![\p{L}\d])/u', $value, $m, PREG_OFFSET_CAPTURE)) {
                $pos = mb_strlen(substr($value, 0, $m[0][1]));
                $ende = $ende === null ? $pos : min($ende, $pos);
            }
        }
        return $ende === null ? $value : trim(mb_substr($value, 0, $ende));
    }

    /**
     * Zellen einer Zeile fuer die Anschrift-Suche: bekannte Beschriftungen
     * werden entfernt (sie gehoeren zur Nachbarspalte), danach wird am
     * Spaltenabstand getrennt und ein doppelt gesetzter Text ("X X" aus zwei
     * Spalten) auf EIN Vorkommen reduziert.
     *
     * @return list<string>
     */
    private function cells(string $line): array
    {
        foreach (self::KNOWN_LABELS as $label) {
            $line = (string) preg_replace(
                '/(?<![\p{L}\d])'.preg_quote($label, '/').'(?![\p{L}\d])\s*:?/u',
                '  ',
                $line
            );
        }
        $out = [];
        foreach (preg_split('/\s{2,}/u', trim($line)) ?: [] as $cell) {
            $cell = trim($cell);
            if ($cell === '') {
                continue;
            }
            // "Alte Kieler Landstr. 141 Alte Kieler Landstr. 141" -> einmal.
            if (preg_match('/^(.{3,}?)\s+\1$/u', $cell, $m)) {
                $cell = trim($m[1]);
            }
            $out[] = $cell;
        }
        return $out;
    }
}
