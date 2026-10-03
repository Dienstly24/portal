<?php

namespace App\Services\Matching;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\CustomerTimeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fuehrt zwei Kundenakten verlustfrei zusammen. Der Duplikat-Datensatz wird
 * geloescht, ABER erst nachdem ALLE abhaengigen Daten auf den Hauptkunden
 * umgehaengt wurden - Vertraege, Dokumente, Tickets, Termine, Notizen,
 * Familie, Fahrzeuge, Nachrichten, Einwilligungen (DSGVO),
 * Dokumentanfragen, Aufgaben, E-Mail-Zuordnungen, externe Kennungen und
 * Verwandte-Kunden-Verknuepfungen (customer_a_id/customer_b_id).
 *
 * Hintergrund: Fast alle customer_id-Fremdschluessel stehen auf
 * ON DELETE CASCADE. Ein simples "Duplikat loeschen" wuerde daher genau die
 * Daten mitreissen, die NICHT vorher umgehaengt wurden. Diese Klasse haengt
 * deshalb JEDE Tabelle mit einer customer_id-Spalte um (per Schema-Abgleich,
 * damit auch kuenftige Tabellen automatisch abgedeckt sind) plus die
 * polymorphen externen Referenzen. Erst danach faellt die leere Duplikat-
 * Huelle weg. Nichts wird geloescht ausser dem leeren Duplikat selbst.
 *
 * PORTAL-ZUGANG (Lehre 06.08.2026): Name und Login-E-Mail liegen am User,
 * nicht am Kunden. Frueher wurde der User des Duplikats IMMER geloescht -
 * war der Hauptkunde ein Import-Rumpf (Platzhalter-E-Mail
 * @dienstly24.internal, kein Passwort) und das Duplikat der echte
 * Portal-Account, verlor der Kunde E-Mail, Passwort und Login-Historie.
 * Jetzt bleibt der besser gepflegte Account erhalten (echte E-Mail >
 * Platzhalter, gesetztes Passwort, erfolgte Logins), unabhaengig von der
 * Merge-Richtung; die Login-Adresse des unterlegenen Accounts wandert nach
 * email2. Zusaetzlich wirkt eine Marketing-Abmeldung des Duplikats fort
 * (DSGVO: Opt-out geht nie verloren).
 */
class CustomerMergeService
{
    /**
     * Sonderfaelle, die nicht ueber den generischen customer_id-Abgleich
     * laufen (eigene Dedup-/Kollisionslogik).
     */
    private const PIVOT_TABLE = 'employee_customers';

    /**
     * Familienrollen fuehren den Kunden in ZWEI Spalten (customer_id UND
     * related_customer_id). Der generische customer_id-Abgleich kennt nur
     * die erste - die Rueckrichtung fiel per Kaskade weg, und die Zeile
     * Duplikat->Hauptkunde wurde zum Selbst-Paar (KI-066).
     */
    private const FAMILY_TABLE = 'customer_family_relations';

    public function __construct(private readonly ?DuplicateDetectionService $detection = null)
    {
    }

    /**
     * Gruende, aus denen zwei Akten NICHT zusammengefuehrt werden duerfen,
     * ohne dass ein Admin es ausdruecklich und begruendet uebersteuert
     * (KI-063/KI-065). Die EINE Stelle dafuer - Einzel-, Sammel- und
     * Ein-Klick-Merge laufen alle hierueber, und `merge()` selbst
     * verweigert sich ohne Begruendung. Eine Pruefung nur in der
     * Oberflaeche haette jeder neue Aufrufweg wieder umgangen.
     *
     * @return list<string>
     */
    public function mergeBlockers(Customer $a, Customer $b): array
    {
        $gruende = $this->detection()->identityConflicts($a, $b);

        // Zwei Menschen, die sich beide schon im Portal angemeldet haben,
        // sind zwei Konten - ein Merge haette eines davon geloescht: Login,
        // Passwort, Zugang weg (KI-065).
        if ($this->hasActivePortalAccess($a->user) && $this->hasActivePortalAccess($b->user)
            && (int) $a->user->id !== (int) $b->user->id) {
            $gruende[] = 'Beide haben einen aktiven Portalzugang ('.$a->user->email.' / '.$b->user->email.')';
        }

        return $gruende;
    }

    /** Aktiver Portalzugang = echte Adresse, nicht deaktiviert, schon einmal angemeldet. */
    public function hasActivePortalAccess(?User $user): bool
    {
        return $user !== null
            && $user->hasRealEmail()
            && $user->first_login_at !== null
            && ! (isset($user->is_active) && ! $user->is_active);
    }

    private function detection(): DuplicateDetectionService
    {
        return $this->detection ?? app(DuplicateDetectionService::class);
    }

    /**
     * @param ?string $uebersteuertMit Begruendung des Admins, wenn
     *        `mergeBlockers()` etwas meldet. Ohne sie wird nichts angefasst.
     * @return array<string, int> Zusammenfassung: umgehaengte Datensaetze je Tabelle.
     * @throws \InvalidArgumentException bei ungueltigen Eingaben (Selbst-Merge,
     *         Nicht-Kunden-Account) - Schutz analog CustomerDeletionService.
     * @throws MergeBlockedException wenn Sperrgruende bestehen und keine
     *         Begruendung vorliegt.
     */
    public function merge(Customer $primary, Customer $duplicate, ?int $actorId = null, ?string $uebersteuertMit = null): array
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

        $sperren = $this->mergeBlockers($primary, $duplicate);
        if ($sperren !== [] && trim((string) $uebersteuertMit) === '') {
            throw new MergeBlockedException($sperren);
        }

        return DB::transaction(function () use ($primary, $duplicate, $actorId, $sperren, $uebersteuertMit) {
            $moved = [];

            // 1) Jede Tabelle mit customer_id-Spalte umhaengen (inkl. Pivot).
            foreach ($this->customerIdTables() as $table) {
                if ($table === self::PIVOT_TABLE || $table === self::FAMILY_TABLE) {
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

            // 3b) Verwandte-Kunden-Verknuepfungen (customer_a_id/customer_b_id)
            //     umhaengen - die laufen NICHT ueber den customer_id-Abgleich
            //     und wuerden sonst per FK-Kaskade mitgeloescht (Familie weg).
            $moved['customer_relationships'] = $this->mergeRelationships($primary, $duplicate);
            $moved[self::FAMILY_TABLE] = $this->mergeFamilyRelations($primary, $duplicate);

            // 4) Portal-Zugang sichern: der besser gepflegte Account bleibt.
            $dupName = $duplicate->user?->name;
            $dupNumber = $duplicate->customer_number;
            $userIdBefore = $primary->user_id;
            $loserUser = $this->preservePortalAccount($primary, $duplicate);

            // 5) Fehlende Stammdaten vom Duplikat ergaenzen (nie ueberschreiben).
            $this->fillMissingFields($primary, $duplicate);
            $primary->save();

            // 6) Leere Duplikat-Huelle entfernen. Den unterlegenen User nur
            //    dann, wenn KEINE Kundenakte mehr auf ihn zeigt -
            //    customers.user_id kaskadiert, ein verfruehtes Loeschen wuerde
            //    eine noch verknuepfte Akte mitreissen.
            $duplicate->delete();
            $portalDeaktiviert = false;
            if ($loserUser
                && (int) $loserUser->id !== (int) $primary->user_id
                && ! Customer::where('user_id', $loserUser->id)->exists()) {
                if ($this->hasActivePortalAccess($loserUser)) {
                    // Nur bei ausdruecklich uebersteuertem Merge erreichbar:
                    // ein benutzter Zugang wird NIE geloescht, sondern
                    // stillgelegt - ein Irrtum laesst sich dann noch beheben.
                    $loserUser->forceFill(['is_active' => false])->save();
                    $portalDeaktiviert = true;
                } else {
                    $loserUser->delete();
                }
            }

            $moved = array_filter($moved, fn ($n) => $n > 0);

            // 7) Protokoll: Audit-Log + Kunden-Timeline (nachvollziehbar).
            ActivityLog::create([
                'user_id' => $actorId,
                'action' => 'customers_merged',
                'entity_type' => 'customer',
                'entity_id' => $primary->id,
                'meta' => json_encode([
                    'merged_from' => $dupName,
                    'merged_from_number' => $dupNumber,
                    'into' => $primary->user?->name,
                    'into_number' => $primary->customer_number,
                    // Nachvollziehbar, welcher Portal-Zugang ueberlebt hat.
                    'portal_account' => (int) $primary->user_id === (int) $userIdBefore ? 'hauptkunde' : 'duplikat',
                    'portal_account_deaktiviert' => $portalDeaktiviert,
                    'uebersteuert' => $sperren === [] ? null : ['gruende' => $sperren, 'begruendung' => $uebersteuertMit],
                    'moved' => $moved,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            if (Schema::hasTable('customer_timeline')) {
                CustomerTimeline::create([
                    'customer_id' => $primary->id,
                    'user_id' => $actorId,
                    'type' => 'merge',
                    'title' => 'Kunde zusammengefuehrt',
                    'description' => 'Duplikat "'.($dupName ?? 'Unbekannt').'" (Kundennummer '.($dupNumber ?? '-').') wurde in diese Akte uebernommen.',
                    'meta' => ['moved' => $moved],
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
        if (Schema::hasTable(self::FAMILY_TABLE)) {
            $fam = DB::table(self::FAMILY_TABLE)->where('related_customer_id', $duplicate->id)->count();
            if ($fam > 0) {
                $counts[self::FAMILY_TABLE] = ($counts[self::FAMILY_TABLE] ?? 0) + $fam;
            }
        }
        if (Schema::hasTable('customer_relationships')) {
            $rels = DB::table('customer_relationships')
                ->where('customer_a_id', $duplicate->id)
                ->orWhere('customer_b_id', $duplicate->id)->count();
            if ($rels > 0) {
                $counts['customer_relationships'] = $rels;
            }
        }
        return $counts;
    }

    /**
     * Entscheidet, welcher Login-Account (User) die vereinte Akte traegt, und
     * haengt den Hauptkunden bei Bedarf auf den Account des Duplikats um.
     * Massstab ist die Portal-Qualitaet: echte E-Mail schlaegt Import-
     * Platzhalter, dann zaehlen gesetztes Passwort, erfolgte Logins,
     * verschickte Einladung. Bei Gleichstand bleibt der Account des
     * Hauptkunden (stabiles Verhalten).
     *
     * Die Login-Adresse des unterlegenen Accounts wird - wenn echt und
     * abweichend - als alternative E-Mail (email2) gesichert; beim
     * Uebernehmen des Duplikat-Accounts gilt dessen im Portal gewaehlte
     * Sprache weiter.
     *
     * @return User|null Der unterlegene Account (zum Aufraeumen) oder null,
     *         wenn es nichts zu entscheiden gibt (gleicher/fehlender User).
     */
    private function preservePortalAccount(Customer $primary, Customer $duplicate): ?User
    {
        $primaryUser = $primary->user;
        $dupUser = $duplicate->user;

        if ($dupUser === null || ($primaryUser !== null && (int) $dupUser->id === (int) $primaryUser->id)) {
            return null;
        }

        $adoptDuplicateUser = $this->portalAccountScore($dupUser) > $this->portalAccountScore($primaryUser);

        if ($adoptDuplicateUser) {
            $primary->user_id = $dupUser->id;
            $primary->setRelation('user', $dupUser);
            // Der Inhaber des uebernommenen Accounts hat seine Sprache im
            // Portal selbst gewaehlt - sie gilt fuer die vereinte Akte weiter.
            if (! empty($duplicate->preferred_lang) && $duplicate->preferred_lang !== $primary->preferred_lang) {
                $primary->preferred_lang = $duplicate->preferred_lang;
            }
            $loser = $primaryUser;
        } else {
            $loser = $dupUser;
        }

        $kept = $adoptDuplicateUser ? $dupUser : $primaryUser;
        if ($loser !== null
            && $loser->hasRealEmail()
            && $loser->email !== $kept?->email
            && empty($primary->email2)) {
            $primary->email2 = $loser->email;
        }

        return $loser;
    }

    /**
     * Portal-Qualitaet eines Login-Accounts. Die echte E-Mail dominiert
     * bewusst alles andere (100): ein aktivierter Account mit Platzhalter-
     * Adresse ist nicht erreichbar und kann sein Passwort nie zuruecksetzen.
     * Ein deaktivierter Account verliert Punkte, bleibt aber vor einem
     * Import-Rumpf (Reaktivieren ist moeglich, Datenverlust nicht).
     */
    private function portalAccountScore(?User $user): int
    {
        if ($user === null) {
            return -1000;
        }
        $score = 0;
        if ($user->hasRealEmail()) {
            $score += 100;
        }
        if ($user->portal_password_set_at !== null) {
            $score += 40;
        }
        if ($user->first_login_at !== null) {
            $score += 20;
        }
        if ($user->last_login_at !== null) {
            $score += 10;
        }
        if ($user->invitation_sent_at !== null) {
            $score += 5;
        }
        if ($user->email_verified_at !== null) {
            $score += 2;
        }
        if (isset($user->is_active) && ! $user->is_active) {
            $score -= 50;
        }
        return $score;
    }

    /**
     * Haengt Verwandte-Kunden-Verknuepfungen (Familie/Haushalt/„kein
     * Duplikat") vom Duplikat auf den Hauptkunden um. Die Tabelle nutzt
     * customer_a_id/customer_b_id (Paar in fester Reihenfolge a < b) und
     * faellt deshalb durch den generischen customer_id-Abgleich; ohne
     * Umhaengen wuerde die FK-Kaskade beim Loeschen des Duplikats die
     * Familien-Verknuepfungen mitreissen.
     *
     * Regeln: Das Paar Hauptkunde<->Duplikat selbst ist nach dem Merge
     * gegenstandslos (kein Selbst-Paar). Umgehaengte Paare werden neu
     * normalisiert (a < b); existiert das Paar mit derselben Art (bzw. mit
     * irgendeiner Familienart) am Hauptkunden bereits, wird die
     * Duplikat-Zeile verworfen. parent_customer_id wandert mit.
     */
    private function mergeRelationships(Customer $primary, Customer $duplicate): int
    {
        if (! Schema::hasTable('customer_relationships')) {
            return 0;
        }

        $p = (string) $primary->id;
        $d = (string) $duplicate->id;

        DB::table('customer_relationships')
            ->where(function ($q) use ($p, $d) {
                $q->where(function ($qq) use ($p, $d) {
                    $qq->where('customer_a_id', $p)->where('customer_b_id', $d);
                })->orWhere(function ($qq) use ($p, $d) {
                    $qq->where('customer_a_id', $d)->where('customer_b_id', $p);
                });
            })
            ->delete();

        $moved = 0;
        $rows = DB::table('customer_relationships')
            ->where('customer_a_id', $d)
            ->orWhere('customer_b_id', $d)
            ->get();
        foreach ($rows as $row) {
            $other = (string) ($row->customer_a_id === $d ? $row->customer_b_id : $row->customer_a_id);
            [$a, $b] = CustomerRelationship::pairKey($p, $other);
            // UNIQUE (a, b, type) - und je Paar hoechstens EINE Familienart
            // (die Familientabelle fuehrt eine Rolle je Paar). Kollision: die
            // Zeile des Hauptkunden gewinnt.
            $types = CustomerRelationship::isFamilyType($row->type)
                ? CustomerRelationship::FAMILY_TYPES
                : [$row->type];
            $exists = DB::table('customer_relationships')
                ->where('customer_a_id', $a)->where('customer_b_id', $b)
                ->whereIn('type', $types)->exists();
            if ($exists) {
                DB::table('customer_relationships')->where('id', $row->id)->delete();
                continue;
            }
            // Die Richtung (Elternteil) zeigt auf die IDs - sie wandert mit,
            // sonst verweist sie auf die geloeschte Duplikat-Akte.
            $parent = $row->parent_customer_id ?? null;
            DB::table('customer_relationships')->where('id', $row->id)->update([
                'customer_a_id' => $a,
                'customer_b_id' => $b,
                'parent_customer_id' => $parent === null ? null : ((string) $parent === $d ? $p : $parent),
            ]);
            $moved++;
        }
        return $moved;
    }

    /**
     * Familienrollen umhaengen - BEIDE Spalten (KI-066). Lesart einer Zeile:
     * "related_customer_id ist relationship_type von customer_id".
     * Zeilen zwischen Hauptkunde und Duplikat sind nach dem Merge
     * gegenstandslos; kollidiert eine Zeile mit einer schon vorhandenen des
     * Hauptkunden (UNIQUE customer_id/related_customer_id), gewinnt die des
     * Hauptkunden.
     */
    private function mergeFamilyRelations(Customer $primary, Customer $duplicate): int
    {
        if (! Schema::hasTable(self::FAMILY_TABLE)) {
            return 0;
        }

        $p = (string) $primary->id;
        $d = (string) $duplicate->id;
        $t = self::FAMILY_TABLE;

        DB::table($t)->where(function ($q) use ($p, $d) {
            $q->where(fn ($qq) => $qq->where('customer_id', $p)->where('related_customer_id', $d))
                ->orWhere(fn ($qq) => $qq->where('customer_id', $d)->where('related_customer_id', $p));
        })->delete();

        $moved = 0;
        foreach (['customer_id' => 'related_customer_id', 'related_customer_id' => 'customer_id'] as $spalte => $gegenspalte) {
            foreach (DB::table($t)->where($spalte, $d)->get() as $row) {
                $kollision = DB::table($t)->where($spalte, $p)->where($gegenspalte, $row->$gegenspalte)->exists();
                if ($kollision) {
                    DB::table($t)->where('id', $row->id)->delete();
                    continue;
                }
                DB::table($t)->where('id', $row->id)->update([$spalte => $p]);
                $moved++;
            }
        }

        return $moved;
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
        if (! Schema::hasTable(self::PIVOT_TABLE)) {
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
        if (! Schema::hasTable('external_references')) {
            return 0;
        }

        $primaryKeys = DB::table('external_references')
            ->where('referenceable_type', Customer::class)
            ->where('referenceable_id', $primary->id)
            ->get(['type', 'value'])
            ->map(fn ($r) => $r->type.'|'.$r->value)->all();

        $moved = 0;
        $dupRefs = DB::table('external_references')
            ->where('referenceable_type', Customer::class)
            ->where('referenceable_id', $duplicate->id)->get();
        foreach ($dupRefs as $ref) {
            if (in_array($ref->type.'|'.$ref->value, $primaryKeys, true)) {
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
            'marital_status', 'nationality', 'occupation', 'employer_name',
            'employer_address', 'email2', 'company_name',
            'company_type', 'customer_type', 'gender', 'birth_place',
            'address_street', 'address_house_number', 'address_house_suffix',
            'address_zip', 'address_city', 'health_insurance_number',
            'health_insurance_company', 'health_insurance_type',
            'pension_insurance_number', 'tax_id',
        ];
        foreach ($fields as $f) {
            if (empty($primary->$f) && ! empty($duplicate->$f)) {
                $primary->$f = $duplicate->$f;
            }
        }

        // DSGVO: Eine Marketing-Abmeldung wirkt fort. Hat sich das Duplikat
        // abgemeldet, darf die vereinte Akte nicht wieder anschreibbar werden.
        if ($duplicate->unsubscribed_at && ! $primary->unsubscribed_at) {
            $primary->unsubscribed_at = $duplicate->unsubscribed_at;
            $primary->marketing_consent = false;
        }

        // "Letzter Kontakt" ist ein Zuletzt-Fakt - der neuere Stand gewinnt
        // (sonst meldet die Wiedervorlage einen laengst kontaktierten Kunden).
        if ($duplicate->last_contact
            && (! $primary->last_contact || $duplicate->last_contact > $primary->last_contact)) {
            $primary->last_contact = $duplicate->last_contact;
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
