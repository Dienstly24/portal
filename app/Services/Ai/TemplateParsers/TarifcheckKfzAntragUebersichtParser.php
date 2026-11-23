<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\RepairsOcrText;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die ZUSAMMENFASSUNG der Kfz-Antragsstrecke des
 * Vergleichsrechners (Tarifcheck/CHECK24-Partnerrechner), Betreiber-Auftrag
 * 23.11.2026: der Betrieb schickt kuenftig den SCREENSHOT dieser Seite
 * ("Pruefen und absenden") statt einzelner Unterlagen. Sie traegt alles, was
 * der Antrag braucht - Gesellschaft, Tarif, Beginn, Deckung, Fahrzeug,
 * Kilometerstand, Kunde komplett und die Bankverbindung. Vorher griff KEIN
 * Parser (gemessen: die ganze Kette lieferte null), das Bild wartete auf die
 * kostenpflichtige KI-Freigabe.
 *
 * Aufbau (Screenshot -> Tesseract, zweispaltig):
 *   links  "Ihr Beitrag / monatlich (i) 58,28 €", "Ihr Tarif" mit Logo,
 *          Gesellschaft und Tarifname, "Ihr Schutz": "Ihr Auto",
 *          "Versicherungsumfang", "Versicherungsbeginn"
 *   rechts "Adresse des Versicherungsnehmers" (E-Mail, Anrede+Name,
 *          Strasse, PLZ Ort, Telefon), "Angaben zum neuen Fahrzeug"
 *          (Kilometerstand), "Zahlungsdaten" (IBAN, Kontoinhaber)
 *
 * OCR-LEHRE (wie Energieportal/CHECK24-DSL): die Spalten kommen OHNE
 * verlaesslichen Abstand an - "Optimal Angaben zum neuen Fahrzeug andern",
 * "Ihr Tarif 015781208190". Gelesen wird deshalb ueber das Beschriftungs-
 * VOKABULAR der Seite; Fremdbeschriftungen der Nachbarspalte werden vor dem
 * Lesen eines Werts abgeschnitten. Das Info-Symbol vor dem Beitrag kommt als
 * "©O" an - der Betrag wird ueber "Zahl,Zahl €" gelesen, nicht ueber seine
 * Position.
 *
 * Regeln:
 *   - Stufe `antrag`: die Seite hat KEINE Vertragsnummer. Die spaetere
 *     Abschluss-Seite (`AntragBestaetigungParser`, Referenznummer) wird ueber
 *     `findApplicationContractForSameProcess` mit diesem Vertrag
 *     zusammengefuehrt, die Police ergaenzt ihn.
 *   - Beginn nur als echtes Datum ("23.11.2026 (bzw. Tag der Zulassung)" ->
 *     23.11.2026); steht nur "Tag der Zulassung", bleibt er leer.
 *   - Deckung NUR aus dem Feld "Versicherungsumfang".
 *   - IBAN nur mit gueltiger Pruefziffer UND nur, wenn der Kontoinhaber der
 *     Versicherungsnehmer ist (Vermerk "(Versicherungsnehmer)" oder gleicher
 *     Name) - sonst landet ein Fremdkonto in der Akte.
 *   - Der Kilometerstand ist der Stand bei Antrag (`initial_mileage`), NICHT
 *     die Jahresfahrleistung.
 */
class TarifcheckKfzAntragUebersichtParser implements DocumentTemplateParser
{
    use RepairsOcrText;
    use ValidatesExtractedFields;

    /**
     * Beschriftungen der Seite (beide Spalten). Ein Wert endet an der
     * naechsten von ihnen - so frisst die linke Spalte nie die rechte.
     */
    private const LABELS = [
        'Adresse des Versicherungsnehmers', 'Angaben zum neuen Fahrzeug',
        'Angaben zum Fahrzeug', 'Zahlungsdaten', 'E-Mail-Adresse',
        'Versicherungsnehmer', 'Kilometerstand', 'Kontoinhaber', 'IBAN',
        'Ihr Beitrag', 'Ihr Tarif', 'Ihr Schutz', 'Ihr Auto',
        'Versicherungsumfang', 'Versicherungsbeginn', 'Tarifdetails anzeigen',
        'Kennzeichen', 'Fahrzeug-Identifikationsnummer', 'Erstzulassung',
        'Jährliche Fahrleistung', 'Fahrleistung', 'ändern', 'andern',
    ];

    /** Kanonische Schreibweise der Gesellschaften, die der Rechner anbietet. */
    private const INSURERS = [
        'sparkassen direkt' => 'Sparkassen DirektVersicherung',
        'allianz direct' => 'Allianz Direct', 'admiraldirekt' => 'AdmiralDirekt',
        'cosmos direkt' => 'CosmosDirekt', 'cosmosdirekt' => 'CosmosDirekt',
        'huk24' => 'HUK24', 'huk-coburg' => 'HUK-COBURG', 'da direkt' => 'DA Direkt',
        'bavariadirekt' => 'BavariaDirekt', 'signal iduna' => 'Signal Iduna',
        'direct line' => 'Direct Line', 'europa' => 'EUROPA', 'wgv' => 'WGV',
        'devk' => 'DEVK', 'verti' => 'Verti', 'adac' => 'ADAC', 'axa' => 'AXA',
        'hdi' => 'HDI', 'vhv' => 'VHV', 'lvm' => 'LVM', 'kravag' => 'KRAVAG',
        'allianz' => 'Allianz', 'gothaer' => 'Gothaer', 'ergo' => 'ERGO',
        'zurich' => 'Zurich', 'generali' => 'Generali', 'itzehoer' => 'Itzehoer',
        'barmenia' => 'Barmenia', 'friday' => 'FRIDAY', 'baloise' => 'Baloise',
        'württembergische' => 'Württembergische', 'r+v' => 'R+V',
    ];

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        // Verkleinerte Screenshots: die OCR liest "Ihr" als "Thr"/"lhr"
        // (gemessen an einer auf 1000 px verkleinerten Fassung).
        $text = (string) preg_replace('/(?<![\p{L}])[TlI1|]hr (Beitrag|Tarif|Schutz|Auto)\b/u', 'Ihr $1', $text);
        $upper = mb_strtoupper($text);

        // "Ihr Beitrag" kommt bei niedrig aufgeloesten PDF-Scans als "Ihr Beitra"
        // an (Betreiber-Upload 23.11.2026) - die Anfangsbuchstaben genuegen,
        // oder die Ueberschrift des Kundenblocks.
        if ((! str_contains($upper, 'IHR BEITRA') && ! str_contains($upper, 'ADRESSE DES VERSICHERUNGSNEHMERS'))
            || ! str_contains($upper, 'IHR TARIF')
            || ! str_contains($upper, 'VERSICHERUNGSNEHMER')
            || (! str_contains($upper, 'VERSICHERUNGSUMFANG') && ! str_contains($upper, 'IHR AUTO'))) {
            return null;
        }
        // Die ABSCHLUSS-Seite (Referenznummer, "Antrag eingegangen") hat ihren
        // eigenen Parser - sie bringt die Vorgangs-Kennung.
        if (str_contains($upper, 'REFERENZNUMMER')) {
            return null;
        }

        $this->lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $text) ?: []),
            fn ($l) => $l !== ''
        ));

        $person = $this->parsePerson($text);
        $insurance = $this->parseInsurance($text);
        $vehicle = $this->parseVehicle($text);
        $bank = $this->parseBank($text, $person);

        // Ohne Kunde UND ohne Gesellschaft ist die Seite nicht verwertbar -
        // dann soll die KI-Stufe sie vollstaendig lesen.
        if (! isset($person['last_name']) || ! isset($insurance['insurer'])) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '').' '.$person['last_name']);

        return [
            'type' => 'kfz_vertrag',
            'confidence' => 78,
            'summary' => $this->summary($text, $name, $person, $insurance, $vehicle, $bank),
            'title' => 'Kfz-Antrag '.$insurance['insurer'].' '.$name,
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
                'kfz' => $vehicle,
                'gesundheit' => [],
                'bank' => $bank,
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function parsePerson(string $text): array
    {
        $raw = [];

        $mailZeile = $this->valueAfter('E-Mail-Adresse');
        $mail = $this->ocrEmail($mailZeile ?? '') ?? $this->ocrEmail($text);
        if ($mail !== null) {
            $raw['email'] = $mail;
        }

        foreach ($this->lines as $i => $line) {
            // "Versicherungsnehmer Herr Ali Sharaf" - NICHT die Ueberschrift
            // "Adresse des Versicherungsnehmers" und nicht "(Versicherungsnehmer)".
            if (! preg_match('/(?:^|\s)Versicherungsnehmer\s*:?\s*(Herrn?|Frau)?\s*(.*)$/u', $line, $m)
                || str_contains($line, 'Adresse des')
                || str_contains($line, '(Versicherungsnehmer')) {
                continue;
            }
            $anrede = $m[1];
            $nameText = $this->cut($m[2]);
            $j = $i + 1;
            if ($nameText === '' && isset($this->lines[$j])) {
                // Name auf der Folgezeile.
                if (preg_match('/^(Herrn?|Frau)?\s*(.+)$/u', $this->cut($this->lines[$j]), $n)) {
                    $anrede = $anrede !== '' ? $anrede : $n[1];
                    $nameText = $n[2];
                    $j++;
                }
            }
            if ($anrede !== '') {
                $raw['gender'] = $anrede === 'Frau' ? 'female' : 'male';
            }
            if (preg_match('/^[\p{Lu}][\p{L}\'\-]+(?:\s+[\p{L}][\p{L}\'\-]+)+$/u', $nameText)) {
                $teile = preg_split('/\s+/u', $nameText) ?: [];
                $raw['last_name'] = array_pop($teile);
                $raw['first_name'] = implode(' ', $teile);
            }

            // Danach Strasse, PLZ Ort und Telefon - hoechstens fuenf Zeilen,
            // jede an ihrer FORM erkannt (die Nachbarspalte kann dazwischen
            // stehen: "Ihr Tarif 015781208190").
            for ($k = $j; $k < min($j + 6, count($this->lines)); $k++) {
                $z = $this->lines[$k];
                if (! isset($raw['zip']) && preg_match('/(?:^|\s)(\d{5})\s+([\p{Lu}][\p{L}\-. ]+?)\s*$/u', $z, $p)) {
                    $raw['zip'] = $p[1];
                    $raw['city'] = trim($p[2]);
                    continue;
                }
                if (! isset($raw['street']) && ! isset($raw['zip'])
                    && preg_match('/^([\p{Lu}][\p{L}\-. ]+?\s\d+[a-zA-Z]?(?:\s?[\/\-]\s?\d+[a-zA-Z]?)?)$/u', $this->cut($z), $s)) {
                    $raw['street'] = $s[1];
                    continue;
                }
                if (! isset($raw['phone']) && preg_match('/(?:^|\s)(\+?\d[\d \/\-]{6,18}\d)\s*$/u', $z, $t)
                    && ! preg_match('/\d{5}\s+\p{L}/u', $z)) {
                    $raw['phone'] = trim($t[1]);
                }
            }
            break;
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseInsurance(string $text): array
    {
        $raw = [
            'sparte' => 'kfz',
            'document_stage' => Contract::STAGE_ANTRAG,
        ];

        [$insurer, $tariff] = $this->insurerAndTariff();
        if ($insurer !== null) {
            $raw['insurer'] = $insurer;
        }
        if ($tariff !== null) {
            $raw['tariff'] = $tariff;
        }

        // Beitrag: "monatlich (i) 58,28 €" - Zahlweise und Betrag koennen auf
        // derselben oder auf benachbarten Zeilen stehen.
        $flat = (string) preg_replace('/\s+/u', ' ', $text);
        if (preg_match('/\b(monatlich|vierteljährlich|vierteljaehrlich|halbjährlich|halbjaehrlich|jährlich|jaehrlich)\b[^\d\n]{0,6}(\d{1,3}(?:\.\d{3})*,\d{2})\s*(?:€|EUR)/u', $flat, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[2]);
            $raw['premium_interval'] = match (true) {
                str_starts_with($m[1], 'monat') => 'monthly',
                str_starts_with($m[1], 'viertel') => 'quarterly',
                str_starts_with($m[1], 'halb') => 'semiannual',
                default => 'yearly',
            };
        }

        $beginn = $this->beginnText();
        if ($beginn !== null && preg_match('/\b(\d{2})\.(\d{2})\.(\d{4})\b/', $beginn, $d)) {
            $raw['start_date'] = $d[3].'-'.$d[2].'-'.$d[1];
        }

        return $this->validatedInsurance($raw);
    }

    /** @return array<string,mixed> */
    private function parseVehicle(string $text): array
    {
        $raw = [];

        $auto = $this->valueAfter('Ihr Auto');
        if ($auto !== null && preg_match('/^([\p{L}\-]+)\s+(.+)$/u', $auto, $m)) {
            $raw['manufacturer'] = $m[1];
            $raw['model'] = $m[2];
        }

        if (preg_match('/Kilometerstand\s*:?\s*(\d{1,3}(?:[.\s]\d{3})*|\d+)\s*km/iu', $text, $m)) {
            $raw['initial_mileage'] = (int) preg_replace('/\D/', '', $m[1]);
        }
        if (preg_match('/(?:Jährliche\s+)?Fahrleistung\s*:?\s*(\d{1,3}(?:\.\d{3})*|\d+)\s*km/iu', $text, $m)) {
            $raw['annual_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        if (preg_match('/Kennzeichen\s*:?\s*([A-ZÄÖÜ]{1,3}[\s\-]+[A-Z]{1,2}\s?\d{1,4}[EH]?)\b/u', $text, $m)) {
            $raw['license_plate'] = (string) preg_replace('/\s*-\s*|\s+(?=[A-Z])/u', '-', trim($m[1]), 1);
        }
        if (preg_match('/Fahrzeug-Identifikationsnummer\s*:?\s*([A-HJ-NPR-Z0-9]{17})\b/u', $text, $m)) {
            $raw['vin'] = $m[1];
        }

        $umfang = $this->valueAfter('Versicherungsumfang');
        if ($umfang !== null) {
            $raw['has_vollkasko'] = (bool) preg_match('/Vollkasko/iu', $umfang);
            $raw['has_teilkasko'] = $raw['has_vollkasko'] || (bool) preg_match('/Teilkasko/iu', $umfang);
        }

        return $this->validatedVehicle($raw);
    }

    /**
     * @param  array<string,mixed>  $person
     * @return array<string,mixed>
     */
    private function parseBank(string $text, array $person): array
    {
        $ibanZeile = $this->valueAfter('IBAN');
        $iban = $this->ocrGermanIban('DE'.preg_replace('/^\s*[D0O][E3]/u', '', $ibanZeile ?? ''))
            ?? $this->ocrGermanIban($text);
        if ($iban === null) {
            return [];
        }

        $inhaber = null;
        $istVn = false;
        foreach ($this->lines as $i => $line) {
            if (! preg_match('/Kontoinhaber\s*:?\s*(.*)$/u', $line, $m)) {
                continue;
            }
            $inhaber = trim((string) preg_replace('/\(.*$/u', '', $m[1]));
            $folge = ($this->lines[$i + 1] ?? '');
            $istVn = str_contains($m[1].' '.$folge, '(Versicherungsnehmer');
            break;
        }

        $vnName = mb_strtolower(trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? '')));
        if (! $istVn && ($inhaber === null || $vnName === '' || mb_strtolower($inhaber) !== $vnName)) {
            return []; // fremdes oder unbekanntes Konto - nie uebernehmen
        }

        return $this->validatedBank([
            'iban' => $iban,
            'account_holder' => $inhaber !== '' ? $inhaber : null,
        ]);
    }

    /**
     * Gesellschaft und Tarif aus dem Block "Ihr Tarif" (Logo, Gesellschaft,
     * Tarifname) bis "Ihr Schutz". Das Logo kommt oft als zweite Kopie des
     * Namens an ("WGV / WGV / Optimal") - doppelte Zeilen zaehlen einmal.
     *
     * @return array{0:?string,1:?string}
     */
    private function insurerAndTariff(): array
    {
        $start = null;
        foreach ($this->lines as $i => $line) {
            if (preg_match('/^Ihr Tarif\b/u', $line)) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            return [null, null];
        }

        $werte = [];
        for ($i = $start; $i < count($this->lines); $i++) {
            if (preg_match('/^Ihr (Schutz|Auto)\b/u', $this->lines[$i])) {
                break;
            }
            $w = $this->cut($this->lines[$i]);
            // Reine Telefonnummern/Zahlen der Nachbarspalte und Rauschen.
            if ($w === '' || preg_match('/^[\d\s\/+\-]+$/u', $w) || mb_strlen($w) < 2) {
                continue;
            }
            if (end($werte) !== $w) {
                $werte[] = $w;
            }
        }

        $insurer = null;
        $tariff = null;
        foreach ($werte as $idx => $w) {
            $kanon = $this->knownInsurer($w);
            if ($kanon !== null) {
                $insurer = $kanon;
                // Der Tarif ist die naechste Zeile, die NICHT wieder der
                // Gesellschaftsname ist - und NUR diese: ist sie verstuemmelt
                // ("imal ." aus "Optimal" bei einem 72-dpi-Scan), bleibt der
                // Tarif leer, statt eine Folgezeile ("Gem") zu nehmen.
                foreach (array_slice($werte, $idx + 1) as $rest) {
                    if ($this->knownInsurer($rest) !== $kanon) {
                        $rest = trim($rest, ' .,;');
                        if (preg_match('/^\p{Lu}[\p{L}\d+\-]{2,}(?:\s+[\p{L}\d+\-]+){0,4}$/u', $rest)) {
                            $tariff = $rest;
                        }
                        break;
                    }
                }
                break;
            }
        }

        return [$insurer, $tariff];
    }

    private function knownInsurer(string $value): ?string
    {
        $klein = mb_strtolower($value);
        foreach (self::INSURERS as $muster => $name) {
            if ($klein === $muster || str_starts_with($klein, $muster.' ') || $klein === mb_strtolower($name)) {
                return $name;
            }
        }

        return null;
    }

    /** Text hinter "Versicherungsbeginn" (kann zwei Zeilen umfassen). */
    private function beginnText(): ?string
    {
        foreach ($this->lines as $i => $line) {
            if (preg_match('/^Versicherungsbeginn\b\s*(.*)$/u', $line, $m)) {
                $wert = $this->cut($m[1]);
                $k = $i + 1;
                if ($wert === '') {
                    $wert = $this->cut($this->lines[$k++] ?? '');
                }
                // "23.11.2026 (bzw. Tag der" + "Zulassung)" - offene Klammer
                // schliesst auf der Folgezeile.
                if (substr_count($wert, '(') > substr_count($wert, ')')) {
                    $wert .= ' '.$this->cut($this->lines[$k] ?? '');
                }

                return $wert !== '' ? trim($wert) : null;
            }
        }

        return null;
    }

    /**
     * Wert zu einer Beschriftung: derselbe Zeilenrest oder - wenn dort nichts
     * steht - die naechste nicht leere Zeile. Fremdbeschriftungen der
     * Nachbarspalte werden abgeschnitten.
     */
    private function valueAfter(string $label): ?string
    {
        $l = preg_quote($label, '/');
        foreach ($this->lines as $i => $line) {
            if (! preg_match('/(?:^|\s)'.$l.'\s*:?\s*(.*)$/u', $line, $m)) {
                continue;
            }
            $wert = $this->cut($m[1]);
            if ($wert === '' && isset($this->lines[$i + 1])) {
                $wert = $this->cut($this->lines[$i + 1]);
            }

            return $wert !== '' ? $wert : null;
        }

        return null;
    }

    /** Schneidet ab der ersten Beschriftung (Nachbarspalte) ab. */
    private function cut(string $value): string
    {
        $value = trim($value);
        foreach (self::LABELS as $label) {
            $pos = mb_stripos($value, $label);
            if ($pos === 0) {
                return '';
            }
            if ($pos !== false) {
                $value = trim(mb_substr($value, 0, $pos));
            }
        }

        return trim($value, " \t|©®:");
    }

    /**
     * @param  array<string,mixed>  $person
     * @param  array<string,mixed>  $ins
     * @param  array<string,mixed>  $kfz
     * @param  array<string,mixed>  $bank
     */
    private function summary(string $text, string $name, array $person, array $ins, array $kfz, array $bank): string
    {
        $s = 'Kfz-Antrag (Zusammenfassung der Antragsstrecke) - '.$ins['insurer']
            .(isset($ins['tariff']) ? ' '.$ins['tariff'] : '')
            .' - '.$name;

        if (isset($kfz['manufacturer'])) {
            $s .= ' - '.$kfz['manufacturer'].' '.($kfz['model'] ?? '');
        }
        if (isset($kfz['has_teilkasko'])) {
            $s .= ' - Deckung: '.(! empty($kfz['has_vollkasko']) ? 'Haftpflicht + Vollkasko'
                : (! empty($kfz['has_teilkasko']) ? 'Haftpflicht + Teilkasko' : 'nur Haftpflicht'));
        }
        if (isset($ins['premium_amount'])) {
            $s .= ' - Beitrag '.number_format($ins['premium_amount'], 2, ',', '.').' EUR '
                .match ($ins['premium_interval'] ?? null) {
                    'monthly' => 'monatlich', 'quarterly' => 'vierteljährlich',
                    'semiannual' => 'halbjährlich', 'yearly' => 'jährlich', default => '',
                };
        }
        $beginn = $this->beginnText();
        if ($beginn !== null) {
            $s .= ' - Versicherungsbeginn laut Seite: '.$beginn;
        }
        if (isset($kfz['initial_mileage'])) {
            $s .= ' - Kilometerstand '.number_format($kfz['initial_mileage'], 0, ',', '.').' km';
        }
        if (isset($person['email']) && str_starts_with($person['email'], 'www.')) {
            $s .= ' - Hinweis: die E-Mail beginnt mit "www." - bitte beim Kunden pruefen (uebernommen wie angegeben)';
        }
        if ($bank === [] && preg_match('/\bIBAN\b/u', $text)) {
            $s .= ' - IBAN NICHT uebernommen (Pruefziffer ungueltig oder Kontoinhaber nicht der Versicherungsnehmer)';
        }

        if (isset($ins['tariff'])) {
            $s .= ' - Tarifname aus der Texterkennung - bei unbekanntem Namen bitte am Bild pruefen';
        } else {
            $s .= ' - Tarifname nicht sicher lesbar - bitte am Bild nachtragen';
        }

        return $s.' - Antrag ohne Vertragsnummer (kommt mit der Police). Felder gratis aus dem Screenshot gelesen (ohne KI).';
    }
}
