<?php

namespace App\Services\Ai\Concerns;

/**
 * Gemeinsamer Baustein fuer die beiden deutschen Ausweiskarten im
 * Scheckkartenformat: den elektronischen AUFENTHALTSTITEL (eAT) und den
 * PERSONALAUSWEIS. Ihre RUECKSEITEN sind baugleich - die maschinenlesbare
 * Zone (MRZ, ICAO 9303 TD1: drei Zeilen a 30 Zeichen) und der
 * Anschrift-Aufkleber. Unterschieden werden sie an Zeile 1 der MRZ ("AR" =
 * Aufenthaltstitel, "ID" = Personalausweis).
 *
 * WARUM EIN GEMEINSAMER BAUSTEIN (Betreiber-Meldung 30.09.2026: "die
 * Rueckseite wird nicht erkannt"): die Rueckseite hing am Aufenthaltstitel-
 * Parser allein, und der erkannte sie NUR an einer fehlerfrei gelesenen
 * MRZ-Datenzeile. Gemessen an echten Kartenfotos (Tesseract, wie auf dem
 * Server) kommt die MRZ aber fast nie fehlerfrei an:
 *   - die Fuellzeichen "<<<<" werden zu "cccceee" / "«",
 *   - am Zeilenrand haengt Rauschen des Hologramms ("|", "‘"),
 *   - in der Dokumentennummer wird die 1 zum I ("L1LMT..." -> "LILMT..."),
 *   - beim Personalausweis wird die Staatsangehoerigkeit "D<<" zu "0<<" -
 *     und "D<<" allein passte schon nicht auf das Muster "drei Buchstaben".
 * Eine einzige solche Stelle liess die GANZE Rueckseite durchfallen - samt
 * Anschrift und Geburtsort, also genau der Angaben, fuer die der Betrieb die
 * Rueckseite hochlaedt. Die Reparatur ist dabei nie ein Raten: repariert
 * wird nur, was die MRZ-Norm eindeutig macht (in der MRZ gibt es keine
 * Kleinbuchstaben; deutsche Dokumentennummern enthalten weder O noch I),
 * und jede Zahl wird ueber ihre PRUEFZIFFER bestaetigt.
 */
trait LiestDeutscheAusweiskarte
{
    // Setzt ValidatesExtractedFields in der KLASSE voraus (validatedPerson):
    // der Parser muss den gemeinsamen Trichter direkt benutzen, das prueft
    // ParserPolicyTest am Klassenkopf.

    /** @var list<string> */
    private array $lines = [];

    /**
     * Haeufige Staatsangehoerigkeits-Laendercodes (ISO 3166 alpha-3) -> Land.
     * "D" ist der Code, den die MRZ fuer Deutschland verwendet (nicht "DEU").
     * Nur eindeutige Codes; unbekannte werden als Rohcode uebernommen.
     */
    private const NATIONALITY = [
        'IRQ' => 'Irak', 'SYR' => 'Syrien', 'TUR' => 'Tuerkei', 'AFG' => 'Afghanistan',
        'IRN' => 'Iran', 'RUS' => 'Russland', 'UKR' => 'Ukraine', 'LBN' => 'Libanon',
        'EGY' => 'Aegypten', 'MAR' => 'Marokko', 'TUN' => 'Tunesien', 'DZA' => 'Algerien',
        'JOR' => 'Jordanien', 'PSE' => 'Palaestina', 'SOM' => 'Somalia', 'ERI' => 'Eritrea',
        'PAK' => 'Pakistan', 'IND' => 'Indien', 'DEU' => 'Deutschland', 'D' => 'Deutschland',
    ];

    /** Endungen, an denen ein Wort als Strassenname erkennbar ist. */
    private const STRASSEN_ENDUNGEN =
        'STRASSE|STRABE|STRAGE|STRA\x{00df}E|STR|WEG|ALLEE|PLATZ|RING|GASSE|DAMM|UFER|CHAUSSEE|STEIG|H\x{00d6}FE|HOF|GARTEN|BERG|KAMP|REDDER';

    /**
     * Felder der VORDERSEITE, falls sie im selben Bild steht (kombinierter
     * Scan). Sie ergaenzen die Rueckseite, ueberschreiben aber nie einen
     * Wert aus der MRZ.
     *
     * @return array<string,string>
     */
    abstract protected function vorderseitenFelder(): array;

    protected function zeilenSetzen(string $text): void
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $this->lines = array_map('trim', preg_split('/\R/', $text) ?: []);
    }

    /**
     * Welche Karte zeigt die Rueckseite? 'aufenthaltstitel', 'personalausweis'
     * oder null (keine erkennbare Rueckseite).
     *
     * Erkannt wird sie an der MRZ-Datenzeile ODER - wenn gerade die
     * verlesen ist - an der MRZ-Namenszeile zusammen mit den Beschriftungen
     * der Rueckseite. Die Namenszeile allein genuegt nie (ein Reisepass
     * traegt auch "NAME<<VORNAME").
     */
    protected function rueckseitenTyp(): ?string
    {
        $datenzeile = $this->td1Datenzeile();
        if ($datenzeile === null
            && ! ($this->mrzNamenszeile(null) !== null && $this->hatRueckseitenBeschriftung())) {
            return null;
        }

        foreach ($this->mrzLines() as $line) {
            if (preg_match('/^ID/', $line) && str_contains($line, '<')) {
                return 'personalausweis';
            }
            if (preg_match('/^AR/', $line) && str_contains($line, '<')) {
                return 'aufenthaltstitel';
            }
        }

        // Zeile 1 unlesbar: ein Personalausweis wird NUR an Deutsche
        // ausgegeben, ein Aufenthaltstitel NIE - die Staatsangehoerigkeit
        // entscheidet damit eindeutig.
        if ($datenzeile !== null) {
            return $datenzeile['nat'] === 'D' ? 'personalausweis' : 'aufenthaltstitel';
        }
        if (preg_match('/ORDENS|K[UÜ]NSTLERNAME|RELIGIOUS|PSEUDONYM/iu', $this->text())) {
            return 'personalausweis';
        }
        if (preg_match('/ANMERKUNG|REMARKS|GEBURTSORT|PLACE OF BIRTH/iu', $this->text())) {
            return 'aufenthaltstitel';
        }

        return null;
    }

    /**
     * Beschriftungen, die nur auf der RUECKSEITE der Karten stehen. Verlangt
     * werden ZWEI verschiedene - eine einzelne kann die OCR zerlegen
     * ("Anschrift" -> "Arschrifi"), zwei zufaellig in einem fremden Schreiben
     * zusammen mit einer MRZ-Namenszeile nicht.
     */
    private function hatRueckseitenBeschriftung(): bool
    {
        $t = $this->text();
        $gruppen = [
            '/ANSCHRI|ADRES|ADDRE/iu',
            '/AUGENFARBE|EYE COLOU?R/iu',
            '/GR(?:[OÖ]|OE)(?:SS|ß)E|HEIGHT/iu',
            '/ANMERKUNG|REMARKS/iu',
            '/GEBURTSORT|PLACE OF BIRTH/iu',
            '/ORDENS|K[UÜ]NSTLERNAME/iu',
            '/BEH(?:[OÖ]|OE)RDE|AUTHORITY/iu',
        ];
        $treffer = 0;
        foreach ($gruppen as $muster) {
            $treffer += preg_match($muster, $t) ? 1 : 0;
        }

        return $treffer >= 2;
    }

    /** Anzahl der MRZ-Datenzeilen im Text - Basis fuer den Mehr-Karten-Schutz. */
    protected function td1DataLineCount(): int
    {
        $count = 0;
        foreach ($this->mrzLines() as $line) {
            if ($this->datenzeileLesen($line) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * RUECKSEITE einer Karte: Personendaten aus der MRZ, Anschrift und (beim
     * Aufenthaltstitel) Geburtsort aus den Klartext-Bloecken.
     */
    protected function parseRueckseite(string $typ): ?array
    {
        $mrz = $this->td1Fields();
        if ($mrz === null) {
            return null; // Keine dekodierbare MRZ -> KI.
        }

        $raw = $mrz;

        // Kombinierter Scan: fehlende Felder aus der Vorderseite ergaenzen
        // (nie MRZ-Werte ueberschreiben).
        foreach ($this->vorderseitenFelder() as $k => $v) {
            $raw[$k] ??= $v;
        }

        // Anschrift-Aufkleber ("Anschrift/Address/Adresse": PLZ Ort und
        // Strasse Hausnummer). Den Geburtsort traegt nur der Aufenthaltstitel
        // auf der Rueckseite - beim Personalausweis steht er VORNE.
        $raw = [...$raw, ...$this->backAddress()];
        $raw['birth_place'] ??= $this->backBirthPlace();

        $expiry = $raw['card_expiry'] ?? null;
        unset($raw['card_expiry']);

        $person = $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
        if (($person['last_name'] ?? null) === null && ($person['first_name'] ?? null) === null) {
            return null; // Ohne belastbaren Namen der normalen Analyse/KI ueberlassen.
        }

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $address = trim(
            (($person['street'] ?? '') !== '' ? trim(($person['street'] ?? '').' '.($person['house_number'] ?? '')).', ' : '')
            .trim(($person['zip'] ?? '').' '.($person['city'] ?? ''))
        );
        $address = trim($address, ', ');

        $bezeichnung = $typ === 'personalausweis' ? 'Personalausweis' : 'Aufenthaltstitel';
        $kartenart = $typ === 'personalausweis' ? 'Personalausweis' : 'Aufenthaltstitel (Aufenthaltserlaubnis)';

        return [
            'type' => $typ,
            'confidence' => 76,
            'summary' => $kartenart.' (Rueckseite, maschinenlesbare Zone gelesen)'
                .($name !== '' ? ' - '.$name : '')
                .(isset($person['nationality']) ? ' - Staatsangehoerigkeit '.$person['nationality'] : '')
                .($expiry !== null ? ' - gueltig bis '.$expiry : '')
                .($address !== '' ? ' - Anschrift '.$address : '')
                .(isset($person['birth_place']) ? ' - Geburtsort '.$person['birth_place'] : '')
                .' - Felder gratis aus der Karte gelesen (ohne KI).',
            'title' => $bezeichnung.($name !== '' ? ' '.$name : ''),
            'data' => $this->kartenDaten($person),
            // Die Rueckseite wird fuer die ANSCHRIFT hochgeladen - fehlt sie,
            // liest die KI das Bild (DocumentAnalyzer).
            'pflichtangaben' => ['zip', 'street'],
        ];
    }

    /**
     * @param  array<string,mixed>  $person
     * @return array<string,mixed>
     */
    protected function kartenDaten(array $person): array
    {
        return [
            'person' => $person,
            'versicherung' => [],
            'kfz' => [],
            'gesundheit' => [],
            'bank' => [],
            'personen' => [],
            'energie' => [],
        ];
    }

    /**
     * Dekodiert die TD1-MRZ (Pruefziffern-validiert):
     *   Zeile 1: AR|ID + Ausstellerstaat + Dokumentennummer(9) + Pruefziffer
     *   Zeile 2: Geburt(6) Pruef Geschlecht Ablauf(6) Pruef Staat(3)
     *   Zeile 3: NACHNAME<<VORNAMEN
     * Ist die Datenzeile verlesen, bleibt der Name aus Zeile 3 - ohne
     * Geburtsdatum, statt mit einem geratenen.
     *
     * @return array<string,mixed>|null
     */
    private function td1Fields(): ?array
    {
        $daten = $this->td1Datenzeile();
        $raw = [];

        if ($daten !== null) {
            // Geburtsdatum/Ablauf nur mit stimmiger Pruefziffer uebernehmen -
            // ein OCR-Zahlendreher darf nie ein falsches Datum erzeugen.
            if ($this->mrzCheckDigit($daten['geburt']) === $daten['geburtPruef']) {
                $raw['birth_date'] = $this->mrzDate($daten['geburt'], false);
            }
            $raw['gender'] = match ($daten['sex']) {
                'M' => 'male',
                'F' => 'female',
                default => null,
            };
            if ($this->mrzCheckDigit($daten['ablauf']) === $daten['ablaufPruef']) {
                $raw['card_expiry'] = $this->displayDate($this->mrzDate($daten['ablauf'], true));
            }
            $raw['nationality'] = $this->nationality($daten['nat']);
        }

        if (($nummer = $this->mrzDokumentennummer()) !== null) {
            $raw['id_number'] = $nummer;
        }

        $namenszeile = $this->mrzNamenszeile($daten['index'] ?? null);
        if ($namenszeile !== null) {
            $parts = preg_split('/<</', $namenszeile, 2) ?: [];
            $surname = $this->mrzName($parts[0] ?? '');
            $given = $this->mrzName($parts[1] ?? '');
            if ($surname !== '') {
                $raw['last_name'] = $surname;
            }
            if ($given !== '') {
                $raw['first_name'] = $given;
            }
        }

        if ($daten === null && $namenszeile === null) {
            return null;
        }

        return array_filter($raw, fn ($v) => $v !== null && $v !== '');
    }

    /** @return array{index:int,geburt:string,geburtPruef:int,sex:string,ablauf:string,ablaufPruef:int,nat:string}|null */
    private function td1Datenzeile(): ?array
    {
        foreach ($this->mrzLines() as $i => $line) {
            $d = $this->datenzeileLesen($line);
            if ($d !== null) {
                return ['index' => $i, ...$d];
            }
        }

        return null;
    }

    /**
     * Eine MRZ-Datenzeile nach POSITION lesen (die Norm legt jede Stelle
     * fest). In den Ziffernfeldern werden die typischen OCR-Verwechslungen
     * zurueckgesetzt (O/D -> 0, I/L -> 1, S -> 5, B -> 8 ...) - aber nur,
     * wenn dort bereits ueberwiegend echte Ziffern stehen. Sonst koennte ein
     * Name wie "SOLIDO" als Zahl durchgehen.
     *
     * @return array{geburt:string,geburtPruef:int,sex:string,ablauf:string,ablaufPruef:int,nat:string}|null
     */
    private function datenzeileLesen(string $line): ?array
    {
        if (strlen($line) < 18) {
            return null;
        }
        $vorn = substr($line, 0, 7);
        $mitte = substr($line, 8, 7);
        if (preg_match_all('/\d/', $vorn) < 5 || preg_match_all('/\d/', $mitte) < 5) {
            return null;
        }

        $ziffern = fn (string $s): string => strtr($s, [
            'O' => '0', 'Q' => '0', 'D' => '0', 'U' => '0', 'I' => '1', 'L' => '1',
            'Z' => '2', 'S' => '5', 'G' => '6', 'B' => '8',
        ]);
        $vorn = $ziffern($vorn);
        $mitte = $ziffern($mitte);
        $sex = $line[7];
        if (! ctype_digit($vorn) || ! ctype_digit($mitte) || ! in_array($sex, ['M', 'F', 'X', '<'], true)) {
            return null;
        }

        $nat = $this->mrzStaat(substr($line, 15, 3));
        if ($nat === null) {
            return null;
        }

        return [
            'geburt' => substr($vorn, 0, 6),
            'geburtPruef' => (int) $vorn[6],
            'sex' => $sex,
            'ablauf' => substr($mitte, 0, 6),
            'ablaufPruef' => (int) $mitte[6],
            'nat' => $nat,
        ];
    }

    /**
     * Staatsangehoerigkeit aus der MRZ. Deutschland steht dort als "D<<" -
     * und die OCR liest das D regelmaessig als 0 oder O. Ein "0<<" an dieser
     * Stelle kann nichts anderes sein: kein Staatencode beginnt mit einer
     * Ziffer, und keiner besteht aus einem einzelnen Buchstaben ausser D.
     */
    private function mrzStaat(string $feld): ?string
    {
        if (preg_match('/^[D0O]<</', $feld)) {
            return 'D';
        }
        $feld = strtr($feld, ['0' => 'O', '1' => 'I', '5' => 'S', '8' => 'B', '2' => 'Z']);

        return preg_match('/^[A-Z]{3}$/', $feld) ? $feld : null;
    }

    /**
     * Dokumentennummer aus Zeile 1 ("ARD<<YZ96CMHZJ5", "IDD<<L1LMT2Z9W8"),
     * nur mit stimmiger Pruefziffer. Deutsche Dokumentennummern enthalten
     * weder O noch I (Zeichenvorrat 0-9 und C F G H J K L M N P R T V W X Y
     * Z) - liest die OCR dort ein I, war es eine 1. Die Pruefziffer
     * bestaetigt die Ruecksetzung, geraten wird nichts.
     */
    private function mrzDokumentennummer(): ?string
    {
        foreach ($this->mrzLines() as $line) {
            if (! preg_match('/^(?:AR|ID|IR)([A-Z]{1,3})<{0,2}([A-Z0-9]{9})([0-9OIDQSB])/', $line, $m)) {
                continue;
            }
            $pruef = (int) strtr($m[3], ['O' => '0', 'D' => '0', 'Q' => '0', 'I' => '1', 'S' => '5', 'B' => '8']);
            $kandidaten = [$m[2]];
            if ($m[1] === 'D') {
                $kandidaten[] = strtr($m[2], ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'S' => '5', 'B' => '8']);
            }
            foreach ($kandidaten as $nummer) {
                if ($this->mrzCheckDigit($nummer) === $pruef) {
                    return $nummer;
                }
            }
        }

        return null;
    }

    /**
     * MRZ-Zeile 3: NACHNAME<<VORNAMEN - hinter der Datenzeile, oder (wenn
     * die Datenzeile verlesen ist) die erste ziffernfreie Zeile mit "<<",
     * die nicht Zeile 1 ist.
     */
    private function mrzNamenszeile(?int $nachIndex): ?string
    {
        foreach ($this->mrzLines() as $j => $line) {
            if ($nachIndex !== null && $j <= $nachIndex) {
                continue;
            }
            if (! str_contains($line, '<<') || ! preg_match('/^[A-Z][A-Z<]+$/', $line)) {
                continue;
            }
            if (preg_match('/^(?:AR|ID|IR)D</', $line)) {
                continue; // Zeile 1 ohne lesbare Ziffern - kein Name.
            }

            return $line;
        }

        return null;
    }

    /**
     * MRZ-taugliche Zeilen, von typischen OCR-Fehlern bereinigt:
     *  - Leerraum raus,
     *  - "«" -> "<<", und KLEINBUCHSTABEN c/e/k -> "<": die MRZ kennt keine
     *    Kleinbuchstaben, Tesseract liest die Fuellzeichen-Kette aber genau
     *    so ("<<<<cccccccccee"). Nur in Zeilen, die schon ein "<" tragen -
     *    gewoehnlicher Text bleibt unberuehrt,
     *  - Rauschen an den Zeilenraendern (Hologramm, Kartenkante) ab.
     * Die Original-Zeilenindexe bleiben als Schluessel erhalten (fuer die
     * Reihenfolge Zeile 2 -> Zeile 3).
     *
     * @return array<int,string>
     */
    private function mrzLines(): array
    {
        $out = [];
        foreach ($this->lines as $i => $line) {
            $c = (string) preg_replace('/\s+/u', '', $line);
            $c = str_replace(['«', '‹'], ['<<', '<'], $c);
            if (! str_contains($c, '<')) {
                continue;
            }
            $c = (string) preg_replace('/[cek]/', '<', $c);
            $c = (string) preg_replace('/^[^A-Za-z0-9<]+|[^A-Za-z0-9<]+$/u', '', $c);
            $c = strtoupper($c);
            if (strlen($c) >= 24 && preg_match('/^[A-Z0-9<]+$/', $c)) {
                $out[$i] = $c;
            }
        }

        return $out;
    }

    /** ICAO-9303-Pruefziffer (Gewichte 7/3/1; "<"=0, A=10 ... Z=35). */
    private function mrzCheckDigit(string $value): int
    {
        $weights = [7, 3, 1];
        $sum = 0;
        foreach (str_split($value) as $i => $ch) {
            $v = match (true) {
                $ch === '<' => 0,
                ctype_digit($ch) => (int) $ch,
                default => ord($ch) - ord('A') + 10,
            };
            $sum += $v * $weights[$i % 3];
        }

        return $sum % 10;
    }

    /** MRZ-Datum "YYMMDD" -> "JJJJ-MM-TT". $expiry steuert die Jahrhundertwahl. */
    private function mrzDate(string $yymmdd, bool $expiry): ?string
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m)) {
            return null;
        }
        $yy = (int) $m[1];
        // Ablauf liegt in der Zukunft -> 20YY. Geburtsdatum: Pivot bei 30
        // (00-30 -> 20YY, 31-99 -> 19YY) - deterministisch, ohne "heute".
        $year = $expiry ? 2000 + $yy : ($yy <= 30 ? 2000 + $yy : 1900 + $yy);
        if (! checkdate((int) $m[2], (int) $m[3], $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[3]);
    }

    /**
     * MRZ-Namensteil ("ALALI", "AL<MASRI") -> "Alali", "Al Masri".
     *
     * Ein DOPPELTES Fuellzeichen beendet den Namen - alles dahinter ist
     * Fuellung. Genau dort verliest sich die OCR am haeufigsten (die lange
     * "<<<<"-Kette wird zu Buchstabensalat), und ohne diese Grenze landete
     * der Salat als zweiter Vorname in der Kundenakte. Einzelne Buchstaben
     * zaehlen nicht: die MRZ schreibt Vornamen IMMER aus, ein einzelner
     * Buchstabe ist dort nie eine Abkuerzung, sondern ein Lesefehler.
     */
    private function mrzName(string $part): string
    {
        $teile = [];
        foreach (explode('<', $part) as $wort) {
            if ($wort === '') {
                break; // "<<" - Ende des Namensfeldes.
            }
            if (preg_match('/^[A-Z]{2,}$/', $wort)) {
                $teile[] = $wort;
            }
        }
        $name = implode(' ', $teile);

        return $name === '' ? '' : mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    private function displayDate(?string $iso): ?string
    {
        if ($iso === null) {
            return null;
        }

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3].'.'.$m[2].'.'.$m[1] : $iso;
    }

    /**
     * Anschrift-Aufkleber der Rueckseite. ZWEI Lehren aus der Messung an
     * einem echten Kartenfoto (14.09.2026, Chromium-Replik + Tesseract):
     *
     * 1. DIE BESCHRIFTUNG BRICHT: die OCR liest "4. ANSCHRIFT/ADDRESS" als
     *    "4. ANSCHRIFTIADDRESS" - der Schraegstrich wird zum Buchstaben.
     *    Gesucht wird deshalb OHNE Wortgrenze.
     * 2. AUF SPALTENABSTAENDE IST KEIN VERLASS: die linke Spalte steht mit
     *    nur EINEM Leerzeichen vor dem Aufkleber ("AUGENFARBE/EYE COLOUR
     *    24768 RENDSBURG", "BRAUN OSTLANDSTRASSE 19"). Gesucht wird daher
     *    nach der FORM des Wertes ("PLZ Ort" bzw. "Strasse Hausnummer" am
     *    Zeilenende), nicht nach seiner Spalte.
     *
     * Ob die Nachbarspalte anklebt, verraet die PLZ-Zeile: steht die PLZ
     * nicht am Zeilenanfang, sind die Spalten verschmolzen - dann zaehlt
     * bei der Strasse nur das Wort mit der Strassen-Endung.
     *
     * @return array<string,string>
     */
    private function backAddress(): array
    {
        // Nur der WORTANFANG: am Foto vom 30.09.2026 kam die Beschriftung
        // als "Anschrif/Addrenn/Adrense" an - kein Teil davon ganz.
        $idx = $this->lineIndex('/ANSCHRI|ADRES|ADDRE/i');
        $zeilenTexte = $idx !== null ? $this->nextNonEmpty($idx, 4) : $this->zeilenUmDiePlz();
        if ($zeilenTexte === []) {
            return [];
        }

        $zeilen = [];
        foreach ($zeilenTexte as $value) {
            $zeilen[] = $this->kandidaten($value);
        }

        $out = [];
        $verschmolzen = false;
        $plzZeile = null;

        foreach ($zeilen as $nr => $kandidaten) {
            foreach ($kandidaten as $kandidat) {
                $plz = $this->plzUndOrt($kandidat);
                if ($plz === null) {
                    continue;
                }
                $out['zip'] = $plz[0];
                $out['city'] = $plz[1];
                $verschmolzen = ! str_starts_with($kandidat, $plz[0]);
                $plzZeile = $nr;
                break 2;
            }
        }

        foreach ($zeilen as $nr => $kandidaten) {
            if ($nr === $plzZeile) {
                continue;
            }
            foreach ($kandidaten as $kandidat) {
                $strasse = $this->strasseUndHausnummer($kandidat, $verschmolzen);
                if ($strasse !== null) {
                    $out['street'] = $strasse[0];
                    $out['house_number'] = $strasse[1];
                    break 2;
                }
            }
        }

        return (isset($out['zip']) || isset($out['street'])) ? $out : [];
    }

    /**
     * Rueckfall, wenn die OCR die BESCHRIFTUNG selbst zerlegt hat (an einem
     * echten Foto gemessen: "Anschrift/Address" -> "Arschrifi Adern"). Auf
     * der Rueckseite der Karten ist der Aufkleber die EINZIGE Stelle mit
     * "PLZ Ort" - die Behoerde steht ohne Postleitzahl da ("ZAB Saarland",
     * "STADT RENDSBURG"). Gelesen werden dann die Zeilen rund um die erste
     * solche Zeile, nie die MRZ.
     *
     * @return list<string>
     */
    private function zeilenUmDiePlz(): array
    {
        $mrz = $this->mrzLines();
        $nutzbar = fn (int $j): bool => ! isset($mrz[$j]) && trim($this->lines[$j]) !== ''
            && ! $this->istBehoerdenZeile($this->lines[$j])
            // Die Zeile UNTER "Behoerde/Authority" ist die Behoerde selbst.
            && ! ($j > 0 && preg_match('/BEH(?:[O\x{00d6}]|OE)RDE|AUTHORITY/iu', $this->lines[$j - 1]));

        foreach ($this->lines as $i => $zeile) {
            if (! $nutzbar($i) || $this->plzUndOrt(trim((string) preg_replace('/\h+/u', ' ', $zeile))) === null) {
                continue;
            }
            $out = [];
            for ($j = max(0, $i - 2); $j <= min(count($this->lines) - 1, $i + 3); $j++) {
                if ($nutzbar($j)) {
                    $out[] = trim($this->lines[$j]);
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * Zeile der ausstellenden Behoerde ("05.02.2025 - KRV Flensburg,
     * Kaiserstrasse 8, 24937 Flensburg", "Behoerde/Authority"). Deren
     * Anschrift ist NIE die Anschrift der Person.
     */
    private function istBehoerdenZeile(string $zeile): bool
    {
        return (bool) preg_match('/\b\d{2}[ .]\d{2}[ .]\d{2,4}\b|BEH(?:[O\x{00d6}]|OE)RDE|AUTHORITY|AUTORIT|AUSSTELLUNG/iu', $zeile);
    }

    /** @return list<string> */
    private function kandidaten(string $zeile): array
    {
        // Rauschen am Zeilenende (Kartenkante, Hologramm) ab: "Fockbeker
        // Chaussee 90 |". Nur Satz-/Strichzeichen - eine Ziffer kann eine
        // Hausnummer sein und bleibt stehen.
        $zeile = (string) preg_replace('/(?:\h+[|!\/\\,;:.\'"\x{2018}\x{2019}\x{201c}\x{201d}\]\[()}{]+)+\h*$/u', '', $zeile);
        $ganz = trim((string) preg_replace('/\h+/u', ' ', $zeile));
        $out = [];
        foreach (preg_split('/\h{2,}/u', trim($zeile)) ?: [] as $spalte) {
            $spalte = trim($spalte);
            if ($spalte !== '' && $spalte !== $ganz) {
                $out[] = $spalte;
            }
        }
        $out[] = $ganz;

        return $out;
    }

    /** @return array{0:string,1:string}|null */
    private function plzUndOrt(string $zeile): ?array
    {
        // Ein Ortsname endet nie auf eine Zahl: eine einzelne Ziffer dahinter
        // ist Rauschen ("24768 Rendsburg 4" am Foto vom 30.09.2026).
        $zeile = (string) preg_replace('/(\p{L})\h+\d{1,2}$/u', '$1', trim($zeile));
        if (! preg_match('/(?<![\d.\-])(\d{5}) (\p{Lu}[\p{L}.\-]+(?:[ \-]\p{L}[\p{L}.\-]+)*)$/u', $zeile, $m)) {
            return null;
        }
        if ($this->istBeschriftung($m[2])) {
            return null;
        }

        return [$m[1], $this->normalizeName($m[2])];
    }

    /** @return array{0:string,1:string}|null */
    private function strasseUndHausnummer(string $zeile, bool $verschmolzen): ?array
    {
        $nummer = '(\d{1,4} ?[a-zA-Z]?)$';

        if (! $verschmolzen
            && preg_match('/^(\p{Lu}[\p{L}.\-]*(?:[ \-]\p{L}[\p{L}.\-]*)*),? '.$nummer.'/u', $zeile, $m)
            && ! $this->istBeschriftung($m[1])) {
            return [$this->strassenName($m[1]), trim($m[2])];
        }

        // Verschmolzene Spalte ODER Rauschen vorn in der Zeile ('"72 cm
        // Fockbeker Chaussee 90' - die Groesse klebt auch dann vorn, wenn
        // die PLZ-Zeile sauber ist, gemessen am Foto vom 30.09.2026): dann
        // traegt nur das Wort mit der Strassen-Endung.
        if (preg_match('/((?:[\p{L}.\-]+ ){0,2})(\p{L}*(?:'.self::STRASSEN_ENDUNGEN.'))\.?,? '.$nummer.'/iu', $zeile, $m)) {
            return [$this->strassenName($this->strassenVorsatz($m[1], $m[2]).$m[2]), trim($m[3])];
        }

        return null;
    }

    /**
     * Mehrteilige Strassennamen bei verschmolzenen Spalten ("172 cm Fockbeker
     * Chaussee 90" - links die Groesse, rechts der Aufkleber). Vor dem Wort
     * mit der Strassen-Endung duerfen hoechstens ZWEI weitere Woerter zum
     * Namen gehoeren, und nur solange sie wie ein Strassenname aussehen: ein
     * Merkmalswert der Nachbarspalte ("cm", "BRAUN"), ein Behoerdenwort
     * ("STADT") oder ein Wechsel der Schreibweise (Karte GROSS, Aufkleber
     * gemischt) beendet die Suche. Lieber "Chaussee" allein als ein Wert der
     * Nachbarspalte im Strassennamen.
     */
    private function strassenVorsatz(string $vorher, string $strassenwort): string
    {
        $gross = mb_strtoupper($strassenwort) === $strassenwort;
        $teile = [];
        foreach (array_reverse(preg_split('/ /', trim($vorher), -1, PREG_SPLIT_NO_EMPTY) ?: []) as $wort) {
            if (! preg_match('/^\p{Lu}[\p{L}\-]+\.?$/u', $wort)
                || (mb_strtoupper($wort) === $wort) !== $gross
                || preg_match('/^(?:BRAUN|BLAU|GR(?:U|UE|\x{00dc})N|GRAU|SCHWARZ|STADT|KREIS|LANDKREIS|GEMEINDE|AMT|CM)$/iu', $wort)
                || $this->istBeschriftung($wort)) {
                break;
            }
            array_unshift($teile, $wort);
        }

        return $teile === [] ? '' : implode(' ', $teile).' ';
    }

    /**
     * Strassenname bereinigen. Die Karte druckt in GROSSBUCHSTABEN, und ein
     * grosses "ss" gibt es dort nicht - die OCR liest das gedruckte
     * "STRAßE" als "STRABE" (ein "Strabe" gibt es im Deutschen nicht).
     */
    private function strassenName(string $name): string
    {
        $name = $this->normalizeName(trim(rtrim(trim($name), ',')));

        // "STRAGE" ist dieselbe Verwechslung mit einem anderen Buchstaben
        // (an einem echten Foto gemessen: "BAHNHOFSTRAGE 51").
        return (string) preg_replace('/stra[bg]e\b/iu', 'stra'."\u{00df}".'e', $name);
    }

    /** Beschriftungen und Merkmalswerte der Karte sind weder Ort noch Strasse. */
    private function istBeschriftung(string $wert): bool
    {
        return (bool) preg_match(
            '/ANSCHRIFT|ADRESSE|ADDRESS|AUGENFARBE|EYE|COLOU?R|GR[O\x{00d6}]SSE|HEIGHT|ANMERKUNG|REMARK'
            .'|GEBURTSORT|BIRTH|PLACE|AUSSTELLUNG|BEH[O\x{00d6}]RDE|AUTHORITY|ISSUE|ZUSATZBLATT|ORDENS|K[U\x{00dc}]NSTLER/iu',
            $wert
        );
    }

    /**
     * Geburtsort des Aufenthaltstitels ("3. GEBURTSORT/PLACE OF BIRTH"): der
     * Wert steht auf der Karte UNTER der Beschriftung, in der OCR-Ausgabe je
     * nach Layout aber hinter der Beschriftung, in derselben Spalte der
     * Folgezeile oder hinter dem Anmerkungs-Text. Unsichere Treffer bleiben
     * leer.
     */
    private function backBirthPlace(): ?string
    {
        $idx = $this->lineIndex('/GEBURTSORT|PLACE OF BIRTH/i');
        if ($idx === null) {
            return null;
        }

        $candidates = [];

        if (preg_match('/^.*(?:GEBURTSORT|BIRTH)\b[\s\/.:]*([\p{Lu}][\p{Lu} \-\.]{1,40})$/u', trim($this->lines[$idx]), $m)) {
            $candidates[] = $m[1];
        }

        $labelCols = preg_split('/\s{2,}/', trim($this->lines[$idx])) ?: [];
        $labelPos = null;
        foreach ($labelCols as $i => $col) {
            if (preg_match('/GEBURTSORT|PLACE OF BIRTH/i', $col)) {
                $labelPos = $i;
                break;
            }
        }
        foreach ($this->nextNonEmpty($idx, 2) as $value) {
            $cols = array_map('trim', preg_split('/\s{2,}/', trim($value)) ?: []);
            if ($labelPos !== null && isset($cols[$labelPos])) {
                $candidates[] = $cols[$labelPos];
            }
            foreach ($cols as $col) {
                $candidates[] = $col;
            }
        }

        foreach ($candidates as $candidate) {
            $place = $this->cleanBirthPlace($candidate);
            if ($place !== null) {
                return $place;
            }
        }

        return null;
    }

    /**
     * Kandidat zu einem plausiblen Geburtsort bereinigen. Bekannte
     * Anmerkungs-Phrasen ("ERWERBSTAETIGKEIT ERLAUBT", "SIEHE ZUSATZBLATT")
     * werden vorn abgeschnitten. Bleibt kein reiner Ortsname (Grossbuchstaben
     * der Karte) uebrig oder enthaelt er ein Beschriftungs-/Merkmalswort,
     * wird der Kandidat verworfen.
     */
    private function cleanBirthPlace(string $candidate): ?string
    {
        $candidate = trim($candidate);
        $candidate = (string) preg_replace(
            '/\b(?:ERWERBST\p{Lu}*|BESCH\p{Lu}*FTIGUNG|T\p{Lu}*TIGKEIT|NICHT|ERLAUBT|GESTATTET|SIEHE|ZUSATZBLATT|ZUSATZ|BLATT)\b[\s\-]*/u',
            ' ',
            $candidate
        );
        $candidate = trim((string) preg_replace('/\s+/', ' ', $candidate));

        if (! preg_match('/^\p{Lu}[\p{Lu} \-\.]{1,40}$/u', $candidate)) {
            return null;
        }
        if (preg_match('/ANMERKUNG|REMARK|GEBURTSORT|BIRTH|PLACE|AUGENFARBE|EYE|COLOU?R|BRAUN|BLAU|GR[UÜ]N|GRUEN|GRAU|SCHWARZ|GR[OÖ]SSE|GROESSE|HEIGHT|AUSSTELLUNG|BEH[OÖ]RDE|BEHOERDE|DATE|ISSUE|AUTHORITY|ANSCHRIFT|ADDRESS|ADRESSE|BUNDESDRUCKEREI|KARTE|SIEGEL|DEUTSCH|NATIONALIT|STAATSANG|G[UÜ]LTIG|EXPIRY/iu', $candidate)) {
            return null;
        }

        return mb_convert_case($candidate, MB_CASE_TITLE, 'UTF-8');
    }

    private function nationality(string $code): string
    {
        return self::NATIONALITY[strtoupper($code)] ?? strtoupper($code);
    }

    private function looksLikeName(string $s): bool
    {
        return (bool) preg_match('/^\p{Lu}[\p{L}\-\'’ ]+$/u', trim($s)) && mb_strlen(trim($s)) >= 2;
    }

    /** Namen in Grossbuchstaben ("MUSTAFA") zu "Mustafa" normalisieren. */
    private function normalizeName(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        if ($s === mb_strtoupper($s)) {
            return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
        }

        return $s;
    }

    private function lineIndex(string $pattern): ?int
    {
        foreach ($this->lines as $i => $line) {
            if (preg_match($pattern, $line)) {
                return $i;
            }
        }

        return null;
    }

    /** @return list<string> Die naechsten $count nicht-leeren Zeilen ab Index+1. */
    private function nextNonEmpty(int $index, int $count): array
    {
        $out = [];
        for ($j = $index + 1; $j < count($this->lines) && count($out) < $count; $j++) {
            $v = trim($this->lines[$j]);
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }
}
