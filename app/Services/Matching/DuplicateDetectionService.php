<?php

namespace App\Services\Matching;

use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\CustomerRelationship;
use App\Models\GeteilterKontaktwert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /**
     * Klassifikation eines Paares (Betreiber-Auftrag 03.10.2026, KI-063).
     * Der Score bleibt eine SORTIER-Zahl; was mit einem Paar geschehen darf,
     * entscheidet ausschliesslich die Klasse - und zwar an EINER Stelle
     * (classify()), die Anzeige, Sammel-Merge, "Alle sicheren" und der
     * Einzel-Merge gleichermassen lesen.
     *
     * SICHER:  gleicher Name UND gleiches Geburtsdatum, kein Widerspruch.
     * MOEGLICH: kein Widerspruch, aber nichts Eindeutiges (z. B. nur Name).
     * FAMILIE: ein Identitaetsmerkmal WIDERSPRICHT (Geburtsdatum, Vorname,
     *          kein gemeinsamer Namensbestandteil) - verschiedene Personen,
     *          die sich Kontaktdaten teilen. Nie zusammenfuehren.
     */
    public const KLASSE_SICHER = 'dublette_sicher';

    public const KLASSE_MOEGLICH = 'dublette_moeglich';

    public const KLASSE_FAMILIE = 'moegliche_familie';

    /** Reihenfolge der Anzeige: zuerst was zu tun ist, Familie zuletzt. */
    private const KLASSE_RANG = [self::KLASSE_SICHER => 0, self::KLASSE_MOEGLICH => 1, self::KLASSE_FAMILIE => 2];

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
     * @return array{pairs: array<int, array{primary: Customer, duplicate: Customer, score: int, tier: string, klasse: string, konflikte: list<string>, signals: array<int, string>}>, scanned: int, capped: bool}
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

        // Gemeinsam genutzte Kontaktwerte (Familien-E-Mail, Festnetz, Konto
        // der Eltern) bilden kein Verdachtspaar (PR-4).
        $geteilt = GeteilterKontaktwert::hashMenge();

        // 1) Blocking: normalisierte Schluessel -> Liste der Kundenindizes.
        $buckets = $this->buildBuckets($customers, $geteilt);

        // Bloecke nach Signalstaerke ordnen, damit bei erreichtem Paar-Limit
        // die starken Signale (Vertrag/IBAN/E-Mail/Telefon/Name) zuerst
        // eingesammelt werden und schwache (nur Anschrift) zuletzt.
        $priority = ['c' => 0, 'i' => 1, 'e' => 2, 'p' => 3, 'n' => 4, 'a' => 5];
        uksort($buckets, function ($x, $y) use ($priority) {
            $px = $priority[explode(':', $x, 2)[0]] ?? 9;
            $py = $priority[explode(':', $y, 2)[0]] ?? 9;
            return $px <=> $py;
        });

        // Als "Kein Duplikat" markierte Paare (Verwandte Kunden) ausschliessen.
        // Ebenso Paare mit einer Familienrolle (customer_family_relations):
        // eine festgelegte Beziehung erscheint nie wieder als Verdachtsfall,
        // auch wenn die Gleichlauf-Zeile in customer_relationships fehlt.
        $dismissed = CustomerRelationship::dismissedKeySet() + $this->familyPairKeySet();

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
                        $this->addPair($customers, $members[$x], $members[$y], $pairIndex, $pairs, $bucketCapped, $dismissed, $geteilt);
                    }
                }
            } else {
                $anchor = end($members); // aeltester im Block
                foreach ($members as $m) {
                    if ($m === $anchor) {
                        continue;
                    }
                    $this->addPair($customers, $anchor, $m, $pairIndex, $pairs, $bucketCapped, $dismissed, $geteilt);
                }
            }
        }

        usort($pairs, fn ($a, $b) => [self::KLASSE_RANG[$a['klasse']], $b['score']] <=> [self::KLASSE_RANG[$b['klasse']], $a['score']]);

        return [
            'pairs' => $pairs,
            'scanned' => $customers->count(),
            'capped' => $capped || $bucketCapped,
        ];
    }

    /** Kurz gecachte Trefferzahl fuer den Hinweis-Badge in der Kundenliste. */
    public function countCached(?array $visibleIds = null, int $ttlSeconds = 300): int
    {
        $scope = $visibleIds === null ? 'all' : md5(implode(',', $visibleIds));
        $version = (int) Cache::get('duplicate_candidates_count_version', 0);

        return (int) Cache::remember("duplicate_candidates_count_v{$version}_{$scope}", $ttlSeconds, function () use ($visibleIds) {
            return count($this->scan($visibleIds)['pairs']);
        });
    }

    /**
     * Cache der Trefferzahl invalidieren (nach einer Zusammenfuehrung).
     * Statt Cache::flush() (loeschte den GESAMTEN App-Cache - Audit PERF-7)
     * wird eine Versionsnummer erhoeht: alle scoped Count-Keys werden dadurch
     * ungueltig, ohne fremde Cache-Eintraege zu beruehren.
     */
    public function forgetCount(): void
    {
        $current = (int) Cache::get('duplicate_candidates_count_version', 0);
        Cache::forever('duplicate_candidates_count_version', $current + 1);
    }

    /** Oeffentlicher Zugriff auf die uebereinstimmenden Merkmale eines Paares. */
    public function pairSignals(Customer $a, Customer $b): array
    {
        return $this->signalsFor($a, $b);
    }

    /**
     * "Verwandte Kunden" fuer EINEN Kunden: andere Kunden, die mindestens ein
     * Merkmal teilen (Name, Telefon, E-Mail, Anschrift, IBAN, Vertragsnummer).
     * Grundlage fuer die Beziehungs-Ansicht (Kundenakte + Uebersichtsseite) -
     * rein informativ, KEINE Zusammenfuehrung.
     *
     * @param ?array<int, string> $visibleIds Portfolio-Sicht; null = alle.
     * @return array<int, array{customer: Customer, signals: array<int, string>, score: int, dismissed: bool, relationship_type: ?string}>
     */
    public function relationsFor(Customer $customer, ?array $visibleIds = null, int $limit = 40): array
    {
        $customer->loadMissing(['user', 'addresses', 'contracts:id,customer_id,contract_number']);

        $name = (string) ($customer->user?->name ?? '');
        $lastName = $name !== '' ? Str::afterLast($name, ' ') : '';

        $query = Customer::query()
            ->with(['user', 'addresses', 'contracts:id,customer_id,contract_number'])
            ->where('id', '!=', $customer->id);
        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }

        // Grobe Vorfilterung per SQL; die exakte (normalisierte) Signalpruefung
        // laeuft danach in PHP. Adresse ueber PLZ als bezahlbaren Vorfilter.
        $query->where(function ($q) use ($customer, $lastName) {
            $matched = false;
            if ($lastName !== '') {
                $q->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.$lastName.'%'));
                $matched = true;
            }
            if ($customer->user?->email) {
                $q->orWhereHas('user', fn ($u) => $u->where('email', $customer->user->email));
                $q->orWhere('email2', $customer->user->email);
                $matched = true;
            }
            if ($customer->email2) {
                $q->orWhere('email2', $customer->email2);
                $matched = true;
            }
            foreach (array_filter([$customer->phone, $customer->mobile]) as $p) {
                $q->orWhere('phone', $p)->orWhere('mobile', $p);
                $matched = true;
            }
            if ($customer->address_zip) {
                $q->orWhere('address_zip', $customer->address_zip);
                $matched = true;
            }
            if (! $matched) {
                $q->whereRaw('1 = 0');
            }
        });

        $candidates = $query->limit(200)->get();
        // Bereits markierte Beziehungen inkl. Art (Ehepaar/Familie/...), damit
        // die Kundenakte eine verwandte Person aussagekraeftig kennzeichnen kann.
        // Ein Paar kann mehrere Arten tragen (z. B. Geschwister + Haushalt):
        // angezeigt wird die aussagekraeftigste - eine Familienart vor allen
        // anderen, "Kein Duplikat" zuletzt.
        $relTypes = [];
        foreach (CustomerRelationship::query()->get(['customer_a_id', 'customer_b_id', 'type']) as $r) {
            $key = $r->customer_a_id.'|'.$r->customer_b_id;
            $bisher = $relTypes[$key] ?? null;
            if ($bisher === null || $bisher === 'not_duplicate'
                || (CustomerRelationship::isFamilyType($r->type) && ! CustomerRelationship::isFamilyType($bisher))) {
                $relTypes[$key] = $r->type;
            }
        }

        $out = [];
        foreach ($candidates as $cand) {
            $signals = $this->signalsFor($customer, $cand);
            if ($signals === []) {
                continue;
            }
            [$ka, $kb] = CustomerRelationship::pairKey((string) $customer->id, (string) $cand->id);
            $relKey = $ka.'|'.$kb;
            $out[] = [
                'customer' => $cand,
                'signals' => $signals,
                'score' => $this->confidence($customer, $cand, $signals),
                'dismissed' => array_key_exists($relKey, $relTypes),
                'relationship_type' => $relTypes[$relKey] ?? null,
            ];
        }

        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    /**
     * Baut die Blocking-Bloecke ueber alle relevanten Merkmale auf.
     *
     * @param Collection<int, Customer> $customers
     * @param array<string, array<string, bool>> $geteilt Gemeinsam genutzte Werte (art => hash)
     * @return array<string, array<int, int>> Schluessel -> Kundenindizes
     */
    private function buildBuckets($customers, array $geteilt = []): array
    {
        $buckets = [];
        $add = function (string $key, int $idx) use (&$buckets) {
            $buckets[$key][] = $idx;
        };
        $frei = fn (string $art, string $wert): bool => ! $this->istGeteilt($geteilt, $art, $wert);

        foreach ($customers as $idx => $customer) {
            // Name (exakt normalisiert, Wortreihenfolge egal). Bewusst KEIN
            // unscharfer Namensblock als eigenstaendiger Schluessel - der
            // wuerde zu viele verschiedene Personen mit aehnlichem Namen
            // zusammenwerfen. Tippfehler-Aehnlichkeit wird nur ergaenzend
            // gewertet, wenn ein Paar bereits ueber ein anderes Merkmal
            // (Telefon/E-Mail/Anschrift/IBAN/Vertrag) gebildet wurde.
            $nameKey = $this->nameKey($customer->user?->name);
            if ($nameKey !== '') {
                $add('n:'.$nameKey, $idx);
            }

            // Telefon + Mobil.
            foreach ([$customer->phone, $customer->mobile] as $phone) {
                $p = $this->phoneKey($phone);
                if ($p !== '' && $frei('telefon', $p)) {
                    $add('p:'.$p, $idx);
                }
            }

            // E-Mail (Login-Adresse + Zweit-Mail), Platzhalter ausgenommen.
            foreach ([$customer->user?->email, $customer->email2] as $email) {
                $e = $this->emailKey($email);
                if ($e !== '' && $frei('email', $e)) {
                    $add('e:'.$e, $idx);
                }
            }

            // Anschrift (normalisierter Haushalts-Schluessel).
            $addr = $customer->householdKey();
            if ($addr !== '' && $frei('anschrift', $addr)) {
                $add('a:'.$addr, $idx);
            }

            // Bankverbindung: dieselbe IBAN ist ein sehr starkes Identitaets-
            // signal (verschluesselt gespeichert, hier im Klartext verglichen).
            foreach ([$customer->iban, $customer->iban2] as $iban) {
                $k = $this->ibanKey($iban);
                if ($k !== '' && $frei('iban', $k)) {
                    $add('i:'.$k, $idx);
                }
            }

            // Vertragsnummern (starkes Signal: dieselbe Police zweimal erfasst).
            foreach ($customer->contracts as $contract) {
                $c = $this->contractKey($contract->contract_number);
                if ($c !== '') {
                    $add('c:'.$c, $idx);
                }
            }
        }

        return $buckets;
    }

    /**
     * Fuegt ein Kandidatenpaar hinzu (dedupliziert, mit ermittelten Signalen).
     *
     * @param Collection<int, Customer> $customers
     * @param array<string, bool> $pairIndex
     * @param array<int, array<string, mixed>> $pairs
     * @param array<string, bool> $dismissed  Als "kein Duplikat" markierte Paare (a|b).
     * @param array<string, array<string, bool>> $geteilt Gemeinsam genutzte Werte (art => hash)
     */
    private function addPair($customers, int $i, int $j, array &$pairIndex, array &$pairs, bool &$capped, array $dismissed = [], array $geteilt = []): void
    {
        if ($i === $j) {
            return;
        }
        $key = $i < $j ? "$i|$j" : "$j|$i";
        if (isset($pairIndex[$key])) {
            return;
        }

        $a = $customers[$i];
        $b = $customers[$j];

        // Bereits als "Verwandte Kunden" (kein Duplikat) markiert -> ueberspringen.
        [$ka, $kb] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
        if (isset($dismissed[$ka.'|'.$kb])) {
            $pairIndex[$key] = true;
            return;
        }

        if (count($pairs) >= self::MAX_PAIRS) {
            $capped = true;
            return;
        }

        $signals = $this->signalsFor($a, $b, $geteilt);
        if ($signals === []) {
            return; // unscharfer Namensblock ohne echte Aehnlichkeit -> verwerfen
        }
        $pairIndex[$key] = true;

        // Aelterer Datensatz bleibt Hauptkunde.
        [$primary, $duplicate] = $a->created_at <= $b->created_at ? [$a, $b] : [$b, $a];

        $score = $this->confidence($a, $b, $signals);
        $einstufung = $this->classify($a, $b);
        $pairs[] = [
            'primary' => $primary,
            'duplicate' => $duplicate,
            'score' => $score,
            'tier' => $score >= 80 ? 'auto' : ($score >= 50 ? 'confirm' : 'manual'),
            'klasse' => $einstufung['klasse'],
            'konflikte' => $einstufung['konflikte'],
            'signals' => array_values($signals),
        ];
    }

    /**
     * Liste der tatsaechlich uebereinstimmenden Merkmale eines Paares.
     * Leeres Array = kein belastbares Signal (Paar verwerfen).
     *
     * Ein als gemeinsam genutzt markierter Wert zaehlt NICHT (PR-4): die
     * Familien-E-Mail allein haelt Vater und Sohn nicht mehr in der Liste.
     *
     * @param array<string, array<string, bool>> $geteilt Gemeinsam genutzte Werte (art => hash)
     * @return array<int, string>
     */
    private function signalsFor(Customer $a, Customer $b, array $geteilt = []): array
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

        if ($this->gemeinsameSchluessel('telefon', $a, $b, $geteilt) !== []) {
            $signals[] = 'Gleiche Telefonnummer';
        }
        if ($this->gemeinsameSchluessel('email', $a, $b, $geteilt) !== []) {
            $signals[] = 'Gleiche E-Mail-Adresse';
        }
        if ($this->gemeinsameSchluessel('anschrift', $a, $b, $geteilt) !== []) {
            $signals[] = 'Gleiche Anschrift';
        }
        if ($this->gemeinsameSchluessel('iban', $a, $b, $geteilt) !== []) {
            $signals[] = 'Gleiche Bankverbindung (IBAN)';
        }
        if ($this->sharedContract($a, $b)) {
            $signals[] = 'Gleiche Vertragsnummer';
        }
        if (! empty($a->birth_date) && (string) $a->birth_date === (string) $b->birth_date) {
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

    /**
     * Klasse eines Paares - die EINE Stelle, die entscheidet, ob zwei Akten
     * zusammengefuehrt werden duerfen.
     *
     * Gemeinsame E-Mail, Telefonnummer, Anschrift oder IBAN allein machen
     * NIE eine sichere Dublette: genau diese Merkmale teilen Familien
     * (Vater und Sohn mit derselben Familien-Adresse, Kind ueber das Konto
     * der Eltern). Frueher hob ein solches Merkmal den Score ueber die
     * Ein-Klick-Grenze, und die Seite zeigte Vater und Sohn als "sicher".
     *
     * @return array{klasse: string, konflikte: list<string>}
     */
    public function classify(Customer $a, Customer $b): array
    {
        $konflikte = $this->identityConflicts($a, $b);
        if ($konflikte !== []) {
            return ['klasse' => self::KLASSE_FAMILIE, 'konflikte' => $konflikte];
        }

        $nameA = $this->nameKey($a->user?->name);
        $gleicherName = $nameA !== '' && $nameA === $this->nameKey($b->user?->name);
        $gleichesGeburtsdatum = ! empty($a->birth_date) && ! empty($b->birth_date)
            && $this->datum($a->birth_date) === $this->datum($b->birth_date);

        return [
            'klasse' => $gleicherName && $gleichesGeburtsdatum ? self::KLASSE_SICHER : self::KLASSE_MOEGLICH,
            'konflikte' => [],
        ];
    }

    /**
     * Widersprechende Identitaetsmerkmale eines Paares, als lesbare Gruende.
     * Ein einziger Eintrag genuegt: die beiden Akten sind verschiedene
     * Personen und werden nie unbeaufsichtigt zusammengefuehrt.
     *
     * @return list<string>
     */
    public function identityConflicts(Customer $a, Customer $b): array
    {
        $gruende = [];

        // Verschiedene, jeweils gesetzte Geburtsdaten -> verschiedene Personen.
        if (! empty($a->birth_date) && ! empty($b->birth_date)
            && $this->datum($a->birth_date) !== $this->datum($b->birth_date)) {
            $gruende[] = 'Abweichendes Geburtsdatum ('.$this->datumAnzeige($a->birth_date)
                .' / '.$this->datumAnzeige($b->birth_date).')';
        }

        $ta = $this->nameTokens($a->user?->name);
        $tb = $this->nameTokens($b->user?->name);
        if ($ta !== [] && $tb !== []) {
            $gemeinsam = array_intersect($ta, $tb);
            if ($gemeinsam === []) {
                $gruende[] = 'Kein gemeinsamer Namensbestandteil';
            } else {
                // Gleicher Nachname, aber ein ANDERER Vorname: auf beiden
                // Seiten bleibt ein Namensteil uebrig, der auf der anderen
                // fehlt ("Maher Abboud" / "Ahmad Jihad Abboud"). Ein
                // zusaetzlicher zweiter Vorname auf nur EINER Seite ist kein
                // Widerspruch ("Ahmad Abboud" / "Ahmad Jihad Abboud"), ein
                // Tippfehler ebenfalls nicht ("Mohamad" / "Mohammad").
                [$restA, $restB] = $this->ohneTippfehler(
                    array_values(array_diff($ta, $gemeinsam)),
                    array_values(array_diff($tb, $gemeinsam))
                );
                if ($restA !== [] && $restB !== []) {
                    $gruende[] = 'Abweichender Vorname ('.implode(' ', array_map('ucfirst', $restA))
                        .' / '.implode(' ', array_map('ucfirst', $restB)).')';
                }
            }
        }

        return $gruende;
    }

    /**
     * Duerfen zwei Kunden zusammengefuehrt werden, ohne dass ein Admin den
     * Widerspruch ausdruecklich uebersteuert? Nein, sobald ein
     * Identitaetsmerkmal WIDERSPRICHT (Audit MERGE-1, KI-063).
     */
    public function hasIdentityConflict(Customer $a, Customer $b): bool
    {
        return $this->identityConflicts($a, $b) !== [];
    }

    /**
     * Entfernt Namensteile, die auf der Gegenseite als Tippfehler-Variante
     * vorkommen (Levenshtein <= 1 bei kurzen, <= 2 bei langen Woertern).
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return array{0: list<string>, 1: list<string>}
     */
    private function ohneTippfehler(array $a, array $b): array
    {
        foreach ($a as $i => $x) {
            foreach ($b as $j => $y) {
                $grenze = min(strlen($x), strlen($y)) >= 6 ? 2 : 1;
                if (min(strlen($x), strlen($y)) >= 4 && levenshtein($x, $y) <= $grenze) {
                    unset($a[$i], $b[$j]);
                    break;
                }
            }
        }

        return [array_values($a), array_values($b)];
    }

    /** @return list<string> */
    private function nameTokens(?string $name): array
    {
        return array_values(array_unique(array_filter(explode(' ', $this->nameKey($name)), fn ($t) => $t !== '')));
    }

    /** Geburtsdatum normiert (Y-m-d) - die Spalte kommt als Datum ODER Zeichenkette. */
    private function datum(mixed $wert): string
    {
        try {
            return Carbon::parse($wert)->format('Y-m-d');
        } catch (\Throwable) {
            return (string) $wert;
        }
    }

    private function datumAnzeige(mixed $wert): string
    {
        try {
            return Carbon::parse($wert)->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $wert;
        }
    }

    /**
     * Paare mit festgelegter Familienrolle als Schluessel "a|b" (sortiert
     * wie CustomerRelationship::pairKey).
     *
     * @return array<string, bool>
     */
    private function familyPairKeySet(): array
    {
        $set = [];
        foreach (CustomerFamilyRelation::query()->get(['customer_id', 'related_customer_id']) as $r) {
            [$ka, $kb] = CustomerRelationship::pairKey((string) $r->customer_id, (string) $r->related_customer_id);
            $set[$ka.'|'.$kb] = true;
        }

        return $set;
    }

    /** Signal-Text -> Art des gemeinsam nutzbaren Werts (PR-4). */
    public const SIGNAL_ART = [
        'Gleiche E-Mail-Adresse' => 'email',
        'Gleiche Telefonnummer' => 'telefon',
        'Gleiche Bankverbindung (IBAN)' => 'iban',
        'Gleiche Anschrift' => 'anschrift',
    ];

    /**
     * Normalisierte Werte einer Art, die BEIDE Kunden fuehren - ohne die als
     * gemeinsam genutzt markierten.
     *
     * @param array<string, array<string, bool>> $geteilt
     * @return list<string>
     */
    private function gemeinsameSchluessel(string $art, Customer $a, Customer $b, array $geteilt = []): array
    {
        $gemeinsam = array_values(array_unique(array_intersect($this->schluesselVon($art, $a), $this->schluesselVon($art, $b))));

        return array_values(array_filter($gemeinsam, fn ($k) => ! $this->istGeteilt($geteilt, $art, $k)));
    }

    /** @return list<string> */
    private function schluesselVon(string $art, Customer $c): array
    {
        $werte = match ($art) {
            'telefon' => array_map(fn ($v) => $this->phoneKey($v), [$c->phone, $c->mobile]),
            'email' => array_map(fn ($v) => $this->emailKey($v), [$c->user?->email, $c->email2]),
            'iban' => array_map(fn ($v) => $this->ibanKey($v), [$c->iban, $c->iban2]),
            'anschrift' => [$c->householdKey()],
            default => [],
        };

        return array_values(array_filter($werte, fn ($v) => $v !== ''));
    }

    /** @param array<string, array<string, bool>> $geteilt */
    private function istGeteilt(array $geteilt, string $art, string $schluessel): bool
    {
        return isset($geteilt[$art]) && isset($geteilt[$art][GeteilterKontaktwert::hashFuer($art, $schluessel)]);
    }

    /**
     * Die Werte einer Art, die zwei Kunden gemeinsam fuehren, als
     * Markier-Kandidaten: HMAC + maskierte Anzeige. Der Wert kommt aus den
     * Akten, NIE aus dem Formular - wer markiert, nennt nur Paar und Art.
     *
     * @return list<array{hash: string, anzeige: string}>
     */
    public function gemeinsameWerte(Customer $a, Customer $b, string $art): array
    {
        $out = [];
        foreach ($this->gemeinsameSchluessel($art, $a, $b) as $k) {
            $out[] = ['hash' => GeteilterKontaktwert::hashFuer($art, $k), 'anzeige' => $this->maskiert($art, $k, $a)];
        }

        return $out;
    }

    /** Maskierte Anzeige - die Tabelle ueberlebt die Akten, der Wert nicht. */
    private function maskiert(string $art, string $schluessel, Customer $c): string
    {
        return match ($art) {
            'email' => mb_substr($schluessel, 0, 1).'***@'.Str::after($schluessel, '@'),
            'telefon', 'iban' => '…'.substr($schluessel, -4),
            'anschrift' => trim(($c->address_zip ?? '').' '.($c->address_city ?? '')) ?: 'Anschrift',
            default => '…',
        };
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
