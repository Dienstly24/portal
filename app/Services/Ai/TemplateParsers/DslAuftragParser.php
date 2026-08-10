<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer die Auftragsbestaetigung eines DSL-/Internet-Anschlusses (z.B.
 * die CHECK24-Uebersicht "Ihr DSL Anschluss" oder der Kabel-Auftrag fuer
 * Vodafone Kabel Deutschland). Der Auftrag traegt bereits alle Kern-Daten,
 * auf die sich der Betrieb stuetzt (Betreiber-Vorgabe 10.08.2026: ALLE
 * Details des Auftrags gehoeren in die Vertragsakte):
 *
 *   Kundendaten : Name, Anschrift, Handynummer, E-Mail, Geburtsdatum
 *   Tarif       : Anbieter, Tarif, Download/Upload, Mindestlaufzeit,
 *                 Kuendigungsfrist, Durchschnittspreis pro Monat
 *   Preise      : Grundgebuehr-Stufen (Aktionspreis -> regulaerer Preis),
 *                 einmalige Kosten (Bereitstellungsgebuehr, Versandkosten),
 *                 Router-Modell + Aufpreis, Bonus/Cashback und Gutschriften,
 *                 Kosten nach der Mindestlaufzeit (nur Zusammenfassung)
 *   Auftrag     : Auftragsnummer, Anschlusstermin
 *
 * Ergebnis: Typ 'internetvertrag' (Sparte internet). Der spaeter zugestellte
 * Provider-Vertrag mit der finalen Vertragsnummer laesst sich ergaenzend
 * hochladen. Die IBAN ist im Auftrag ueblicherweise maskiert (DE46****2425)
 * und wird bewusst NICHT als Bankverbindung uebernommen. Ein Anschlusstermin
 * wird nur als Beginn uebernommen, wenn er ein ECHTES Datum ist -
 * "schnellstmoeglich" wird nie geraten.
 */
class DslAuftragParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /**
     * Bekannte Router-Modelle der grossen Anbieter (Telekom Speedport,
     * AVM FRITZ!Box, 1&1 HomeServer, Vodafone Station/EasyBox, o2 HomeBox,
     * Kabel: Connect Box / GigaCube ...). Ein generisches "Router" waere zu
     * riskant ("Routergutschrift" ist ein Abzug, kein Geraet).
     */
    private const ROUTER_MODELS = 'Speedport|FRITZ!?\s?Box|FritzBox|Home\s?Server'
        . '|Easy\s?Box|Connect\s?Box|Home\s?Box|Giga\s?Cube|Giga\s?Box|Speedbox'
        . '|Kabelrouter|Vodafone\s+(?:Power\s?)?Station';

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        // DSL-/Internet-Auftrag: Anbieter + Mindestlaufzeit + ein klarer
        // Internet-Marker (MBit/DSL/Anschluss). Grenzt gegen Versicherungs-/
        // Energie-Dokumente ab.
        $hasInternetMarker = str_contains($upper, 'MBIT') || str_contains($upper, 'DSL')
            || str_contains($upper, 'ANSCHLUSS') || str_contains($upper, 'MAGENTA')
            || str_contains($upper, 'INTERNET');
        if (!str_contains($upper, 'ANBIETER') || !str_contains($upper, 'MINDESTLAUFZEIT') || !$hasInternetMarker) {
            return null;
        }

        $lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        // Spalten-OCR (Screenshot/Handyfoto): Tesseract liest die CHECK24-
        // Karten als SPALTEN-Bloecke - erst alle Beschriftungen, dann alle
        // Werte, dann die Betraege. Kein Label trifft seinen Wert auf einer
        // Zeile. Die Paare werden konservativ rekonstruiert und als
        // synthetische "Label  Wert"-Zeilen angehaengt - danach greifen die
        // normalen Zeilen-Regexe unveraendert.
        $synth = $this->pairColumnLayout($lines);
        if ($synth !== []) {
            $text .= "\n" . implode("\n", $synth);
            $lines = array_merge($lines, $synth);
        }

        $person = $this->parsePerson($text, $lines);
        $contract = $this->parseContract($text, $lines);
        $internet = $this->parseInternet($text);
        $zusatz = $this->parseZusatz($text, $lines);

        // Ohne belastbaren Vertragskern (Anbieter/Tarif/Preise) NICHT mit
        // "nur Name und Adresse" gewinnen - null zurueckgeben, damit die
        // Analyse normal weiterlaeuft und die KI-Eskalation das Bild
        // vollstaendig liest (Lehre 10.08.2026: Spalten-OCR ohne lesbare
        // Paare lieferte sonst eine fast leere Akte).
        if (!isset($contract['insurer']) && !isset($contract['tariff'])
            && !isset($contract['premium_amount'])
            && !isset($internet['price_initial']) && !isset($internet['price_regular'])) {
            return null;
        }

        // Ohne "Durchschnitt pro Monat" (steht nicht auf jedem Auftrag) ist
        // der regulaere Monatspreis der ehrlichste Beitrag.
        if (empty($contract['premium_amount']) && !empty($internet['price_regular'])) {
            $contract['premium_amount'] = $internet['price_regular'];
            $contract['premium_interval'] = 'monthly';
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        return [
            'type' => 'internetvertrag',
            'confidence' => 70,
            'summary' => $this->buildSummary($name, $contract, $internet, $zusatz),
            'title' => 'Internet-/DSL-Auftrag' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $contract,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
                'internet' => $internet,
            ],
        ];
    }

    /**
     * Zusammenfassung mit ALLEN Auftrags-Details (Betreiber-Vorgabe
     * 10.08.2026): Preisstufen, einmalige Kosten, Router, Bonus, Laufzeit,
     * Kosten nach der Mindestlaufzeit, Anschlusstermin. Werte, die kein
     * eigenes Vertragsfeld haben (Kuendigungsfrist, "ab Monat 25" ...),
     * stehen NUR hier.
     *
     * @param array<string,mixed> $contract
     * @param array<string,mixed> $internet
     * @param array<string,mixed> $zusatz
     */
    private function buildSummary(string $name, array $contract, array $internet, array $zusatz): string
    {
        $teile = [];
        $teile[] = 'Internet-/DSL-Auftrag'
            . (isset($contract['insurer']) ? ' - ' . $contract['insurer'] : '')
            . (isset($contract['tariff']) ? ' ' . $contract['tariff'] : '')
            . ($name !== '' ? ' - ' . $name : '');

        if (isset($internet['speed'])) {
            $teile[] = $internet['speed']
                . (isset($internet['upload_speed']) ? ' / ' . $internet['upload_speed'] . ' Upload' : '');
        }
        if (isset($internet['price_initial'], $internet['price_initial_months'])) {
            $teile[] = 'Grundgebuehr ' . $this->euro($internet['price_initial'])
                . '/Monat (Monat 1-' . $internet['price_initial_months'] . ')'
                . (isset($internet['price_regular']) ? ', danach ' . $this->euro($internet['price_regular']) . '/Monat' : '');
        } elseif (isset($internet['price_regular'])) {
            $teile[] = 'Grundgebuehr ' . $this->euro($internet['price_regular']) . '/Monat';
        }

        $einmalig = [];
        if (isset($internet['setup_fee'])) {
            $einmalig[] = 'Bereitstellung ' . $this->euro($internet['setup_fee']);
        }
        if (isset($internet['shipping_fee'])) {
            $einmalig[] = 'Versand ' . $this->euro($internet['shipping_fee']);
        }
        if ($einmalig !== []) {
            $teile[] = 'einmalig: ' . implode(' + ', $einmalig);
        }

        if (!empty($internet['has_router'])) {
            $teile[] = 'Router' . (isset($internet['router_name']) ? ' ' . $internet['router_name'] : '')
                . (isset($internet['router_price']) ? ' ' . $this->euro($internet['router_price']) . '/Monat' : '');
        }
        if (isset($internet['bonus_amount'])) {
            $teile[] = 'Bonus/Cashback ' . $this->euro($internet['bonus_amount']);
        }
        if (isset($internet['voucher_amount'])) {
            $teile[] = 'Gutschrift ' . $this->euro($internet['voucher_amount']);
        }
        if (isset($internet['min_duration_months'])) {
            $frist = isset($zusatz['kuendigungsfrist'])
                ? ' (Kuendigungsfrist ' . $zusatz['kuendigungsfrist']
                    . (isset($zusatz['verlaengerung']) ? ', Verlaengerung ' . $zusatz['verlaengerung'] : '')
                    . ')'
                : '';
            $teile[] = 'Mindestlaufzeit ' . $internet['min_duration_months'] . ' Monate' . $frist;
        }
        if (isset($zusatz['after_term_month'], $zusatz['after_term_price'])) {
            $teile[] = 'ab Monat ' . $zusatz['after_term_month'] . ': '
                . $this->euro($zusatz['after_term_price']) . '/Monat';
        }
        if (isset($zusatz['durchschnitt'])) {
            $teile[] = 'Durchschnitt ' . $this->euro($zusatz['durchschnitt']) . '/Monat';
        }
        if (!empty($zusatz['inklusive'])) {
            $teile[] = 'inklusive: ' . implode(', ', $zusatz['inklusive']);
        }
        if (isset($zusatz['anschlusstermin'])) {
            $teile[] = 'Anschlusstermin ' . $zusatz['anschlusstermin'];
        }
        $teile[] = 'Felder gratis aus dem Auftrag gelesen (ohne KI).';

        return implode(' - ', $teile);
    }

    /**
     * Internet-Detaildaten aus der CHECK24-Preisuebersicht: Tarifname,
     * Download/Upload, preisvariabler Tarif (Grundgebuehr-Stufen), Router
     * (inklusive/Aufpreis), Bonus/Gutschein (stehen als Abzug -155,00 EUR),
     * einmalige Kosten (Bereitstellung, Versand) und Mindestlaufzeit.
     *
     * @return array<string,mixed>
     */
    private function parseInternet(string $text): array
    {
        $raw = [];

        // Tarifname (auch fuer die Detailtabelle). Nur MIT Wert auf derselben
        // Zeile (\h = horizontaler Leerraum) - sonst frisst sich der Ausdruck
        // ueber die Ueberschrift "Ihr Tarif" in die Folgezeile.
        if (preg_match('/\bTarif\b\h*:?\h+([^\r\n]+?)(?:\s{2,}|$)/mu', $text, $m)) {
            $raw['tariff'] = trim($m[1]);
        }

        // Download-/Upload-Geschwindigkeit (z.B. "100 MBit/s", "40,0 MBit/s").
        if (preg_match('/Max\.?\s*Download[^\d\r\n]{0,20}(\d{1,4}(?:[.,]\d+)?\s*MBit\/?s)/iu', $text, $m)) {
            $raw['speed'] = trim((string) preg_replace('/\s+/', ' ', $m[1]));
        }
        if (preg_match('/Max\.?\s*Upload[^\d\r\n]{0,20}(\d{1,4}(?:[.,]\d+)?\s*MBit\/?s)/iu', $text, $m)) {
            $raw['upload_speed'] = trim((string) preg_replace('/\s+/', ' ', $m[1]));
        }

        // Preisvariabel: alle "Grundgebuehr Monat X - Y ... Betrag"-Zeilen.
        // Erste Stufe (Monat 1) = Aktionspreis + Aktionsdauer (Ende der Stufe),
        // letzte Stufe = regulaerer Preis.
        if (preg_match_all('/Grundgeb(?:ü|ue|u)hr\s*Monat\s*(\d{1,2})\s*[-–—]\s*(\d{1,3})[^\d\r\n]{0,40}?(\d{1,3}(?:\.\d{3})*,\d{2})/iu', $text, $mm, PREG_SET_ORDER)) {
            $first = $mm[0];
            $last = $mm[count($mm) - 1];
            $raw['price_initial'] = $this->amount($first[3]);
            $raw['price_initial_months'] = (int) $first[2];
            $raw['price_regular'] = $this->amount($last[3]);
        } elseif (preg_match('/Grundgeb(?:ü|ue|u)hr(?!\s*Monat)[^\d\r\n]{0,40}?(\d{1,3}(?:\.\d{3})*,\d{2})/iu', $text, $m)) {
            // Fester Tarif ohne Preisstufen: eine Grundgebuehr = regulaerer Preis.
            $raw['price_regular'] = $this->amount($m[1]);
        }

        // Einmalige Kosten: Bereitstellungs-/Anschluss-/Einrichtungsgebuehr
        // ("was kostet die Schaltung") und Versandkosten fuer den Router.
        if (preg_match('/(?:Bereitstellungs?|Anschluss|Einrichtungs?|Aktivierungs?)[\- ]?(?:geb(?:ü|ue|u)hr|preis|entgelt|kosten)[^\d\r\n]{0,45}?(\d{1,3}(?:\.\d{3})*,\d{2})/iu', $text, $m)) {
            $raw['setup_fee'] = $this->amount($m[1]);
        }
        if (preg_match('/Versand(?:kosten|pauschale)?[^\d\r\n]{0,45}?(\d{1,3}(?:\.\d{3})*,\d{2})/iu', $text, $m)) {
            $raw['shipping_fee'] = $this->amount($m[1]);
        }

        // Mindestlaufzeit (Monate): beim Auftrag gibt es noch keinen
        // Anschlusstermin, Beginn/Ablauf des Vertrags bleiben also leer -
        // die Laufzeit muss deshalb als eigenes Feld in die Akte.
        if (preg_match('/(?:Mindest(?:vertrags)?laufzeit|Vertragslaufzeit)\D{0,25}?(\d{1,2})\s*Monat/iu', $text, $m)) {
            $raw['min_duration_months'] = (int) $m[1];
        }

        // Router (z.B. "Telekom Speedport Smart 4", "AVM FRITZ!Box 7590",
        // "Vodafone Station"): Name aus der ersten Fundstelle, Aufpreis =
        // hoechster Betrag auf den Router-Zeilen (die Aktionsstufe ist oft
        // 0,00, danach der Aufpreis).
        if (preg_match('/((?:(?:Telekom|AVM|Vodafone|1&1|o2)\s+)?(?:' . self::ROUTER_MODELS . ')[A-Za-z0-9 .\-!+]*?)(?=\s{2,}|\s*Monat\b|\s*\d+\s*[-–—]|\s*\d{1,3}(?:\.\d{3})*,\d{2}|\s*[\r\n]|\s*$)/iu', $text, $m)) {
            $raw['has_router'] = true;
            $name = trim((string) preg_replace('/\s+/', ' ', $m[1]));
            if ($name !== '') {
                $raw['router_name'] = $name;
            }
            // Alle Betraege auf Zeilen mit Router-Bezug einsammeln -> Maximum.
            $prices = [];
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                if (preg_match('/' . self::ROUTER_MODELS . '/iu', $line)
                    && preg_match_all('/(\d{1,3}(?:\.\d{3})*,\d{2})/u', $line, $pm)) {
                    foreach ($pm[1] as $p) {
                        $prices[] = $this->amount($p);
                    }
                }
            }
            if ($prices !== []) {
                $raw['router_price'] = max($prices);
            }
        }

        // Bonus/Cashback und Gutschrift/Gutschein (stehen als Abzug, Betrag
        // wird als positive Magnitude uebernommen).
        if (preg_match('/Cashback[^\d\r\n]{0,45}?[-–—]?\s*(\d{1,3}(?:\.\d{3})*,\d{2})/iu', $text, $m)) {
            $raw['bonus_amount'] = $this->amount($m[1]);
        }
        if (preg_match('/(?:Routergutschrift|Gutschrift|Gutschein)[^\d\r\n]{0,45}?[-–—]?\s*(\d{1,3}(?:\.\d{3})*,\d{2})/iu', $text, $m)) {
            $raw['voucher_amount'] = $this->amount($m[1]);
        }

        return $this->validatedInternet(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Angaben ohne eigenes Vertragsfeld - sie gehoeren trotzdem zur vollen
     * Auskunft und stehen deshalb in der Zusammenfassung: Kuendigungsfrist,
     * Verlaengerung, Kosten nach der Mindestlaufzeit ("Mtl. Kosten ab dem
     * 25. Monat"), Durchschnittspreis, Anschlusstermin und die im Preis
     * enthaltenen 0,00-Optionen (z.B. "Basis Kabelfernsehen (TV Connect)").
     *
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private function parseZusatz(string $text, array $lines): array
    {
        $raw = [];

        if (preg_match('/K(?:ü|ue|u)ndigungsfrist\D{0,25}?(\d{1,2})\s*(Monat(?:e|en)?|Woche(?:n)?|Tag(?:e|en)?)/iu', $text, $m)) {
            $raw['kuendigungsfrist'] = $m[1] . ' ' . $m[2];
        }
        if (preg_match('/Verl(?:ä|ae|a)ngerung\D{0,25}?(\d{1,2})\s*(Monat(?:e|en)?|Woche(?:n)?|Tag(?:e|en)?)/iu', $text, $m)) {
            $raw['verlaengerung'] = $m[1] . ' ' . $m[2];
        }
        if (preg_match('/Kosten ab dem\s*(\d{1,3})\.\s*Monat[^\d\r\n]{0,40}?(\d{1,3}(?:\.\d{3})*,\d{2})/iu', $text, $m)) {
            $raw['after_term_month'] = (int) $m[1];
            $raw['after_term_price'] = $this->amount($m[2]);
        }
        if (preg_match('/Durchschnitt pro Monat[^\d\r\n]*(\d{1,3}(?:\.\d{3})*,\d{2})/u', $text, $m)) {
            $raw['durchschnitt'] = $this->amount($m[1]);
        }
        if (preg_match('/Anschlusstermin\h*:?\h*([^\r\n]+?)(?:\s{2,}|$)/mu', $text, $m) && trim($m[1]) !== '') {
            $raw['anschlusstermin'] = trim($m[1]);
        }

        // Im Preis enthaltene 0,00-Positionen (TV-Option, Flatrate ...).
        // Grundgebuehr-/Router-Aktionsstufen und Einmalkosten zaehlen nicht.
        $inklusive = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\p{L}[^\r\n]{2,60}?)\s+[-–—]?\s*0,00\s*€?\s*$/u', $line, $m)
                && !preg_match('/Monat|Grundgeb|Versand|Bereitstellung|' . self::ROUTER_MODELS . '/iu', $m[1])) {
                $inklusive[] = trim((string) preg_replace('/\s{2,}/', ' ', $m[1]));
            }
        }
        if ($inklusive !== []) {
            $raw['inklusive'] = array_slice(array_values(array_unique($inklusive)), 0, 3);
        }

        return $raw;
    }

    /** Deutschen Geldbetrag ("1.234,56") als float. */
    private function amount(string $s): float
    {
        return (float) str_replace(['.', ','], ['', '.'], $s);
    }

    /** Betrag fuer die Zusammenfassung ("49,99 EUR"). */
    private function euro(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' EUR';
    }

    /**
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private function parsePerson(string $text, array $lines): array
    {
        $raw = [];

        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m)) {
            $raw['email'] = strtolower($m[0]);
        }
        // Geburtsdatum: nach dem Label.
        if (preg_match('/Geburtsdatum\D*(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // Handynummer/Telefon fuer Rueckfragen.
        if (preg_match('/(?:Handynummer|Telefon)[^\d]*(0[\d\s\/()+-]{8,20})/u', $text, $m)) {
            $digits = preg_replace('/[\s\/()+-]/', '', $m[1]);
            if (preg_match('/^0\d{7,14}$/', (string) $digits)) {
                $raw['phone'] = $digits;
            }
        }

        // Anschrift: Zeile "Adresse" gefolgt von Name / Strasse / PLZ Ort, oder
        // - falls das Label fehlt - ueber die "PLZ Ort"-Zeile und die Zeilen
        // darueber (Name, Strasse).
        $zip = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/(\d{5})\s+([A-ZÄÖÜ][\p{L}\-. ]{2,})$/u', trim($line), $m)
                && !preg_match('/(MBit|Monat|Euro|€|Tarif)/ui', $line)) {
                $zip = [$i, $m[1], trim($m[2])];
                break;
            }
        }
        if ($zip !== null) {
            $raw['zip'] = $zip[1];
            $raw['city'] = $zip[2];
            // Name + Strasse in den beiden nicht-leeren Zeilen ueber der PLZ.
            // Der Name steht oft rechts neben dem Label "Adresse" - das Label
            // wird abgeschnitten.
            $above = [];
            for ($j = $zip[0] - 1; $j >= 0 && count($above) < 2; $j--) {
                $v = trim((string) preg_replace('/^Adresse\s*:?\s*/iu', '', trim($lines[$j])));
                if ($v !== '') {
                    $above[] = $v;
                }
            }
            // above[0] = Strasse (naeher an PLZ), above[1] = Name.
            if (isset($above[0]) && preg_match('/^([A-ZÄÖÜ].*\D)\s*(\d+(?:\s*[a-zA-Z])?)$/u', $above[0], $s) && preg_match('/\p{L}{3,}/u', $s[1])) {
                $raw['street'] = trim($s[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
            }
            if (isset($above[1]) && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+)+$/u', $above[1])) {
                $parts = preg_split('/\s+/', $above[1]) ?: [];
                $raw['first_name'] = array_shift($parts);
                $raw['last_name'] = implode(' ', $parts) ?: null;
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private function parseContract(string $text, array $lines): array
    {
        // Der DSL-Auftrag ist noch keine Vertragsbestaetigung des Providers:
        // Stufe 'antrag'. Der spaeter zugestellte Provider-Vertrag ergaenzt
        // denselben Vertrag (finale Vertragsnummer, Preise) statt einen
        // zweiten anzulegen.
        $raw = ['sparte' => 'internet', 'document_stage' => \App\Models\Contract::STAGE_ANTRAG];

        // Anbieter (z.B. Telekom, Vodafone Kabel Deutschland, 1&1, o2) -
        // Wert neben dem Label (\h haelt die Suche auf derselben Zeile, sonst
        // wuerde ein allein stehendes Label die Folgezeile einsammeln); bei
        // Spalten-/Foto-OCR notfalls kontrolliert in der Folgezeile (gleiche
        // Lehre wie beim WGV-Handyfoto).
        if (preg_match('/Anbieter\h*:?\h+([^\r\n]+?)(?:\s{2,}|$)/mu', $text, $m)) {
            $raw['insurer'] = trim($m[1]);
        } elseif (($v = $this->valueOnNextLine($lines, '/^Anbieter\s*:?$/iu')) !== null) {
            $raw['insurer'] = $v;
        }
        // Tarif (z.B. "Magenta Zuhause L", "Young GigaZuhause 300 Kabel") -
        // die Ueberschrift "Ihr Tarif" (ohne Wert dahinter) matcht nicht.
        if (preg_match('/\bTarif\b\h*:?\h+([^\r\n]+?)(?:\s{2,}|$)/mu', $text, $m)) {
            $raw['tariff'] = trim($m[1]);
        } elseif (($v = $this->valueOnNextLine($lines, '/^Tarif\s*:?$/iu')) !== null) {
            $raw['tariff'] = $v;
        }
        // Auftragsnummer als Vertrags-/Auftragsnummer (bis der finale
        // Provider-Vertrag mit eigener Nummer nachgereicht wird). Nur auf
        // derselben Zeile - im Spalten-OCR folgt auf das blosse Label sonst
        // eine fremde Zeile (z.B. "IBAN" wuerde zur Auftragsnummer).
        if (preg_match('/Auftragsnummer\h*:?\h*([A-Z0-9\-]{4,})/u', $text, $m)) {
            $raw['contract_number'] = trim($m[1]);
        }
        // Anschlusstermin: nur ein ECHTES Datum wird als Beginn uebernommen -
        // "schnellstmoeglich" wird nie geraten (die spaetere Bestaetigung des
        // Providers bringt den Termin und ergaenzt DIESEN Vertrag).
        if (preg_match('/Anschlusstermin[^\d\r\n]{0,25}?(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $text, $m)) {
            $raw['start_date'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        // Durchschnittspreis pro Monat -> Monatsbeitrag. Nur auf derselben
        // Zeile suchen: im Spalten-OCR steht das blosse Label weit VOR dem
        // Betragsblock - zeilenuebergreifend wuerde der ERSTE Betrag der
        // Liste (Grundgebuehr) faelschlich zum Durchschnitt.
        if (preg_match('/Durchschnitt pro Monat[^\d\r\n]*(\d{1,3}(?:\.\d{3})*,\d{2})/u', $text, $m)) {
            $raw['premium_amount'] = $this->amount($m[1]);
            $raw['premium_interval'] = 'monthly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Spalten-Block-OCR rekonstruieren (Lehre 10.08.2026, mit Chromium +
     * Tesseract nachgestellt): Bei einem Screenshot der CHECK24-Uebersicht
     * liest Tesseract (PSM 3) je Karte ZWEI Bloecke - erst alle
     * Beschriftungen, dann alle Werte; die Preisliste liefert erst alle
     * Positionsnamen, dann alle Betraege in derselben Reihenfolge.
     *
     * Rekonstruiert werden nur eindeutig belegbare Paare und NUR streng:
     * - Tarif-Karte ueber die selbstidentifizierenden "MBit/s"-Werte
     *   (davor stehen in der festen CHECK24-Reihenfolge Tarif und Anbieter,
     *   danach die Monats-Angaben; Beschriftungen muessen als eigene Zeilen
     *   vorhanden sein, sonst passiert nichts).
     * - Preisliste nur, wenn Positionsnamen und Nur-Betrag-Zeilen EXAKT
     *   gleich viele sind (sonst lieber gar nichts als eine Fehlzuordnung).
     *
     * Ergebnis: synthetische "Label  Wert"-Zeilen fuer die normalen Regexe.
     * Im gewoehnlichen Zeilen-Layout (Label und Wert nebeneinander) gibt es
     * keine allein stehenden Beschriftungen -> leeres Ergebnis, kein Effekt.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function pairColumnLayout(array $lines): array
    {
        $t = array_map('trim', $lines);
        $count = count($t);

        // Nur aktiv, wenn die Kern-Beschriftungen ALLEIN auf ihren Zeilen
        // stehen (Spalten-Block-OCR).
        if ($this->firstIndex($t, '/^Anbieter\s*:?$/iu') === null
            || $this->firstIndex($t, '/^Tarif\s*:?$/iu') === null) {
            return [];
        }

        $synth = [];
        $amountRe = '/^[-–—]?\s*\d{1,3}(?:\.\d{3})*,\d{2}\s*€?$/u';
        $labelOderKopf = '/^(Anbieter|Tarif(?:kosten)?|Max\.|Mindestlaufzeit|K(?:ü|ue|u)ndigungsfrist'
            . '|Verl(?:ä|ae|a)ngerung|Anschlusstermin|Grundgeb|IBAN|E-?Mail|Geburtsdatum|Zahlungsart'
            . '|Kreditinstitut|Handynummer|Adresse|Preis|Vorteile|Hardware|Ihre?\b)/iu';

        // --- Selbst-identifizierende Kundendaten-Werte -------------------
        // Geburtsdatum (Datum in der Vergangenheit) und Handynummer (0...)
        // stehen im Werte-Block ohne ihr Label; das Muster ist eindeutig,
        // gepaart wird nur, wenn die Beschriftung allein auf ihrer Zeile
        // steht. Ein ZUKUNFTS-Datum wird nie Geburtsdatum (das waere z.B.
        // der Anschlusstermin).
        if ($this->firstIndex($t, '/^Geburtsdatum\s*:?$/iu') !== null) {
            $idx = $this->firstIndex($t, '/^\d{1,2}\.\d{1,2}\.\d{4}$/u');
            if ($idx !== null && preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/u', $t[$idx], $dm)
                && sprintf('%04d-%02d-%02d', $dm[3], $dm[2], $dm[1]) < date('Y-m-d')) {
                $synth[] = 'Geburtsdatum  ' . $t[$idx];
            }
        }
        if ($this->firstIndex($t, '/^(?:Handynummer[^\r\n]*?|Telefon(?:nummer)?)\s*:?$/iu') !== null) {
            $idx = $this->firstIndex($t, '/^0[\d\s\/()+-]{7,20}$/u');
            if ($idx !== null) {
                $synth[] = 'Handynummer  ' . $t[$idx];
            }
        }

        // --- Tarif-Karte: MBit/s-Werte als Anker -------------------------
        $speedIdx = [];
        foreach ($t as $i => $l) {
            if (preg_match('/^\d{1,4}(?:[.,]\d+)?\s*MBit\/?s$/iu', $l)) {
                $speedIdx[] = $i;
            }
        }
        if ($speedIdx !== []) {
            if ($this->firstIndex($t, '/^Max\.?\s*Download\s*:?$/iu') !== null) {
                $synth[] = 'Max. Download  ' . $t[$speedIdx[0]];
            }
            if (isset($speedIdx[1]) && $this->firstIndex($t, '/^Max\.?\s*Upload\s*:?$/iu') !== null) {
                $synth[] = 'Max. Upload  ' . $t[$speedIdx[1]];
            }

            // Werte VOR dem ersten MBit-Wert: [Anschlusstermin,] Anbieter,
            // Tarif - rueckwaerts eingesammelt; eine Beschriftung oder ein
            // Betrag bricht ab (Struktur nicht verstanden -> nichts raten).
            $prev = [];
            for ($i = $speedIdx[0] - 1; $i >= 0 && count($prev) < 3; $i--) {
                $l = $t[$i];
                // Leerzeilen und "aendern"-Schaltflaechen ueberspringen.
                if ($l === '' || preg_match('/^((?:ä|ae)ndern|bearbeiten)$/iu', $l)) {
                    continue;
                }
                // Gueltiger Wert: Text ODER ein Datum (Anschlusstermin).
                $istWert = preg_match('/\p{L}{2,}/u', $l) || preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/u', $l);
                if (!$istWert || preg_match($labelOderKopf, $l) || preg_match($amountRe, $l)) {
                    break;
                }
                $prev[] = $l;
            }
            if (isset($prev[0])) {
                $synth[] = 'Tarif  ' . $prev[0];
            }
            if (isset($prev[1])) {
                $synth[] = 'Anbieter  ' . $prev[1];
            }
            if (isset($prev[2]) && $this->firstIndex($t, '/^Anschlusstermin\s*:?$/iu') !== null) {
                $synth[] = 'Anschlusstermin  ' . $prev[2];
            }

            // Monats-Angaben NACH dem letzten MBit-Wert: der Reihe nach
            // Mindestlaufzeit, Kuendigungsfrist, Verlaengerung - aber nur,
            // wenn genau so viele Werte wie vorhandene Beschriftungen da sind.
            $monatLabels = [];
            foreach ([
                ['Mindestlaufzeit', 'Mindestlaufzeit'],
                ['K(?:ü|ue|u)ndigungsfrist', 'Kuendigungsfrist'],
                ['Verl(?:ä|ae|a)ngerung', 'Verlaengerung'],
            ] as [$re, $plain]) {
                if ($this->firstIndex($t, '/^' . $re . '\s*:?$/iu') !== null) {
                    $monatLabels[] = $plain;
                }
            }
            $monatWerte = [];
            for ($i = ($speedIdx[1] ?? $speedIdx[0]) + 1; $i < $count; $i++) {
                $l = $t[$i];
                if ($l === '') {
                    continue;
                }
                if (!preg_match('/^\d{1,2}\s*Monat(?:e|en)?$/iu', $l)) {
                    break;
                }
                $monatWerte[] = $l;
            }
            if ($monatLabels !== [] && count($monatLabels) === count($monatWerte)) {
                foreach ($monatLabels as $k => $plain) {
                    $synth[] = $plain . '  ' . $monatWerte[$k];
                }
            }
        }

        // --- Preisliste: Positionsnamen-Block + Betrags-Block ------------
        $start = $this->firstIndex($t, '/^(Preis(?:ü|ue)bersicht|Tarifkosten)\s*:?$/iu');
        if ($start !== null) {
            $firstAmt = null;
            for ($i = $start + 1; $i < $count; $i++) {
                if (preg_match($amountRe, $t[$i])) {
                    $firstAmt = $i;
                    break;
                }
            }
            if ($firstAmt !== null) {
                $amounts = [];
                for ($i = $firstAmt; $i < $count; $i++) {
                    if ($t[$i] === '') {
                        continue;
                    }
                    if (!preg_match($amountRe, $t[$i])) {
                        break;
                    }
                    $amounts[] = $t[$i];
                }
                // Positionsnamen zwischen Start und erstem Betrag -
                // Ueberschriften, Spaltenkoepfe, Fragen und "aendern" zaehlen
                // nicht. Nur bei EXAKT gleicher Anzahl wird gepaart.
                $skip = '/^(Tarifkosten|Hardware\s*&\s*Optionen|(?:Ihre\s+)?Vorteile|einmalig|monatlich'
                    . '|einmalig\s+monatlich|(?:ä|ae)ndern|bearbeiten)$/iu';
                $posNamen = [];
                for ($i = $start + 1; $i < $firstAmt; $i++) {
                    $l = $t[$i];
                    if ($l === '' || preg_match($skip, $l) || str_ends_with($l, '?')
                        || !preg_match('/\p{L}{2,}/u', $l)) {
                        continue;
                    }
                    $posNamen[] = $l;
                }
                if ($posNamen !== [] && count($posNamen) === count($amounts)) {
                    foreach ($posNamen as $k => $name) {
                        $synth[] = $name . '  ' . $amounts[$k];
                    }
                }
            }

            // "Mtl. Kosten ab dem 25. Monat": der Betrag ist die naechste
            // Nur-Betrag-Zeile hinter dem Beschriftungs-Block (nur fuer die
            // Zusammenfassung).
            $abIdx = $this->firstIndex($t, '/^Mtl\.?\s*Kosten ab dem\s*\d{1,3}\.\s*Monat\s*:?$/iu');
            if ($abIdx !== null) {
                for ($i = $abIdx + 1; $i < min($abIdx + 9, $count); $i++) {
                    if (preg_match($amountRe, $t[$i])) {
                        $synth[] = $t[$abIdx] . '  ' . $t[$i];
                        break;
                    }
                }
            }
        }

        return $synth;
    }

    /**
     * Index der ersten Zeile, die auf das Muster passt (sonst null).
     *
     * @param list<string> $trimmedLines
     */
    private function firstIndex(array $trimmedLines, string $re): ?int
    {
        foreach ($trimmedLines as $i => $l) {
            if ($l !== '' && preg_match($re, $l)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Wert aus der Folgezeile eines allein stehenden Labels: Spalten-/Foto-OCR
     * trennt Label und Wert oft in eigene Zeilen. Ist die naechste Zeile
     * selbst eine Beschriftung, bleibt das Feld leer (PlanB-Lehre: nie den
     * Wert eines anderen Feldes uebernehmen).
     *
     * @param list<string> $lines
     */
    private function valueOnNextLine(array $lines, string $labelRe): ?string
    {
        $andereLabels = '/^(Anbieter|Tarif(?:kosten)?|Max\.|Mindestlaufzeit|K(?:ü|ue|u)ndigungsfrist'
            . '|Verl(?:ä|ae|a)ngerung|Anschlusstermin|Grundgeb|IBAN|E-?Mail|Geburtsdatum|Zahlungsart'
            . '|Kreditinstitut|Handynummer|Adresse|Preis|Vorteile|Hardware|Ihre?\b)/iu';
        foreach ($lines as $i => $line) {
            if (!preg_match($labelRe, trim($line))) {
                continue;
            }
            $max = min($i + 3, count($lines));
            for ($j = $i + 1; $j < $max; $j++) {
                $v = trim($lines[$j]);
                if ($v === '') {
                    continue;
                }
                if (preg_match($andereLabels, $v) || !preg_match('/\p{L}{2,}/u', $v)) {
                    return null;
                }
                return $v;
            }
            return null;
        }
        return null;
    }
}
