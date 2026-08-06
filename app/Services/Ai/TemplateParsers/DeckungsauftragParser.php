<?php
namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer den "Deckungsauftrag" der Makler-Vergleichsplattform
 * (Fonds Finanz / Thinksurance) - das Schwesterdokument der
 * Beratungsdokumentation: der verbindliche AUFTRAG an den Versicherer
 * (z.B. "Deckungsauftrag zur Frachtfuehrerhaftpflicht"). Aufbau:
 *
 *   Kopf     : "Deckungsauftrag zur <Sparte>" + Vorgangsnummer
 *   Kunde    : Block "Daten des Versicherungsnehmers" (Firmenname/
 *              Rechtsform/Anschrift/E-Mail-Adresse/Name Ansprechpartner)
 *   Vertrag  : Block "Gewuenschter Versicherungsschutz" (Versicherer/
 *              Gewaehlter Tarif/Versicherungssumme/Zahlungsweise/Praemie)
 *   Bank     : Block "Zahlungsweise" (Kontoinhaber/IBAN/BIC/Bank)
 *   Beginn   : Risikofragen "Gewuenschter Versicherungsbeginn"
 *
 * Regeln:
 *  - Der Deckungsauftrag ist ein ANTRAG (Stufe 'antrag'): Vorgangs- und
 *    RV-Nummer (Rahmenvertrag) sind KEINE Vertragsnummern - die echte
 *    Nummer bringt erst die Police des Versicherers.
 *  - Versicherungsbeginn: der Abschnitt "Gewuenschter Versicherungsschutz"
 *    verweist selbst auf die Risikoangaben ("siehe Risikoangaben") - deren
 *    Datum ist massgeblich. Der ISO-Zeitraum "Beginn / Ende" aus der
 *    Beitragsberechnung ist nur die Rechen-Grundlage und wandert in die
 *    Zusammenfassung; nur wenn die Risikoangaben KEIN Datum nennen, gilt er.
 *  - Kennzeichen/Fahrzeugart beschreiben das versicherte GEWERBE-Fahrzeug
 *    und stehen nur in der Zusammenfassung - NICHT in data.kfz, sonst
 *    wuerde die Fahrzeug-Identitaet spaetere Kfz-Dokumente desselben
 *    Fahrzeugs faelschlich diesem Haftpflicht-Vertrag zuordnen.
 *  - IBAN/BIC nur, wenn der Kontoinhaber der Versicherungsnehmer ist
 *    (Name identisch mit Ansprechpartner oder Firmenname).
 *  - Vermittlerdaten (FondsFinanz, sach@fondsfinanz.de, "Betreut von")
 *    werden NIE Kundendaten - gelesen wird nur der VN-Block.
 */
class DeckungsauftragParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** E-Mail-Domains des Vermittlungswegs - nie Kundendaten. */
    private const BROKER_MAIL_DOMAINS = ['fondsfinanz.de', 'dienstly24.de', 'thinksurance.de'];

    private string $text = '';

    public function parse(string $text): ?array
    {
        $this->text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($this->text);

        if (!str_contains($upper, 'DECKUNGSAUFTRAG')
            || !str_contains($upper, 'VORGANGSNUMMER')
            || (!str_contains($upper, 'DATEN DES VERSICHERUNGSNEHMERS')
                && !str_contains($upper, 'DECKUNGSAUFTRAG FÜR'))) {
            return null;
        }

        $person = $this->parsePerson();
        $versicherung = $this->parseInsurance();

        // Ohne belastbaren Kern lieber der KI ueberlassen.
        if (($person['last_name'] ?? null) === null && ($person['company_name'] ?? null) === null) {
            return null;
        }
        $bank = $this->parseBank($person);

        $who = ($person['company_name'] ?? '') !== ''
            ? $person['company_name']
            : trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $sparte = $versicherung['sparte'] ?? null;

        return [
            'type' => 'versicherungsvertrag',
            'confidence' => 76,
            'summary' => 'Deckungsauftrag (Gewerbe)'
                . ($sparte !== null ? ' - ' . (Contract::TYPES[$sparte]['label'] ?? $sparte) : '')
                . (isset($versicherung['insurer']) ? ' - ' . $versicherung['insurer'] : '')
                . ($who !== '' ? ' - ' . $who : '')
                . $this->extras()
                . ($bank !== [] ? ' Bankverbindung des Versicherungsnehmers uebernommen.' : ' Ohne Bankuebernahme.')
                . ' Felder gratis aus dem Deckungsauftrag gelesen (ohne KI).',
            'title' => 'Deckungsauftrag'
                . ($sparte !== null ? ' ' . (Contract::TYPES[$sparte]['label'] ?? $sparte) : '')
                . ($who !== '' ? ' - ' . $who : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $versicherung,
                'kfz' => [],
                'gesundheit' => [],
                'bank' => $bank,
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function parsePerson(): array
    {
        $raw = [];

        // Ansprechpartner des VN ("Name Ansprechpartner" im VN-Block; der
        // "Ansprechpartner:" rechts oben ist der MAKLER und traegt kein
        // zeilenanfangs-Label).
        $name = $this->labelValue('Name Ansprechpartner');
        if ($name !== null) {
            $parts = preg_split('/\s+/', trim($name)) ?: [];
            if (count($parts) >= 2) {
                $raw['first_name'] = array_shift($parts);
                $raw['last_name'] = implode(' ', $parts);
            } elseif ($parts !== []) {
                $raw['last_name'] = $parts[0];
            }
        }
        $anrede = $this->labelValue('Anrede Ansprechpartner');
        if ($anrede !== null && preg_match('/^(Herrn?|Frau)\b/u', trim($anrede), $m)) {
            $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
        }

        // Firmenname nur, wenn er sich vom Ansprechpartner unterscheidet
        // (Einzelunternehmer: der "Firmenname" ist schlicht die Person).
        $firma = $this->labelValue('Firmenname');
        $rechtsform = $this->labelValue('Rechtsform');
        if ($firma !== null) {
            $full = trim(($raw['first_name'] ?? '') . ' ' . ($raw['last_name'] ?? ''));
            if ($full === '' || $this->normalize($firma) !== $this->normalize($full)) {
                $company = trim($firma);
                // Rechtsform (GmbH, UG ...) anhaengen, wenn sie nicht schon
                // im Namen steht - beides steht so im Dokument.
                if ($rechtsform !== null
                    && !preg_match('/einzelunt/i', $rechtsform)
                    && mb_stripos($company, trim($rechtsform)) === false) {
                    $company .= ' ' . trim($rechtsform);
                }
                $raw['company_name'] = $company;
            }
        }

        if (($v = $this->labelValue('E-Mail-Adresse')) !== null
            && preg_match('/^[\w.+\-]+@[\w.\-]+\.\w{2,}$/u', trim($v))
            && !$this->isBrokerMail($v)) {
            $raw['email'] = mb_strtolower(trim($v));
        }

        // Anschrift: Wert in der rechten Spalte, Folgezeilen eingerueckt bis
        // zum naechsten Label am Zeilenanfang. Gelesen werden nur klar
        // erkennbare Strassen-/PLZ-Zeilen (Firmen-/Namenszeilen fallen durch).
        foreach ($this->blockValues('Anschrift') as $line) {
            if (!isset($raw['zip']) && preg_match('/^(\d{5})\s+(\p{L}.*)$/u', $line, $m)) {
                $raw['zip'] = $m[1];
                $raw['city'] = trim($m[2]);
                continue;
            }
            if (!isset($raw['street'])
                && preg_match('/^(.*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)$/u', $line, $m)
                && preg_match('/\p{L}{3,}/u', $m[1])) {
                $raw['street'] = trim($m[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = ['document_stage' => Contract::STAGE_ANTRAG];

        // Sparte aus dem Titel "Deckungsauftrag zur <Sparte>" (im Kopf jeder
        // Seite wiederholt; der Treffer "Vorgangsnummer" der Kopfzeile wird
        // uebersprungen).
        if (preg_match_all('/Deckungsauftrag\s+zu[rm]\s+([\p{L}\-\/ ]{4,60})/u', $this->text, $all)) {
            foreach ($all[1] as $kandidat) {
                $kandidat = trim($kandidat);
                if ($kandidat === '' || str_starts_with($kandidat, 'Vorgangsnummer')) {
                    continue;
                }
                $raw['sparte'] = $this->mapSparte($kandidat);
                break;
            }
        }

        if (($v = $this->labelValue('Versicherer')) !== null) {
            $raw['insurer'] = $v;
        }
        $raw['tariff'] = $this->labelValue('Gewählter Tarif') ?? $this->labelValue('Produktname');

        // Beginn: massgeblich ist das Datum der Risikoangaben (der Abschnitt
        // "Gewuenschter Versicherungsschutz" verweist selbst darauf); der
        // ISO-Zeitraum der Beitragsberechnung gilt nur ersatzweise.
        $beginn = $this->risikoBeginn();
        if ($beginn !== null) {
            $raw['start_date'] = $beginn;
        } elseif (($pair = $this->berechnungszeitraum()) !== null) {
            [$raw['start_date'], $raw['end_date']] = $pair;
        }

        // Beitrag: "Praemie gemaess Zahlweise" + gewaehlte Zahlungsweise
        // (Brutto-Betrag), ersatzweise die Jahresbruttopraemie.
        $interval = $this->interval((string) $this->labelValue('Gewünschte Zahlungsweise'));
        if (($v = $this->labelValue('Prämie gemäß Zahlweise')) !== null
            && preg_match('/([\d.]+,\d{2})/', $v, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $interval ?? 'yearly';
        } elseif (($v = $this->labelValue('Jahresbruttoprämie')) !== null
            && preg_match('/([\d.]+,\d{2})/', $v, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = 'yearly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * IBAN/BIC nur, wenn der Kontoinhaber der Versicherungsnehmer ist
     * (Name identisch mit dem Ansprechpartner oder dem Firmennamen).
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function parseBank(array $person): array
    {
        $inhaber = $this->labelValue('Name des Kontoinhabers');
        if ($inhaber === null) {
            return [];
        }
        $full = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $firma = (string) ($person['company_name'] ?? '');

        $gehoertKunde = ($full !== '' && $this->normalize($inhaber) === $this->normalize($full))
            || ($firma !== '' && $this->normalize($inhaber) === $this->normalize($firma));
        if (!$gehoertKunde) {
            return [];
        }

        $raw = ['account_holder' => trim($inhaber)];
        if (($v = $this->labelValue('IBAN')) !== null) {
            $iban = strtoupper((string) preg_replace('/\s+/', '', $v));
            if (preg_match('/^DE\d{20}$/', $iban)) {
                $raw['iban'] = $iban;
            }
        }
        if (($v = $this->labelValue('BIC')) !== null && preg_match('/^[A-Z0-9]{8,11}$/', strtoupper(trim($v)))) {
            $raw['bic'] = strtoupper(trim($v));
        }

        return $this->validatedBank($raw);
    }

    /**
     * Zusatzangaben fuer die Zusammenfassung: Vorgangsnummer (KEINE
     * Vertragsnummer), Versicherungssumme, Selbstbehalt, Gewerbe, Laufzeit,
     * Fahrzeug, Rahmenvertrag und der Berechnungszeitraum.
     */
    private function extras(): string
    {
        $out = '.';
        if (($nr = $this->vorgangsnummer()) !== null) {
            $out .= ' Vorgangsnummer ' . $nr . ' (keine Vertragsnummer - die bringt erst die Police).';
        }
        if (($v = $this->labelValue('Versicherungssumme')) !== null) {
            $out .= ' Versicherungssumme: ' . $v . '.';
        }
        if (($v = $this->labelValue('Selbstbehalt')) !== null) {
            $out .= ' Selbstbehalt: ' . $v . '.';
        }
        if (($v = $this->labelValue('Gewerbe')) !== null) {
            $out .= ' Gewerbe: ' . $v . '.';
        }
        if (($v = $this->labelValue('Vertragslaufzeit')) !== null) {
            $out .= ' Vertragslaufzeit: ' . $v . '.';
        }
        $kennzeichen = $this->labelValue('Kennzeichen 1') ?? $this->labelValue('Kennzeichen');
        if ($kennzeichen !== null) {
            $art = $this->labelValue('Fahrzeugart');
            $gewicht = $this->labelValue('Zulässiges Gesamtgewicht 1')
                ?? $this->labelValue('Zulässiges Gesamtgewicht (in t)');
            $out .= ' Fahrzeug: ' . $kennzeichen
                . ($art !== null || $gewicht !== null
                    ? ' (' . implode(', ', array_filter([$art, $gewicht])) . ')' : '')
                . '.';
        }
        if (($v = $this->labelValue('RV Nummer')) !== null) {
            $out .= ' Rahmenvertrag ' . $v . '.';
        }
        // Nur wenn der Beginn aus den Risikoangaben stammt, ist der
        // ISO-Zeitraum eine ZUSATZ-Info (sonst ist er bereits Beginn/Ende).
        if (($pair = $this->berechnungszeitraum()) !== null && $this->risikoBeginn() !== null) {
            $out .= ' Berechnungszeitraum laut Beitragsberechnung: '
                . $pair[0] . ' bis ' . $pair[1] . '.';
        }
        return $out;
    }

    /**
     * ISO-Zeitraum "Beginn / Ende  2026-08-07/2027-08-06" aus der
     * Beitragsberechnung.
     *
     * @return array{0:string,1:string}|null
     */
    private function berechnungszeitraum(): ?array
    {
        $v = $this->labelValue('Beginn / Ende');
        if ($v !== null && preg_match('#^(\d{4}-\d{2}-\d{2})\s*/\s*(\d{4}-\d{2}-\d{2})$#', trim($v), $m)) {
            return [$m[1], $m[2]];
        }
        return null;
    }

    /** Beginn-Datum aus den Risikoangaben (ISO) - "siehe Risikoangaben" faellt durch. */
    private function risikoBeginn(): ?string
    {
        foreach ($this->labelValues('Gewünschter Versicherungsbeginn') as $v) {
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($v), $m)) {
                return $m[3] . '-' . $m[2] . '-' . $m[1];
            }
        }
        return null;
    }

    /** Vorgangsnummer - steht neben ODER unter der Beschriftung (Kopfzeile). */
    private function vorgangsnummer(): ?string
    {
        if (preg_match('/Vorgangsnummer:\s*(\d{6,12})\b/u', $this->text, $m)) {
            return $m[1];
        }
        if (preg_match('/Vorgangsnummer:[^\n]*\n[^\n]*?\b(\d{6,12})\b/u', $this->text, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Sparten-Zuordnung wie in der Beratungsdokumentation (gewerblich zuerst). */
    private function mapSparte(string $name): string
    {
        $n = mb_strtolower($name);
        return match (true) {
            str_contains($n, 'frachtführer') || str_contains($n, 'frachtfuehrer')
                || str_contains($n, 'verkehrshaftung')                         => 'frachtfuehrerhaftpflicht',
            str_contains($n, 'betriebshaftpflicht')                            => 'betriebshaftpflicht',
            str_contains($n, 'rechtsschutz')                                   => 'rechtsschutz',
            str_contains($n, 'unfall')                                         => 'unfall',
            str_contains($n, 'inhalt') || str_contains($n, 'geschäft')
                || str_contains($n, 'gebäude') || str_contains($n, 'gebaeude')
                || str_contains($n, 'sach')                                    => 'sach',
            default                                                            => 'haftpflicht',
        };
    }

    private function interval(string $german): ?string
    {
        return match (mb_strtolower(trim($german))) {
            'monatlich'        => 'monthly',
            'vierteljährlich'  => 'quarterly',
            'halbjährlich'     => 'semiannual',
            'jährlich'         => 'yearly',
            default            => null,
        };
    }

    private function isBrokerMail(string $mail): bool
    {
        $domain = mb_strtolower(trim((string) strrchr(trim($mail), '@')), 'UTF-8');
        return in_array(ltrim($domain, '@'), self::BROKER_MAIL_DOMAINS, true);
    }

    /** Vergleichsform fuer Namens-Abgleiche. */
    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($value)));
    }

    /** Erster nicht-leerer Wert hinter der Beschriftung (Spaltenlayout). */
    private function labelValue(string $label): ?string
    {
        $values = $this->labelValues($label);
        return $values[0] ?? null;
    }

    /**
     * Alle Werte hinter einer Beschriftung am Zeilenanfang (das Layout trennt
     * Beschriftung und Wert mit mindestens zwei Leerzeichen).
     *
     * @return list<string>
     */
    private function labelValues(string $label): array
    {
        $re = '/^' . preg_quote($label, '/') . '\s{2,}(\S[^\n]*?)\s*$/mu';
        if (!preg_match_all($re, $this->text, $all)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', $all[1]), fn ($v) => $v !== ''));
    }

    /**
     * Wert-Zeilen eines mehrzeiligen Blocks (Wert neben der Beschriftung +
     * eingerueckte Folgezeilen bis zum naechsten Label am Zeilenanfang).
     *
     * @return list<string>
     */
    private function blockValues(string $label): array
    {
        $lines = preg_split('/\R/', $this->text) ?: [];
        $out = [];
        foreach ($lines as $i => $line) {
            if (!preg_match('/^' . preg_quote($label, '/') . '\s{2,}(\S.*)$/u', $line, $m)) {
                continue;
            }
            $out[] = trim($m[1]);
            for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
                if (!preg_match('/^\s{6,}(\S.*)$/u', $lines[$j], $f)) {
                    break;
                }
                $out[] = trim($f[1]);
            }
            break;
        }
        return $out;
    }
}
