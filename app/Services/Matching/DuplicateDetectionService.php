<?php
namespace App\Services\Matching;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Systemweiter Dubletten-Abgleich. Findet JEDES Kundenpaar, das in
 * mindestens EINEM belastbaren Merkmal uebereinstimmt - Name, Telefon,
 * E-Mail, Anschrift ODER Vertragsnummer. Bewusst KEINE Punkte-Mindestgrenze
 * mehr: schon eine einzige Uebereinstimmung (z. B. zweimal derselbe Name)
 * ist ein Verdachtsfall, den ein Mitarbeiter pruefen soll. Der Punkte-Score
 * dient nur noch der Sortierung/Gewichtung, nicht als Filter.
 *
 * Verfahren (Blocking): statt jeden mit jedem zu vergleichen (n^2), werden
 * Kunden ueber normalisierte Schluessel in "Bloecke" gruppiert (gleicher
 * Name / gleiche Nummer / gleiche Anschrift ...). Nur innerhalb eines Blocks
 * entstehen Kandidatenpaare - das skaliert auch bei tausenden Kunden.
 */
class DuplicateDetectionService
{
    /** Nur noch fuer die Merge-Vorauswahl (starker Treffer) genutzt. */
    public const DEFAULT_THRESHOLD = 70;

    /**
     * Ab diesem Score gilt ein Paar als "sicher" und darf per Ein-Klick-Aktion
     * ohne Einzelpruefung zusammengefuehrt werden (Betreiber-Entscheidung).
     * Darunter (nur schwaches Signal wie gleicher Name allein) bleibt die
     * manuelle Pruefung Pflicht.
     */
    public const AUTO_MERGE_MIN_SCORE = 40;

    /** Sicherheitsdeckel gegen Extrembestaende (Blocking ist ~O(n)). */
    private const MAX_SCAN = 20000;

    /** Obergrenze angezeigter Paare, damit die Seite handhabbar bleibt. */
    private const MAX_PAIRS = 500;

    /** Ab dieser Blockgroesse nicht mehr alle Paare bilden, sondern verketten. */
    private const BUCKET_ALLPAIRS_LIMIT = 8;

    /** Mindest-Namensaehnlichkeit fuer den unscharfen Namensblock (Tippfehler). */
    private const NAME_FUZZY_MIN = 0.82;

    public function __construct(private readonly CustomerMatchingService $matcher)
    {
    }

    /**
     * @param ?array<int, string> $visibleIds Portfolio-Sicht; null = alle.
     * @return array{pairs: array<int, array{primary: Customer, duplicate: Customer, score: int, tier: string, signals: array<int, string>}>, scanned: int, capped: bool}
     */
    public function scan(?array $visibleIds = null): array
    {
        $query = Customer::query()
            ->with(['user', 'addresses', 'contracts:id,customer_id,contract_number'])
            ->latest('created_at');
        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }

        $customers = $query->limit(self::MAX_SCAN + 1)->get();
        $capped = $customers->count() > self::MAX_SCAN;
        if ($capped) {
            $customers = $customers->slice(0, self::MAX_SCAN)->values();
        }

        // 1) Blocking: normalisierte Schluessel -> Liste der Kundenindizes.
        $buckets = $this->buildBuckets($customers);

        // Bloecke nach Signalstaerke ordnen, damit bei erreichtem Paar-Limit
        // die starken Signale (Vertrag/IBAN/E-Mail/Telefon/Name) zuerst
        // eingesammelt werden und schwache (nur Anschrift) zuletzt.
        $priority = ['c' => 0, 'i' => 1, 'e' => 2, 'p' => 3, 'n' => 4, 'a' => 5];
        uksort($buckets, function ($x, $y) use ($priority) {
            $px = $priority[explode(':', $x, 2)[0]] ?? 9;
            $py = $priority[explode(':', $y, 2)[0]] ?? 9;
            return $px <=> $py;
        });

        // 2) Kandidatenpaare aus allen Bloecken einsammeln (dedupliziert).
        $pairIndex = [];  // "i|j" => true
        $pairs = [];
        $bucketCapped = false;

        foreach ($buckets as $members) {
            $members = array_values(array_unique($members));
            $count = count($members);
            if ($count < 2) {
                continue;
            }

            // Kleine Bloecke: alle Paare. Grosse Bloecke: an den aeltesten
            // Datensatz (members[0], da nach created_at absteigend geladen ->
            // letzter Eintrag ist der aelteste) verketten, statt n^2 Paare.
            if ($count <= self::BUCKET_ALLPAIRS_LIMIT) {
                for ($x = 0; $x < $count; $x++) {
                    for ($y = $x + 1; $y < $count; $y++) {
                        $this->addPair($customers, $members[$x], $members[$y], $pairIndex, $pairs, $bucketCapped);
                    }
                }
            } else {
                $anchor = end($members); // aeltester im Block
                foreach ($members as $m) {
                    if ($m === $anchor) {
                        continue;
                    }
                    $this->addPair($customers, $anchor, $m, $pairIndex, $pairs, $bucketCapped);
                }
            }
        }

        usort($pairs, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'pairs'   => $pairs,
            'scanned' => $customers->count(),
            'capped'  => $capped || $bucketCapped,
        ];
    }

    /** Kurz gecachte Trefferzahl fuer den Hinweis-Badge in der Kundenliste. */
    public function countCached(?array $visibleIds = null, int $ttlSeconds = 300): int
    {
        $scope = $visibleIds === null ? 'all' : md5(implode(',', $visibleIds));

        return (int) Cache::remember("duplicate_candidates_count_{$scope}", $ttlSeconds, function () use ($visibleIds) {
            return count($this->scan($visibleIds)['pairs']);
        });
    }

    /** Cache der Trefferzahl invalidieren (nach einer Zusammenfuehrung). */
    public function forgetCount(): void
    {
        Cache::flush();
    }

    /**
     * Baut die Blocking-Bloecke ueber alle relevanten Merkmale auf.
     *
     * @param \Illuminate\Support\Collection<int, Customer> $customers
     * @return array<string, array<int, int>> Schluessel -> Kundenindizes
     */
    private function buildBuckets($customers): array
    {
        $buckets = [];
        $add = function (string $key, int $idx) use (&$buckets) {
            $buckets[$key][] = $idx;
        };

        foreach ($customers as $idx => $customer) {
            // Name (exakt normalisiert, Wortreihenfolge egal). Bewusst KEIN
            // unscharfer Namensblock als eigenstaendiger Schluessel - der
            // wuerde zu viele verschiedene Personen mit aehnlichem Namen
            // zusammenwerfen. Tippfehler-Aehnlichkeit wird nur ergaenzend
            // gewertet, wenn ein Paar bereits ueber ein anderes Merkmal
            // (Telefon/E-Mail/Anschrift/IBAN/Vertrag) gebildet wurde.
            $nameKey = $this->nameKey($customer->user?->name);
            if ($nameKey !== '') {
                $add('n:' . $nameKey, $idx);
            }

            // Telefon + Mobil.
            foreach ([$customer->phone, $customer->mobile] as $phone) {
                $p = $this->phoneKey($phone);
                if ($p !== '') {
                    $add('p:' . $p, $idx);
                }
            }

            // E-Mail (Login-Adresse + Zweit-Mail), Platzhalter ausgenommen.
            foreach ([$customer->user?->email, $customer->email2] as $email) {
                $e = $this->emailKey($email);
                if ($e !== '') {
                    $add('e:' . $e, $idx);
                }
            }

            // Anschrift (normalisierter Haushalts-Schluessel).
            $addr = $customer->householdKey();
            if ($addr !== '') {
                $add('a:' . $addr, $idx);
            }

            // Bankverbindung: dieselbe IBAN ist ein sehr starkes Identitaets-
            // signal (verschluesselt gespeichert, hier im Klartext verglichen).
            foreach ([$customer->iban, $customer->iban2] as $iban) {
                $k = $this->ibanKey($iban);
                if ($k !== '') {
                    $add('i:' . $k, $idx);
                }
            }

            // Vertragsnummern (starkes Signal: dieselbe Police zweimal erfasst).
            foreach ($customer->contracts as $contract) {
                $c = $this->contractKey($contract->contract_number);
                if ($c !== '') {
                    $add('c:' . $c, $idx);
                }
            }
        }

        return $buckets;
    }

    /**
     * Fuegt ein Kandidatenpaar hinzu (dedupliziert, mit ermittelten Signalen).
     *
     * @param \Illuminate\Support\Collection<int, Customer> $customers
     * @param array<string, bool> $pairIndex
     * @param array<int, array<string, mixed>> $pairs
     */
    private function addPair($customers, int $i, int $j, array &$pairIndex, array &$pairs, bool &$capped): void
    {
        if ($i === $j) {
            return;
        }
        $key = $i < $j ? "$i|$j" : "$j|$i";
        if (isset($pairIndex[$key])) {
            return;
        }
        if (count($pairs) >= self::MAX_PAIRS) {
            $capped = true;
            return;
        }

        $a = $customers[$i];
        $b = $customers[$j];

        $signals = $this->signalsFor($a, $b);
        if ($signals === []) {
            return; // unscharfer Namensblock ohne echte Aehnlichkeit -> verwerfen
        }
        $pairIndex[$key] = true;

        // Aelterer Datensatz bleibt Hauptkunde.
        [$primary, $duplicate] = $a->created_at <= $b->created_at ? [$a, $b] : [$b, $a];

        $score = $this->confidence($a, $b, $signals);
        $pairs[] = [
            'primary'   => $primary,
            'duplicate' => $duplicate,
            'score'     => $score,
            'tier'      => $score >= 80 ? 'auto' : ($score >= 50 ? 'confirm' : 'manual'),
            'signals'   => array_values($signals),
        ];
    }

    /**
     * Liste der tatsaechlich uebereinstimmenden Merkmale eines Paares.
     * Leeres Array = kein belastbares Signal (Paar verwerfen).
     *
     * @return array<int, string>
     */
    private function signalsFor(Customer $a, Customer $b): array
    {
        $signals = [];

        // Name: exakt normalisiert oder hohe Aehnlichkeit (Tippfehler).
        $nameA = $this->nameKey($a->user?->name);
        $nameB = $this->nameKey($b->user?->name);
        if ($nameA !== '' && $nameB !== '') {
            if ($nameA === $nameB) {
                $signals[] = 'Gleicher Name';
            } elseif ($this->similarity($a->user?->name, $b->user?->name) >= self::NAME_FUZZY_MIN) {
                $signals[] = 'Sehr aehnlicher Name';
            }
        }

        if ($this->sharesKey([$a->phone, $a->mobile], [$b->phone, $b->mobile], fn ($v) => $this->phoneKey($v))) {
            $signals[] = 'Gleiche Telefonnummer';
        }
        if ($this->sharesKey([$a->user?->email, $a->email2], [$b->user?->email, $b->email2], fn ($v) => $this->emailKey($v))) {
            $signals[] = 'Gleiche E-Mail-Adresse';
        }
        if ($a->householdKey() !== '' && $a->householdKey() === $b->householdKey()) {
            $signals[] = 'Gleiche Anschrift';
        }
        if ($this->sharesKey([$a->iban, $a->iban2], [$b->iban, $b->iban2], fn ($v) => $this->ibanKey($v))) {
            $signals[] = 'Gleiche Bankverbindung (IBAN)';
        }
        if ($this->sharedContract($a, $b)) {
            $signals[] = 'Gleiche Vertragsnummer';
        }
        if (!empty($a->birth_date) && (string) $a->birth_date === (string) $b->birth_date) {
            $signals[] = 'Gleiches Geburtsdatum';
        }

        return array_values(array_unique($signals));
    }

    /**
     * Konfidenz (0..100) rein zur Sortierung. Nutzt den gewichteten Score aus
     * dem Import-Abgleich und hebt Paare mit derselben Vertragsnummer stark an.
     */
    private function confidence(Customer $a, Customer $b, array $signals): int
    {
        $base = $this->matcher->scorePair($a, $b)->score;
        // Vertragsnummer/IBAN sind starke Identitaetssignale, die der
        // gewichtete Import-Score nicht kennt - hier gezielt anheben.
        if (array_intersect(['Gleiche Vertragsnummer', 'Gleiche Bankverbindung (IBAN)'], $signals) !== []) {
            $base = max($base, 55) + 30;
        }
        return (int) min(100, $base);
    }

    /** @param array<int, ?string> $left @param array<int, ?string> $right */
    private function sharesKey(array $left, array $right, callable $norm): bool
    {
        $l = array_filter(array_map($norm, $left), fn ($v) => $v !== '');
        $r = array_filter(array_map($norm, $right), fn ($v) => $v !== '');
        return array_intersect($l, $r) !== [];
    }

    private function sharedContract(Customer $a, Customer $b): bool
    {
        $ca = array_filter($a->contracts->map(fn ($c) => $this->contractKey($c->contract_number))->all(), fn ($v) => $v !== '');
        $cb = array_filter($b->contracts->map(fn ($c) => $this->contractKey($c->contract_number))->all(), fn ($v) => $v !== '');
        return array_intersect($ca, $cb) !== [];
    }

    /** Titel weg, Umlaute transliteriert, Tokens sortiert (Reihenfolge egal). */
    private function nameKey(?string $name): string
    {
        $name = $this->transliterate((string) $name);
        $name = preg_replace('/\b(dr|prof|dipl|ing|herr|frau)\b\.?/', ' ', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name) ?? $name;
        $tokens = array_values(array_filter(explode(' ', $name), fn ($t) => $t !== ''));
        sort($tokens);
        return implode(' ', $tokens);
    }

    private function phoneKey(?string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '0049')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '49') && strlen($digits) >= 11) {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');
        return strlen($digits) >= 5 ? $digits : '';
    }

    private function emailKey(?string $email): string
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || str_ends_with($email, '@dienstly24.internal')) {
            return '';
        }
        return $email;
    }

    private function contractKey(?string $number): string
    {
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $number) ?? '');
        return strlen($key) >= 4 ? $key : '';
    }

    private function ibanKey(?string $iban): string
    {
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $iban) ?? '');
        return strlen($key) >= 15 ? $key : '';
    }

    private function similarity(?string $a, ?string $b): float
    {
        $a = $this->transliterate((string) $a);
        $b = $this->transliterate((string) $b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $percent);
        return $percent / 100;
    }

    private function transliterate(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
