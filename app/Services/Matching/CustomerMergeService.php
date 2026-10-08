<?php

namespace App\Services\Matching;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\CustomerRelationship;
use App\Models\CustomerTimeline;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fuehrt zwei Kundenakten verlustfrei zusammen. Der Duplikat-Datensatz wird
 * seit 04.10.2026 ARCHIVIERT statt geloescht (KI-064, Rueckgaengig:
 * `CustomerMergeUndoService`) - ABER erst nachdem ALLE abhaengigen Daten auf den Hauptkunden
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

    /**
     * Stammdaten, die ein Merge vom Duplikat ERGAENZT (leere Felder) bzw.
     * auf ausdrueckliche Wahl des Admins UEBERNIMMT (PR-3c).
     */
    public const STAMMDATEN = [
        'phone', 'mobile', 'address', 'address2', 'iban', 'iban2', 'birth_date',
        'marital_status', 'nationality', 'occupation', 'employer_name',
        'employer_address', 'email2', 'company_name',
        'company_type', 'customer_type', 'gender', 'birth_place',
        'address_street', 'address_house_number', 'address_house_suffix',
        'address_zip', 'address_city', 'health_insurance_number',
        'health_insurance_company', 'health_insurance_type',
        'pension_insurance_number', 'tax_id',
    ];

    /**
     * Felder, die nur GEMEINSAM gewaehlt werden duerfen (PR-3c): Strasse aus
     * der einen und PLZ aus der anderen Akte ergaeben eine Anschrift, die es
     * nicht gibt. Alles uebrige ist eine Gruppe aus einem Feld.
     */
    public const FELDGRUPPEN = [
        'anschrift' => ['address_street', 'address_house_number', 'address_house_suffix', 'address_zip', 'address_city', 'address'],
        'krankenkasse' => ['health_insurance_company', 'health_insurance_number', 'health_insurance_type'],
        'arbeitgeber' => ['employer_name', 'employer_address'],
    ];

    public const GRUPPEN_NAMEN = [
        'anschrift' => 'Anschrift', 'krankenkasse' => 'Krankenkasse', 'arbeitgeber' => 'Arbeitgeber',
        'phone' => 'Telefon', 'mobile' => 'Mobil', 'address2' => 'Zweitanschrift',
        'iban' => 'IBAN', 'iban2' => 'Zweite IBAN', 'birth_date' => 'Geburtsdatum',
        'marital_status' => 'Familienstand', 'nationality' => 'Staatsangehörigkeit',
        'occupation' => 'Beruf', 'email2' => 'Alternative E-Mail', 'company_name' => 'Firma',
        'company_type' => 'Rechtsform', 'customer_type' => 'Kundentyp', 'gender' => 'Geschlecht',
        'birth_place' => 'Geburtsort', 'pension_insurance_number' => 'Rentenversicherungsnummer',
        'tax_id' => 'Steuer-ID',
    ];

    /**
     * Protokoll des laufenden Merges (KI-064): umgehaengte Zeilen, verworfene
     * Kollisionszeilen (vollstaendig), ergaenzte Felder, Konto. Wird als
     * `customer_merges`-Datensatz gespeichert - die Grundlage zum
     * Rueckgaengigmachen. Je merge() neu begonnen.
     *
     * @var array<string, mixed>
     */
    private array $protokoll = [];

    /** @var array<string, bool> */
    private static array $hatIdSpalte = [];

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
     * @param array<string, string> $feldwahl Gruppe => 'duplikat' fuer
     *        abweichende Stammdaten, bei denen der Wert des Duplikats gelten
     *        soll (PR-3c). Ohne Eintrag bleibt der Hauptkunde.
     * @return array<string, int> Zusammenfassung: umgehaengte Datensaetze je Tabelle.
     * @throws \InvalidArgumentException bei ungueltigen Eingaben (Selbst-Merge,
     *         Nicht-Kunden-Account) - Schutz analog CustomerDeletionService.
     * @throws MergeBlockedException wenn Sperrgruende bestehen und keine
     *         Begruendung vorliegt.
     */
    public function merge(Customer $primary, Customer $duplicate, ?int $actorId = null, ?string $uebersteuertMit = null, array $feldwahl = []): array
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

        $this->protokoll = ['umgehaengt' => [], 'verworfen' => [], 'ergaenzt' => [], 'uebernommen' => [], 'konto' => null];

        return DB::transaction(function () use ($primary, $duplicate, $actorId, $sperren, $uebersteuertMit, $feldwahl) {
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
            $email2Vorher = $primary->email2;
            $langVorher = $primary->preferred_lang;
            $loserUser = $this->preservePortalAccount($primary, $duplicate);
            $this->protokoll['konto'] = [
                'user_vorher' => $userIdBefore,
                'getauscht' => (int) $primary->user_id !== (int) $userIdBefore,
                'verlierer_user_id' => $loserUser?->id,
                // Damit ein Rueckgaengigmachen nur oeffnet, was der Merge
                // geschlossen hat - nicht einen schon vorher gesperrten Zugang.
                'verlierer_war_aktiv' => $loserUser === null || ! (isset($loserUser->is_active) && ! $loserUser->is_active),
                'email2_vorher' => $email2Vorher,
                'sprache_vorher' => $langVorher,
            ];

            // 5) Abweichende Stammdaten: nur, was der Admin ausdruecklich
            //    gewaehlt hat, kommt vom Duplikat (PR-3c). Danach fehlende
            //    Felder ergaenzen (nie ueberschreiben).
            $behalten = $this->applyFeldwahl($primary, $duplicate, $feldwahl);
            $this->fillMissingFields($primary, $duplicate, $behalten);
            $primary->save();

            // 6) Die leere Duplikat-Akte wird ARCHIVIERT, nicht geloescht
            //    (KI-064): ihre Kundennummer bleibt belegt und fuehrt ueber
            //    die Suche zum Hauptkunden, und ein Irrtum laesst sich noch
            //    rueckgaengig machen. Der globale Scope blendet sie ueberall
            //    aus. Huellen, die schon in das Duplikat aufgegangen waren,
            //    zeigen ab jetzt direkt auf den Hauptkunden (keine Ketten).
            DB::table('customers')->where('merged_into_id', $duplicate->id)
                ->update(['merged_into_id' => $primary->id]);
            DB::table('customers')->where('id', $duplicate->id)->update([
                'merged_into_id' => $primary->id,
                'archived_at' => now(),
                'updated_at' => now(),
            ]);

            // Der unterlegene Zugang wird NIE mehr geloescht, sondern
            // stillgelegt (KI-064/KI-065) - aber nur, wenn keine andere
            // (lebende) Akte ihn noch benutzt.
            $portalDeaktiviert = false;
            if ($loserUser
                && (int) $loserUser->id !== (int) $primary->user_id
                && ! Customer::where('user_id', $loserUser->id)->exists()) {
                $portalDeaktiviert = $this->hasActivePortalAccess($loserUser);
                $loserUser->forceFill(['is_active' => false])->save();
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
                    // Nur die NAMEN der uebernommenen Felder - die Werte stehen
                    // (verschluesselt) im Merge-Protokoll.
                    'feldwahl' => array_keys($this->protokoll['uebernommen']),
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

            CustomerMerge::create([
                'primary_customer_id' => $primary->id,
                'duplicate_customer_id' => $duplicate->id,
                'actor_id' => $actorId,
                'duplicate_number' => $dupNumber,
                'protokoll' => $this->protokoll,
                'begruendung' => $sperren === [] ? null : $uebersteuertMit,
            ]);

            $this->detection?->forgetCount();
            app(DuplicateDetectionService::class)->forgetCount();

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

        $this->verwerfe('customer_relationships', DB::table('customer_relationships')
            ->where(function ($q) use ($p, $d) {
                $q->where(function ($qq) use ($p, $d) {
                    $qq->where('customer_a_id', $p)->where('customer_b_id', $d);
                })->orWhere(function ($qq) use ($p, $d) {
                    $qq->where('customer_a_id', $d)->where('customer_b_id', $p);
                });
            }));

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
                $this->verwerfe('customer_relationships', DB::table('customer_relationships')->where('id', $row->id));
                continue;
            }
            $this->protokoll['umgehaengt']['customer_relationships'][] = (array) $row;
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

        $this->verwerfe($t, DB::table($t)->where(function ($q) use ($p, $d) {
            $q->where(fn ($qq) => $qq->where('customer_id', $p)->where('related_customer_id', $d))
                ->orWhere(fn ($qq) => $qq->where('customer_id', $d)->where('related_customer_id', $p));
        }));

        $moved = 0;
        foreach (['customer_id' => 'related_customer_id', 'related_customer_id' => 'customer_id'] as $spalte => $gegenspalte) {
            foreach (DB::table($t)->where($spalte, $d)->get() as $row) {
                $kollision = DB::table($t)->where($spalte, $p)->where($gegenspalte, $row->$gegenspalte)->exists();
                if ($kollision) {
                    $this->verwerfe($t, DB::table($t)->where('id', $row->id));
                    continue;
                }
                $this->protokoll['umgehaengt'][$t][] = ['id' => $row->id, 'spalte' => $spalte];
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

        if ($this->hatIdSpalte($table)) {
            $ids = DB::table($table)->where('customer_id', $duplicate->id)->pluck('id')->all();
            if ($ids !== []) {
                $this->protokoll['umgehaengt'][$table] = $ids;
            }
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
            $this->verwerfe($table, DB::table($table)->where('customer_id', $duplicate->id));
            return;
        }

        $this->verwerfe($table, DB::table($table)
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
            }));
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
                $this->verwerfe(self::PIVOT_TABLE, DB::table(self::PIVOT_TABLE)->where('id', $row->id));
                continue;
            }
            $this->protokoll['umgehaengt'][self::PIVOT_TABLE][] = $row->id;
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
                $this->verwerfe('external_references', DB::table('external_references')->where('id', $ref->id));
                continue;
            }
            $this->protokoll['umgehaengt']['external_references'][] = $ref->id;
            DB::table('external_references')->where('id', $ref->id)->update(['referenceable_id' => $primary->id]);
            $moved++;
        }
        return $moved;
    }

    /**
     * Loescht die Zeilen der Abfrage - aber erst, nachdem sie VOLLSTAENDIG im
     * Merge-Protokoll stehen. Verworfen werden nur Zeilen, die nach dem Merge
     * doppelt waeren (Kollision) oder auf sich selbst zeigten; das Protokoll
     * ist der Weg zurueck.
     */
    private function verwerfe(string $table, Builder $query): void
    {
        foreach ($query->get() as $row) {
            $this->protokoll['verworfen'][$table][] = (array) $row;
        }
        $query->delete();
    }

    private function hatIdSpalte(string $table): bool
    {
        return self::$hatIdSpalte[$table] ??= Schema::hasColumn($table, 'id');
    }

    /** Leere Stammdatenfelder des Hauptkunden aus dem Duplikat ergaenzen. */
    /**
     * Gruppen (siehe FELDGRUPPEN), in denen BEIDE Akten etwas fuehren und die
     * Werte sich unterscheiden - genau dort muss ein Mensch waehlen. Leere
     * Felder des Hauptkunden ergaenzt der Merge ohnehin.
     *
     * @return array<string, array{name: string, felder: list<string>, haupt: string, duplikat: string}>
     */
    public function abweichendeFelder(Customer $primary, Customer $duplicate): array
    {
        $ergebnis = [];
        foreach ($this->gruppen() as $gruppe => $felder) {
            $haupt = $this->gruppenText($primary, $felder);
            $dup = $this->gruppenText($duplicate, $felder);
            if ($haupt === '' || $dup === '' || $this->vergleichbar($haupt) === $this->vergleichbar($dup)) {
                continue;
            }
            $ergebnis[$gruppe] = [
                'name' => self::GRUPPEN_NAMEN[$gruppe] ?? $gruppe,
                'felder' => $felder,
                'haupt' => $haupt,
                'duplikat' => $dup,
            ];
        }

        return $ergebnis;
    }

    /** @return array<string, list<string>> */
    private function gruppen(): array
    {
        $gruppen = self::FELDGRUPPEN;
        $vergeben = array_merge(...array_values(self::FELDGRUPPEN));
        foreach (self::STAMMDATEN as $f) {
            if (! in_array($f, $vergeben, true)) {
                $gruppen[$f] = [$f];
            }
        }

        return $gruppen;
    }

    /** @param list<string> $felder */
    private function gruppenText(Customer $c, array $felder): string
    {
        if ($felder === self::FELDGRUPPEN['anschrift']) {
            $strukturiert = $c->fullAddress();

            return $strukturiert !== '' ? $strukturiert : trim((string) $c->address);
        }

        return implode(' · ', array_values(array_filter(
            array_map(fn ($f) => trim((string) $c->$f), $felder),
            fn ($v) => $v !== ''
        )));
    }

    private function vergleichbar(string $wert): string
    {
        $wert = mb_strtolower($wert);
        $wert = str_replace(['ß', 'str.'], ['ss', 'strasse'], $wert);

        return (string) preg_replace('/[\s,.\-\/·]+/u', '', $wert);
    }

    /**
     * Uebernimmt die gewaehlten Gruppen vom Duplikat - IMMER als ganze Gruppe
     * (eine halbe Anschrift gibt es nicht). Unbekannte Gruppen und Gruppen
     * ohne echte Abweichung werden ignoriert: dem Formular wird nichts
     * geglaubt, was die Pruefung nicht selbst ermittelt hat.
     *
     * @param array<string, string> $feldwahl
     * @return list<string> Felder abweichender Gruppen, bei denen der
     *         Hauptkunde bleibt - sie werden auch nicht teilweise ergaenzt.
     */
    private function applyFeldwahl(Customer $primary, Customer $duplicate, array $feldwahl): array
    {
        $abweichend = $this->abweichendeFelder($primary, $duplicate);
        $behalten = [];
        foreach ($abweichend as $gruppe => $info) {
            if (($feldwahl[$gruppe] ?? null) !== 'duplikat') {
                array_push($behalten, ...$info['felder']);
            }
        }
        foreach ($feldwahl as $gruppe => $wahl) {
            if ($wahl !== 'duplikat' || ! isset($abweichend[$gruppe])) {
                continue;
            }
            foreach ($abweichend[$gruppe]['felder'] as $f) {
                if ($primary->$f == $duplicate->$f) {
                    continue;
                }
                // Alter Wert fuers Rueckgaengigmachen (Protokoll ist
                // verschluesselt und wird nach der Frist geleert).
                $this->protokoll['uebernommen'][$f] = $primary->$f;
                $primary->$f = $duplicate->$f;
            }
        }

        return $behalten;
    }

    /**
     * @param list<string> $nichtErgaenzen Felder einer abweichenden Gruppe, bei
     *        der der Hauptkunde bleibt: die Hausnummer-Ergaenzung der einen
     *        Anschrift an die Strasse der anderen ergaebe eine Adresse, die
     *        es nicht gibt (PR-3c).
     */
    private function fillMissingFields(Customer $primary, Customer $duplicate, array $nichtErgaenzen = []): void
    {
        foreach (self::STAMMDATEN as $f) {
            if (in_array($f, $nichtErgaenzen, true)) {
                continue;
            }
            if (empty($primary->$f) && ! empty($duplicate->$f)) {
                // Nur der NAME: der Wert steht weiter in der archivierten Huelle.
                $this->protokoll['ergaenzt'][] = $f;
                $primary->$f = $duplicate->$f;
            }
        }

        // DSGVO: Eine Marketing-Abmeldung wirkt fort. Hat sich das Duplikat
        // abgemeldet, darf die vereinte Akte nicht wieder anschreibbar werden.
        if ($duplicate->unsubscribed_at && ! $primary->unsubscribed_at) {
            $this->protokoll['ergaenzt'][] = 'unsubscribed_at';
            $this->protokoll['marketing_consent_vorher'] = $primary->marketing_consent;
            $primary->unsubscribed_at = $duplicate->unsubscribed_at;
            $primary->marketing_consent = false;
        }

        // "Letzter Kontakt" ist ein Zuletzt-Fakt - der neuere Stand gewinnt
        // (sonst meldet die Wiedervorlage einen laengst kontaktierten Kunden).
        if ($duplicate->last_contact
            && (! $primary->last_contact || $duplicate->last_contact > $primary->last_contact)) {
            $this->protokoll['last_contact_vorher'] = $primary->getAttributes()['last_contact'] ?? null;
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
