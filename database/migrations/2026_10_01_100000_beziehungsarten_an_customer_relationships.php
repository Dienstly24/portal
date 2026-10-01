<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Allgemeine Kundenbeziehungen statt nur "Ehepaar" (Betreiber-Auftrag
 * 01.10.2026).
 *
 * VOR DEM PRODUKTIONSLAUF (Betreiber-Vorgabe):
 *  1. Sicherung ziehen: `bash scripts/backup.sh` und pruefen, dass die
 *     Statusdatei "ok" meldet.
 *  2. Probelauf auf einer KOPIE der Produktionsdaten: Sicherung mit
 *     `scripts/restore.sh --pruefen` in eine Wegwerf-Datenbank spielen,
 *     dort `php artisan migrate` ausfuehren und die Zaehlung im Log
 *     (`customer_relationships Migration: ...`) gegenpruefen - vor allem
 *     die Zahl der Konflikte.
 *  3. Erst danach auf dem Server migrieren.
 *
 * Bewusst KEINE neue Tabelle: `customer_relationships` IST bereits die Stelle,
 * an der "dieses Paar ist keine Dublette" steht, und die Dubletten-Pruefung
 * schliesst ueber sie aus. Eine zweite Tabelle waere eine zweite Wahrheit.
 *
 * SCHEMA
 *  - `parent_customer_id`: nur bei `elternteil_kind` gesetzt und dann gleich
 *    customer_a_id ODER customer_b_id. Das Paar bleibt sortiert (a < b), die
 *    RICHTUNG steht ausdruecklich in dieser Spalte.
 *    Die Regel ist KEIN CHECK-Constraint: MySQL verbietet CHECK auf Spalten,
 *    die eine Fremdschluessel-Aktion tragen (customer_a_id/_b_id kaskadieren,
 *    Fehler 3823). Durchgesetzt wird sie im Modell (saving-Hook, jeder
 *    Eloquent-Schreibweg) und im CustomerMergeService (DB::table-Weg).
 *  - UNIQUE (a, b) -> UNIQUE (a, b, type): ein Paar kann z. B. Geschwister
 *    UND gleicher Haushalt sein. Der neue Index entsteht VOR dem Loeschen des
 *    alten - MySQL braucht fuer den Fremdschluessel auf customer_a_id immer
 *    einen Index mit dieser Spalte vorn.
 *
 * DATEN (nichts wird geloescht, keine Familienrolle wird angelegt)
 *  - spouse    -> ehepartner. Bewusst OHNE Familienrolle: "Ehepaar" war bis
 *                 hierher die einzige Auswahl fuer "verwandt", ein Teil
 *                 dieser Markierungen ist deshalb ungenau. Sie gelten als
 *                 UNBESTAETIGT (Filter auf "Verwandte Kunden"); die Rolle
 *                 entsteht erst beim Bestaetigen oder Bearbeiten.
 *  - household -> gleicher_haushalt
 *  - family    -> aus customer_family_relations abgeleitet (elternteil_kind
 *                 MIT Richtung, geschwister, ehepartner, sonstige_verwandte);
 *                 ohne Familienrolle: sonstige_verwandte
 *  - not_duplicate bleibt - ausser fuer das Paar existiert eine
 *    Familienrolle, dann wird es die abgeleitete Art (praeziser, gleiche
 *    Ausschlusswirkung).
 *  - Konflikt "spouse, aber Familienrolle ist z. B. Vater": die Ehepaar-
 *    Markierung bleibt (Vorgabe: ohne Verlust), Anzahl und Paar-IDs (keine
 *    Namen) stehen im Log.
 *  - Paar mit Familienrolle, aber ohne Zeile hier: Zeile wird ergaenzt
 *    (household + Familienrolle ergibt damit zwei Zeilen).
 *  - Gezaehlt wird je Art VORHER und NACHHER, dazu ergaenzte Zeilen und
 *    Konflikte - im Log und, bei `php artisan migrate`, auf der Konsole.
 *
 * GRENZEN VON down() - es schlaegt lieber fehl, als still Daten zu verlieren:
 *  - mehr als eine Zeile je Paar          -> Abbruch (alter UNIQUE (a, b))
 *  - Arten nachbar / sonstiges            -> Abbruch (gab es vorher nicht)
 *  - elternteil_kind / geschwister OHNE passende Familienrolle -> Abbruch
 *    (Richtung bzw. Art waere verloren; MIT Familienrolle bleibt sie dort
 *    erhalten und die Zeile wird zu "family")
 *  - Familienrollen, die NACH der Migration ueber die Oberflaeche entstanden
 *    sind, bleiben stehen - sie sind gueltige Daten, der alte Code liest sie.
 *  Geprueft wird VOLLSTAENDIG, bevor irgendetwas geaendert wird.
 */
return new class extends Migration {
    private const CHILD_ROLES = ['sohn', 'tochter', 'kind'];

    private const PARENT_ROLES = ['vater', 'mutter', 'elternteil'];

    private const OLD_TYPES = ['not_duplicate', 'spouse', 'family', 'household'];

    private const FAMILY_TYPES = ['ehepartner', 'elternteil_kind', 'geschwister', 'sonstige_verwandte'];

    public function up(): void
    {
        // Vorabpruefung VOR jeder Schemaaenderung: eine unbekannte Art wird
        // nie geraten.
        $unbekannt = DB::table('customer_relationships')->whereNotIn('type', self::OLD_TYPES)
            ->distinct()->pluck('type')->all();
        if ($unbekannt !== []) {
            throw new RuntimeException('customer_relationships enthaelt unbekannte Arten: '
                .implode(', ', $unbekannt).' - Migration abgebrochen, nichts geaendert.');
        }

        Schema::table('customer_relationships', function (Blueprint $table) {
            $table->foreignUuid('parent_customer_id')->nullable()->after('type')
                ->constrained('customers')->cascadeOnDelete();
            $table->unique(['customer_a_id', 'customer_b_id', 'type'], 'cust_rel_pair_type_unique');
        });
        Schema::table('customer_relationships', function (Blueprint $table) {
            $table->dropUnique(['customer_a_id', 'customer_b_id']);
        });

        $bericht = DB::transaction(fn () => $this->konvertiereDaten());
        $this->melde($bericht);
    }

    public function down(): void
    {
        $fehler = $this->pruefeRueckbau();
        if ($fehler !== []) {
            throw new RuntimeException('Rueckbau abgebrochen, nichts geaendert - sonst gingen Daten verloren: '
                .implode('; ', $fehler).'.');
        }

        DB::transaction(function () {
            foreach ([
                'ehepartner' => 'spouse',
                'gleicher_haushalt' => 'household',
                'sonstige_verwandte' => 'family',
                'elternteil_kind' => 'family',
                'geschwister' => 'family',
            ] as $neu => $alt) {
                DB::table('customer_relationships')->where('type', $neu)
                    ->update(['type' => $alt, 'parent_customer_id' => null]);
            }
        });

        Schema::table('customer_relationships', function (Blueprint $table) {
            $table->unique(['customer_a_id', 'customer_b_id']);
        });
        Schema::table('customer_relationships', function (Blueprint $table) {
            $table->dropUnique('cust_rel_pair_type_unique');
            $table->dropForeign(['parent_customer_id']);
            $table->dropColumn('parent_customer_id');
        });
    }

    /**
     * Datenteil von up(). Oeffentlich, damit ein Test die Umsetzung pruefen
     * kann, ohne das Schema anzufassen (DDL beendet auf MySQL die
     * Test-Transaktion).
     *
     * @return array{vorher: array<string, int>, nachher: array<string, int>, ergaenzt: int, konflikte: list<string>}
     */
    public function konvertiereDaten(): array
    {
        $vorher = $this->zaehleJeArt();
        $familie = $this->familienArtenJePaar();
        $jetzt = now();
        $konflikte = [];
        $ergaenzt = 0;

        foreach (DB::table('customer_relationships')->whereIn('type', self::OLD_TYPES)->orderBy('id')->get() as $row) {
            $key = $row->customer_a_id.'|'.$row->customer_b_id;
            $abgeleitet = $familie[$key] ?? null;

            if ($row->type === 'household') {
                DB::table('customer_relationships')->where('id', $row->id)
                    ->update(['type' => 'gleicher_haushalt', 'parent_customer_id' => null]);
                continue;
            }

            if ($row->type === 'spouse') {
                if ($abgeleitet && $abgeleitet['type'] !== 'ehepartner') {
                    $konflikte[] = $key;
                }
                DB::table('customer_relationships')->where('id', $row->id)
                    ->update(['type' => 'ehepartner', 'parent_customer_id' => null]);
                continue;
            }

            // family / not_duplicate
            $ziel = $abgeleitet ?? [
                'type' => $row->type === 'family' ? 'sonstige_verwandte' : 'not_duplicate',
                'parent' => null,
            ];
            DB::table('customer_relationships')->where('id', $row->id)
                ->update(['type' => $ziel['type'], 'parent_customer_id' => $ziel['parent']]);
        }

        // Familienrolle ohne Familien-Zeile hier -> ergaenzen.
        foreach ($familie as $key => $art) {
            [$a, $b] = explode('|', $key);
            $vorhanden = DB::table('customer_relationships')
                ->where('customer_a_id', $a)->where('customer_b_id', $b)
                ->whereIn('type', self::FAMILY_TYPES)
                ->exists();
            if ($vorhanden) {
                continue;
            }
            // Ein "Kein Duplikat" desselben Paares ist durch die praezisere
            // Art ueberholt (bei household bleibt der Haushalt stehen).
            DB::table('customer_relationships')->where('customer_a_id', $a)->where('customer_b_id', $b)
                ->where('type', 'not_duplicate')->delete();
            DB::table('customer_relationships')->insert([
                'id' => (string) Str::uuid(),
                'customer_a_id' => $a,
                'customer_b_id' => $b,
                'type' => $art['type'],
                'parent_customer_id' => $art['parent'],
                'note' => 'Familienzuordnung',
                'created_by' => $art['created_by'],
                'created_at' => $jetzt,
                'updated_at' => $jetzt,
            ]);
            $ergaenzt++;
        }

        return [
            'vorher' => $vorher,
            'nachher' => $this->zaehleJeArt(),
            'ergaenzt' => $ergaenzt,
            'konflikte' => $konflikte,
        ];
    }

    /**
     * Vorabpruefung von down(). Leere Liste = Rueckbau ohne Datenverlust
     * moeglich.
     *
     * @return list<string>
     */
    public function pruefeRueckbau(): array
    {
        $familie = $this->familienArtenJePaar();
        $fehler = [];

        $mehrfach = DB::table('customer_relationships')
            ->select('customer_a_id', 'customer_b_id')
            ->groupBy('customer_a_id', 'customer_b_id')
            ->havingRaw('COUNT(*) > 1')->get()->count();
        if ($mehrfach > 0) {
            $fehler[] = $mehrfach.' Paar(e) mit mehreren Beziehungsarten';
        }
        $neu = DB::table('customer_relationships')->whereIn('type', ['nachbar', 'sonstiges'])->count();
        if ($neu > 0) {
            $fehler[] = $neu.' Beziehung(en) der Art Nachbar/Sonstiges';
        }
        $ohneRolle = DB::table('customer_relationships')
            ->whereIn('type', ['elternteil_kind', 'geschwister'])->get()
            ->filter(fn ($r) => ($familie[$r->customer_a_id.'|'.$r->customer_b_id]['type'] ?? null) !== $r->type)
            ->count();
        if ($ohneRolle > 0) {
            $fehler[] = $ohneRolle.' Elternteil-Kind-/Geschwister-Beziehung(en) ohne passende Familienrolle';
        }

        return $fehler;
    }

    /** @return array<string, int> */
    private function zaehleJeArt(): array
    {
        return DB::table('customer_relationships')
            ->select('type', DB::raw('COUNT(*) as anzahl'))
            ->groupBy('type')->orderBy('type')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->type => (int) $r->anzahl])
            ->all();
    }

    /** @param array{vorher: array<string, int>, nachher: array<string, int>, ergaenzt: int, konflikte: list<string>} $bericht */
    private function melde(array $bericht): void
    {
        $text = sprintf(
            'customer_relationships Migration: vorher %s | nachher %s | ergaenzt %d | Konflikte %d',
            json_encode($bericht['vorher']),
            json_encode($bericht['nachher']),
            $bericht['ergaenzt'],
            count($bericht['konflikte'])
        );
        Log::info($text);
        if ($bericht['konflikte'] !== []) {
            Log::warning('customer_relationships: Ehepaar-Markierung widerspricht der Familienrolle - '
                .'Ehepaar beibehalten, bitte pruefen.', ['paare' => $bericht['konflikte']]);
        }
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            fwrite(STDOUT, '  '.$text.PHP_EOL);
        }
    }

    /**
     * Beziehungsart je sortiertem Paar aus customer_family_relations.
     * Zeile (X, Y, rolle) heisst "Y ist <rolle> von X".
     *
     * @return array<string, array{type: string, parent: ?string, created_by: mixed}>
     */
    private function familienArtenJePaar(): array
    {
        $out = [];
        $rows = DB::table('customer_family_relations')
            ->orderBy('customer_id')->orderBy('related_customer_id')->get();
        foreach ($rows as $r) {
            $x = (string) $r->customer_id;
            $y = (string) $r->related_customer_id;
            $key = $x < $y ? $x.'|'.$y : $y.'|'.$x;
            if (isset($out[$key])) {
                continue;
            }
            $role = (string) $r->relationship_type;
            $out[$key] = match (true) {
                in_array($role, self::CHILD_ROLES, true) => ['type' => 'elternteil_kind', 'parent' => $x],
                in_array($role, self::PARENT_ROLES, true) => ['type' => 'elternteil_kind', 'parent' => $y],
                $role === 'ehepartner' => ['type' => 'ehepartner', 'parent' => null],
                $role === 'geschwister' => ['type' => 'geschwister', 'parent' => null],
                default => ['type' => 'sonstige_verwandte', 'parent' => null],
            } + ['created_by' => $r->created_by];
        }

        return $out;
    }
};
