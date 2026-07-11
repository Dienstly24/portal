<?php
namespace App\Services\Matching;

use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * Score-basierte Kundenerkennung (Architekturplan Abschnitt 5).
 * Gewichte: Geburtsdatum exakt 40, Name (fuzzy) 30, E-Mail exakt 20,
 * Adresse (fuzzy) 10, Bonus Telefon +5. Freigabestufen (>90/70-90/<70)
 * siehe MatchResult::tier() - Anwendung der generalisierten HITL-Logik
 * aus dem Architekturplan Abschnitt 13.
 *
 * Bewusst KEIN zweites Kunden-Verzeichnis: diese Klasse liest
 * ausschließlich aus dem bestehenden Customer/User/CustomerAddress-
 * Datenmodell, sie führt keine eigene Kundenliste.
 */
class CustomerMatchingService
{
    private const WEIGHT_BIRTH_DATE = 40;
    private const WEIGHT_NAME = 30;
    private const WEIGHT_EMAIL = 20;
    private const WEIGHT_ADDRESS = 10;
    private const WEIGHT_PHONE_BONUS = 5;

    /**
     * @param array{
     *     full_name?: ?string, first_name?: ?string, last_name?: ?string,
     *     birth_date?: ?string, email?: ?string, phone?: ?string,
     *     street?: ?string, zip?: ?string, city?: ?string,
     * } $criteria
     */
    public function match(array $criteria): MatchResult
    {
        $candidates = $this->candidatePool($criteria);

        $best = null;
        $bestScore = 0;
        $bestBreakdown = [];

        foreach ($candidates as $customer) {
            [$score, $breakdown] = $this->score($customer, $criteria);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $customer;
                $bestBreakdown = $breakdown;
            }
        }

        return new MatchResult($best, min(100, $bestScore), $bestBreakdown);
    }

    /** Begrenzter Kandidatenpool statt Volltabellen-Scan bei jedem Matching-Lauf. */
    private function candidatePool(array $criteria)
    {
        $query = Customer::with(['user', 'addresses']);

        $query->where(function ($q) use ($criteria) {
            $any = false;

            if (!empty($criteria['birth_date'])) {
                $q->orWhere('birth_date', $criteria['birth_date']);
                $any = true;
            }
            if (!empty($criteria['email'])) {
                $q->orWhereHas('user', fn ($u) => $u->where('email', $criteria['email']))
                    ->orWhere('email2', $criteria['email']);
                $any = true;
            }
            $name = $criteria['full_name'] ?? trim(($criteria['first_name'] ?? '') . ' ' . ($criteria['last_name'] ?? ''));
            if (trim($name) !== '') {
                $lastNamePart = $criteria['last_name'] ?? Str::afterLast($name, ' ');
                if ($lastNamePart !== '') {
                    $q->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%' . $lastNamePart . '%'));
                    $any = true;
                }
            }
            if (!empty($criteria['phone'])) {
                $q->orWhere('phone', $criteria['phone']);
                $any = true;
            }

            // Keine Kriterien angegeben: leerer Pool statt Volltabellen-Match.
            if (!$any) {
                $q->whereRaw('1 = 0');
            }
        });

        return $query->limit(50)->get();
    }

    /** @return array{0: int, 1: array<string, array{points: int, max: int, reason: string}>} */
    private function score(Customer $customer, array $criteria): array
    {
        $breakdown = [];
        $total = 0;

        // Geburtsdatum exakt (40)
        if (!empty($criteria['birth_date']) && $customer->birth_date) {
            $match = (string) $customer->birth_date === (string) $criteria['birth_date'];
            $breakdown['birth_date'] = ['points' => $match ? self::WEIGHT_BIRTH_DATE : 0, 'max' => self::WEIGHT_BIRTH_DATE, 'reason' => $match ? 'Geburtsdatum stimmt exakt überein' : 'Geburtsdatum weicht ab'];
            $total += $breakdown['birth_date']['points'];
        }

        // Name fuzzy (30)
        $candidateName = (string) ($customer->user?->name ?? '');
        $inputName = trim($criteria['full_name'] ?? (($criteria['first_name'] ?? '') . ' ' . ($criteria['last_name'] ?? '')));
        if ($candidateName !== '' && $inputName !== '') {
            $similarity = $this->nameSimilarity($candidateName, $inputName);
            $points = (int) round(self::WEIGHT_NAME * $similarity);
            $breakdown['name'] = ['points' => $points, 'max' => self::WEIGHT_NAME, 'reason' => sprintf('Namensähnlichkeit %d%% ("%s" vs. "%s")', round($similarity * 100), $candidateName, $inputName)];
            $total += $points;
        }

        // E-Mail exakt (20)
        if (!empty($criteria['email'])) {
            $match = $this->normalizeEmail($customer->user?->email) === $this->normalizeEmail($criteria['email'])
                || $this->normalizeEmail($customer->email2) === $this->normalizeEmail($criteria['email']);
            $breakdown['email'] = ['points' => $match ? self::WEIGHT_EMAIL : 0, 'max' => self::WEIGHT_EMAIL, 'reason' => $match ? 'E-Mail-Adresse stimmt überein' : 'E-Mail-Adresse weicht ab'];
            $total += $breakdown['email']['points'];
        }

        // Adresse fuzzy: PLZ + Straße (10)
        if (!empty($criteria['zip']) || !empty($criteria['street'])) {
            $addressScore = $this->addressSimilarity($customer, $criteria);
            $points = (int) round(self::WEIGHT_ADDRESS * $addressScore);
            $breakdown['address'] = ['points' => $points, 'max' => self::WEIGHT_ADDRESS, 'reason' => sprintf('Adressähnlichkeit %d%%', round($addressScore * 100))];
            $total += $points;
        }

        // Bonus: Telefon (5)
        if (!empty($criteria['phone']) && $customer->phone) {
            $match = $this->normalizePhone($customer->phone) === $this->normalizePhone($criteria['phone']);
            if ($match) {
                $breakdown['phone_bonus'] = ['points' => self::WEIGHT_PHONE_BONUS, 'max' => self::WEIGHT_PHONE_BONUS, 'reason' => 'Telefonnummer stimmt überein (Bonus)'];
                $total += self::WEIGHT_PHONE_BONUS;
            }
        }

        return [$total, $breakdown];
    }

    private function addressSimilarity(Customer $customer, array $criteria): float
    {
        $best = 0.0;
        $addresses = $customer->addresses->isNotEmpty() ? $customer->addresses : collect();

        // Fallback auf das einzelne Freitext-Adressfeld, falls keine strukturierten Adressen vorliegen.
        if ($addresses->isEmpty() && $customer->address) {
            $candidateStreet = $customer->address;
            $inputStreet = trim(($criteria['street'] ?? '') . ' ' . ($criteria['zip'] ?? '') . ' ' . ($criteria['city'] ?? ''));
            return $inputStreet !== '' ? $this->textSimilarity($candidateStreet, $inputStreet) : 0.0;
        }

        foreach ($addresses as $addr) {
            $zipMatch = !empty($criteria['zip']) && $addr->zip && trim($addr->zip) === trim($criteria['zip']);
            $streetSim = !empty($criteria['street']) && $addr->street ? $this->textSimilarity($addr->street, $criteria['street']) : 0.0;
            $score = ($zipMatch ? 0.5 : 0.0) + ($streetSim * 0.5);
            $best = max($best, $score);
        }

        return $best;
    }

    private function nameSimilarity(string $a, string $b): float
    {
        return $this->textSimilarity($this->normalizeName($a), $this->normalizeName($b));
    }

    private function textSimilarity(string $a, string $b): float
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        similar_text($a, $b, $percent);
        return $percent / 100;
    }

    /** Titel entfernen, Umlaute transliterieren, Mehrfach-Leerzeichen normalisieren. */
    private function normalizeName(string $name): string
    {
        $name = preg_replace('/^(dr\.?|prof\.?|dipl\.?[- ]?ing\.?)\s+/i', '', trim($name)) ?? $name;
        $name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return trim($name);
    }

    private function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
    }
}
