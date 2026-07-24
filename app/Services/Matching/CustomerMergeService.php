<?php
namespace App\Services\Matching;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerTimeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fuehrt zwei Kundenakten verlustfrei zusammen. Der Duplikat-Datensatz wird
 * geloescht, ABER erst nachdem ALLE abhaengigen Daten auf den Hauptkunden
 * umgehaengt wurden - Vertraege, Dokumente, Tickets, Termine, Notizen,
 * Familie, Fahrzeuge, Nachrichten, Einwilligungen (DSGVO),
 * Dokumentanfragen, Aufgaben, E-Mail-Zuordnungen und externe Kennungen.
 *
 * Hintergrund: Fast alle customer_id-Fremdschluessel stehen auf
 * ON DELETE CASCADE. Ein simples "Duplikat loeschen" wuerde daher genau die
 * Daten mitreissen, die NICHT vorher umgehaengt wurden. Diese Klasse haengt
 * deshalb JEDE Tabelle mit einer customer_id-Spalte um (per Schema-Abgleich,
 * damit auch kuenftige Tabellen automatisch abgedeckt sind) plus die
 * polymorphen externen Referenzen. Erst danach faellt die leere Duplikat-
 * Huelle weg. Nichts wird geloescht ausser dem leeren Duplikat selbst.
 */
class CustomerMergeService
{
    /**
     * Sonderfaelle, die nicht ueber den generischen customer_id-Abgleich
     * laufen (eigene Dedup-/Kollisionslogik).
     */
    private const PIVOT_TABLE = 'employee_customers';

    public function __construct(private readonly ?DuplicateDetectionService $detection = null)
    {
    }

    /**
     * @return array<string, int> Zusammenfassung: umgehaengte Datensaetze je Tabelle.
     * @throws \InvalidArgumentException bei ungueltigen Eingaben (Selbst-Merge,
     *         Nicht-Kunden-Account) - Schutz analog CustomerDeletionService.
     */
    public function merge(Customer $primary, Customer $duplicate, ?int $actorId = null): array
    {
        if ((string) $primary->id === (string) $duplicate->id) {
            throw new \InvalidArgumentException('Haupt- und Duplikat-Kunde sind identisch.');
        }
        // Schutz: niemals Mitarbeiter-/Partner-Accounts ueber den Merge anfassen.
        if ($primary->user && $primary->user->role !== 'customer') {
            throw new \InvalidArgumentException('Hauptkunde ist kein Kundenkonto.');
        }
        if ($duplicate->user && $duplicate->user->role !== 'customer') {
            throw new \InvalidArgumentException('Duplikat ist kein Kundenkonto.');
        }

        return DB::transaction(function () use ($primary, $duplicate, $actorId) {
            $moved = [];

            // 1) Jede Tabelle mit customer_id-Spalte umhaengen (inkl. Pivot).
            foreach ($this->customerIdTables() as $table) {
                if ($table === self::PIVOT_TABLE) {
                    continue; // eigene Dedup-Logik unten
                }
                $count = $this->moveCustomerIdRows($table, $primary, $duplicate);
                if ($count > 0) {
                    $moved[$table] = $count;
                }
            }

            // 2) Betreuer-Zuordnung (Pivot) umhaengen + doppelte Zuordnung entfernen.
            $moved[self::PIVOT_TABLE] = $this->mergePivot($primary, $duplicate);

            // 3) Polymorphe externe Kennungen (Lexoffice/Fonds-Finanz) umhaengen.
            $moved['external_references'] = $this->mergeExternalReferences($primary, $duplicate);

            // 4) Fehlende Stammdaten vom Duplikat ergaenzen (nie ueberschreiben).
            $this->fillMissingFields($primary, $duplicate);
            $primary->save();

            // 5) Leere Duplikat-Huelle + verwaisten User entfernen.
            $dupName = $duplicate->user?->name;
            $dupUser = $duplicate->user;
            $duplicate->delete();
            if ($dupUser && $dupUser->id !== $primary->user_id) {
                $dupUser->delete();
            }

            $moved = array_filter($moved, fn ($n) => $n > 0);

            // 6) Protokoll: Audit-Log + Kunden-Timeline (nachvollziehbar).
            ActivityLog::create([
                'user_id'     => $actorId,
                'action'      => 'customers_merged',
                'entity_type' => 'customer',
                'entity_id'   => $primary->id,
                'meta'        => json_encode([
                    'merged_from' => $dupName,
                    'into'        => $primary->user?->name,
                    'moved'       => $moved,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            if (Schema::hasTable('customer_timeline')) {
                CustomerTimeline::create([
                    'customer_id' => $primary->id,
                    'user_id'     => $actorId,
                    'type'        => 'merge',
                    'title'       => 'Kunde zusammengefuehrt',
                    'description' => 'Duplikat "' . ($dupName ?? 'Unbekannt') . '" wurde in diese Akte uebernommen.',
                    'meta'        => ['moved' => $moved],
                ]);
            }

            $this->detection?->forgetCount();

            return $moved;
        });
    }

    /**
     * Vorschau, WAS ein Merge umhaengen wuerde - ohne etwas zu veraendern.
     * Grundlage fuer die Bestaetigungsansicht ("nichts geht verloren").
     *
     * @return array<string, int>
     */
    public function preview(Customer $duplicate): array
    {
        $counts = [];
        foreach ($this->customerIdTables() as $table) {
            $n = DB::table($table)->where('customer_id', $duplicate->id)->count();
            if ($n > 0) {
                $counts[$table] = $n;
            }
        }
        $refs = DB::table('external_references')
            ->where('referenceable_type', Customer::class)
            ->where('referenceable_id', $duplicate->id)->count();
        if ($refs > 0) {
            $counts['external_references'] = $refs;
        }
        return $counts;
    }

    /**
     * Haengt alle Zeilen einer customer_id-Tabelle vom Duplikat auf den
     * Hauptkunden um. Hat die Tabelle einen UNIQUE-Index, der customer_id
     * einschliesst (z. B. customer_views / favorite_customers mit
     * unique(user_id, customer_id)), wuerde ein blindes UPDATE genau dann eine
     * Integritaetsverletzung (-> HTTP 500) ausloesen, wenn derselbe
     * Schluessel am Hauptkunden bereits existiert. Genau das passiert im
     * Normalfall: der Bearbeiter oeffnet erst beide Akten (customer_views),
     * bevor er sie zusammenfuehrt. Deshalb werden kollidierende Duplikat-
     * Zeilen vor dem Umhaengen verworfen.
     *
     * @return int Anzahl tatsaechlich umgehaengter Zeilen.
     */
    private function moveCustomerIdRows(string $table, Customer $primary, Customer $duplicate): int
    {
        foreach ($this->uniquePeerColumns($table) as $peers) {
            $this->deleteCollidingDuplicateRows($table, $primary, $duplicate, $peers);
        }

        return DB::table($table)
            ->where('customer_id', $duplicate->id)
            ->update(['customer_id' => $primary->id]);
    }

    /**
     * Entfernt Duplikat-Zeilen, die beim Umhaengen mit einer bereits am
     * Hauptkunden vorhandenen Zeile auf demselben UNIQUE-Schluessel kollidieren
     * wuerden. `$peers` sind die uebrigen Spalten des UNIQUE-Index (ohne
     * customer_id). NULL-Werte gelten in SQL als verschieden und kollidieren
     * daher nie - solche Zeilen bleiben erhalten und werden normal umgehaengt.
     *
     * @param array<int, string> $peers
     */
    private function deleteCollidingDuplicateRows(string $table, Customer $primary, Customer $duplicate, array $peers): void
    {
        $primaryRows = DB::table($table)->where('customer_id', $primary->id)->get();
        if ($primaryRows->isEmpty()) {
            return;
        }

        // Reine unique(customer_id)-Tabelle (keine weiteren Schluesselspalten):
        // existiert am Hauptkunden schon eine Zeile, ist jede Duplikat-Zeile
        // ein Konflikt und wird verworfen.
        if ($peers === []) {
            DB::table($table)->where('customer_id', $duplicate->id)->delete();
            return;
        }

        DB::table($table)
            ->where('customer_id', $duplicate->id)
            ->where(function ($q) use ($primaryRows, $peers) {
                foreach ($primaryRows as $row) {
                    // NULL-Peers koennen laut UNIQUE-Semantik nicht kollidieren.
                    if (array_filter($peers, fn ($c) => $row->$c === null) !== []) {
                        continue;
                    }
                    $q->orWhere(function ($qq) use ($row, $peers) {
                        foreach ($peers as $col) {
                            $qq->where($col, $row->$col);
                        }
                    });
                }
            })
            ->delete();
    }

    /**
     * Spalten (ohne customer_id) aller UNIQUE-Indizes einer Tabelle, die
     * customer_id einschliessen. Ergebnis wird pro Request und Tabelle
     * gecacht (Bulk-Merge ruft dies je Paar auf).
     *
     * @return array<int, array<int, string>>
     */
    private function uniquePeerColumns(string $table): array
    {
        if (isset(self::$uniquePeerCache[$table])) {
            return self::$uniquePeerCache[$table];
        }

        $result = [];
        foreach (Schema::getIndexes($table) as $index) {
            $columns = $index['columns'] ?? [];
            if (($index['unique'] ?? false) && in_array('customer_id', $columns, true)) {
                $result[] = array_values(array_filter($columns, fn ($c) => $c !== 'customer_id'));
            }
        }

        return self::$uniquePeerCache[$table] = $result;
    }

    /** Betreuer-Zuordnungen umhaengen, danach doppelte (user_id) entfernen. */
    private function mergePivot(Customer $primary, Customer $duplicate): int
    {
        if (!Schema::hasTable(self::PIVOT_TABLE)) {
            return 0;
        }

        $existing = DB::table(self::PIVOT_TABLE)
            ->where('customer_id', $primary->id)
            ->pluck('user_id')->all();

        $moved = 0;
        $dupRows = DB::table(self::PIVOT_TABLE)->where('customer_id', $duplicate->id)->get();
        foreach ($dupRows as $row) {
            if (in_array($row->user_id, $existing, false)) {
                // Betreuer bereits am Hauptkunden - doppelte Zeile verwerfen.
                DB::table(self::PIVOT_TABLE)->where('id', $row->id)->delete();
                continue;
            }
            DB::table(self::PIVOT_TABLE)->where('id', $row->id)->update(['customer_id' => $primary->id]);
            $existing[] = $row->user_id;
            $moved++;
        }
        return $moved;
    }

    /** Externe Kennungen umhaengen; bereits vorhandene (type+value) nicht doppeln. */
    private function mergeExternalReferences(Customer $primary, Customer $duplicate): int
    {
        if (!Schema::hasTable('external_references')) {
            return 0;
        }

        $primaryKeys = DB::table('external_references')
            ->where('referenceable_type', Customer::class)
            ->where('referenceable_id', $primary->id)
            ->get(['type', 'value'])
            ->map(fn ($r) => $r->type . '|' . $r->value)->all();

        $moved = 0;
        $dupRefs = DB::table('external_references')
            ->where('referenceable_type', Customer::class)
            ->where('referenceable_id', $duplicate->id)->get();
        foreach ($dupRefs as $ref) {
            if (in_array($ref->type . '|' . $ref->value, $primaryKeys, true)) {
                DB::table('external_references')->where('id', $ref->id)->delete();
                continue;
            }
            DB::table('external_references')->where('id', $ref->id)->update(['referenceable_id' => $primary->id]);
            $moved++;
        }
        return $moved;
    }

    /** Leere Stammdatenfelder des Hauptkunden aus dem Duplikat ergaenzen. */
    private function fillMissingFields(Customer $primary, Customer $duplicate): void
    {
        $fields = [
            'phone', 'mobile', 'address', 'address2', 'iban', 'iban2', 'birth_date',
            'marital_status', 'nationality', 'occupation', 'email2', 'company_name',
            'company_type', 'customer_type', 'gender', 'birth_place',
            'address_street', 'address_house_number', 'address_house_suffix',
            'address_zip', 'address_city', 'health_insurance_number',
            'health_insurance_company', 'health_insurance_type',
            'pension_insurance_number', 'tax_id',
        ];
        foreach ($fields as $f) {
            if (empty($primary->$f) && !empty($duplicate->$f)) {
                $primary->$f = $duplicate->$f;
            }
        }
    }

    /** @var array<int, string>|null Schema-Abgleich einmal pro Request cachen. */
    private static ?array $customerIdTablesCache = null;

    /** @var array<string, array<int, array<int, string>>> UNIQUE-Peers je Tabelle (Request-Cache). */
    private static array $uniquePeerCache = [];

    /**
     * Alle Tabellen mit einer customer_id-Spalte (Schema-Abgleich). So sind
     * auch kuenftige Tabellen automatisch abgedeckt - kein hartkodiertes
     * Modell-Register, das beim naechsten Feature vergessen wird.
     *
     * Das Ergebnis wird pro Request gecacht: bei der Sammel-Zusammenfuehrung
     * vieler Paare wuerde sonst fuer JEDEN Merge das komplette Schema
     * abgefragt (getTables + hasColumn je Tabelle) - der teuerste Teil.
     *
     * @return array<int, string>
     */
    private function customerIdTables(): array
    {
        if (self::$customerIdTablesCache !== null) {
            return self::$customerIdTablesCache;
        }

        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = is_array($table) ? ($table['name'] ?? null) : ($table->name ?? null);
            if ($name && Schema::hasColumn($name, 'customer_id')) {
                $tables[] = $name;
            }
        }
        return self::$customerIdTablesCache = $tables;
    }
}
