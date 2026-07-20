<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer das CHECK24-Beratungsprotokoll zur Kfz-Versicherung.
 *
 * Dieses Formular ist ueber alle Kunden hinweg identisch aufgebaut (nur die
 * Werte unterscheiden sich) - es per fester Regel aus der Textebene zu lesen
 * ist kostenlos, fehlerfrei und sofort, statt jedes Mal die (kostenpflichtige)
 * KI zu bemuehen. Alle Werte durchlaufen dieselbe harte Feldvalidierung wie
 * die KI-Antwort; unsichere Felder bleiben leer statt falsch.
 */
class Check24KfzProtocolParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /**
     * Bekannte deutsche Kfz-Versicherer, wie sie in der CHECK24-Tarifzeile
     * vorne stehen. Dient dazu, den Versicherer sauber vom Tarifnamen zu
     * trennen ("DA Direkt Komfort Smart ..." -> Versicherer "DA Direkt",
     * Tarif "Komfort Smart ..."). Mehrwortige Namen zuerst gedanklich - der
     * Abgleich nimmt ohnehin den LAENGSTEN passenden Praefix. Neuer Anbieter
     * = hier eine Zeile ergaenzen (kanonische Schreibweise).
     */
    private const KNOWN_INSURERS = [
        'Sparkassen DirektVersicherung', 'SV SparkassenVersicherung',
        'DA Direkt', 'Allianz Direct', 'HUK-COBURG', 'HUK24', 'CosmosDirekt',
        'Signal Iduna', 'Direct Line', 'BavariaDirekt', 'Rhion Digital', 'Rhion',
        'Allianz', 'AXA', 'HDI', 'DEVK', 'ADAC', 'Verti', 'Generali', 'WGV',
        'R+V24', 'R+V', 'VHV', 'LVM', 'Gothaer', 'Württembergische',
        'Wuerttembergische', 'Zurich', 'ERGO', 'Barmenia', 'Continentale',
        'KRAVAG', 'Baloise', 'Basler', 'uniVersa', 'Ammerländer', 'Ammerlaender',
        'VGH', 'Provinzial', 'Nürnberger', 'Nuernberger', 'FRIDAY', 'Getsafe',
        'Neodigital', 'andsafe', 'mailo', 'Feuersozietät', 'Konzept',
    ];

    public function parse(string $text): ?array
    {
        // Nur zustaendig fuer das CHECK24-Kfz-Beratungsprotokoll.
        $upper = mb_strtoupper($text);
        if (!str_contains($upper, 'BERATUNGSPROTOKOLL') || !str_contains($upper, 'KFZ')
            || !str_contains($upper, 'CHECK24')) {
            return null;
        }

        $person = $this->parsePerson($text);
        $kfz = $this->parseVehicle($text);
        $versicherung = $this->parseInsurance($text);

        // Ohne belastbare Kern-Felder lieber nicht als Template ausgeben
        // (dann uebernimmt die normale Analyse/KI).
        if ($person === [] && $kfz === []) {
            return null;
        }

        return [
            'type' => 'beratungsprotokoll',
            // Deterministisch aus einem bekannten Formular - hoehere Konfidenz
            // als die generische OCR-Heuristik, aber weiter mit Mitarbeiter-
            // Pruefung (der Kunde wird i.d.R. neu angelegt).
            'confidence' => 75,
            'summary' => 'CHECK24-Beratungsprotokoll Kfz - Felder gratis aus der Textebene gelesen (ohne KI).'
                . (isset($versicherung['insurer'])
                    ? ' Gewaehlt: ' . $versicherung['insurer']
                        . (isset($versicherung['tariff']) ? ' ' . $versicherung['tariff'] : '') . '.'
                    : '')
                . (in_array('werkstattbindung', $kfz['extras'] ?? [], true) ? ' Mit Werkstattbindung.' : '')
                . (isset($kfz['has_teilkasko']) || isset($kfz['has_vollkasko'])
                    ? ' Deckung: ' . $this->coverageSummary($kfz) . '.'
                    : '')
                . (isset($versicherung['previous_insurer'])
                    ? ' Vorversicherung: ' . $versicherung['previous_insurer']
                        . (isset($versicherung['previous_insurance_since']) ? ' (' . $versicherung['previous_insurance_since'] . ')' : '') . '.'
                    : '')
                . (isset($kfz['sf_liability_class'])
                    ? ' SF-Klasse Haftpflicht: SF ' . $kfz['sf_liability_class']
                        . (($kfz['sf_liability_type'] ?? null) === 'sondereinstufung'
                            ? ' (Sondereinstufung, nicht uebertragbar'
                                . (isset($kfz['sf_liability_real_class']) ? '; echte Klasse SF ' . $kfz['sf_liability_real_class'] : '')
                                . ')'
                            : '')
                        . '.'
                    : '')
                . (isset($versicherung['end_date']) ? ' Ablauf der Versicherung: ' . $this->displayDate($versicherung['end_date']) . '.' : ''),
            'title' => 'Beratungsprotokoll Kfz'
                . (isset($versicherung['insurer']) ? ' ' . $versicherung['insurer'] : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $versicherung,
                'kfz' => $kfz,
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function parsePerson(string $text): array
    {
        $raw = [
            'birth_date' => $this->germanDate($this->label($text, 'Geburtsdatum') ?? $this->afterWord($text, 'Geboren am')),
            'email' => $this->customerEmail($text),
            'phone' => $this->phone($text),
        ];

        // Geschlecht -> nur intern fuer die Person nicht noetig, aber Wohnort
        // liefert PLZ + Ort zuverlaessig ueber ein Label.
        if (preg_match('/(?:Wohnort|Zulassung in)\s*:\s*(\d{5})\s+([A-Za-zÄÖÜäöüß .\-]+?)(?:\s{2,}|$)/u', $text, $m)) {
            $raw['zip'] = $m[1];
            $raw['city'] = trim($m[2]);
        }

        // Name + Strasse aus dem Kopf-Block (3-Spalten-Layout: Anrede |
        // Anschrift | E-Mail). Die Zeile NACH der Anrede-Zeile traegt in der
        // ersten Spalte den Namen, in der zweiten die Strasse.
        [$name, $street] = $this->nameAndStreet($text);
        if ($name !== null) {
            $parts = preg_split('/\s+/', $name);
            $raw['last_name'] = array_pop($parts);
            $raw['first_name'] = implode(' ', $parts);
        }
        if ($street !== null) {
            // Hausnummer am Ende abspalten - auch mit Leerzeichen vor dem
            // Zusatzbuchstaben ("Mittelstr. 21 b") oder ohne ("Alleestr. 43").
            if (preg_match('/^(.*?)\s+(\d+\s*[a-zA-Z]?)$/u', $street, $m)) {
                $raw['street'] = trim($m[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
            } else {
                $raw['street'] = $street;
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseVehicle(string $text): array
    {
        $raw = [];
        if (preg_match('/HSN\/TSN\s*:\s*(\d{4})\s*\/\s*([A-Z0-9]{3})/i', $text, $m)) {
            $raw['hsn'] = $m[1];
            $raw['tsn'] = strtoupper($m[2]);
        }
        $halter = $this->label($text, 'Halter');
        if ($halter !== null) {
            $raw['holder_type'] = stripos($halter, 'Versicherungsnehmer') !== false
                ? 'versicherungsnehmer' : 'abweichender_halter';
        }
        $mileage = $this->label($text, 'Jährliche Fahrleistung');
        if ($mileage !== null && preg_match('/([\d.]+)/', $mileage, $m)) {
            $raw['annual_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        // Deckung + Selbstbeteiligung. Wichtig: "mit Vollkasko" schliesst die
        // Teilkasko-Risiken ein - weist das Protokoll BEIDE Selbstbeteiligungen
        // aus ("500 € VK, 150 € TK"), gehoeren auch beide in den Vertrag (der
        // Betrieb will Teilkasko 150 UND Vollkasko 500 sehen, nicht nur eine).
        $deckung = $this->label($text, 'Deckung');
        $sb = $this->label($text, 'Selbstbeteiligung') ?? '';
        $hasVoll = $deckung !== null && stripos($deckung, 'Vollkasko') !== false;
        // Vollkasko enthaelt Teilkasko - bei ausgewiesener TK-SB oder Vollkasko
        // gilt Teilkasko als vorhanden.
        $hasTeil = ($deckung !== null && stripos($deckung, 'Teilkasko') !== false)
            || (bool) preg_match('/€\s*TK\b/iu', $sb)
            || $hasVoll;
        // Ist die Deckung ueberhaupt angegeben, beide Flags setzen (auch false).
        if ($deckung !== null || $hasTeil) {
            $raw['has_vollkasko'] = $hasVoll;
            $raw['has_teilkasko'] = $hasTeil;
        }
        // Getrennte Selbstbeteiligungen fuer VK und TK ("500 € VK, 150 € TK").
        if (preg_match('/([\d.]+)\s*€\s*VK\b/iu', $sb, $m)) {
            $raw['vollkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }
        if (preg_match('/([\d.]+)\s*€\s*TK\b/iu', $sb, $m)) {
            $raw['teilkasko_deductible'] = (int) str_replace('.', '', $m[1]);
        }
        // Fallback: nur EIN Betrag ohne VK/TK-Kennung.
        if (!isset($raw['vollkasko_deductible']) && !isset($raw['teilkasko_deductible'])
            && $sb !== '' && preg_match('/([\d.]+)\s*€/u', $sb, $m)) {
            $deductible = (int) str_replace('.', '', $m[1]);
            if ($hasVoll) {
                $raw['vollkasko_deductible'] = $deductible;
            } elseif ($hasTeil) {
                $raw['teilkasko_deductible'] = $deductible;
            }
        }

        // Zusatzleistungen aus dem Protokoll (nur eindeutige Ja-Angaben bzw. der
        // Tarifname): Werkstattbindung, Schutzbrief, Fahrerschutz. Schluessel
        // stammen aus ContractVehicleDetail::EXTRAS.
        $extras = $this->parseExtras($text);
        if ($extras !== []) {
            $raw['extras'] = $extras;
        }
        // SF-Einstufung Haftpflicht (Spaltenlayout ohne Doppelpunkt):
        //   Angegebene SF-Klasse Haftpflicht     SF 4        (oder "keine")
        //   Tatsächliche SF-Klasse Haftpflicht   SF 5 (Sondereinstufung)
        // Die "Tatsaechliche" ist die Einstufung beim NEUEN Versicherer. Steht
        // dahinter "(...Sondereinstufung)", ist sie NICHT uebertragbar - dann
        // ist die "Angegebene" die echte, uebertragbare Klasse des Kunden
        // (sf_liability_real_class). Der Betrieb musste das bisher von Hand
        // auseinanderhalten.
        if (preg_match('/Tats[äa]chliche SF-Klasse Haftpflicht\s+SF\s*(\d{1,2}(?:\/\d)?|[MS])\s*(\(([^)\r\n]*)\))?/ui', $text, $m)) {
            $raw['sf_liability_class'] = strtoupper($m[1]);
            $zusatz = $m[3] ?? '';
            if (stripos($zusatz, 'Sondereinstufung') !== false) {
                $raw['sf_liability_type'] = 'sondereinstufung';
                $raw['sf_liability_special_reason'] = $this->sfSpecialReason($zusatz);
                // Die vom Kunden ANGEGEBENE Klasse = seine echte (uebertragbare).
                if (preg_match('/Angegebene SF-Klasse Haftpflicht\s+SF\s*(\d{1,2}(?:\/\d)?|[MS])/ui', $text, $a)) {
                    $raw['sf_liability_real_class'] = strtoupper($a[1]);
                }
            } else {
                $raw['sf_liability_type'] = 'tatsaechlich';
            }
        }

        return $this->validatedVehicle($raw);
    }

    /** @return array<string,mixed> */
    private function parseInsurance(string $text): array
    {
        $raw = ['sparte' => 'kfz'];

        // Ausgewaehlter Tarif: die Zeile nach "folgenden Tarif:" (z.B.
        // "DA Direkt Komfort Smart mit Werkstattbindung"). Versicherer und
        // Tarifname sauber trennen - der Versicherer ist NICHT immer nur das
        // erste Wort ("DA Direkt", "Sparkassen DirektVersicherung", ...).
        $tariffLine = $this->selectedTariffLine($text);
        if ($tariffLine !== null) {
            [$insurer, $tariff] = $this->splitInsurerTariff($tariffLine);
            if ($insurer !== null) {
                $raw['insurer'] = $insurer;
            }
            if ($tariff !== null) {
                $raw['tariff'] = $tariff;
            }
            // Beitrag zum gewaehlten Tarif aus der Vergleichstabelle lesen
            // (dieselbe Tarifzeile traegt dort den Monatsbeitrag).
            if (preg_match('/' . preg_quote($tariffLine, '/') . '\s+([\d.]+,\d{2})\s*€/u', $text, $m)) {
                $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
                $raw['premium_interval'] = $this->interval((string) $this->label($text, 'Zahlweise')) ?? 'monthly';
            }
        }

        $raw['start_date'] = $this->germanDate($this->label($text, 'Versicherungsbeginn'));

        // "Ablauf der Versicherung  29.06.2027 (automatische Verlaengerung ...)"
        // -> Vertragsablauf (Spaltenlayout ohne Doppelpunkt). Bisher musste der
        // Betrieb dieses Datum bei einem Wechsel von Hand nachtragen.
        if (preg_match('/Ablauf der Versicherung\s+(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['end_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Gesamtbeitrag (inkl. Versicherungssteuer)  222,65 € vierteljährlich
        if (preg_match('/Gesamtbeitrag[^\d]*([\d.]+,\d{2})\s*€\s*([a-zäöü]+)/ui', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $this->interval($m[2]);
        }

        // Vorversicherung (bisheriger Versicherer, von dem der Kunde wechselt).
        // Wichtiger Wechsel-Kontext ("wo war der Kunde vorher"). Es gibt keine
        // eigene Vertragsspalte dafuer - der Wert bleibt in der Extraktion und
        // wird im Zusammenfassungstext sichtbar gemacht.
        if (preg_match('/Vorversicherung:\s*([^\r\n]+)/u', $text, $m)) {
            // Bis zur naechsten Spalte (2+ Leerzeichen) bzw. Zeilenende nehmen.
            $prev = trim((string) (preg_split('/\s{2,}/', trim($m[1]))[0] ?? ''));
            // Manche Layouts trennen die naechste Spalte nur mit EINEM
            // Leerzeichen ("Verti Versicherung AG Zahlweise: jährlich") -
            // am naechsten "Label:" abschneiden.
            $prev = trim((string) preg_replace('/\s+\p{Lu}[\p{L}]*:.*$/u', '', $prev));
            if (preg_match('/\p{L}{2,}/u', $prev) && stripos($prev, 'keine') === false && mb_strlen($prev) <= 60) {
                $raw['previous_insurer'] = $prev;
            }
        }

        // Seit wann beim Vorversicherer ("länger als 3 Jahre") + ob der
        // Vorversicherer gekuendigt hat (relevant fuer Annahme/Beitrag beim
        // neuen Versicherer). Nur setzen, wenn eine Vorversicherung erkannt ist.
        if (isset($raw['previous_insurer'])) {
            $since = $this->label($text, 'Seit wann');
            if ($since !== null && mb_strlen($since) <= 60) {
                $raw['previous_insurance_since'] = $since;
            }
            // "Kündigung durch\nVorversicherer: nein" (das Label steht ueber
            // zwei Zeilen; \s matcht auch den Zeilenumbruch).
            if (preg_match('/K[üu]ndigung durch\s+Vorversicherer\s*:?\s*(ja|nein)/ui', $text, $k)) {
                $raw['previous_insurance_terminated'] = mb_strtolower($k[1]) === 'ja';
            }
        }

        return $this->validatedInsurance(array_filter(
            $raw,
            // false (z.B. previous_insurance_terminated) MUSS erhalten bleiben.
            fn ($v) => $v !== null && $v !== ''
        ));
    }

    /** Kurztext der Deckung fuer die Zusammenfassung (Haftpflicht immer dabei). */
    private function coverageSummary(array $kfz): string
    {
        $parts = ['Haftpflicht'];
        if (!empty($kfz['has_teilkasko'])) {
            $parts[] = 'Teilkasko' . (isset($kfz['teilkasko_deductible']) ? ' (' . $kfz['teilkasko_deductible'] . ' EUR SB)' : '');
        }
        if (!empty($kfz['has_vollkasko'])) {
            $parts[] = 'Vollkasko' . (isset($kfz['vollkasko_deductible']) ? ' (' . $kfz['vollkasko_deductible'] . ' EUR SB)' : '');
        }
        return implode(', ', $parts);
    }

    /** Die ausgewaehlte Tarifzeile (nach "folgenden Tarif:") oder null. */
    private function selectedTariffLine(string $text): ?string
    {
        if (preg_match('/folgenden Tarif:\s*\R+\s*([^\r\n]+)/u', $text, $m)) {
            $line = trim($m[1]);
            return $line !== '' ? $line : null;
        }
        return null;
    }

    /**
     * Versicherer + Tarifname aus der Tarifzeile trennen. Der Versicherer wird
     * am laengsten passenden bekannten Namen erkannt (kanonische Schreibweise);
     * faellt keiner, wird konservativ das erste Wort genommen.
     *
     * @return array{0:?string,1:?string} [Versicherer, Tarifname]
     */
    private function splitInsurerTariff(string $line): array
    {
        $line = trim($line);
        $best = null;
        foreach (self::KNOWN_INSURERS as $name) {
            if (preg_match('/^' . preg_quote($name, '/') . '(?=\s|$)/iu', $line)
                && ($best === null || mb_strlen($name) > mb_strlen($best))) {
                $best = $name;
            }
        }
        if ($best !== null) {
            $tariff = trim(mb_substr($line, mb_strlen($best)));
            return [$best, $tariff !== '' ? $tariff : null];
        }
        $parts = preg_split('/\s+/', $line, 2);
        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    /**
     * Zusatzleistungen aus dem Protokoll (Schluessel aus
     * ContractVehicleDetail::EXTRAS). Bewusst konservativ: nur der Tarifname
     * (Werkstattbindung) und eindeutige "gewuenscht: ja"-Angaben - nichts aus
     * dem langen Leistungskatalog erraten.
     *
     * @return list<string>
     */
    private function parseExtras(string $text): array
    {
        $extras = [];
        // Werkstattbindung: aus dem Tarifnamen ("... mit Werkstattbindung/
        // -service/-bonus") oder ueber "Nur freie Werkstattwahl: nein".
        $tariff = $this->selectedTariffLine($text) ?? '';
        $freeChoice = $this->label($text, 'Nur freie Werkstattwahl');
        if (preg_match('/mit Werkstatt(bindung|service|bonus)/iu', $tariff)
            || ($freeChoice !== null && mb_strtolower(trim($freeChoice)) === 'nein')) {
            $extras[] = 'werkstattbindung';
        }
        // Schutzbrief / Fahrerschutz nur bei ausdruecklichem "gewuenscht: ja".
        foreach (['Schutzbrief' => 'schutzbrief', 'Fahrerschutz' => 'fahrerschutz'] as $labelText => $key) {
            $val = $this->label($text, $labelText . ' gewünscht');
            if ($val !== null && mb_strtolower(trim($val)) === 'ja') {
                $extras[] = $key;
            }
        }
        return array_values(array_unique($extras));
    }

    /**
     * Wert nach "Label:" bis zum Spaltenumbruch (2+ Leerzeichen) oder
     * Zeilenende. Der /m-Modifier ist wichtig: ohne ihn matcht "$" nur am
     * Dokumentende, sodass ein Wert am ZEILENENDE (ohne folgende Spalte, aber
     * nicht in der letzten Zeile) nicht erkannt wuerde (z.B. "Fahrerschutz
     * gewünscht: ja" mitten im Dokument).
     */
    private function label(string $text, string $label): ?string
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*:\s*([^\n]+?)(?:\s{2,}|$)/um';
        return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
    }

    private function afterWord(string $text, string $word): ?string
    {
        return preg_match('/' . preg_quote($word, '/') . '\s+([^\s]+)/u', $text, $m) ? $m[1] : null;
    }

    /** Erste E-Mail, die NICHT von CHECK24 stammt (= die des Kunden). */
    private function customerEmail(string $text): ?string
    {
        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $mm)) {
            foreach ($mm[0] as $email) {
                if (stripos($email, '@check24') === false) {
                    return strtolower($email);
                }
            }
        }
        return null;
    }

    private function phone(string $text): ?string
    {
        // Erste 0-Nummer, die WIE eine deutsche Telefon-/Mobilnummer aussieht -
        // so wird nicht versehentlich eine lange 0-Vertrags-/Referenznummer als
        // Telefon uebernommen.
        if (preg_match_all('/\b(0\d{9,14})\b/', $text, $mm)) {
            foreach ($mm[1] as $candidate) {
                if (\App\Support\GermanPhone::isMobile($candidate) || \App\Support\GermanPhone::isLandline($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /** @return array{0:?string,1:?string} [Name, Strasse] aus dem Kopf-Block. */
    private function nameAndStreet(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(Herr|Frau)\b.*Anschrift/u', $line) && isset($lines[$i + 1])) {
                $cols = preg_split('/\s{2,}/', trim($lines[$i + 1]));
                $name = ($cols[0] ?? '');
                $street = $cols[1] ?? null;
                // Name muss wie ein Name aussehen (>= 2 Woerter, nur Buchstaben).
                if (!preg_match('/^[A-Za-zÄÖÜäöüß\-]+(?:\s+[A-Za-zÄÖÜäöüß\-]+)+$/u', $name)) {
                    $name = null;
                }
                return [$name ?: null, $street];
            }
        }
        return [null, null];
    }

    private function germanDate(?string $value): ?string
    {
        if ($value !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return null;
    }

    /** ISO-Datum ("2027-06-29") fuer die Anzeige nach "29.06.2027". */
    private function displayDate(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $iso;
    }

    /**
     * Grund der Sondereinstufung aus dem Klammerzusatz ("Zweitwagen-
     * Sondereinstufung" -> zweitwagen). Traegt der Zusatz keinen erkennbaren
     * Grund (nur "Sondereinstufung"), bleibt er leer - der Mitarbeiter waehlt
     * ihn dann im Vertrag (lieber leer als geraten).
     */
    private function sfSpecialReason(string $zusatz): ?string
    {
        $z = mb_strtolower($zusatz);
        return match (true) {
            str_contains($z, 'zweitwagen') => 'zweitwagen',
            str_contains($z, 'drittwagen') => 'drittwagen',
            str_contains($z, 'führerschein') || str_contains($z, 'fuehrerschein') =>
                str_contains($z, '5') ? 'fuehrerschein_5' : 'fuehrerschein_3',
            str_contains($z, 'familie') => 'familie',
            str_contains($z, 'firmen') => 'firmenfahrzeug',
            str_contains($z, 'sonderaktion') => 'sonderaktion',
            default => null,
        };
    }

    private function interval(string $german): ?string
    {
        return match (mb_strtolower($german)) {
            'monatlich' => 'monthly',
            'vierteljährlich' => 'quarterly',
            'halbjährlich' => 'semiannual',
            'jährlich' => 'yearly',
            default => null,
        };
    }
}
