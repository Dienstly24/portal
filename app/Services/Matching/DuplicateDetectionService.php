<?php
namespace App\Services\Matching;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

/**
 * Systemweiter Dubletten-Abgleich: prueft den bestehenden Kundenbestand
 * gegen sich selbst und liefert Verdachtspaare (Name, Geburtsdatum,
 * E-Mail, Adresse, Telefon) zur manuellen Pruefung. Nutzt exakt dieselbe
 * score-basierte Logik wie der Import-Abgleich (CustomerMatchingService) -
 * kein zweites Regelwerk, das aus dem Takt laufen koennte.
 *
 * Bewusst on-demand (kein Cron): Der Abgleich laeuft, wenn ein Mitarbeiter
 * die Dubletten-Seite oeffnet. Nur die reine Trefferzahl wird kurz
 * gecacht (Badge in der Kundenliste), damit nicht jede Seitenansicht den
 * kompletten Bestand scannt.
 */
class DuplicateDetectionService
{
    /** Ab diesem Score gilt ein Paar als Verdachtsfall (>= confirm-Stufe). */
    public const DEFAULT_THRESHOLD = 70;

    /** Sicherheitsdeckel gegen Volltabellen-Scans bei sehr grossen Bestaenden. */
    private const MAX_SCAN = 1500;

    public function __construct(private readonly CustomerMatchingService $matcher)
    {
    }

    /**
     * Findet Verdachtspaare im (optional eingeschraenkten) Kundenbestand.
     *
     * @param ?array<int, string> $visibleIds  Nur diese Kunden pruefen
     *        (Portfolio-Sicht der Mitarbeiter); null = alle.
     * @return array{pairs: array<int, array{primary: Customer, duplicate: Customer, score: int, tier: string, signals: array<int, string>}>, scanned: int, capped: bool}
     */
    public function scan(?array $visibleIds = null, int $threshold = self::DEFAULT_THRESHOLD): array
    {
        $query = Customer::query()->with(['user', 'addresses'])->latest('created_at');
        if ($visibleIds !== null) {
            $query->whereIn('id', $visibleIds);
        }

        $customers = $query->limit(self::MAX_SCAN)->get();
        $capped = $customers->count() >= self::MAX_SCAN;

        $byId = $customers->keyBy(fn ($c) => (string) $c->id);

        $pairs = [];
        $seen = [];

        foreach ($customers as $customer) {
            $result = $this->matcher->matchExisting($customer);
            if (!$result->hasMatch() || $result->score < $threshold) {
                continue;
            }

            $other = $result->customer;
            // Der Treffer stammt aus der Datenbank und liegt evtl. ausserhalb
            // der Portfolio-Sicht - dann kein Paar bilden (kein Datenleck).
            if ($visibleIds !== null && !$byId->has((string) $other->id)) {
                continue;
            }

            $key = $this->pairKey((string) $customer->id, (string) $other->id);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // Aelterer Datensatz bleibt Hauptkunde (die urspruengliche Akte),
            // der neuere wird als Duplikat zur Pruefung vorgeschlagen.
            [$primary, $duplicate] = $this->orderByAge($customer, $byId->get((string) $other->id, $other));

            $pairs[] = [
                'primary'   => $primary,
                'duplicate' => $duplicate,
                'score'     => $result->score,
                'tier'      => $result->tier(),
                'signals'   => $this->signals($result),
            ];
        }

        usort($pairs, fn ($a, $b) => $b['score'] <=> $a['score']);

        return ['pairs' => $pairs, 'scanned' => $customers->count(), 'capped' => $capped];
    }

    /**
     * Kurz gecachte Trefferzahl fuer den Hinweis-Badge in der Kundenliste.
     * Cache-Key ist an die Portfolio-Sicht gebunden, damit Mitarbeiter nur
     * ihre eigenen Verdachtsfaelle gezaehlt bekommen.
     */
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

    /** @return array{0: Customer, 1: Customer} aelterer Datensatz zuerst. */
    private function orderByAge(Customer $a, Customer $b): array
    {
        return $a->created_at <= $b->created_at ? [$a, $b] : [$b, $a];
    }

    /** Reihenfolge-unabhaengiger Schluessel fuer ein Kundenpaar. */
    private function pairKey(string $a, string $b): string
    {
        return $a < $b ? "$a|$b" : "$b|$a";
    }

    /** Lesbare Liste der uebereinstimmenden Signale (fuer die Pruef-Ansicht). */
    private function signals(MatchResult $result): array
    {
        $out = [];
        foreach ($result->breakdown as $part) {
            if (($part['points'] ?? 0) > 0) {
                $out[] = $part['reason'];
            }
        }
        return $out;
    }
}
