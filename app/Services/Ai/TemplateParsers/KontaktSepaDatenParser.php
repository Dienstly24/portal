<?php

namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer die KONTAKT-/SEPA-DATEN-Ansicht eines Antragsportals
 * (Screenshot der Seiten "Kundendaten" + "SEPA-Daten", z.B. aus der
 * Fonds-Finanz-Strecke einer gewerblichen Haftpflicht). Beschriftete Felder:
 *
 *   Unternehmensname & Rechtsform / Vertragsansprechpartner / E-Mail /
 *   Festnetznummer / Strasse & Hausnummer / PLZ und Ort
 *   Kontoinhaber / Name des Kontoinhabers / IBAN / BIC / Bank
 *
 * Der Satz "Fuer <Sparte> ist Lastschrift als Zahlungsweise ausgewaehlt"
 * verraet die Sparte (z.B. Frachtfuehrerhaftpflicht) - sie wird uebernommen,
 * damit der Datensatz beim richtigen Vertrag landet.
 *
 * Die IBAN wird NUR uebernommen, wenn der Kontoinhaber laut Seite der
 * VERSICHERUNGSNEHMER ist oder der Kontoinhaber-Name dem Ansprechpartner
 * entspricht - ein fremdes Konto gehoert nicht in die Kundenakte.
 *
 * Ein Firmenname wird nur gesetzt, wenn er sich vom Ansprechpartner
 * unterscheidet: beim Einzelunternehmer ist "Unternehmensname" schlicht der
 * Personenname und macht den Kunden nicht zur Firma.
 */
class KontaktSepaDatenParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);

        if ($this->looksLikeComparisonProtocol($text)) {
            return null;
        }

        $this->lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        // Nur diese Portal-Ansicht: Kontoinhaber-Beschriftung UND einer der
        // Kundendaten-Titel muessen vorhanden sein. Andere beschriftete
        // Formulare (NAFI-Antrag etc.) tragen diese Kombination nicht.
        if ($this->labelValue('Kontoinhaber') === null
            || ($this->labelValue('Vertragsansprechpartner') === null
                && $this->labelValue('Unternehmensname & Rechtsform') === null)) {
            return null;
        }

        $person = $this->parsePerson();
        if (($person['last_name'] ?? null) === null && ($person['company_name'] ?? null) === null) {
            return null;
        }
        $bank = $this->parseBank($person);
        $insurance = $this->parseInsurance();

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $sparte = $insurance['sparte'] ?? null;

        return [
            'type' => 'kontaktdaten',
            'confidence' => 74,
            'summary' => 'Kunden- & SEPA-Daten (Antragsportal)'
                .($name !== '' ? ' - '.$name : '')
                .($sparte !== null
                    ? ' - zur Sparte '.(Contract::TYPES[$sparte]['label'] ?? $sparte) : '')
                .($bank !== [] ? ' - Bankverbindung des Versicherungsnehmers' : ' - ohne Bankuebernahme')
                .' - Felder gratis gelesen (ohne KI).',
            'title' => 'Kunden- & SEPA-Daten'.($name !== '' ? ' '.$name : ''),
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

        // Ansprechpartner ("Herr Saleh Hamdo Alaswad Abdullah") - Anrede wird
        // Geschlecht, der erste Namensteil Vorname, der Rest Nachname.
        $ansprech = $this->labelValue('Vertragsansprechpartner');
        if ($ansprech !== null) {
            if (preg_match('/^(Herrn?|Frau)\s+(.+)$/u', trim($ansprech), $m)) {
                $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
                $ansprech = trim($m[2]);
            }
            $parts = preg_split('/\s+/', trim($ansprech)) ?: [];
            if (count($parts) >= 2) {
                $raw['first_name'] = array_shift($parts);
                $raw['last_name'] = implode(' ', $parts);
            } elseif ($parts !== []) {
                $raw['last_name'] = $parts[0];
            }
        }

        // Firmenname nur, wenn er sich vom Ansprechpartner unterscheidet
        // (sonst Einzelunternehmer: der "Unternehmensname" ist die Person).
        $firma = $this->labelValue('Unternehmensname & Rechtsform') ?? $this->labelValue('Unternehmensname');
        if ($firma !== null) {
            $full = trim(($raw['first_name'] ?? '').' '.($raw['last_name'] ?? ''));
            if ($full === '' || $this->normalize($firma) !== $this->normalize($full)) {
                $raw['company_name'] = trim($firma);
                // Ohne Ansprechpartner traegt die Firma den Datensatz.
                $raw['last_name'] ??= null;
            }
        }

        if (($v = $this->labelValue('E-Mail')) !== null
            && preg_match('/^[\w.+\-]+@[\w.\-]+\.\w{2,}$/u', trim($v))) {
            $raw['email'] = mb_strtolower(trim($v));
        }
        // Telefon: "+491781117036" -> fuehrende 0.
        $tel = $this->labelValue('Festnetznummer') ?? $this->labelValue('Mobilnummer') ?? $this->labelValue('Telefonnummer');
        if ($tel !== null) {
            $digits = (string) preg_replace('/^(?:\+|00)49/', '0', (string) preg_replace('/[^\d+]/', '', $tel));
            if (preg_match('/^0\d{9,14}$/', $digits)) {
                $raw['phone'] = $digits;
            }
        }
        if (($v = $this->labelValue('Straße & Hausnummer')) !== null
            && preg_match('/^(.*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)\s*$/u', trim($v), $s)) {
            $raw['street'] = trim($s[1]);
            $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
        }
        if (($v = $this->labelValue('PLZ und Ort')) !== null
            && preg_match('/^(\d{5})\s+(.+)$/u', trim($v), $z)) {
            $raw['zip'] = $z[1];
            $raw['city'] = trim($z[2]);
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * IBAN/BIC nur, wenn der Kontoinhaber der Versicherungsnehmer ist oder
     * sein Name dem Ansprechpartner entspricht.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function parseBank(array $person): array
    {
        $inhaber = $this->labelValue('Kontoinhaber');
        $inhaberName = $this->labelValue('Name des Kontoinhabers');
        $full = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));

        $gehoertKunde = ($inhaber !== null && preg_match('/Versicherungsnehmer/iu', $inhaber))
            || ($inhaberName !== null && $full !== '' && $this->normalize($inhaberName) === $this->normalize($full));
        if (! $gehoertKunde) {
            return [];
        }

        $raw = [];
        if (($v = $this->labelValue('IBAN')) !== null) {
            $iban = strtoupper((string) preg_replace('/\s+/', '', trim($v)));
            if (preg_match('/^DE\d{20}$/', $iban)) {
                $raw['iban'] = $iban;
            }
        }
        if (($v = $this->labelValue('BIC')) !== null && preg_match('/^[A-Z0-9]{8,11}$/', strtoupper(trim($v)))) {
            $raw['bic'] = strtoupper(trim($v));
        }
        if (($v = $this->labelValue('Bank')) !== null && preg_match('/^\p{L}[\p{L} .\-&]{2,60}$/ui', trim($v))) {
            $raw['bank_name'] = trim($v);
        }
        if ($inhaberName !== null) {
            $raw['account_holder'] = trim($inhaberName);
        }

        return $this->validatedBank($raw);
    }

    /**
     * Sparte aus dem Satz "Fuer <Sparte> ist Lastschrift als Zahlungsweise
     * ausgewaehlt" - so landet der Datensatz beim richtigen Vertrag.
     *
     * @return array<string,mixed>
     */
    private function parseInsurance(): array
    {
        $text = mb_strtolower(implode("\n", $this->lines));
        $sparte = match (true) {
            str_contains($text, 'frachtführerhaftpflicht'),
            str_contains($text, 'frachtfuehrerhaftpflicht'),
            str_contains($text, 'verkehrshaftung') => 'frachtfuehrerhaftpflicht',
            str_contains($text, 'betriebshaftpflicht') => 'betriebshaftpflicht',
            default => null,
        };

        return $sparte === null ? [] : $this->validatedInsurance(['sparte' => $sparte]);
    }

    /** Vergleichsform fuer Namens-Abgleiche. */
    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($value)));
    }

    /**
     * Wert hinter "Beschriftung:" - Screenshot-OCR setzt zwischen Beschriftung
     * und Wert mal viele, mal nur EIN Leerzeichen. Die Beschriftung steht am
     * Zeilenanfang.
     */
    private function labelValue(string $label): ?string
    {
        $re = '/^\s*'.preg_quote($label, '/').'\s*:\s*(\S.*?)\s*$/u';
        foreach ($this->lines as $line) {
            if (preg_match($re, $line, $m)) {
                $val = trim($m[1]);
                if ($val !== '') {
                    return $val;
                }
            }
        }

        return null;
    }
}
