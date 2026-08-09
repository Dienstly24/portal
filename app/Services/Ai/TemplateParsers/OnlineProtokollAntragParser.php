<?php
namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer den ANTRAG aus dem Online-Vergleichsportal des Maklerbundes
 * (Mr-Money / www.online-protokoll.de), z.B. "Antrag
 * Rechtsschutzversicherung". Aufbau (Beschriftung rechtsbuendig, Wert
 * daneben):
 *
 *   Kopf    : "Antrag" + "<Sparte>versicherung", Block "Gewaehlter Tarif"
 *             mit Anbieter/Tarif/Tarif-Nr.
 *   Makler  : Block "Vermittler" (Mr-Money Makler-Bund GmbH) - wird NIE
 *             Kundendatum (eigene Labels: Name/Anschrift/Telefon / Fax).
 *   Kunde   : Block "Versicherungsnehmer" mit Anrede/Vorname/Nachname/
 *             PLZ Ort/Strasse Hausnr./Telefon-Nr./E-Mail-Adresse/
 *             Geburtsdatum/Staatsangehoerigkeit/Familienstand/Beruf.
 *   Beitrag : "Zahlungsweise monatlich", "Beitrag gemaess Zahlweise" =
 *             wiederkehrender BRUTTO-Betrag; Jahresbeitrag inkl. Steuer
 *             in der Zusammenfassung.
 *
 * Der Antrag traegt KEINE Vertragsnummer (Betreiber-Hinweis 09.08.2026:
 * "es fehlt nur die Vertragsnummer, die bringt erst die Police") - Stufe
 * 'antrag'; die Protokoll-/Antragsnummer steht nur in der Zusammenfassung.
 * Die spaetere Police ergaenzt denselben Vertrag ueber Sparte + Anbieter
 * (findApplicationContractForConfirmation). Die IBAN wird nur uebernommen,
 * wenn KEIN abweichender Kontoinhaber eingetragen ist.
 */
class OnlineProtokollAntragParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    /** E-Mail-Domains des Vermittlungswegs - nie Kundendaten. */
    private const BROKER_MAIL_DOMAINS = ['makler-bund.de', 'mr-money.de', 'fondsfinanz.de', 'dienstly24.de'];

    private string $text = '';

    public function parse(string $text): ?array
    {
        $this->text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($this->text);

        if ($this->looksLikeComparisonProtocol($this->text)) {
            return null;
        }
        if (!str_contains($upper, 'ANTRAG')
            || !str_contains($upper, 'GEWÄHLTER TARIF')
            || $this->labelValue('Anbieter') === null) {
            return null;
        }

        $person = $this->parsePerson();
        $insurance = $this->parseInsurance();

        if (($person['last_name'] ?? null) === null
            || ($insurance['insurer'] ?? null) === null) {
            return null; // ohne Kern lieber die normale Analyse
        }
        $bank = $this->parseBank($person);

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $sparte = $insurance['sparte'] ?? null;

        return [
            'type' => 'versicherungsvertrag',
            'confidence' => 75,
            'summary' => 'Versicherungs-Antrag (Online-Protokoll)'
                . ($sparte !== null ? ' - ' . (Contract::TYPES[$sparte]['label'] ?? $sparte) : '')
                . ' - ' . $insurance['insurer']
                . ($name !== '' ? ' - ' . $name : '')
                . $this->extras()
                . ($bank !== [] ? ' Bankverbindung des Antragstellers uebernommen.' : ' Ohne Bankuebernahme.')
                . ' Felder gratis aus dem Antrag gelesen (ohne KI).',
            'title' => 'Antrag ' . ($sparte !== null ? (Contract::TYPES[$sparte]['label'] ?? $sparte) : 'Versicherung')
                . ($name !== '' ? ' - ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $insurance,
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

        if (($v = $this->labelValue('Anrede, Titel')) !== null
            && preg_match('/^(Herrn?|Frau)\b/u', trim($v), $m)) {
            $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
        }
        // Namen normalisieren - das Portal uebernimmt die Tipp-Schreibweise
        // des Kunden ("kadro" -> "Kadro").
        if (($v = $this->labelValue('Vorname')) !== null) {
            $raw['first_name'] = $this->tidyName($v);
        }
        if (($v = $this->labelValue('Nachname')) !== null) {
            $raw['last_name'] = $this->tidyName($v);
        }
        if (($v = $this->labelValue('Postleitzahl, Ort')) !== null
            && preg_match('/^(\d{5})\s+(\p{L}.*)$/u', trim($v), $m)) {
            $raw['zip'] = $m[1];
            $raw['city'] = trim($m[2]);
        }
        if (($v = $this->labelValue('Straße, Hausnr.')) !== null
            && preg_match('/^(.*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)$/u', trim($v), $m)) {
            $raw['street'] = trim($m[1]);
            $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
        }
        if (($v = $this->labelValue('Telefon-Nr.')) !== null) {
            $digits = (string) preg_replace('/^(?:\+|00)49/', '0', (string) preg_replace('/[^\d+]/', '', $v));
            if (preg_match('/^0\d{8,14}$/', $digits)) {
                $raw['phone'] = $digits;
            }
        }
        if (($v = $this->labelValue('E-Mail-Adresse')) !== null
            && preg_match('/^[\w.+\-]+@[\w.\-]+\.\w{2,}$/u', trim($v))
            && !$this->isBrokerMail($v)) {
            $raw['email'] = mb_strtolower(trim($v));
        }
        if (($v = $this->labelValue('Geburtsdatum')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (($v = $this->labelValue('Staatsangehörigkeit')) !== null) {
            $raw['nationality'] = $v;
        }
        if (($v = $this->labelValue('Familienstand')) !== null) {
            $raw['marital_status'] = mb_strtolower(trim($v));
        }
        if (($v = $this->labelValue('Genaue derzeitige Berufsbezeichnung')) !== null) {
            $raw['occupation'] = $v;
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = ['document_stage' => Contract::STAGE_ANTRAG];

        // Sparte aus dem Titel ("Antrag" + "Rechtsschutzversicherung") -
        // unbekannte Sparten bleiben bewusst leer.
        if (preg_match('/\bAntrag\s*\R\s*([\p{L}][\p{L} \-]*versicherung)\b/iu', $this->text, $m)) {
            $raw['sparte'] = $this->mapSparte($m[1]);
        }

        $raw['insurer'] = $this->labelValue('Anbieter');
        $raw['tariff'] = $this->labelValue('Tarif');

        // Beginn: erstes echtes Datum hinter "Gewuenschter Versicherungsbeginn"
        // (die Beratungsdokumentation wiederholt das Label mit
        // "schnellstmoeglich" - das ist KEIN Datum und wird nie geraten).
        foreach ($this->labelValues('Gewünschter Versicherungsbeginn') as $v) {
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', trim($v), $m)) {
                $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
                break;
            }
        }

        // Beitrag gemaess Zahlweise = wiederkehrender BRUTTO-Betrag;
        // ersatzweise der Jahresbeitrag inkl. Steuer.
        $interval = null;
        if (($v = $this->labelValue('Zahlungsweise')) !== null) {
            $interval = $this->interval($v);
        }
        if (($v = $this->labelValue('Beitrag gemäß Zahlweise')) !== null
            && preg_match('/([\d.]+,\d{2})/', $v, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $interval ?? 'yearly';
        } elseif (($v = $this->labelValue('Jahresbeitrag inkl. Steuer')) !== null
            && preg_match('/([\d.]+,\d{2})/', $v, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = 'yearly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * IBAN nur, wenn KEIN abweichender Kontoinhaber eingetragen ist - steht
     * hinter der Beschriftung "Abweichender Kontoinhaber" ein Name, gehoert
     * das Konto einem Dritten und bleibt draussen.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function parseBank(array $person): array
    {
        foreach (preg_split('/\R/', $this->text) ?: [] as $line) {
            if (mb_stripos($line, 'Abweichender Kontoinhaber') === false) {
                continue;
            }
            $rest = trim((string) preg_replace('/.*Abweichender Kontoinhaber\s*:?/iu', '', $line));
            if ($rest !== '' && preg_match('/\p{L}{2,}/u', $rest)) {
                return []; // abweichender Kontoinhaber eingetragen
            }
        }

        $raw = [];
        if (($v = $this->labelValue('IBAN')) !== null) {
            $iban = strtoupper((string) preg_replace('/\s+/', '', $v));
            if (preg_match('/^DE\d{20}$/', $iban)) {
                $raw['iban'] = $iban;
            }
        }
        if ($raw !== []) {
            $full = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
            if ($full !== '') {
                $raw['account_holder'] = $full;
            }
        }

        return $this->validatedBank($raw);
    }

    /** Zusatzangaben fuer die Zusammenfassung. */
    private function extras(): string
    {
        $out = '.';
        // Protokoll-/Antragsnummer neben der Unterschriftszeile - KEINE
        // Vertragsnummer (die bringt erst die Police).
        if (preg_match('/\((\d{6,}-[A-Z0-9]+\/\d+\/\d+)\)/u', $this->text, $m)) {
            $out .= ' Antrags-/Protokoll-Nr. ' . $m[1] . ' (keine Vertragsnummer - die bringt erst die Police).';
        }
        if (($v = $this->labelValue('Jahresbeitrag inkl. Steuer')) !== null) {
            $out .= ' Jahresbeitrag inkl. Steuer: ' . $v . '.';
        }
        if (($v = $this->labelValue('Laufzeit')) !== null) {
            $out .= ' Laufzeit: ' . $v . '.';
        }
        if (($v = $this->labelValue('Risiko')) !== null) {
            $out .= ' Risiko: ' . $v . '.';
        }
        if (($v = $this->labelValue('Versicherungssumme')) !== null) {
            $out .= ' Versicherungssumme: ' . $v . '.';
        }
        if (($v = $this->labelValue('Selbstbeteiligung')) !== null) {
            $out .= ' Selbstbeteiligung: ' . $v . '.';
        }
        // Deckungs-Bausteine: auf einen Blick, ob der Schutz UMFASSEND oder
        // nur ein einzelner Baustein ist (Betreiber-Hinweis 09.08.2026).
        [$ja, $nein] = $this->bausteine();
        if ($ja !== [] || $nein !== []) {
            $out .= ' Gewuenschte Bausteine (' . count($ja) . ' von ' . (count($ja) + count($nein)) . '): '
                . ($ja !== [] ? implode(', ', $ja) : 'keine')
                . ($nein !== [] ? ' - NICHT gewuenscht: ' . implode(', ', $nein) : '')
                . '.';
        }
        $zusatz = [];
        foreach (['Spezial Straf RS', 'Erw. Internet-Schutz', 'Singlerabatt'] as $label) {
            if (($v = $this->labelValue($label)) !== null && preg_match('/^(ja|nein)$/iu', trim($v))) {
                $zusatz[] = $label . ' ' . mb_strtolower(trim($v));
            }
        }
        if ($zusatz !== []) {
            $out .= ' Laut Antragsdaten: ' . implode(', ', $zusatz) . '.';
        }
        return $out;
    }

    /**
     * Deckungs-Bausteine aus dem Bedarfsblock "Angaben des Kunden zu seinem
     * Versicherungsbedarf": die zusammenhaengenden Ja/nein-Zeilen direkt
     * unter der Ueberschrift (die erste Zeile ohne Ja/nein beendet den
     * Block - die spaeteren Filterkriterien gehoeren nicht dazu).
     *
     * @return array{0: list<string>, 1: list<string>} [gewaehlt, abgewaehlt]
     */
    private function bausteine(): array
    {
        $lines = preg_split('/\R/', $this->text) ?: [];
        $start = null;
        foreach ($lines as $i => $line) {
            if (mb_stripos($line, 'Angaben des Kunden zu seinem Versicherungsbedarf') !== false) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return [[], []];
        }

        $ja = [];
        $nein = [];
        for ($i = $start + 1, $n = count($lines); $i < $n; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^(\S.*?)\s{2,}(Ja|Nein|ja|nein)$/u', $line, $m)) {
                break;
            }
            if (mb_strtolower($m[2]) === 'ja') {
                $ja[] = trim($m[1]);
            } else {
                $nein[] = trim($m[1]);
            }
        }

        return [$ja, $nein];
    }

    /** Sparte aus dem Antragstitel - unbekannt bleibt leer. */
    private function mapSparte(string $titel): ?string
    {
        $t = mb_strtolower($titel);
        return match (true) {
            str_contains($t, 'rechtsschutz')                                   => 'rechtsschutz',
            str_contains($t, 'hausrat')                                        => 'hausrat',
            str_contains($t, 'privathaftpflicht') || str_contains($t, 'haftpflicht') => 'haftpflicht',
            str_contains($t, 'unfall')                                         => 'unfall',
            str_contains($t, 'wohngebäude') || str_contains($t, 'wohngebaeude') => 'sach',
            default                                                            => null,
        };
    }

    /** Tipp-Schreibweisen normalisieren ("kadro"/"KADRO" -> "Kadro"). */
    private function tidyName(string $value): string
    {
        $words = preg_split('/\s+/u', trim($value)) ?: [];
        foreach ($words as &$w) {
            if ($w === mb_strtolower($w) || $w === mb_strtoupper($w)) {
                $w = mb_convert_case(mb_strtolower($w), MB_CASE_TITLE, 'UTF-8');
            }
        }
        return implode(' ', $words);
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

    /** Erster nicht-leerer Wert hinter der rechtsbuendigen Beschriftung. */
    private function labelValue(string $label): ?string
    {
        $values = $this->labelValues($label);
        return $values[0] ?? null;
    }

    /**
     * Alle Werte hinter einer Beschriftung (rechtsbuendig, Wert nach
     * mindestens zwei Leerzeichen in derselben Zeile).
     *
     * @return list<string>
     */
    private function labelValues(string $label): array
    {
        $re = '/^\s*' . preg_quote($label, '/') . '\s{2,}(\S[^\n]*?)\s*$/mu';
        if (!preg_match_all($re, $this->text, $all)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', $all[1]), fn ($v) => $v !== ''));
    }
}
