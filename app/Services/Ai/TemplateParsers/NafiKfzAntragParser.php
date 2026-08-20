<?php
namespace App\Services\Ai\TemplateParsers;

use App\Models\Contract;
use App\Services\Ai\Concerns\ReadsDocumentPages;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den "Antrag Kraftfahrtversicherung" aus der
 * NAFI-Maklersoftware. Dieses Formular ist ueber ALLE Gesellschaften hinweg
 * gleich aufgebaut (Itzehoer, VHV, R+V ...) - der Versicherer steht als Feld
 * im Dokument. Beschriftung links, Wert rechtsbuendig in derselben Zeile.
 *
 * Gelesen wird der komplette Antrag:
 *   Person   : Anrede/Name, Anschrift, E-Mail, Geburtsdatum, Familienstand,
 *              Staatsangehoerigkeit, beruflicher Status
 *   Vertrag  : Versicherer, Tarif, Antragsart, gewuenschter Beginn,
 *              naechste Hauptfaelligkeit, Beitrag mit Zahlungsperiode
 *   Fahrzeug : Kennzeichen, Fahrgestellnummer, Wagnisart, HSN + Hersteller,
 *              Leistung, Kraftstoff, Erstzulassung, Zulassung auf den Halter,
 *              Halter, Jahresfahrleistung, Kilometerstand, SF-Klasse,
 *              Kaskoart und Zusatzleistungen (Schutzbrief)
 *   Bank     : IBAN/BIC des SEPA-Mandats - NUR wenn die zahlungspflichtige
 *              Person der Versicherungsnehmer ist (sonst fremdes Konto)
 *
 * Stufe 'antrag': das ist ein ANTRAG, kein Versicherungsschein. Eine
 * Vertragsnummer gibt es noch nicht - die NAFI-Vorgangs-ID und die eVB-Nummer
 * sind KEINE Vertragsnummern und landen nur in der Zusammenfassung. Die
 * spaetere Police bringt die echte Nummer und findet ihren Vertrag ueber
 * FIN/Kennzeichen (Auftrag-zuerst-System).
 */
class NafiKfzAntragParser implements DocumentTemplateParser
{
    use ReadsDocumentPages;
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $text = (string) preg_replace('/\x{00ad}\s*/u', '', $text);
        $upper = mb_strtoupper($text);

        if ($this->looksLikeComparisonProtocol($text)) {
            return null;
        }
        if (!preg_match('/ANTRAG\s+KRAFTFAHRTVERSICHERUNG/u', $upper)
            && !(str_contains($upper, 'NAFI') && str_contains($upper, 'KFZ-ANTRAG'))) {
            return null;
        }

        $this->lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        $person = $this->parsePerson();
        $vehicle = $this->parseVehicle();
        $insurance = $this->parseInsurance();
        $bank = $this->parseBank();

        // Ohne belastbaren Kern (Kennzeichen oder FIN) der normalen Analyse
        // ueberlassen.
        if (($vehicle['license_plate'] ?? null) === null && ($vehicle['vin'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $evb = $this->labelValue('eVB - Nummer') ?? $this->labelValue('eVB-Nummer');

        return [
            'type' => 'kfz_vertrag',
            'confidence' => 78,
            'summary' => 'Kfz-Antrag (NAFI)'
                . (isset($insurance['insurer']) ? ' - ' . $insurance['insurer'] : '')
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($vehicle['license_plate']) ? ' - ' . $vehicle['license_plate'] : '')
                . (isset($insurance['tariff']) ? ' - ' . $insurance['tariff'] : '')
                . (isset($vehicle['sf_liability_class']) ? ' - SF ' . $vehicle['sf_liability_class'] : '')
                . ' - Deckung: ' . $this->coverageSummary($vehicle)
                . (isset($insurance['premium_amount'])
                    ? ' - Beitrag ' . number_format($insurance['premium_amount'], 2, ',', '.') . ' EUR'
                        . ' ' . $this->intervalLabel($insurance['premium_interval'] ?? null)
                    : '')
                . ($evb !== null ? ' - eVB ' . preg_replace('/\s.*$/', '', $evb) : '')
                . ' - noch kein Versicherungsschein (Antrag)'
                . ' - Felder gratis aus dem Antrag gelesen (ohne KI).',
            'title' => 'Kfz-Antrag' . ($name !== '' ? ' ' . $name : ''),
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
    private function parsePerson(): array
    {
        $raw = [];

        // "Anrede, Titel, Vorname, Nachname:   Herr Ali Al-Rikabi"
        $name = $this->labelValue('Anrede, Titel, Vorname, Nachname');
        if ($name !== null) {
            if (preg_match('/^(Herrn?|Frau)\s+(.+)$/u', $name, $m)) {
                $raw['gender'] = mb_strtolower($m[1]) === 'frau' ? 'female' : 'male';
                $name = trim($m[2]);
            }
            // Akademische Titel gehoeren nicht in den Namen.
            $name = trim((string) preg_replace('/\b(Dr\.|Prof\.|Dipl\.-\p{L}+)\s*/u', '', $name));
            $parts = preg_split('/\s+/', $name) ?: [];
            if (count($parts) >= 2) {
                $raw['last_name'] = array_pop($parts);
                $raw['first_name'] = implode(' ', $parts);
            } elseif ($parts !== []) {
                $raw['last_name'] = $parts[0];
            }
        }
        if (($v = $this->labelValue('Anrede')) !== null && !isset($raw['gender'])) {
            $raw['gender'] = mb_strtolower(trim($v)) === 'frau' ? 'female' : 'male';
        }

        if (($v = $this->labelValue('Straße')) !== null
            && preg_match('/^(.*\p{L}\.?)\s+(\d+\s*[a-zA-Z]?)\s*$/u', trim($v), $s)) {
            $raw['street'] = trim($s[1]);
            $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
        }
        if (($v = $this->labelValue('Plz, Ort')) !== null && preg_match('/^(\d{5})\s+(.+)$/u', trim($v), $z)) {
            $raw['zip'] = $z[1];
            $raw['city'] = trim($z[2]);
        }
        if (($v = $this->labelValue('E-Mail')) !== null
            && preg_match('/^[\w.+\-]+@[\w.\-]+\.\w{2,}$/u', trim($v))) {
            $raw['email'] = mb_strtolower(trim($v));
        }
        if (($v = $this->labelValue('Geburtsdatum')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (($v = $this->labelValue('Familienstand')) !== null) {
            $raw['marital_status'] = $this->mapMaritalStatus($v);
        }
        // "Staatsangehoerigkeit: Deutschland" bzw. "Nationalitaet".
        $nat = $this->labelValue('Staatsangehörigkeit') ?? $this->labelValue('Nationalität');
        if ($nat !== null && preg_match('/^\p{L}[\p{L} \-]{2,40}$/u', trim($nat))) {
            $raw['nationality'] = trim($nat);
        }
        // Beruflicher Status ("Angestellter").
        $status = $this->labelValue('Derzeitiger Status des Versicherungsnehmers');
        if ($status !== null && preg_match('/^\p{L}[\p{L} \-\/()]{2,60}$/u', trim($status))) {
            $raw['occupation'] = trim($status);
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseVehicle(): array
    {
        $raw = [];

        // Kennzeichen steht mal als "RD - AS 1212", mal als "RD-AS 1212".
        $plate = $this->labelValue('Amtliches Kennzeichen') ?? $this->labelValue('Kennzeichen');
        if ($plate !== null) {
            $plate = trim((string) preg_replace('/\s*-\s*/', '-', trim($plate)));
            if (preg_match('/^[A-ZÄÖÜ]{1,3}-[A-ZÄÖÜ]{1,2}\s?\d{1,4}[EH]?$/u', $plate)) {
                $raw['license_plate'] = $plate;
            }
        }
        if (($v = $this->labelValue('Fahrgestellnummer')) !== null
            && preg_match('/\b([A-HJ-NPR-Z0-9]{11,17})\b/', strtoupper(trim($v)), $m)) {
            $raw['vin'] = $m[1];
        }
        // "Wagnis (gemaess GDV): Lkw bis 3,5 t zul. Gesamtgewicht ..."
        if (($v = $this->labelValue('Wagnis')) !== null) {
            $raw['vehicle_type'] = $this->mapVehicleType($v);
        }
        // "HSN / Hersteller:  4136 - FIAT"
        if (($v = $this->labelValue('HSN / Hersteller')) !== null
            && preg_match('/^(\d{4})\s*-\s*(\p{L}[\p{L} .\-]{1,40})$/u', trim($v), $m)) {
            $raw['hsn'] = $m[1];
            $raw['manufacturer'] = trim($m[2]);
        }
        // "Leistung des Fahrzeugs: 88 kW / 120 PS"
        if (($v = $this->labelValue('Leistung des Fahrzeugs')) !== null
            && preg_match('/^([\d.]+)\s*kW/iu', trim($v), $m)) {
            $raw['power_kw'] = (int) str_replace('.', '', $m[1]);
        }
        if (($v = $this->labelValue('Verwendeter Kraftstoff')) !== null) {
            $raw['fuel_type'] = trim($v);
        }
        if (($v = $this->labelValue('Datum der Erstzulassung')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['first_registration'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (($v = $this->labelValue('Zulassung auf den Fahrzeughalter')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['acquisition_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (($v = $this->labelValue('Fahrzeughalter')) !== null
            && preg_match('/Versicherungsnehmer/iu', $v)) {
            $raw['holder_type'] = 'versicherungsnehmer';
        }
        if (($v = $this->labelValue('Jährliche Fahrleistung')) !== null
            && preg_match('/([\d.]+)\s*km/iu', $v, $m)) {
            $raw['annual_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        $km = $this->labelValue('Aktueller Kilometerstand') ?? $this->labelValue('Kilometerstand');
        if ($km !== null && preg_match('/([\d.]+)\s*km/iu', $km, $m)) {
            $raw['initial_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        // "Berechnete SF-Klasse Haftpflicht:  SF 1 (70 %)"
        $sf = $this->labelValue('Berechnete SF-Klasse Haftpflicht')
            ?? $this->labelValue('SF-Klasse Haftpflicht');
        if ($sf !== null) {
            $raw['sf_liability_class'] = trim($sf);
        }

        // Kaskoart ist ein eigenes Feld - eindeutiger als jede Stichwortsuche.
        $kasko = $this->labelValue('Gewünschte Kaskoart') ?? $this->labelValue('Kaskoart');
        if ($kasko !== null) {
            $k = mb_strtolower($kasko);
            $raw['has_vollkasko'] = str_contains($k, 'vollkasko');
            $raw['has_teilkasko'] = $raw['has_vollkasko'] || str_contains($k, 'teilkasko');
        }

        // Zusatzleistungen: nur was mit "Ja" angefordert wurde.
        $extras = [];
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*-?\s*Schutzbrief\s{2,}Ja\s*$/u', $line)) {
                $extras[] = 'schutzbrief';
            }
        }
        if ($extras !== []) {
            $raw['extras'] = array_values(array_unique($extras));
        }

        return $this->validatedVehicle($raw);
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = [
            'sparte' => 'kfz',
            // NAFI-Vorgangsnummer = Referenz des Vorgangs (KEINE
            // Vertragsnummer) - Bruecke zur spaeteren Police.
            'reference_number' => $this->labelValue('Vorgang') ?? $this->labelValue('Vorgangsnummer'),
            // Ein ANTRAG ist noch kein Vertrag - und hat keine Vertragsnummer.
            'document_stage' => Contract::STAGE_ANTRAG,
        ];

        $versicherer = $this->labelValue('Versicherer / Risikoträger') ?? $this->labelValue('Versicherer');
        if ($versicherer !== null && preg_match('/^\p{L}[\p{L} .\-&+]{2,60}$/u', trim($versicherer))) {
            $raw['insurer'] = trim($versicherer);
        }
        if (($v = $this->labelValue('Tarif')) !== null && preg_match('/^[\p{L}\d][\p{L}\d .\-+]{2,60}$/u', trim($v))) {
            $raw['tariff'] = trim($v);
        }
        $beginn = $this->labelValue('Gewünschter Versicherungsbeginn') ?? $this->labelValue('Versicherungsbeginn');
        if ($beginn !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $beginn, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // "Vertragsablauf (naechste Hauptfaelligkeit): 01.01.2027"
        if (($v = $this->labelValue('Vertragsablauf')) !== null
            && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $v, $m)) {
            $raw['end_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Beitrag: "Zu zahlender Gesamtbeitrag (vierteljaehrlich): 296,00 EUR"
        // - der Gesamtbeitrag, nicht die einzelne Sparte.
        foreach ($this->lines as $line) {
            if (!preg_match('/^\s*Zu zahlender Gesamtbeitrag\s*(?:\(([^)]*)\))?\s*:\s*.*?([\d.]+,\d{2})\s*EUR/u', $line, $m)) {
                continue;
            }
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[2]);
            $raw['premium_interval'] = $this->mapInterval($m[1] ?? '') ?? $this->paymentInterval();
            break;
        }
        if (!isset($raw['premium_amount'])
            && preg_match('/Ihr Beitrag[^:]*:\s*([\d.]+,\d{2})\s*EUR/u', $this->text(), $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $this->paymentInterval();
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * IBAN/BIC des SEPA-Mandats - aber NUR, wenn die zahlungspflichtige Person
     * der Versicherungsnehmer ist. Zahlt ein Dritter, gehoert das Konto nicht
     * in die Kundenakte.
     *
     * @return array<string,mixed>
     */
    private function parseBank(): array
    {
        $zahler = $this->labelValue('Zahlungspflichtige Person');
        if ($zahler === null || !preg_match('/Versicherungsnehmer/iu', $zahler)) {
            return [];
        }

        $raw = [];
        if (($v = $this->labelValue('IBAN (SEPA)')) !== null || ($v = $this->labelValue('IBAN')) !== null) {
            $iban = strtoupper((string) preg_replace('/\s+/', '', trim($v)));
            if (preg_match('/^DE\d{20}$/', $iban)) {
                $raw['iban'] = $iban;
            }
        }
        if (($v = $this->labelValue('BIC (SEPA)')) !== null || ($v = $this->labelValue('BIC')) !== null) {
            $bic = strtoupper(trim($v));
            if (preg_match('/^[A-Z0-9]{8,11}$/', $bic)) {
                $raw['bic'] = $bic;
            }
        }
        if (($v = $this->labelValue('Bankname')) !== null && preg_match('/^\p{L}[\p{L} .\-&]{2,60}$/u', trim($v))) {
            $raw['bank_name'] = trim($v);
        }

        return $this->validatedBank($raw);
    }

    /** Zahlungsperiode aus dem eigenen Feld. */
    private function paymentInterval(): ?string
    {
        $v = $this->labelValue('Zahlungsperiode');

        return $v !== null ? $this->mapInterval($v) : null;
    }

    private function mapInterval(string $german): ?string
    {
        $v = mb_strtolower(trim($german));

        return match (true) {
            $v === ''                          => null,
            str_contains($v, 'monatlich')      => 'monthly',
            str_contains($v, 'viertelj')       => 'quarterly',
            str_contains($v, 'halbj')          => 'semiannual',
            str_contains($v, 'jährlich'), str_contains($v, 'jaehrlich') => 'yearly',
            default                            => null,
        };
    }

    private function intervalLabel(?string $interval): string
    {
        return match ($interval) {
            'monthly'    => 'monatlich',
            'quarterly'  => 'vierteljaehrlich',
            'semiannual' => 'halbjaehrlich',
            'yearly'     => 'jaehrlich',
            default      => '',
        };
    }

    private function mapMaritalStatus(string $value): ?string
    {
        $v = mb_strtolower(trim($value));

        return match (true) {
            str_contains($v, 'verheiratet')  => 'verheiratet',
            str_contains($v, 'ledig')        => 'ledig',
            str_contains($v, 'geschieden')   => 'geschieden',
            str_contains($v, 'verwitwet')    => 'verwitwet',
            default                          => null,
        };
    }

    private function mapVehicleType(string $value): ?string
    {
        $v = mb_strtolower($value);

        return match (true) {
            str_contains($v, 'lkw') || str_contains($v, 'lastkraft') => 'lkw',
            str_contains($v, 'transporter')                          => 'transporter',
            str_contains($v, 'wohnmobil')                            => 'wohnmobil',
            str_contains($v, 'wohnwagen')                            => 'wohnwagen',
            str_contains($v, 'anhänger'), str_contains($v, 'anhaenger') => 'anhaenger',
            str_contains($v, 'taxi')                                 => 'taxi',
            str_contains($v, 'pkw') || str_contains($v, 'personenkraftwagen') => 'pkw',
            default                                                  => null,
        };
    }

    /** Kurztext der Deckung fuer die Zusammenfassung. */
    private function coverageSummary(array $kfz): string
    {
        $parts = ['Haftpflicht'];
        if (!empty($kfz['has_vollkasko'])) {
            $parts[] = 'Vollkasko';
        } elseif (!empty($kfz['has_teilkasko'])) {
            $parts[] = 'Teilkasko';
        } elseif (isset($kfz['has_teilkasko'])) {
            $parts[] = 'keine Kasko';
        }
        if (in_array('schutzbrief', $kfz['extras'] ?? [], true)) {
            $parts[] = 'Schutzbrief';
        }

        return implode(', ', $parts);
    }

    /**
     * Wert hinter "Beschriftung:" - das Formular setzt ihn rechtsbuendig in
     * dieselbe Zeile. Die Beschriftung steht am Zeilenanfang, damit ein
     * gleichlautendes Wort im Fliesstext nicht zaehlt.
     */
    private function labelValue(string $label): ?string
    {
        $re = '/^\s*' . preg_quote($label, '/') . '\s*(?:\([^)]*\))?\s*:\s*(\S.*?)\s*$/u';
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

    private function text(): string
    {
        return implode("\n", $this->lines);
    }
}
