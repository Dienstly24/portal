<?php
namespace App\Services\DocumentIntake;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\Document;
use App\Services\Matching\CustomerMatchingService;
use Illuminate\Support\Facades\Storage;

/**
 * Gemeinsame Logik des Smart Document Upload nach der KI-Analyse:
 * Kunden-Matching, Zuordnung eines Eingangs-Dokuments zu einem Kunden,
 * Uebernahme extrahierter Daten in die Kundenakte und Vertragsanlage/-
 * verknuepfung. Wird vom Analyse-Job (automatische Stufe) und von der
 * Mitarbeiter-Review-UI (Freigabe-Stufe) gleichermassen genutzt.
 *
 * Grundregeln:
 * - Extrahierte Daten fuellen nur LEERE Kundenfelder, nie bestehende.
 * - Automatisch zugeordnet wird nur bei eindeutigem Match (tier 'auto',
 *   Score > 90) - analog zur HITL-Logik des E-Mail-Postfachs.
 */
class DocumentIntakeService
{
    public function __construct(private readonly CustomerMatchingService $matcher)
    {
    }

    /** Match-Kriterien aus dem validierten Analyse-Ergebnis ableiten. */
    public function matchCriteria(array $extracted): array
    {
        $person = $extracted['person'] ?? [];
        return array_filter([
            'first_name' => $person['first_name'] ?? null,
            'last_name' => $person['last_name'] ?? null,
            'full_name' => trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')) ?: null,
            // Firmenname (Gewerbekunde) - wird bei der Neuanlage uebernommen.
            'company_name' => $person['company_name'] ?? null,
            'birth_date' => $person['birth_date'] ?? null,
            'email' => $person['email'] ?? null,
            'phone' => $person['phone'] ?? null,
            'street' => $person['street'] ?? null,
            'house_number' => $person['house_number'] ?? null,
            'zip' => $person['zip'] ?? null,
            'city' => $person['city'] ?? null,
        ]);
    }

    /** Ausweis-Dokumente liefern die verlaesslichsten Personendaten. */
    private const IDENTITY_TYPES = ['personalausweis', 'reisepass'];
    private const LICENSE_TYPES = ['fuehrerschein'];

    /**
     * Analyse-Ergebnisse MEHRERER Dokumente eines Kunden zu einem Ergebnis
     * verschmelzen (Betreiber-Vorgabe: Hoheit je Feld nach Dokumenttyp):
     * - Person (Name/Geburtsdatum/Adresse): Ausweis-Dokumente zuerst.
     * - Bank/IBAN, Fahrzeug/Tarif, Gesundheit: jeweils erster nicht-leerer Wert.
     * Bewusst NICHT aus einem Beratungsprotokoll uebernommen: Fuehrerschein-
     * datum, weitere Fahrer (dort oft ungenau).
     *
     * Stimmen Name auf Ausweis und Fuehrerschein nicht ueberein, wird ein
     * Konflikt gemeldet (_conflicts) -> keine automatische Anlage.
     *
     * @param iterable<Document> $documents
     * @return array<string,mixed>
     */
    public function mergeExtractions(iterable $documents): array
    {
        $docs = collect($documents);
        $merged = ['person' => [], 'versicherung' => [], 'kfz' => [], 'gesundheit' => [], 'bank' => [], 'energie' => [], 'internet' => []];

        // Fuer Personendaten Ausweis-Dokumente zuerst; sonst Reihenfolge egal.
        $personFirst = $docs->sortByDesc(fn ($d) => $this->personPriority($d->ai_type));

        foreach (array_keys($merged) as $group) {
            $source = $group === 'person' ? $personFirst : $docs;
            foreach ($source as $doc) {
                $values = ($doc->ai_extracted[$group] ?? []);
                if (!is_array($values)) {
                    continue;
                }
                foreach ($values as $field => $value) {
                    if ($value === null || $value === '' || $value === []) {
                        continue;
                    }
                    $current = $merged[$group][$field] ?? null;
                    if ($current === null || $current === '') {
                        $merged[$group][$field] = $value;
                    }
                }
            }
        }

        $merged['_conflicts'] = $this->nameConflicts($docs);

        return $merged;
    }

    private function personPriority(?string $aiType): int
    {
        if (in_array($aiType, self::IDENTITY_TYPES, true)) {
            return 3;
        }
        if (in_array($aiType, self::LICENSE_TYPES, true)) {
            return 2;
        }
        return 1;
    }

    /**
     * Namens-Abgleich Ausweis vs. Fuehrerschein. Weichen die Namen ab, ist
     * die Zuordnung unsicher -> Mitarbeiter muss manuell pruefen.
     *
     * @return array<string,string>
     */
    private function nameConflicts(\Illuminate\Support\Collection $docs): array
    {
        $idName = null;
        $licenseName = null;
        foreach ($docs as $doc) {
            $person = $doc->ai_extracted['person'] ?? [];
            $name = $this->normalizeName(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (in_array($doc->ai_type, self::IDENTITY_TYPES, true)) {
                $idName ??= $name;
            } elseif (in_array($doc->ai_type, self::LICENSE_TYPES, true)) {
                $licenseName ??= $name;
            }
        }

        if ($idName !== null && $licenseName !== null && $idName !== $licenseName) {
            return ['name' => 'Name auf Ausweis und Fuehrerschein stimmen nicht ueberein - bitte manuell pruefen.'];
        }
        return [];
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^a-zäöüß ]/u', '', $name) ?? $name;
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Kunden zum Analyse-Ergebnis suchen.
     *
     * @return array{customer_id: string, name: ?string, customer_number: ?string, score: int, tier: string}|null
     */
    public function findMatch(array $extracted): ?array
    {
        $criteria = $this->matchCriteria($extracted);
        if ($criteria === []) {
            return null;
        }

        $result = $this->matcher->match($criteria);
        if (!$result->hasMatch() || $result->score < 40) {
            return null; // zu schwach, gar nicht erst anzeigen
        }

        return [
            'customer_id' => (string) $result->customer->id,
            'name' => $result->customer->user?->name,
            'customer_number' => $result->customer->customer_number,
            'score' => $result->score,
            'tier' => $result->tier(),
        ];
    }

    /**
     * Kunde ueber die ZAEHLERNUMMER eines Zaehlerfotos finden. Auf einem
     * Zaehler steht kein Name - die Nummer ist die einzige Bruecke zum
     * bereits erfassten Energievertrag und damit zum Kunden. Sie ist ein
     * hartes Identitaetsmerkmal (wie eine Vertragsnummer), daher Tier 'auto';
     * treffen mehrere Kunden zu, liefert der Service bewusst nichts.
     */
    public function findMeterMatch(array $extracted): ?array
    {
        $number = ($extracted['energie'] ?? [])['meter_number'] ?? null;
        if (blank($number)) {
            return null;
        }

        $located = app(\App\Services\Energy\MeterReadingService::class)->locate($number);
        if ($located === null) {
            return null;
        }

        $customer = $located['customer'];
        return [
            'customer_id' => (string) $customer->id,
            'name' => $customer->user?->name,
            'customer_number' => $customer->customer_number,
            'score' => 95,
            'tier' => 'auto',
            // Fuer die Review-UI: der Treffer kommt vom Zaehler, nicht vom Namen.
            'via' => 'meter_number',
        ];
    }

    /**
     * Zuordnungs-Vorschlaege fuer den Dokumenten-Eingang: die naechstliegenden
     * Kunden zu einem Analyse-Ergebnis. Damit sieht der Mitarbeiter beim Klick
     * auf "Kunden zuordnen" sofort Namen, statt selbst suchen zu muessen.
     *
     * Zwei Quellen, bewusst in dieser Reihenfolge:
     * 1. HARTE Identitaetsmerkmale aus dem Dokument (Vertragsnummer, FIN,
     *    Kennzeichen, MaLo-ID, Zaehlernummer): ist das
     *    Merkmal bereits an einem Vertrag/Fahrzeug eines Kunden erfasst, ist
     *    die Zuordnung so gut wie sicher.
     * 2. WEICHE Personendaten (Name, Geburtsdatum, E-Mail, Adresse, Telefon)
     *    ueber die gewichtete Kundenerkennung - mit breiterem Kandidatenpool
     *    als beim automatischen Match, damit auch abweichende Schreibweisen
     *    auftauchen.
     *
     * Es wird nichts geraten: jeder Vorschlag nennt seinen GRUND, die Auswahl
     * bleibt eine bewusste Mitarbeiter-Aktion.
     *
     * @param  array<string,mixed>  $extracted
     * @return list<array{customer_id:string,name:?string,customer_number:?string,email:?string,score:int,tier:string,reasons:list<string>}>
     */
    public function findSuggestions(array $extracted, int $limit = 5): array
    {
        /** @var array<string,array{customer: Customer, score: int, reasons: list<string>}> $found */
        $found = [];

        foreach ($this->identityHits($extracted) as [$customer, $reason]) {
            $this->collectSuggestion($found, $customer, 100, [$reason]);
        }

        $criteria = $this->matchCriteria($extracted);
        if ($criteria !== []) {
            foreach ($this->matcher->topMatches($criteria, $limit + 3) as $result) {
                if ($result->hasMatch()) {
                    $this->collectSuggestion($found, $result->customer, $result->score, $this->suggestionReasons($result));
                }
            }
        }

        $suggestions = array_values($found);
        usort($suggestions, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($s) => [
            'customer_id' => (string) $s['customer']->id,
            'name' => $s['customer']->user?->name ?: $s['customer']->company_name,
            'customer_number' => $s['customer']->customer_number,
            'email' => $s['customer']->user?->email,
            'score' => $s['score'],
            'tier' => (new \App\Services\Matching\MatchResult($s['customer'], $s['score']))->tier(),
            'reasons' => array_values(array_unique($s['reasons'])),
        ], array_slice($suggestions, 0, $limit));
    }

    /**
     * Kunden, bei denen ein hartes Identitaetsmerkmal des Dokuments bereits
     * erfasst ist. Kennzeichen/FIN werden zusaetzlich NORMALISIERT verglichen
     * ("LUEN-G 1110" = "LUENG1110"), weil die Schreibweise je Dokument
     * abweicht.
     *
     * @param  array<string,mixed>  $extracted
     * @return list<array{0: Customer, 1: string}>
     */
    private function identityHits(array $extracted): array
    {
        $ins = $extracted['versicherung'] ?? [];
        $kfz = $extracted['kfz'] ?? [];
        $energie = $extracted['energie'] ?? [];

        $hits = [];

        // Merkmale, die als Freitext eindeutig genug sind (Kundensuche deckt
        // Vertrag, Fahrzeug, Energie-Zaehler und Kundenstamm gemeinsam ab).
        $tokens = [
            // Die ADAC-Mitgliedsnummer landet bewusst in contract_number.
            'Vertragsnummer' => $ins['contract_number'] ?? null,
            'MaLo-ID' => $energie['malo_id'] ?? null,
            'Zaehlernummer' => $energie['meter_number'] ?? null,
        ];
        foreach ($tokens as $label => $value) {
            $value = trim((string) ($value ?? ''));
            // Kurze Nummern (z.B. "12") wuerden halbe Bestaende treffen.
            if (mb_strlen($value) < 5) {
                continue;
            }
            foreach (Customer::with('user')->search($value)->limit(3)->get() as $customer) {
                $hits[] = [$customer, $label . ' ' . $value . ' ist bei diesem Kunden erfasst'];
            }
        }

        foreach ($this->vehicleIdentityHits($kfz) as $hit) {
            $hits[] = $hit;
        }

        return $hits;
    }

    /**
     * Fahrzeug-Identitaet (FIN/Kennzeichen) normalisiert gegen die erfassten
     * Fahrzeuge pruefen. Vorgefiltert wird ueber die laengste Ziffernfolge,
     * damit kein Volltabellen-Scan noetig ist.
     *
     * @param  array<string,mixed>  $kfz
     * @return list<array{0: Customer, 1: string}>
     */
    private function vehicleIdentityHits(array $kfz): array
    {
        $hits = [];
        $wanted = [
            'FIN' => ContractVehicleDetail::normalizeVin($kfz['vin'] ?? null),
            'Kennzeichen' => ContractVehicleDetail::normalizePlate($kfz['license_plate'] ?? null),
        ];

        foreach ($wanted as $label => $normalized) {
            if ($normalized === null || mb_strlen($normalized) < 4) {
                continue;
            }
            $column = $label === 'FIN' ? 'vin' : 'license_plate';
            $needle = $this->longestDigitRun($normalized);
            $details = ContractVehicleDetail::query()
                ->when($needle !== null, fn ($q) => $q->where($column, 'like', '%' . $needle . '%'))
                ->whereNotNull($column)
                ->with('contract.customer.user')
                ->limit(50)->get();

            foreach ($details as $detail) {
                $value = $label === 'FIN'
                    ? ContractVehicleDetail::normalizeVin($detail->vin)
                    : ContractVehicleDetail::normalizePlate($detail->license_plate);
                $customer = $detail->contract?->customer;
                if ($value === $normalized && $customer) {
                    $hits[] = [$customer, $label . ' ' . ($detail->{$column}) . ' ist bei diesem Kunden erfasst'];
                }
            }
        }

        return $hits;
    }

    /** Laengste zusammenhaengende Ziffernfolge als Vorfilter fuer LIKE. */
    private function longestDigitRun(string $value): ?string
    {
        preg_match_all('/\d+/', $value, $matches);
        $runs = array_filter($matches[0] ?? [], fn ($r) => strlen($r) >= 3);
        if ($runs === []) {
            return null;
        }
        usort($runs, fn ($a, $b) => strlen($b) <=> strlen($a));
        return $runs[0];
    }

    /**
     * Bestwert je Kunde behalten und die Gruende zusammenfuehren - derselbe
     * Kunde kann ueber mehrere Merkmale gefunden werden.
     *
     * @param  array<string,array{customer: Customer, score: int, reasons: list<string>}>  $found
     * @param  list<string>  $reasons
     */
    private function collectSuggestion(array &$found, Customer $customer, int $score, array $reasons): void
    {
        $key = (string) $customer->id;
        if (!isset($found[$key])) {
            $found[$key] = ['customer' => $customer, 'score' => $score, 'reasons' => $reasons];
            return;
        }
        $found[$key]['score'] = max($found[$key]['score'], $score);
        $found[$key]['reasons'] = array_merge($found[$key]['reasons'], $reasons);
    }

    /**
     * Die aussagekraeftigsten Treffergruende eines Matchings (nur Punkte > 0,
     * hoechste zuerst, maximal drei) - der Mitarbeiter soll auf einen Blick
     * sehen, WARUM ein Kunde vorgeschlagen wird.
     *
     * @return list<string>
     */
    private function suggestionReasons(\App\Services\Matching\MatchResult $result): array
    {
        $parts = array_filter($result->breakdown, fn ($b) => ($b['points'] ?? 0) > 0);
        uasort($parts, fn ($a, $b) => $b['points'] <=> $a['points']);

        return array_slice(array_values(array_map(fn ($b) => (string) $b['reason'], $parts)), 0, 3);
    }

    /**
     * Eingangs-Dokument einem Kunden zuordnen: Datei in den Kundenordner
     * verschieben, Zuordnung speichern, protokollieren. $auto = durch die
     * Analyse (eindeutiger Match), sonst durch einen Mitarbeiter.
     *
     * Die Uebernahme ist atomisch (UPDATE ... WHERE customer_id IS NULL):
     * pruefen zwei Mitarbeiter dasselbe Eingangs-Dokument gleichzeitig,
     * gewinnt genau einer - sonst koennte ein Dokument von Kunde B im
     * Dateiordner von Kunde A landen (und dessen DSGVO-Purge zum Opfer
     * fallen).
     *
     * @return bool false, wenn das Dokument inzwischen einem ANDEREN Kunden gehoert
     */
    public function assignToCustomer(Document $document, Customer $customer, ?int $byUserId, bool $auto = false): bool
    {
        $claimed = Document::whereKey($document->id)
            ->whereNull('customer_id')
            ->update(['customer_id' => $customer->id]);
        if (!$claimed) {
            $document->refresh();
            // Idempotent: derselbe Kunde ist ok, ein anderer nicht.
            return (string) $document->customer_id === (string) $customer->id;
        }
        $document->customer_id = $customer->id;

        $disk = $document->disk ?: 'local';
        $target = 'customers/' . $customer->id . '/documents/' . basename($document->file_path);
        if ($document->file_path !== $target && Storage::disk($disk)->exists($document->file_path)) {
            if (Storage::disk($disk)->exists($target)) {
                $target = 'customers/' . $customer->id . '/documents/' . uniqid() . '_' . basename($document->file_path);
            }
            Storage::disk($disk)->move($document->file_path, $target);
            $document->file_path = $target;
        }

        $document->save();

        ActivityLog::create([
            'user_id' => $byUserId,
            'action' => $auto ? 'document_auto_assigned' : 'document_assigned',
            'entity_type' => 'document',
            'entity_id' => $document->id,
            'meta' => json_encode([
                'customer_id' => (string) $customer->id,
                'file' => $document->file_name,
                'ai_type' => $document->ai_type,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if ($auto) {
            // Betreuer informieren, dass die KI ein Dokument zugeordnet hat.
            $recipients = $customer->betreuer()->get();
            if ($recipients->isEmpty()) {
                $recipients = \App\Models\User::whereIn('role', ['admin', 'manager'])->where('is_active', true)->get();
            }
            \App\Support\Facades\Notify::pushMany($recipients->pluck('id'), [
                'type' => \App\Services\Notifications\NotificationService::TYPE_DOCUMENT,
                'title' => 'Dokument automatisch zugeordnet: ' . ($document->aiTypeLabel() ?? $document->file_name),
                'body' => 'Die KI-Analyse hat ein Dokument dem Kunden ' . ($customer->user?->name ?? $customer->customer_number) . ' zugeordnet.',
                'link' => route('admin.customer', $customer->id) . '#tab-dokumente',
                'dedup_key' => 'doc-auto-' . $document->id,
            ]);
        }

        // Geburtsurkunde: das Kind automatisch mit den bereits erfassten
        // Eltern-Kunden verknuepfen. Fehler duerfen die Zuordnung nie blockieren.
        if ($document->ai_type === 'geburtsurkunde') {
            try {
                $this->linkBirthCertificateParents($document, $customer, $byUserId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return true;
    }

    /**
     * Kind <-> Eltern verknuepfen (Geburtsurkunde): fuer jeden im Dokument
     * erkannten Elternteil (relation mutter/vater) den bestehenden Eltern-Kunden
     * suchen und - nur bei belastbarem Namens-Treffer (Score >= 70) - eine
     * Familien-Beziehung (CustomerRelationship, type 'family') zwischen Kind und
     * Elternteil anlegen. Idempotent (Paar in fester Reihenfolge). So ist das
     * neugeborene Kind sofort mit seinen Eltern verknuepft, ohne dass der
     * Mitarbeiter die Beziehung von Hand pflegen muss.
     *
     * @return list<string> Namen der tatsaechlich verknuepften Eltern
     */
    public function linkBirthCertificateParents(Document $document, Customer $child, ?int $byUserId): array
    {
        $personen = $document->ai_extracted['personen'] ?? [];
        if (!is_array($personen) || $personen === []) {
            return [];
        }

        $linked = [];
        foreach ($personen as $parent) {
            if (!is_array($parent)) {
                continue;
            }
            $relation = $parent['relation'] ?? null;
            if (!in_array($relation, ['mutter', 'vater'], true)) {
                continue;
            }
            $first = $parent['first_name'] ?? null;
            $last = $parent['last_name'] ?? null;
            $full = trim(($first ?? '') . ' ' . ($last ?? ''));
            if ($full === '') {
                continue;
            }

            // Bestehenden Eltern-Kunden ueber einen EXAKTEN und EINDEUTIGEN
            // Namens-Treffer suchen (die Geburtsurkunde liefert nur Namen, kein
            // Geburtsdatum/Adresse - ein Fuzzy-Score waere zu unsicher). Ueber
            // den Nachnamen grob vorfiltern, dann in PHP exakt normalisiert
            // vergleichen. Nur bei GENAU EINEM Treffer wird verknuepft; bei
            // Namensgleichheit mehrerer Kunden bleibt es dem Mitarbeiter
            // ueberlassen (kein Raten).
            $lastToken = $last !== null && $last !== ''
                ? (string) preg_replace('/.*\s/u', '', trim($last))
                : (string) preg_replace('/.*\s/u', '', $full);
            $target = $this->normalizeName($full);
            $candidates = Customer::with('user')
                ->where('id', '!=', $child->id)
                ->whereHas('user', fn ($u) => $u->where('name', 'like', '%' . $lastToken . '%'))
                ->limit(50)
                ->get()
                ->filter(fn ($c) => $this->normalizeName((string) ($c->user?->name ?? '')) === $target)
                ->values();
            if ($candidates->count() !== 1) {
                continue;
            }
            $parentCustomer = $candidates->first();
            if ($parentCustomer === null || (string) $parentCustomer->id === (string) $child->id) {
                continue;
            }

            [$a, $b] = \App\Models\CustomerRelationship::pairKey((string) $child->id, (string) $parentCustomer->id);
            \App\Models\CustomerRelationship::updateOrCreate(
                ['customer_a_id' => $a, 'customer_b_id' => $b],
                [
                    'type' => 'family',
                    'note' => 'Aus Geburtsurkunde: ' . ($relation === 'mutter' ? 'Mutter' : 'Vater') . ' des Kindes',
                    'created_by' => $byUserId,
                ]
            );
            $linked[] = $full;

            ActivityLog::create([
                'user_id' => $byUserId,
                'action' => 'customer_relationship_linked',
                'entity_type' => 'customer',
                'entity_id' => (string) $child->id,
                'meta' => json_encode([
                    'parent_customer_id' => (string) $parentCustomer->id,
                    'relation' => $relation,
                    'source' => 'geburtsurkunde',
                    'document_id' => (string) $document->id,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $linked;
    }

    /**
     * Extrahierte Daten in LEERE Felder der Kundenakte uebernehmen.
     * $keys sind Gruppen-Schluessel aus der Review-UI; ohne Angabe wird
     * nichts uebernommen (Mitarbeiter entscheidet explizit).
     *
     * @param list<string> $keys z.B. ['birth_date','address','phone','health_insurance','iban','email2','nationality','birth_place']
     * @return list<string> tatsaechlich befuellte Kundenfelder
     */
    public function applyExtractedToCustomer(Document $document, Customer $customer, array $keys, ?int $byUserId, ?array $extracted = null): array
    {
        // $extracted erlaubt das Anwenden EINES aus mehreren Dokumenten
        // zusammengefuehrten Ergebnisses (mergeExtractions); ohne Angabe wird
        // das Analyse-Ergebnis des Dokuments selbst genutzt.
        $data = $extracted ?? ($document->ai_extracted ?? []);
        $person = $data['person'] ?? [];
        $health = $data['gesundheit'] ?? [];
        $bank = $data['bank'] ?? [];

        $updates = [];
        $set = function (string $attribute, $value) use ($customer, &$updates): void {
            if ($value !== null && $value !== '' && blank($customer->{$attribute})) {
                $updates[$attribute] = $value;
            }
        };

        // Die aus dem Dokument gelesene E-Mail ist die Kontaktadresse des
        // Kunden und soll - wenn moeglich - die HAUPT-Login-Adresse
        // (users.email) werden: erst damit laesst sich der Portal-Zugang
        // aktivieren und die Willkommens-/Portal-Mail versenden. Nur wenn der
        // Kunde bereits eine echte Haupt-Adresse hat ODER die Adresse schon
        // einem ANDEREN Nutzer gehoert (users.email ist unique), wandert sie
        // in die Zweitadresse (email2).
        $userEmail = null;
        $applyEmail = function (?string $email) use ($customer, &$updates, &$userEmail): void {
            $email = $email !== null ? trim($email) : null;
            if ($email === null || $email === '') {
                return;
            }
            $user = $customer->user;
            // Gehoert die Adresse bereits diesem Kunden (Haupt- oder Zweit-
            // adresse), ist nichts zu tun - keine Dopplung.
            if ($user !== null && strcasecmp((string) $user->email, $email) === 0) {
                return;
            }
            if (strcasecmp((string) $customer->email2, $email) === 0) {
                return;
            }
            $takenByOther = \App\Models\User::where('email', $email)
                ->when($customer->user_id, fn ($q) => $q->where('id', '!=', $customer->user_id))
                ->exists();
            if ($user !== null && !$user->hasRealEmail() && !$takenByOther) {
                // Haupt-Login-Adresse setzen -> aktiviert den Portal-Zugang.
                $userEmail = $email;
            } elseif (blank($customer->email2)) {
                // Fallback: als Zweitadresse hinterlegen (nur wenn noch leer).
                $updates['email2'] = $email;
            }
        };

        foreach (array_unique($keys) as $key) {
            match ($key) {
                'birth_date' => $set('birth_date', $person['birth_date'] ?? null),
                'birth_place' => $set('birth_place', $person['birth_place'] ?? null),
                // Eindeutige Mobilnummer gehoert ins Feld "Handy", nicht ins
                // Festnetz-Feld "Telefon" (z.B. die Handynummer aus dem
                // CHECK24-Beratungsprotokoll).
                'phone' => (function () use ($set, $person): void {
                    $phone = $person['phone'] ?? null;
                    if ($phone !== null && \App\Support\GermanPhone::isMobile($phone)) {
                        $set('mobile', $phone);
                    } else {
                        $set('phone', $phone);
                    }
                })(),
                'nationality' => $set('nationality', $person['nationality'] ?? null),
                'marital_status' => $set('marital_status', $person['marital_status'] ?? null),
                'gender' => $set('gender', $person['gender'] ?? null),
                // Bewusst KEIN reines email2: die gelesene Adresse soll primaer
                // die Haupt-Login-Adresse werden (Portal-Zugang), sonst email2.
                'email2' => $applyEmail($person['email'] ?? null),
                'address' => (function () use ($set, $person): void {
                    $set('address_street', $person['street'] ?? null);
                    $set('address_house_number', $person['house_number'] ?? null);
                    $set('address_zip', $person['zip'] ?? null);
                    $set('address_city', $person['city'] ?? null);
                })(),
                'health_insurance' => (function () use ($set, $health): void {
                    $set('health_insurance_company', $health['health_insurance_company'] ?? null);
                    $set('health_insurance_number', $health['health_insurance_number'] ?? null);
                    $set('health_insurance_type', $health['health_insurance_type'] ?? null);
                    // Renten-/Sozialversicherungsnummer (aus der Beitrittserklaerung).
                    $set('pension_insurance_number', $health['pension_number'] ?? null);
                })(),
                'iban' => (function () use ($set, $bank): void {
                    $set('iban', $bank['iban'] ?? null);
                    $set('account_holder', $bank['account_holder'] ?? null);
                })(),
                default => null,
            };
        }

        if ($updates === [] && $userEmail === null) {
            return [];
        }

        $applied = [];
        if ($userEmail !== null && $customer->user !== null) {
            // Haupt-Login-Adresse am User setzen (aktiviert den Portal-Zugang).
            $customer->user->forceFill(['email' => $userEmail])->save();
            $applied[] = 'email';
        }
        if ($updates !== []) {
            $customer->fill($updates)->save();
            $applied = array_merge($applied, array_keys($updates));
        }

        ActivityLog::create([
            'user_id' => $byUserId,
            'action' => 'document_data_applied',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode([
                'document_id' => (string) $document->id,
                'fields' => $applied,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return $applied;
    }

    /**
     * Vertrag aus dem Analyse-Ergebnis anlegen ODER - wenn bereits ein
     * passender Vertrag existiert - diesen aktualisieren (Mitarbeiter-Freigabe).
     *
     * Betreiber-Vorgabe (23.07.2026): Ein neu importiertes Dokument fuer ein
     * bereits erfasstes Fahrzeug/eine bereits erfasste Police erzeugt KEIN
     * Duplikat mehr. Zuerst wird anhand der Vertrags-Identitaet
     * (Vertragsnummer, FIN/VIN, Kennzeichen, Energie-Zaehler/MaLo) ein
     * bestehender Vertrag gesucht. Trifft einer zu, wird nur er aktualisiert
     * und jede geaenderte Angabe in der Version History (ContractRevision)
     * festgehalten. Nur wenn kein passender Vertrag existiert, wird ein neuer
     * angelegt. So sieht der Kunde genau EINEN Vertrag je Fahrzeug (Single
     * Source of Truth) mit vollstaendigem Aenderungsverlauf.
     */
    public function createContractFromExtraction(Document $document, Customer $customer, ?int $byUserId, ?array $extracted = null): ?Contract
    {
        $data = $extracted ?? ($document->ai_extracted ?? []);
        $ins = $data['versicherung'] ?? [];
        $kfz = $data['kfz'] ?? [];
        $energie = $data['energie'] ?? [];

        if (blank($ins['insurer'] ?? null) && blank($ins['contract_number'] ?? null)) {
            return null;
        }

        // Stufe des Dokuments: Auftrag/Antrag oder Vertragsbestaetigung/Police.
        $stage = Document::contractStageFor($document->ai_type, $data);

        // Duplikat-Schutz: passenden Bestandsvertrag anhand der Identitaet
        // suchen und stattdessen aktualisieren (mit Audit-Log).
        $existing = $this->findExistingContractByIdentity($customer, $data)
            // Kein hartes Merkmal getroffen: ist dies die BESTAETIGUNG zu einem
            // frueher hochgeladenen Auftrag, wird dieser vervollstaendigt statt
            // ein zweiter Vertrag angelegt (Betreiber-Vorgabe 29.07.2026).
            ?? $this->findApplicationContractForConfirmation($customer, $data, $stage);
        if ($existing) {
            return $this->updateContractFromExtraction($existing, $document, $customer, $byUserId, $data);
        }

        $type = $ins['sparte']
            ?? ($document->ai_type === 'kfz_vertrag' ? 'kfz' : 'andere');

        // Bei der gesetzlichen Krankenversicherung den Subtyp 'gkv' setzen -
        // erst damit greift die 12-Monats-Wechsel-Erinnerung (§175 SGB V) im
        // ContractSwitchReminderService (Bindungsfrist ab Mitgliedsbeginn).
        $healthType = ($data['gesundheit'] ?? [])['health_insurance_type'] ?? null;
        $subtype = $type === 'krankenversicherung'
            ? match ($healthType) {
                'gesetzlich' => 'gkv',
                'privat' => 'pkv',
                default => null,
            }
            : null;
        // Von der Extraktion gelieferte Untergruppe (z.B. Mitgliedschafts-
        // Stufe basis/plus/premium der ADAC-Mitgliedschaft) - bereits gegen
        // Contract::SUBTYPES validiert (validatedInsurance).
        if ($subtype === null && isset($ins['subtype'])
            && isset(Contract::SUBTYPES[$type][$ins['subtype']])) {
            $subtype = $ins['subtype'];
        }

        // E-Scooter: Einmalbeitrag als Standard-Zahlweise (kein laufender
        // Beitrag). Contract::saving erzwingt zudem den Saison-Ablauf.
        $defaultInterval = $type === 'escooter' ? 'einmalig' : 'monthly';

        // Schutzbrief/Mobilclub (z.B. ADAC-Mitgliedschaft) beginnt SOFORT
        // (Betreiber-Vorgabe 28.07.2026): ab 0 Uhr des Tages, an dem das
        // Dokument hochgeladen wurde. Laufzeit 1 Jahr mit automatischer
        // jaehrlicher Verlaengerung - das Ablaufdatum ist der Verlaengerungs-
        // Stichtag (fuer die Erinnerung 3 Monate vorher), KEIN Vertragsende
        // (stillschweigende Verlaengerung).
        $startDate = $ins['start_date'] ?? null;
        $endDate = $ins['end_date'] ?? null;
        if ($type === 'schutzbrief' && $startDate === null) {
            $startDate = ($document->created_at ?? now())->toDateString();
            $endDate = $endDate ?? \Carbon\Carbon::parse($startDate)->addYear()->toDateString();
        }

        $contract = Contract::create([
            'customer_id' => $customer->id,
            'contract_number' => $ins['contract_number'] ?? null,
            'type' => $type,
            'subtype' => $subtype,
            'insurer' => $ins['insurer'] ?? null,
            'status' => 'active',
            // Stufe festhalten: 'antrag' = wartet auf die Vertragsbestaetigung
            // (nur dieser Vertrag darf spaeter automatisch ergaenzt werden),
            // 'vertrag' = Police/Bestaetigung liegt bereits vor.
            'stage' => $stage,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'premium_amount' => $ins['premium_amount'] ?? null,
            'premium_interval' => $ins['premium_interval'] ?? $defaultInterval,
        ]);

        // Fahrzeug-Detaildaten fuer KFZ und E-Scooter (beide nutzen die
        // Fahrzeugtabelle). Beim E-Scooter wird der Fahrzeugtyp gesetzt, damit
        // Anzeige und Bearbeitung ihn als E-Scooter erkennen.
        if (in_array($type, ['kfz', 'escooter'], true) && $kfz !== []) {
            ContractVehicleDetail::create(array_filter([
                'contract_id' => $contract->id,
                'vehicle_type' => $type === 'escooter' ? 'escooter' : ($kfz['vehicle_type'] ?? null),
                'license_plate' => $kfz['license_plate'] ?? null,
                'vin' => $kfz['vin'] ?? null,
                'hsn' => $kfz['hsn'] ?? null,
                'tsn' => $kfz['tsn'] ?? null,
                'manufacturer' => $kfz['manufacturer'] ?? null,
                'model' => $kfz['model'] ?? null,
                'first_registration' => $kfz['first_registration'] ?? null,
                // Zusaetzliche Tarif-/Fahrzeugfakten (validiert in
                // ValidatesExtractedFields::validatedVehicle).
                'has_teilkasko' => $kfz['has_teilkasko'] ?? null,
                'teilkasko_deductible' => $kfz['teilkasko_deductible'] ?? null,
                'has_vollkasko' => $kfz['has_vollkasko'] ?? null,
                'vollkasko_deductible' => $kfz['vollkasko_deductible'] ?? null,
                'holder_type' => $kfz['holder_type'] ?? null,
                'annual_mileage' => $kfz['annual_mileage'] ?? null,
                // Zusatzleistungen (z.B. Werkstattbindung/Schutzbrief) aus dem
                // Beratungsprotokoll - Schluessel bereits gegen den Katalog
                // validiert (ValidatesExtractedFields::validatedVehicle).
                'extras' => !empty($kfz['extras']) ? $kfz['extras'] : null,
                // Vorversicherung (bisheriger Kfz-Versicherer beim Wechsel).
                'previous_insurer' => $ins['previous_insurer'] ?? null,
                'previous_contract_number' => $ins['previous_contract_number'] ?? null,
                'previous_insurance_since' => $ins['previous_insurance_since'] ?? null,
                'previous_insurance_terminated_by_insurer' => $ins['previous_insurance_terminated'] ?? null,
                // Schadenfreiheitsklassen (z.B. aus der ADAC-Beitragsinformation
                // oder dem CHECK24-Protokoll) - inkl. Sondereinstufung: die
                // gewaehrte Klasse ist dann NICHT uebertragbar, die echte
                // (uebertragbare) Klasse steht in sf_liability_real_class.
                'sf_liability_class' => $kfz['sf_liability_class'] ?? null,
                'sf_liability_type' => $kfz['sf_liability_type'] ?? null,
                'sf_liability_special_reason' => $kfz['sf_liability_special_reason'] ?? null,
                'sf_liability_real_class' => $kfz['sf_liability_real_class'] ?? null,
                'sf_comprehensive_class' => $kfz['sf_comprehensive_class'] ?? null,
            ], fn ($v) => $v !== null));

            // Wechsel-Automatik (Betreiber-Vorgabe 26.07.2026): laeuft fuer
            // dasselbe Fahrzeug noch ein Vertrag eines ANDEREN Versicherers,
            // wird dort automatisch die Kuendigung erfasst - der Altvertrag
            // endet zum Beginn des neuen (nahtlose Kette in der Akte).
            if ($type === 'kfz') {
                $this->recordSwitchIfAny($contract, $kfz, $byUserId);
            }
        }

        // Energie-Vertrag (Strom/Gas): Zaehler-/Tarifdaten aus dem Auftrag
        // bzw. Zaehlerfoto in die Energie-Detailtabelle uebernehmen.
        if (in_array($type, Contract::ENERGY_TYPES, true) && $energie !== []) {
            \App\Models\ContractEnergyDetail::create(array_filter([
                'contract_id' => $contract->id,
                'meter_number' => $energie['meter_number'] ?? null,
                'malo_id' => $energie['malo_id'] ?? null,
                'meter_reading' => $energie['meter_reading'] ?? null,
                'consumption_kwh' => $energie['consumption_kwh'] ?? null,
                'tariff' => $energie['tariff'] ?? null,
                'customer_number' => $energie['customer_number'] ?? null,
                'grid_operator' => $energie['grid_operator'] ?? null,
                // Tarifpreise (Arbeitspreis ct/kWh, Grundpreis EUR/Monat) -
                // stehen im Auftrag UND in der Vertragsbestaetigung.
                'working_price' => $energie['working_price'] ?? null,
                'base_price' => $energie['base_price'] ?? null,
                'payment_amount' => $ins['premium_amount'] ?? null,
                'payment_interval' => $ins['premium_interval'] ?? null,
                // Vorversorger (bisheriger Lieferant beim Wechsel) + dessen
                // Kundennummer - aus dem Strom-/Gas-Auftrag.
                'previous_provider' => $energie['previous_provider'] ?? null,
                'previous_customer_number' => $energie['previous_customer_number'] ?? null,
            ], fn ($v) => $v !== null));
        }

        // Internet-/DSL-Vertrag: Tarif, Geschwindigkeit, preisvariabler Tarif,
        // Router und Bonus/Gutschein aus dem Auftrag in die Internet-Detailtabelle.
        $internet = $data['internet'] ?? [];
        if ($type === 'internet' && $internet !== []) {
            \App\Models\ContractInternetDetail::create(array_filter([
                'contract_id' => $contract->id,
                'tariff' => $internet['tariff'] ?? null,
                'speed' => $internet['speed'] ?? null,
                'upload_speed' => $internet['upload_speed'] ?? null,
                'price_initial' => $internet['price_initial'] ?? null,
                'price_initial_months' => $internet['price_initial_months'] ?? null,
                'price_regular' => $internet['price_regular'] ?? null,
                'has_router' => $internet['has_router'] ?? null,
                'router_name' => $internet['router_name'] ?? null,
                'router_price' => $internet['router_price'] ?? null,
                'bonus_amount' => $internet['bonus_amount'] ?? null,
                'voucher_amount' => $internet['voucher_amount'] ?? null,
            ], fn ($v) => $v !== null));
        }

        $document->contract_id = $contract->id;
        $document->save();

        // Vertragsverlauf starten (Betreiber-Vorgabe: fuer alle Sparten).
        app(\App\Services\ContractHistoryService::class)->record([
            'customer_id' => (string) $customer->id,
            'contract_id' => (string) $contract->id,
            'branch' => $type,
            'provider' => $ins['insurer'] ?? null,
            'effective_from' => $ins['start_date'] ?? null,
            'reason' => 'initial',
            'source_document_id' => (string) $document->id,
            'created_by' => $byUserId,
        ]);

        ActivityLog::create([
            'user_id' => $byUserId,
            'action' => 'contract_created_from_document',
            'entity_type' => 'contract',
            'entity_id' => $contract->id,
            'meta' => json_encode([
                'document_id' => (string) $document->id,
                'customer_id' => (string) $customer->id,
                'type' => $type,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return $contract;
    }

    /**
     * Bestehenden Vertrag des Kunden anhand der Vertrags-Identitaet
     * verknuepfen (rein additive Automatik: nur contract_id des Dokuments
     * wird gesetzt, KEINE Feldaenderung - das bleibt der Freigabe-Stufe
     * vorbehalten). Nutzt dieselbe Identitaets-Suche wie der Duplikat-Schutz
     * beim Anlegen.
     */
    public function linkMatchingContract(Document $document, Customer $customer): ?Contract
    {
        // Zaehlerfoto: den abgelesenen Stand in die Verbrauchshistorie des
        // Energievertrags schreiben. Laeuft VOR dem Abbruch weiter unten,
        // damit auch ein bereits mit dem Vertrag verknuepftes Foto
        // (Portal-Upload aus der Vertragsansicht) erfasst wird.
        if ($document->ai_type === 'zaehlerfoto') {
            try {
                app(\App\Services\Energy\MeterReadingService::class)->recordFromDocument($document, $customer);
            } catch (\Throwable $e) {
                report($e); // darf die Zuordnung nie blockieren
            }
        }

        if ($document->contract_id) {
            return null;
        }

        $data = $document->ai_extracted ?? [];
        $stage = Document::contractStageFor($document->ai_type, $data);
        $contract = $this->findExistingContractByIdentity($customer, $data)
            // Vertragsbestaetigung zu einem frueher erfassten Auftrag.
            ?? $this->findApplicationContractForConfirmation($customer, $data, $stage);
        if ($contract) {
            $document->contract_id = $contract->id;
            $document->save();

            // Fahrzeugschein/-brief (Zulassungsbescheinigung Teil I/II): die
            // AMTLICHEN Fahrzeugdaten (FIN, HSN/TSN, Marke, Modell, Erst-
            // zulassung, Kennzeichen) mit dem passenden Vertrag abgleichen und
            // dessen LEERE Fahrzeugfelder ergaenzen - Bestand wird nie
            // ueberschrieben, jede Ergaenzung steht in der Version History.
            // So fuellt die amtliche Zulassung fehlende Fahrzeugdaten des
            // Kundenvertrags automatisch nach.
            $istZulassung = in_array($document->ai_type, ['fahrzeugschein', 'fahrzeugbrief'], true)
                && in_array($contract->type, ['kfz', 'escooter'], true)
                && !empty($data['kfz']);
            // Vertragsbestaetigung/Police zu einem noch offenen ANTRAG: die
            // endgueltigen Angaben (Vertragsnummer, Kundennummer, MaLo-ID,
            // Beginn, Abschlag) werden in den vorhandenen Vertrag uebernommen
            // (Betreiber-Vorgabe 29.07.2026) - jede Aenderung feldgenau in der
            // Version History.
            $istBestaetigung = $stage === Contract::STAGE_VERTRAG && $contract->isApplication();
            // Zaehlerfoto zu einem Energievertrag: Zaehlerstand/-nummer
            // nachtragen (leere Felder), damit das Foto nicht nur "irgendwo"
            // in der Akte liegt.
            $istZaehlerfoto = $document->ai_type === 'zaehlerfoto'
                && $contract->isEnergy() && !empty($data['energie']);

            if ($istZulassung || $istBestaetigung || $istZaehlerfoto) {
                $this->updateContractFromExtraction($contract, $document, $customer, null, $data);
            }
        }

        return $contract;
    }

    /**
     * Bestehenden Vertrag des Kunden anhand der Vertrags-Identitaet suchen -
     * Grundlage des Duplikat-Schutzes. Geprueft wird (in dieser Reihenfolge,
     * jeweils streng):
     *   1. Vertragsnummer (versichererunabhaengig - gleiche Nummer = gleiche Police)
     *   2. Fahrzeug-Identnummer (FIN/VIN) - nur beim selben Versicherer
     *   3. Kennzeichen (normalisiert, umlaut-tolerant) - nur beim selben Versicherer
     *   4. Energie: MaLo-ID bzw. Zaehlernummer
     * Der erste Treffer gewinnt. Bewusst nur harte Identitaetsmerkmale, damit
     * nicht faelschlich zwei verschiedene Vertraege verschmolzen werden.
     * Fahrzeug-Treffer bei ANDEREM Versicherer sind ein Wechsel und werden
     * absichtlich NICHT zugeordnet -> eigener Vertrag (26.07.2026).
     */
    public function findExistingContractByIdentity(Customer $customer, array $data): ?Contract
    {
        $ins = $data['versicherung'] ?? [];
        $kfz = $data['kfz'] ?? [];
        $energie = $data['energie'] ?? [];

        // 1. Vertragsnummer
        if (!blank($ins['contract_number'] ?? null)) {
            $byNumber = Contract::where('customer_id', $customer->id)
                ->where('contract_number', $ins['contract_number'])->first();
            if ($byNumber) {
                return $byNumber;
            }
        }

        // 2./3. Fahrzeug-Identitaet: erst FIN/VIN, dann Kennzeichen - in PHP
        // verglichen, damit Umlaut-Schreibweisen dasselbe Fahrzeug treffen
        // ("LÜN-G 1110" = "LUN-G1110"; SQL-upper() kann keine Umlaute falten).
        // WICHTIG (Betreiber-Vorgabe 26.07.2026): die Fahrzeug-Identitaet
        // greift nur beim SELBEN Versicherer. Ein Dokument eines ANDEREN
        // Versicherers fuer dasselbe Fahrzeug ist ein WECHSEL und muss ein
        // eigener Vertrag werden (alter gekuendigt zum X, neuer aktiv ab X) -
        // kein Update des Altvertrags. Ohne Versicherer-Angabe im Dokument
        // bleibt es beim bisherigen Verhalten (Zuordnung zum Bestand).
        $vin = ContractVehicleDetail::normalizeVin($kfz['vin'] ?? null);
        $plate = ContractVehicleDetail::normalizePlate($kfz['license_plate'] ?? null);
        if ($vin || $plate) {
            $vehicleContracts = Contract::where('customer_id', $customer->id)
                ->whereHas('vehicleDetail')->with('vehicleDetail')->get();
            foreach (['vin', 'plate'] as $merkmal) {
                foreach ($vehicleContracts as $vehicleContract) {
                    $veh = $vehicleContract->vehicleDetail;
                    $hit = $merkmal === 'vin'
                        ? ($vin && $vin === ContractVehicleDetail::normalizeVin($veh->vin))
                        : ($plate && $plate === ContractVehicleDetail::normalizePlate($veh->license_plate));
                    if ($hit && $this->insurersLookAlike($vehicleContract->insurer, $ins['insurer'] ?? null)) {
                        return $vehicleContract;
                    }
                }
            }
        }

        // 4. Energie: MaLo-ID (11-stellig, eindeutig), Zaehlernummer und
        // Kundennummer beim Versorger. Verglichen wird NORMALISIERT (in PHP),
        // weil dieselbe Zaehlernummer je Quelle anders geschrieben ist:
        // auf dem Zaehler "1 LOG00 9228 3078", im Auftrag "1LOG0092283078".
        $malo = \App\Models\ContractEnergyDetail::normalizeMalo($energie['malo_id'] ?? null);
        $meter = \App\Models\ContractEnergyDetail::normalizeMeter($energie['meter_number'] ?? null);
        // Die Kundennummer gilt nur BEIM SELBEN Versorger - sie ist nicht
        // global eindeutig, deshalb zusaetzlich der Versicherer-/Versorger-
        // Abgleich (fehlt die Angabe im Dokument, bleibt es beim Bestand).
        $energyCustomerNumber = trim((string) ($energie['customer_number'] ?? ''));
        if (mb_strlen($energyCustomerNumber) < 4) {
            $energyCustomerNumber = '';
        }

        if ($malo !== null || $meter !== null || $energyCustomerNumber !== '') {
            $energyContracts = Contract::where('customer_id', $customer->id)
                ->whereHas('energyDetail')->with('energyDetail')->get();
            foreach (['malo', 'meter', 'customer_number'] as $merkmal) {
                foreach ($energyContracts as $energyContract) {
                    $en = $energyContract->energyDetail;
                    $hit = match ($merkmal) {
                        'malo' => $malo !== null && $malo === \App\Models\ContractEnergyDetail::normalizeMalo($en->malo_id),
                        'meter' => $meter !== null && $meter === \App\Models\ContractEnergyDetail::normalizeMeter($en->meter_number),
                        default => $energyCustomerNumber !== ''
                            && strcasecmp(trim((string) $en->customer_number), $energyCustomerNumber) === 0
                            && $this->insurersLookAlike($energyContract->insurer, $ins['insurer'] ?? null),
                    };
                    if ($hit) {
                        return $energyContract;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Wie lange ein Antrags-Vertrag auf seine Bestaetigung "warten" darf.
     * Zwischen Auftrag und Vertragsbestaetigung liegen in der Praxis Wochen
     * bis wenige Monate; ein uralter Antrag soll von einem neuen Dokument
     * nicht mehr eingesammelt werden.
     */
    private const APPLICATION_MAX_AGE_MONTHS = 12;

    /**
     * Betreiber-Vorgabe 29.07.2026 - AUFTRAG zuerst, VERTRAG spaeter:
     * Ueblicherweise wird zuerst der AUFTRAG/ANTRAG hochgeladen (viele Daten,
     * aber noch keine Bestaetigung). Wochen spaeter kommt die
     * VERTRAGSBESTAETIGUNG/POLICE mit Vertragsnummer, Kundennummer, MaLo-ID,
     * endgueltigem Beginn und Abschlag. Beide Dokumente teilen oft KEIN
     * einziges hartes Identitaetsmerkmal (der EWE-Auftrag nennt nur die
     * Zaehlernummer, die Bestaetigung nur die MaLo-ID) - die reine
     * Identitaets-Suche wuerde also einen ZWEITEN Vertrag anlegen.
     *
     * Deshalb sucht diese Methode den passenden ANTRAGS-Vertrag. Sie ist
     * bewusst streng:
     *  - nur Vertraege der Stufe 'antrag' (aus einem Auftrag/Antrag entstanden;
     *    Altbestand und manuell angelegte Vertraege bleiben unberuehrt),
     *  - gleiche Sparte (Strom und Gas vermischen sich nie),
     *  - gleiche Gesellschaft (ein ANDERER Versorger waere ein Wechsel),
     *  - kein WIDERSPRUCH in den harten Merkmalen (andere Vertragsnummer,
     *    andere MaLo-ID/Zaehlernummer, anderes Fahrzeug),
     *  - hoechstens 12 Monate alt.
     * Bleiben danach MEHRERE Antraege uebrig, entscheidet ein zusaetzliches
     * Indiz (gleicher Tarif/gleiches Fahrzeug); bleibt es mehrdeutig, wird
     * NICHT geraten - dann entsteht ein eigener Vertrag und der Mitarbeiter
     * sieht beide.
     *
     * @param array<string,mixed> $data validiertes Analyse-Ergebnis
     * @param ?string $stage Stufe des neuen Dokuments (Contract::STAGE_*)
     */
    public function findApplicationContractForConfirmation(Customer $customer, array $data, ?string $stage): ?Contract
    {
        // Nur eine BESTAETIGUNG darf einen Antrag vervollstaendigen.
        if ($stage !== Contract::STAGE_VERTRAG) {
            return null;
        }

        $ins = $data['versicherung'] ?? [];
        $type = $ins['sparte'] ?? null;
        if ($type === null || !isset(Contract::TYPES[$type])) {
            return null;
        }
        // Strom und Gas sind getrennte Sparten und duerfen sich nie
        // vermischen; nur die Alt-Sammelsparte 'strom_gas' gilt zusaetzlich
        // als passend (Bestandsdaten vor der Aufteilung).
        $types = [$type];
        if (in_array($type, Contract::ENERGY_TYPES, true)) {
            $types[] = 'strom_gas';
        }

        $candidates = Contract::where('customer_id', $customer->id)
            ->where('stage', Contract::STAGE_ANTRAG)
            ->whereIn('type', $types)
            ->whereIn('status', ['active', 'pending'])
            ->where('created_at', '>=', now()->subMonths(self::APPLICATION_MAX_AGE_MONTHS))
            ->with(['energyDetail', 'vehicleDetail'])
            ->get()
            ->filter(fn (Contract $c) => $this->insurersLookAlike($c->insurer, $ins['insurer'] ?? null)
                && !$this->identityContradicts($c, $data))
            ->values();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }
        if ($candidates->isEmpty()) {
            return null;
        }

        // Mehrere offene Antraege bei derselben Gesellschaft: nur mit einem
        // klaren Indiz zuordnen (sonst lieber ein eigener Vertrag).
        $confirmed = $candidates->filter(fn (Contract $c) => $this->sharesDistinctiveDetail($c, $data))->values();

        return $confirmed->count() === 1 ? $confirmed->first() : null;
    }

    /**
     * Widersprechen sich Vertrag und Dokument in einem HARTEN Merkmal? Dann
     * gehoeren sie sicher nicht zusammen (z.B. zwei Stromvertraege desselben
     * Kunden an unterschiedlichen Lieferstellen).
     *
     * @param array<string,mixed> $data
     */
    private function identityContradicts(Contract $contract, array $data): bool
    {
        $ins = $data['versicherung'] ?? [];
        $kfz = $data['kfz'] ?? [];
        $energie = $data['energie'] ?? [];

        $differs = function (?string $a, ?string $b): bool {
            $a = trim((string) $a);
            $b = trim((string) $b);
            return $a !== '' && $b !== '' && strcasecmp($a, $b) !== 0;
        };

        // Die Nummer eines ANTRAGS ist nur vorlaeufig (Auftrags-/Antragsnummer,
        // z.B. beim DSL-Auftrag) - eine abweichende Vertragsnummer in der
        // Bestaetigung ist also KEIN Widerspruch, sondern genau die erwartete
        // endgueltige Nummer. Bei einem bereits bestaetigten Vertrag zaehlt sie
        // dagegen als hartes Merkmal.
        if (!$contract->isApplication()
            && $differs($contract->contract_number, $ins['contract_number'] ?? null)) {
            return true;
        }

        $en = $contract->energyDetail;
        if ($en) {
            if ($differs(
                \App\Models\ContractEnergyDetail::normalizeMalo($en->malo_id),
                \App\Models\ContractEnergyDetail::normalizeMalo($energie['malo_id'] ?? null)
            )) {
                return true;
            }
            if ($differs(
                \App\Models\ContractEnergyDetail::normalizeMeter($en->meter_number),
                \App\Models\ContractEnergyDetail::normalizeMeter($energie['meter_number'] ?? null)
            )) {
                return true;
            }
        }

        $veh = $contract->vehicleDetail;
        if ($veh) {
            if ($differs(
                ContractVehicleDetail::normalizeVin($veh->vin),
                ContractVehicleDetail::normalizeVin($kfz['vin'] ?? null)
            )) {
                return true;
            }
            if ($differs(
                ContractVehicleDetail::normalizePlate($veh->license_plate),
                ContractVehicleDetail::normalizePlate($kfz['license_plate'] ?? null)
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zusaetzliches Indiz, dass Vertrag und Dokument dieselbe Sache meinen:
     * gleiche Tarif-/Produktbezeichnung oder gleiches Fahrzeug. Wird nur
     * gebraucht, wenn mehrere offene Antraege in Frage kommen.
     *
     * @param array<string,mixed> $data
     */
    private function sharesDistinctiveDetail(Contract $contract, array $data): bool
    {
        $ins = $data['versicherung'] ?? [];
        $energie = $data['energie'] ?? [];
        $kfz = $data['kfz'] ?? [];

        $tariff = $this->normalizeTariff($energie['tariff'] ?? ($ins['tariff'] ?? null));
        if ($tariff !== null) {
            foreach ([$contract->energyDetail?->tariff, $contract->internetDetail?->tariff] as $known) {
                if ($tariff === $this->normalizeTariff($known)) {
                    return true;
                }
            }
        }

        $veh = $contract->vehicleDetail;
        if ($veh) {
            $vin = ContractVehicleDetail::normalizeVin($kfz['vin'] ?? null);
            $plate = ContractVehicleDetail::normalizePlate($kfz['license_plate'] ?? null);
            if ($vin !== null && $vin === ContractVehicleDetail::normalizeVin($veh->vin)) {
                return true;
            }
            if ($plate !== null && $plate === ContractVehicleDetail::normalizePlate($veh->license_plate)) {
                return true;
            }
        }

        return false;
    }

    /** Tarifname auf seinen Kern reduzieren (Gross/Klein, Sonderzeichen egal). */
    private function normalizeTariff(?string $value): ?string
    {
        $n = mb_strtolower(trim((string) $value));
        $n = strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $n = (string) preg_replace('/[^a-z0-9]+/', '', $n);
        return $n !== '' ? $n : null;
    }

    /**
     * Zwei Versicherer-Angaben grob vergleichen - zentral im Contract-Modell
     * (auch die Wechsel-Automatik im Admin-Formular nutzt dieselbe Logik).
     */
    private function insurersLookAlike(?string $a, ?string $b): bool
    {
        return Contract::insurersLookAlike($a, $b);
    }

    /**
     * Wechsel-Automatik nach dem Anlegen eines neuen KFZ-Vertrags aus einem
     * Dokument: laeuft fuer dasselbe Fahrzeug (FIN/Kennzeichen) noch ein
     * Vertrag eines ANDEREN Versicherers in den Zeitraum hinein, wird dort
     * die Kuendigung erfasst (eingereicht heute, Ablauf = Beginn des neuen).
     * Ohne Beginn im Dokument passiert bewusst nichts (keine erfundenen
     * Daten); gleicher Versicherer ist kein Wechsel (Duplikat-Schutz hat
     * vorher schon zugeordnet).
     */
    private function recordSwitchIfAny(Contract $neu, array $kfz, ?int $byUserId): void
    {
        if (empty($neu->start_date)) {
            return;
        }
        $conflict = app(\App\Services\VehicleOverlapGuard::class)->findConflict($neu, [
            'vin' => $kfz['vin'] ?? null,
            'license_plate' => $kfz['license_plate'] ?? null,
            'hsn' => $kfz['hsn'] ?? null,
            'tsn' => $kfz['tsn'] ?? null,
        ], (string) $neu->id);
        if (!$conflict || Contract::insurersLookAlike($conflict->insurer, $neu->insurer)) {
            return;
        }
        app(\App\Services\ContractSwitchService::class)->recordCancellationForSwitch(
            $conflict,
            \Illuminate\Support\Carbon::parse($neu->start_date),
            'document',
            $byUserId,
        );
    }

    /**
     * Bestehenden Vertrag aus einem neu importierten Dokument aktualisieren:
     * geaenderte Sachfelder (Beitrag, Beginn/Ende, Deckung, Zusatzleistungen
     * ...) werden uebernommen und jede Aenderung in der Version History
     * (ContractRevision) mit altem und neuem Wert protokolliert. Leere neue
     * Werte ueberschreiben nie einen bestehenden (kein Datenverlust);
     * Zusatzleistungen werden ergaenzt, nie entfernt.
     */
    private function updateContractFromExtraction(Contract $contract, Document $document, Customer $customer, ?int $byUserId, array $data): Contract
    {
        $ins = $data['versicherung'] ?? [];
        $kfz = $data['kfz'] ?? [];
        $energie = $data['energie'] ?? [];

        $recorder = app(ContractRevisionRecorder::class);
        $ctx = [
            'source' => 'document',
            'source_document_id' => (string) $document->id,
            'changed_by' => $byUserId,
            'batch_id' => $recorder->newBatchId(),
        ];

        // ---- Vertragsstammdaten -------------------------------------------
        $contractProposed = [
            'insurer' => $ins['insurer'] ?? null,
            'start_date' => $ins['start_date'] ?? null,
            'end_date' => $ins['end_date'] ?? null,
            'premium_amount' => $ins['premium_amount'] ?? null,
            'premium_interval' => $ins['premium_interval'] ?? null,
        ];
        // Antrag -> bestaetigter Vertrag: die Stufe wandert nur VORWAERTS.
        // Eine Police kann einen Antrag bestaetigen, ein spaeter nachgereichter
        // Auftrag macht aus einem bestaetigten Vertrag nie wieder einen Antrag.
        $stage = Document::contractStageFor($document->ai_type, $data);
        $bestaetigt = $stage === Contract::STAGE_VERTRAG && $contract->isApplication();
        if ($bestaetigt) {
            $contractProposed['stage'] = Contract::STAGE_VERTRAG;
        }

        // Vertragsnummer: normalerweise nur ERGAENZEN, wenn bislang leer (nie
        // eine bestehende ueberschreiben). Bestaetigt eine Police/Vertrags-
        // bestaetigung einen ANTRAG, ersetzt ihre endgueltige Nummer aber die
        // vorlaeufige Auftrags-/Antragsnummer - der alte Wert bleibt in der
        // Version History nachvollziehbar. Immer nur, wenn die Nummer nicht
        // schon an einem anderen Vertrag haengt (unique).
        $newNumber = $ins['contract_number'] ?? null;
        if ((blank($contract->contract_number) || $bestaetigt) && !blank($newNumber)
            && !Contract::where('contract_number', $newNumber)->where('id', '!=', $contract->id)->exists()) {
            $contractProposed['contract_number'] = $newNumber;
        }
        $changed = $recorder->apply($contract, $contract, $contractProposed, $this->contractRevisionSpec(), $ctx);

        // ---- Fahrzeug-Detaildaten (KFZ / E-Scooter) -----------------------
        if (in_array($contract->type, ['kfz', 'escooter'], true) && $kfz !== []) {
            $veh = $contract->vehicleDetail
                ?: ContractVehicleDetail::create(['contract_id' => $contract->id]);

            $vehProposed = [
                'license_plate' => $kfz['license_plate'] ?? null,
                'vin' => $kfz['vin'] ?? null,
                'hsn' => $kfz['hsn'] ?? null,
                'tsn' => $kfz['tsn'] ?? null,
                'manufacturer' => $kfz['manufacturer'] ?? null,
                'model' => $kfz['model'] ?? null,
                'first_registration' => $kfz['first_registration'] ?? null,
                'has_teilkasko' => $kfz['has_teilkasko'] ?? null,
                'teilkasko_deductible' => $kfz['teilkasko_deductible'] ?? null,
                'has_vollkasko' => $kfz['has_vollkasko'] ?? null,
                'vollkasko_deductible' => $kfz['vollkasko_deductible'] ?? null,
                'holder_type' => $kfz['holder_type'] ?? null,
                'annual_mileage' => $kfz['annual_mileage'] ?? null,
                'sf_liability_class' => $kfz['sf_liability_class'] ?? null,
                'sf_comprehensive_class' => $kfz['sf_comprehensive_class'] ?? null,
                'previous_insurer' => $ins['previous_insurer'] ?? null,
                'previous_contract_number' => $ins['previous_contract_number'] ?? null,
            ];
            // Zusatzleistungen ERGAENZEN (nie entfernen): so geht z.B. ein
            // bereits erfasster Schutzbrief nicht verloren, wenn ihn ein
            // spaeteres Dokument nicht erneut auffuehrt.
            if (!empty($kfz['extras'])) {
                $vehProposed['extras'] = array_values(array_unique(
                    array_merge($veh->extras ?? [], $kfz['extras'])
                ));
            }
            // Feste Fahrzeug-Identitaets-/Stammfelder nur ERGAENZEN, wenn leer -
            // eine abweichende Schreibweise (z.B. "S-AB 1234" vs "S-AB1234")
            // ist keine echte Aenderung und darf den Bestand nicht ueberschreiben.
            foreach (['license_plate', 'vin', 'hsn', 'tsn', 'manufacturer', 'model', 'first_registration'] as $static) {
                if (filled($veh->{$static})) {
                    unset($vehProposed[$static]);
                }
            }
            $changed = array_merge($changed, $recorder->apply($contract, $veh, $vehProposed, $this->vehicleRevisionSpec(), $ctx));
        }

        // ---- Energie-Detaildaten (Strom / Gas) ----------------------------
        if (in_array($contract->type, Contract::ENERGY_TYPES, true) && $energie !== []) {
            $en = $contract->energyDetail
                ?: \App\Models\ContractEnergyDetail::create(['contract_id' => $contract->id]);

            $enProposed = [
                'tariff' => $energie['tariff'] ?? null,
                'consumption_kwh' => $energie['consumption_kwh'] ?? null,
                'meter_number' => $energie['meter_number'] ?? null,
                'malo_id' => $energie['malo_id'] ?? null,
                'meter_reading' => $energie['meter_reading'] ?? null,
                'customer_number' => $energie['customer_number'] ?? null,
                'grid_operator' => $energie['grid_operator'] ?? null,
                'working_price' => $energie['working_price'] ?? null,
                'base_price' => $energie['base_price'] ?? null,
                'payment_amount' => $ins['premium_amount'] ?? null,
                'payment_interval' => $ins['premium_interval'] ?? null,
                'previous_provider' => $energie['previous_provider'] ?? null,
                'previous_customer_number' => $energie['previous_customer_number'] ?? null,
            ];
            // Physische Zaehler-Identitaet nur ergaenzen, wenn leer (nie eine
            // bestehende MaLo-ID/Zaehlernummer durch eine Schreibvariante ersetzen).
            foreach (['malo_id', 'meter_number'] as $static) {
                if (filled($en->{$static})) {
                    unset($enProposed[$static]);
                }
            }
            $changed = array_merge($changed, $recorder->apply($contract, $en, $enProposed, $this->energyRevisionSpec(), $ctx));
        }

        // ---- Internet-Detaildaten (DSL / Internet) ------------------------
        $internet = $data['internet'] ?? [];
        if ($contract->type === 'internet' && $internet !== []) {
            $net = $contract->internetDetail
                ?: \App\Models\ContractInternetDetail::create(['contract_id' => $contract->id]);

            $netProposed = [
                'tariff' => $internet['tariff'] ?? null,
                'speed' => $internet['speed'] ?? null,
                'upload_speed' => $internet['upload_speed'] ?? null,
                'price_initial' => $internet['price_initial'] ?? null,
                'price_initial_months' => $internet['price_initial_months'] ?? null,
                'price_regular' => $internet['price_regular'] ?? null,
                'router_name' => $internet['router_name'] ?? null,
                'router_price' => $internet['router_price'] ?? null,
                'bonus_amount' => $internet['bonus_amount'] ?? null,
                'voucher_amount' => $internet['voucher_amount'] ?? null,
            ];
            // has_router nur ergaenzen, wenn im Dokument gesetzt (true) - ein
            // fehlender Router-Block soll ein bereits erfasstes "mit Router"
            // nicht auf false zuruecksetzen.
            if (!empty($internet['has_router'])) {
                $netProposed['has_router'] = true;
            }
            $changed = array_merge($changed, $recorder->apply($contract, $net, $netProposed, $this->internetRevisionSpec(), $ctx));
        }

        // Dokument mit dem (aktualisierten) Vertrag verknuepfen.
        if (!$document->contract_id) {
            $document->contract_id = $contract->id;
            $document->save();
        }

        ActivityLog::create([
            'user_id' => $byUserId,
            'action' => $bestaetigt ? 'contract_confirmed_from_document' : 'contract_updated_from_document',
            'entity_type' => 'contract',
            'entity_id' => $contract->id,
            'meta' => json_encode([
                'document_id' => (string) $document->id,
                'customer_id' => (string) $customer->id,
                'changed_fields' => $changed,
                'batch_id' => $ctx['batch_id'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // Aus einem Antrag ist ein bestaetigter Vertrag geworden: Betreuer
        // informieren (der Vorgang laeuft automatisch, soll aber sichtbar
        // sein) und den Vertragsverlauf fortschreiben.
        if ($bestaetigt) {
            $this->notifyContractConfirmed($contract, $customer, $document, $changed);
        }

        return $contract;
    }

    /**
     * Glocken-Hinweis + Verlaufseintrag, wenn eine Vertragsbestaetigung einen
     * offenen Auftrag vervollstaendigt hat. Fehler duerfen die Uebernahme nie
     * blockieren - die Daten sind zu dem Zeitpunkt bereits gespeichert.
     *
     * @param list<string> $changed
     */
    private function notifyContractConfirmed(Contract $contract, Customer $customer, Document $document, array $changed): void
    {
        try {
            // Vertragsverlauf: KEIN zweiter Eintrag fuer denselben Vertrag
            // (das waere nur Rauschen). Stattdessen bekommt der vorhandene
            // Eintrag den jetzt bestaetigten Beginn, falls er beim Auftrag noch
            // offen war ("schnellstmoeglich").
            if (filled($contract->start_date)) {
                \App\Models\ContractHistory::where('contract_id', $contract->id)
                    ->whereNull('effective_from')
                    ->update(['effective_from' => $contract->start_date]);
            }

            $recipients = $customer->betreuer()->get();
            if ($recipients->isEmpty()) {
                $recipients = \App\Models\User::whereIn('role', ['admin', 'manager'])->where('is_active', true)->get();
            }
            $name = $customer->user?->name ?? $customer->customer_number;
            \App\Support\Facades\Notify::pushMany($recipients->pluck('id'), [
                'type' => \App\Services\Notifications\NotificationService::TYPE_DOCUMENT,
                'title' => 'Vertragsbestätigung übernommen: ' . $contract->typeLabel()
                    . ($contract->insurer ? ' (' . $contract->insurer . ')' : ''),
                'body' => 'Der Auftrag von ' . $name . ' wurde durch die Vertragsbestätigung ergänzt'
                    . ($contract->contract_number ? ' - Vertragsnummer ' . $contract->contract_number : '')
                    . '. Ergänzte/aktualisierte Angaben: ' . count(array_diff($changed, ['stage'])) . '.',
                'link' => route('admin.contract.edit', $contract->id),
                'dedup_key' => 'contract-confirmed-' . $contract->id . '-' . $document->id,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Anzeige-Spezifikation (Label + Formatter) der Vertragsstammfelder. */
    private function contractRevisionSpec(): array
    {
        return [
            'insurer' => ['label' => 'Versicherer'],
            'contract_number' => ['label' => 'Vertragsnummer'],
            'start_date' => ['label' => 'Vertragsbeginn', 'format' => [$this, 'fmtDate']],
            'end_date' => ['label' => 'Vertragsende', 'format' => [$this, 'fmtDate']],
            'premium_amount' => ['label' => 'Beitrag', 'format' => [$this, 'fmtEuro']],
            'premium_interval' => ['label' => 'Zahlweise', 'format' => [$this, 'fmtInterval']],
            'stage' => ['label' => 'Vertragsstufe', 'format' => [$this, 'fmtStage']],
        ];
    }

    /** Anzeige-Spezifikation der Fahrzeug-Detailfelder. */
    private function vehicleRevisionSpec(): array
    {
        return [
            'license_plate' => ['label' => 'Kennzeichen'],
            'vin' => ['label' => 'FIN'],
            'hsn' => ['label' => 'HSN'],
            'tsn' => ['label' => 'TSN'],
            'manufacturer' => ['label' => 'Hersteller'],
            'model' => ['label' => 'Modell'],
            'first_registration' => ['label' => 'Erstzulassung', 'format' => [$this, 'fmtDate']],
            'has_teilkasko' => ['label' => 'Teilkasko'],
            'teilkasko_deductible' => ['label' => 'SB Teilkasko', 'format' => [$this, 'fmtDeductible']],
            'has_vollkasko' => ['label' => 'Vollkasko'],
            'vollkasko_deductible' => ['label' => 'SB Vollkasko', 'format' => [$this, 'fmtDeductible']],
            'holder_type' => ['label' => 'Halter'],
            'annual_mileage' => ['label' => 'Jahresfahrleistung', 'format' => [$this, 'fmtKm']],
            'sf_liability_class' => ['label' => 'SF-Klasse Haftpflicht'],
            'sf_comprehensive_class' => ['label' => 'SF-Klasse Vollkasko'],
            'previous_insurer' => ['label' => 'Vorversicherer'],
            'previous_contract_number' => ['label' => 'Vertragsnummer Vorversicherer'],
            'extras' => ['label' => 'Zusatzleistungen', 'format' => [$this, 'fmtExtras']],
        ];
    }

    /** Anzeige-Spezifikation der Energie-Detailfelder. */
    private function energyRevisionSpec(): array
    {
        return [
            'tariff' => ['label' => 'Tarif'],
            'consumption_kwh' => ['label' => 'Verbrauch', 'format' => [$this, 'fmtKwh']],
            'meter_number' => ['label' => 'Zaehlernummer'],
            'malo_id' => ['label' => 'MaLo-ID'],
            'meter_reading' => ['label' => 'Zaehlerstand'],
            'customer_number' => ['label' => 'Kundennummer (Anbieter)'],
            'grid_operator' => ['label' => 'Netzbetreiber'],
            'working_price' => ['label' => 'Arbeitspreis', 'format' => [$this, 'fmtCent']],
            'base_price' => ['label' => 'Grundpreis', 'format' => [$this, 'fmtEuroMonth']],
            'payment_amount' => ['label' => 'Abschlag', 'format' => [$this, 'fmtEuro']],
            'payment_interval' => ['label' => 'Zahlweise Abschlag', 'format' => [$this, 'fmtInterval']],
            'previous_provider' => ['label' => 'Vorversorger'],
            'previous_customer_number' => ['label' => 'Kundennummer Vorversorger'],
        ];
    }

    /** Anzeige-Spezifikation der Internet-/DSL-Detailfelder. */
    private function internetRevisionSpec(): array
    {
        return [
            'tariff' => ['label' => 'Tarif'],
            'speed' => ['label' => 'Geschwindigkeit'],
            'upload_speed' => ['label' => 'Upload'],
            'price_initial' => ['label' => 'Aktionspreis', 'format' => [$this, 'fmtEuro']],
            'price_initial_months' => ['label' => 'Aktion (Monate)'],
            'price_regular' => ['label' => 'Preis danach', 'format' => [$this, 'fmtEuro']],
            'has_router' => ['label' => 'Router'],
            'router_name' => ['label' => 'Router-Modell'],
            'router_price' => ['label' => 'Router-Aufpreis', 'format' => [$this, 'fmtEuro']],
            'bonus_amount' => ['label' => 'Bonus/Cashback', 'format' => [$this, 'fmtEuro']],
            'voucher_amount' => ['label' => 'Gutschein', 'format' => [$this, 'fmtEuro']],
        ];
    }

    public function fmtEuro($v): string
    {
        return number_format((float) $v, 2, ',', '.') . ' €';
    }

    public function fmtDate($v): string
    {
        try {
            return \Carbon\Carbon::parse($v)->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $v;
        }
    }

    public function fmtInterval($v): string
    {
        return Contract::PREMIUM_INTERVALS[$v]['label'] ?? (string) $v;
    }

    /** Vertragsstufe fuer die Version History ("Antrag" -> "Vertrag bestätigt"). */
    public function fmtStage($v): string
    {
        return Contract::STAGE_LABELS[$v] ?? (string) $v;
    }

    public function fmtCent($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',') . ' ct/kWh';
    }

    public function fmtEuroMonth($v): string
    {
        return number_format((float) $v, 2, ',', '.') . ' €/Monat';
    }

    public function fmtDeductible($v): string
    {
        return ContractVehicleDetail::deductibleLabel((int) $v);
    }

    public function fmtKm($v): string
    {
        return number_format((int) $v, 0, ',', '.') . ' km';
    }

    public function fmtKwh($v): string
    {
        return number_format((int) $v, 0, ',', '.') . ' kWh';
    }

    public function fmtExtras($v): string
    {
        $keys = (array) $v;
        $labels = array_values(array_intersect_key(ContractVehicleDetail::EXTRAS, array_flip($keys)));
        return implode(', ', $labels ?: $keys);
    }
}
