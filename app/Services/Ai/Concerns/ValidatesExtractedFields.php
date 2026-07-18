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
        return array_filter([
            'health_insurance_company' => $this->cleanString($in['health_insurance_company'] ?? null, 120),
            'health_insurance_number' => $this->cleanString($in['health_insurance_number'] ?? null, 30),
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
}
