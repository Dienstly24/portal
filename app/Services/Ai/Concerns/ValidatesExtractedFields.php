<?php
namespace App\Services\Ai\Concerns;

use App\Models\Contract;

/**
 * Harte Validierung extrahierter Dokumentfelder - unabhaengig davon, ob
 * die Rohdaten von einem KI-Anbieter (Freitext-JSON) oder der OCR-
 * Heuristik (Regex-Treffer) stammen: unbekannte Werte, kaputte Datums-
 * angaben oder unplausible Zahlen werden verworfen bzw. bereinigt, eine
 * Halluzination/ein Regex-Fehltreffer darf nie falsche Stammdaten erzeugen.
 */
trait ValidatesExtractedFields
{
    private function validatedPerson(mixed $in): array
    {
        if (!is_array($in)) return [];
        $gender = $in['gender'] ?? null;
        $marital = $in['marital_status'] ?? null;
        return array_filter([
            'first_name' => $this->cleanString($in['first_name'] ?? null, 80),
            'last_name' => $this->cleanString($in['last_name'] ?? null, 80),
            'birth_date' => $this->cleanDate($in['birth_date'] ?? null),
            'birth_place' => $this->cleanString($in['birth_place'] ?? null, 100),
            'street' => $this->cleanString($in['street'] ?? null, 120),
            'house_number' => $this->cleanString($in['house_number'] ?? null, 10),
            'zip' => $this->cleanZip($in['zip'] ?? null),
            'city' => $this->cleanString($in['city'] ?? null, 100),
            'email' => $this->cleanEmail($in['email'] ?? null),
            'phone' => $this->cleanString($in['phone'] ?? null, 40),
            'nationality' => $this->cleanString($in['nationality'] ?? null, 60),
            'id_number' => $this->cleanString($in['id_number'] ?? null, 40),
            // Geschlecht/Familienstand nur aus eindeutigen Quellen (z.B. das
            // strukturierte Kranken-Beitrittsformular) - Wertelisten wie in der
            // Kundenakte, Unbekanntes faellt heraus.
            'gender' => in_array($gender, ['male', 'female'], true) ? $gender : null,
            'marital_status' => in_array($marital, ['ledig', 'verheiratet', 'geschieden', 'verwitwet'], true) ? $marital : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Liste weiterer Personen im Dokument (z.B. Buendel mehrerer
     * Gesundheitskarten einer Familie, Familienbescheinigung). Jede Person
     * durchlaeuft dieselbe harte Validierung; zusaetzlich sind Geschlecht und
     * Versichertennummer erlaubt. Hart auf 10 begrenzt.
     */
    private function validatedPersons(mixed $in): array
    {
        if (!is_array($in)) return [];
        $out = [];
        foreach (array_slice(array_values($in), 0, 10) as $entry) {
            if (!is_array($entry)) continue;
            $person = $this->validatedPerson($entry);
            $gender = $entry['gender'] ?? null;
            if (in_array($gender, ['male', 'female'], true)) {
                $person['gender'] = $gender;
            }
            $kvnr = $this->cleanString($entry['health_insurance_number'] ?? null, 30);
            if ($kvnr !== null) {
                $person['health_insurance_number'] = $kvnr;
            }
            if (($person['first_name'] ?? '') !== '' || ($person['last_name'] ?? '') !== '') {
                $out[] = $person;
            }
        }
        return $out;
    }

    /**
     * Energie-Felder (Strom-/Gas-Auftrag, Zaehlerfoto). Zaehlerstand und
     * Verbrauch nur in plausiblen Grenzen; MaLo-ID muss dem Format (11
     * Ziffern) entsprechen, sonst wird sie verworfen.
     */
    private function validatedEnergy(mixed $in): array
    {
        if (!is_array($in)) return [];
        $malo = $this->cleanString($in['malo_id'] ?? null, 20);
        if ($malo !== null && !preg_match('/^\d{11}$/', $malo)) {
            $malo = null;
        }
        $reading = $in['meter_reading'] ?? null;
        $consumption = $in['consumption_kwh'] ?? null;
        return array_filter([
            'meter_number' => $this->cleanString($in['meter_number'] ?? null, 30),
            'malo_id' => $malo,
            'meter_reading' => (is_numeric($reading) && $reading >= 0 && $reading < 100000000) ? round((float) $reading, 1) : null,
            'consumption_kwh' => (is_numeric($consumption) && $consumption > 0 && $consumption < 1000000) ? (int) $consumption : null,
            'tariff' => $this->cleanString($in['tariff'] ?? null, 80),
            'customer_number' => $this->cleanString($in['customer_number'] ?? null, 40),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function validatedInsurance(mixed $in): array
    {
        if (!is_array($in)) return [];
        $sparte = $in['sparte'] ?? null;
        $interval = $in['premium_interval'] ?? null;
        $premium = $in['premium_amount'] ?? null;
        return array_filter([
            'insurer' => $this->cleanString($in['insurer'] ?? null, 120),
            'contract_number' => $this->cleanString($in['contract_number'] ?? null, 60),
            'sparte' => (is_string($sparte) && isset(Contract::TYPES[$sparte])) ? $sparte : null,
            'start_date' => $this->cleanDate($in['start_date'] ?? null),
            'end_date' => $this->cleanDate($in['end_date'] ?? null),
            'premium_amount' => (is_numeric($premium) && $premium > 0 && $premium < 1000000) ? round((float) $premium, 2) : null,
            'premium_interval' => in_array($interval, Contract::premiumIntervalKeys(), true) ? $interval : null,
            // Vorversicherung (bisheriger Versicherer bei einem Wechsel) - reine
            // Anzeige-Info fuer den Mitarbeiter, keine eigene Vertragsspalte.
            'previous_insurer' => $this->cleanString($in['previous_insurer'] ?? null, 120),
            // Tarif-/Produktbezeichnung (z.B. "Magenta Zuhause L" beim
            // Internet-Auftrag) - Anzeige-Info.
            'tariff' => $this->cleanString($in['tariff'] ?? null, 80),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function validatedVehicle(mixed $in): array
    {
        if (!is_array($in)) return [];
        $plate = $this->cleanString($in['license_plate'] ?? null, 15);
        $vin = $this->cleanString($in['vin'] ?? null, 20);
        if ($vin !== null && !preg_match('/^[A-HJ-NPR-Z0-9]{11,17}$/i', $vin)) {
            $vin = null; // FIN-Format unplausibel -> lieber weglassen als falsch speichern
        }
        // Wahrheitswerte (Deckung) nur uebernehmen, wenn eindeutig bool-artig.
        $bool = function ($v): ?bool {
            if (is_bool($v)) return $v;
            if (in_array($v, ['true', '1', 1, 'ja'], true)) return true;
            if (in_array($v, ['false', '0', 0, 'nein'], true)) return false;
            return null;
        };
        // Selbstbeteiligung/Fahrleistung nur in plausiblen Grenzen.
        $intInRange = function ($v, int $min, int $max): ?int {
            if (!is_numeric($v)) return null;
            $n = (int) round((float) $v);
            return ($n >= $min && $n <= $max) ? $n : null;
        };
        $holder = $in['holder_type'] ?? null;
        $holder = in_array($holder, ['versicherungsnehmer', 'abweichender_halter'], true) ? $holder : null;

        return array_filter([
            'license_plate' => $plate !== null ? mb_strtoupper($plate) : null,
            'vin' => $vin !== null ? strtoupper($vin) : null,
            'hsn' => $this->cleanString($in['hsn'] ?? null, 6),
            'tsn' => $this->cleanString($in['tsn'] ?? null, 5),
            'manufacturer' => $this->cleanString($in['manufacturer'] ?? null, 60),
            'model' => $this->cleanString($in['model'] ?? null, 80),
            'first_registration' => $this->cleanDate($in['first_registration'] ?? null),
            // Schadenfreiheitsklasse (Haftpflicht/Vollkasko) - nur wenn sie einer
            // gueltigen SF-Klasse entspricht (M, S, 0, 1/2, SF 1-50).
            'sf_liability_class' => $this->cleanSfClass($in['sf_liability_class'] ?? null),
            'sf_comprehensive_class' => $this->cleanSfClass($in['sf_comprehensive_class'] ?? null),
            // Art der Einstufung: tatsaechlich vs. Sondereinstufung (nicht
            // uebertragbar) + echte (uebertragbare) Klasse + Grund - nur aus
            // den bekannten Wertelisten.
            'sf_liability_type' => in_array($in['sf_liability_type'] ?? null, array_keys(\App\Models\ContractVehicleDetail::SF_TYPES), true)
                ? $in['sf_liability_type'] : null,
            'sf_liability_special_reason' => in_array($in['sf_liability_special_reason'] ?? null, array_keys(\App\Models\ContractVehicleDetail::SF_SPECIAL_REASONS), true)
                ? $in['sf_liability_special_reason'] : null,
            'sf_liability_real_class' => $this->cleanSfClass($in['sf_liability_real_class'] ?? null),
            // Zusaetzliche, klar abgrenzbare Tarif-/Fahrzeugfakten (z.B. aus
            // dem CHECK24-Beratungsprotokoll). Ungenaue/geschaetzte Angaben
            // fallen durch die harte Validierung heraus.
            'has_teilkasko' => $bool($in['has_teilkasko'] ?? null),
            'teilkasko_deductible' => $intInRange($in['teilkasko_deductible'] ?? null, 0, 5000),
            'has_vollkasko' => $bool($in['has_vollkasko'] ?? null),
            'vollkasko_deductible' => $intInRange($in['vollkasko_deductible'] ?? null, 0, 5000),
            'holder_type' => $holder,
            'annual_mileage' => $intInRange($in['annual_mileage'] ?? null, 0, 200000),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function validatedHealth(mixed $in): array
    {
        if (!is_array($in)) return [];
        $company = $this->cleanString($in['health_insurance_company'] ?? null, 120);
        $type = $in['health_insurance_type'] ?? null;
        $type = in_array($type, ['gesetzlich', 'privat'], true) ? $type : null;
        // Fehlt der Typ, aber der Kassenname ist eindeutig -> automatisch
        // ableiten (GKV -> gesetzlich, PKV -> privat). Sonst leer lassen.
        if ($type === null && $company !== null) {
            $type = \App\Services\Ai\KrankenkasseType::resolve($company);
        }
        return array_filter([
            'health_insurance_company' => $company,
            'health_insurance_number' => $this->cleanString($in['health_insurance_number'] ?? null, 30),
            'health_insurance_type' => $type,
            // Rentenversicherungs-/Sozialversicherungsnummer (aus dem
            // Beitrittsformular). Speicherung in customer.pension_insurance_number.
            'pension_number' => $this->cleanString($in['pension_number'] ?? null, 30),
            // Bisherige/letzte Krankenkasse (Vorversicherung) - reine Anzeige
            // fuer den Mitarbeiter, keine Kundenspalte.
            'previous_insurer' => $this->cleanString($in['previous_insurer'] ?? null, 120),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function validatedBank(mixed $in): array
    {
        if (!is_array($in)) return [];
        $iban = $in['iban'] ?? null;
        if (is_string($iban)) {
            $iban = strtoupper((string) preg_replace('/\s+/', '', $iban));
            if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban)) {
                $iban = null;
            }
        } else {
            $iban = null;
        }
        return array_filter([
            'iban' => $iban,
            'bic' => $this->cleanString($in['bic'] ?? null, 11),
            'account_holder' => $this->cleanString($in['account_holder'] ?? null, 120),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function cleanString(mixed $value, int $max): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function cleanDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($value) : null;
    }

    private function cleanZip(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return preg_match('/^\d{4,5}$/', $value) ? $value : null;
    }

    private function cleanEmail(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_substr($value, 0, 190) : null;
    }

    /**
     * SF-Klasse auf den Schluessel bringen ("SF 2" -> "2", "Klasse M" -> "M")
     * und gegen die gueltigen Klassen pruefen (M, S, 0, 1/2, 1-50). Alles
     * andere wird verworfen - lieber leer als eine falsche Einstufung.
     */
    private function cleanSfClass(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $s = trim($value);
        if ($s === '') return null;
        if (preg_match('/(?:SF|Klasse)?\s*(\d{1,2}\/\d|\d{1,2}|[MS])\b/i', $s, $m)) {
            $key = strtoupper($m[1]);
            return in_array($key, \App\Models\ContractVehicleDetail::sfClassKeys(), true) ? $key : null;
        }
        return null;
    }
}
