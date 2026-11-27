<?php

namespace App\Support;

use App\Models\Customer;
use Carbon\Carbon;

/**
 * Die Kundendaten der Kundenakte in logisch getrennten Bereichen - fuer die
 * Uebersicht OHNE Bearbeiten-Modus und fuer "Alle Kundendaten kopieren"
 * (Betreiber-Auftrag 27.11.2026: Daten fuer neue Vertraege uebernehmen).
 *
 * EINE Quelle fuer Anzeige UND Kopie: jeder Wert stammt direkt aus den
 * gespeicherten Feldern der Akte, es wird nichts abgeleitet oder geraten
 * (kein Land, wenn keines erfasst ist; kein Bankname, weil die Akte keinen
 * fuehrt). `kopie` ist der ORIGINALWERT, `anzeige` darf formatiert sein
 * (IBAN in Vierergruppen) - kopiert wird nie die Anzeige.
 *
 * Wer die Kundenakte oeffnen darf, darf auch die Daten sehen - dieselbe
 * Regel wie im Bearbeiten-Formular, das alle Werte im Klartext zeigt.
 */
final class Kundendatenblatt
{
    /**
     * @return array<string, array{titel: string, felder: list<array{key: string, label: string, anzeige: string, kopie: string, sensibel: bool, geerbt: bool}>}>
     */
    public static function bereiche(Customer $customer): array
    {
        $user = $customer->user;
        $name = trim((string) ($user->name ?? ''));
        // Dieselbe Trennung wie im Bearbeiten-Formular - die Uebersicht darf
        // nichts anderes zeigen, als dort gespeichert wird.
        $teile = $name !== '' ? explode(' ', $name, 2) : [];
        $loginMail = ($user !== null && $user->hasRealEmail()) ? (string) $user->email : '';

        $telefon = $customer->effectiveContact('phone');
        $mobil = $customer->effectiveContact('mobile');
        $anschrift = self::anschrift($customer);

        $persoenlich = [
            self::feld('first_name', 'Vorname', $teile[0] ?? ''),
            self::feld('last_name', 'Nachname', $teile[1] ?? ''),
            self::feld('birth_date', 'Geburtsdatum', self::datum($customer->birth_date)),
            self::feld('customer_number', 'Kundennummer', (string) $customer->customer_number),
            self::feld('email', 'E-Mail (Login)', $loginMail),
            self::feld('email2', 'Weitere E-Mail', (string) $customer->email2),
            self::feld('phone', 'Telefon', $telefon['value'], geerbt: $telefon['inherited']),
            self::feld('mobile', 'Mobil', $mobil['value'], geerbt: $mobil['inherited']),
        ];

        $adresse = [
            self::feld('address_full', 'Vollständige Adresse', $anschrift['voll'], geerbt: $anschrift['geerbt']),
            self::feld('address_street', 'Straße', $anschrift['strasse']),
            self::feld('address_house_number', 'Hausnummer', $anschrift['nummer']),
            self::feld('address_zip', 'PLZ', $anschrift['plz']),
            self::feld('address_city', 'Ort', $anschrift['ort']),
            self::feld('address_country', 'Land', $anschrift['land']),
            self::feld('address2', 'Zweitadresse / Postadresse', (string) $customer->address2),
        ];

        $iban = self::iban((string) $customer->iban);
        $iban2 = self::iban((string) $customer->iban2);
        $bank = [
            self::feld('account_holder', 'Kontoinhaber', (string) $customer->account_holder),
            self::feld('iban', 'IBAN', $iban, self::ibanAnzeige($iban), sensibel: true),
            self::feld('bic', 'BIC', strtoupper(trim((string) $customer->bic)), sensibel: true),
            self::feld('iban2', 'IBAN 2 (alternativ)', $iban2, self::ibanAnzeige($iban2), sensibel: true),
        ];

        $weitere = [
            self::feld('health_insurance_type', 'Versicherungsart', ['gesetzlich' => 'Gesetzlich', 'privat' => 'Privat'][$customer->health_insurance_type] ?? ''),
            self::feld('health_insurance_company', 'Krankenkasse', (string) $customer->health_insurance_company),
            self::feld('health_insurance_number', 'KV-Nummer', (string) $customer->health_insurance_number, sensibel: true),
            self::feld('pension_insurance_number', 'Rentenversicherungsnr.', (string) $customer->pension_insurance_number, sensibel: true),
            self::feld('tax_id', 'Steuer-ID', (string) $customer->tax_id, sensibel: true),
            self::feld('gender', 'Geschlecht', Customer::GENDERS[$customer->gender] ?? ''),
            self::feld('marital_status', 'Familienstand', $customer->marital_status ? ucfirst((string) $customer->marital_status) : ''),
            self::feld('nationality', 'Staatsangehörigkeit', (string) $customer->nationality),
            self::feld('birth_place', 'Geburtsort', (string) $customer->birth_place),
            self::feld('occupation', 'Beruf', (string) $customer->occupation),
            self::feld('employer_name', 'Arbeitgeber', (string) $customer->employer_name),
            self::feld('employer_address', 'Arbeitgeber-Anschrift', (string) $customer->employer_address),
            self::feld('preferred_lang', 'Korrespondenzsprache', strtoupper((string) $customer->preferred_lang)),
            self::feld('company_name', 'Firma', trim($customer->company_name.($customer->company_type ? ' ('.$customer->company_type.')' : ''))),
        ];

        return [
            'persoenlich' => ['titel' => 'Persönliche Daten', 'felder' => $persoenlich],
            'adresse' => ['titel' => 'Adresse', 'felder' => $adresse],
            'bank' => ['titel' => 'Bankverbindung', 'felder' => $bank],
            'weitere' => ['titel' => 'Weitere Kundendaten', 'felder' => $weitere],
        ];
    }

    /**
     * Alle GEFUELLTEN Werte als Text zum Einfuegen ("Label: Wert"), je Bereich
     * mit Ueberschrift. Leere Felder fehlen - ein "—" waere beim Einfuegen in
     * ein fremdes Formular ein falscher Wert. Die Einzelteile der Anschrift
     * stehen dabei, weil Zielformulare sie meist getrennt verlangen.
     */
    public static function alsText(Customer $customer): string
    {
        $name = trim((string) ($customer->user->name ?? ''));
        $zeilen = ['Kundendaten'.($name !== '' ? ' – '.$name : '').($customer->customer_number ? ' (Kd.-Nr. '.$customer->customer_number.')' : '')];
        foreach (self::bereiche($customer) as $bereich) {
            $gefuellt = array_filter($bereich['felder'], fn ($f) => $f['kopie'] !== '');
            if ($gefuellt === []) {
                continue;
            }
            $zeilen[] = '';
            $zeilen[] = $bereich['titel'];
            foreach ($gefuellt as $f) {
                $zeilen[] = $f['label'].': '.$f['kopie'];
            }
        }

        return implode("\n", $zeilen);
    }

    /** @return array{voll: string, strasse: string, nummer: string, plz: string, ort: string, land: string, geerbt: bool} */
    private static function anschrift(Customer $customer): array
    {
        $land = trim(explode(',', (string) $customer->address)[2] ?? '');
        $hatStruktur = filled($customer->address_street) || filled($customer->address_zip) || filled($customer->address_city);

        if ($hatStruktur) {
            [$strasse, $nummer] = Anschrift::trenne($customer->address_street, $customer->address_house_number);
            $nummer = trim((string) $nummer.($customer->address_house_suffix ? ' '.$customer->address_house_suffix : ''));
            $voll = $customer->fullAddress();

            return [
                'voll' => $voll.($land !== '' ? ', '.$land : ''),
                'strasse' => trim((string) $strasse),
                'nummer' => $nummer,
                'plz' => trim((string) $customer->address_zip),
                'ort' => trim((string) $customer->address_city),
                'land' => $land,
                'geerbt' => false,
            ];
        }

        // Altbestand bzw. abhaengiges Kind: die Anschrift kann von der
        // Bezugsperson GELESEN werden (nie kopiert gespeichert).
        $eff = $customer->effectiveContact('address');
        $voll = $eff['value'] !== '' ? $eff['value'] : trim((string) $customer->address);
        $teile = array_map('trim', explode(',', $voll));
        [$strasse, $nummer] = Anschrift::trenne($teile[0], null);
        $plz = $ort = '';
        if (isset($teile[1]) && preg_match('/^(\d{4,5})\s+(.+)$/u', $teile[1], $m)) {
            [$plz, $ort] = [$m[1], trim($m[2])];
        } elseif (isset($teile[1])) {
            $ort = $teile[1];
        }

        return [
            'voll' => $voll,
            'strasse' => trim((string) $strasse),
            'nummer' => trim((string) $nummer),
            'plz' => $plz,
            'ort' => $ort,
            'land' => trim($teile[2] ?? ''),
            'geerbt' => $eff['inherited'],
        ];
    }

    private static function datum(mixed $wert): string
    {
        if (blank($wert)) {
            return '';
        }
        try {
            return Carbon::parse($wert)->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $wert;
        }
    }

    private static function iban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    private static function ibanAnzeige(string $iban): string
    {
        return trim(chunk_split($iban, 4, ' '));
    }

    /** @return array{key: string, label: string, anzeige: string, kopie: string, sensibel: bool, geerbt: bool} */
    private static function feld(string $key, string $label, string $kopie, ?string $anzeige = null, bool $sensibel = false, bool $geerbt = false): array
    {
        $kopie = trim($kopie);

        return [
            'key' => $key,
            'label' => $label,
            'anzeige' => $anzeige !== null ? trim($anzeige) : $kopie,
            'kopie' => $kopie,
            'sensibel' => $sensibel,
            'geerbt' => $geerbt,
        ];
    }
}
