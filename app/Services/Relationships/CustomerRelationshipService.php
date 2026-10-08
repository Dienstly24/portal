<?php

namespace App\Services\Relationships;

use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\CustomerRelationship;
use App\Services\Family\FamilyRelationService;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Die EINE Stelle, an der eine Kundenbeziehung (customer_relationships)
 * entsteht, sich aendert oder endet (Betreiber-Auftrag 01.10.2026).
 *
 * GLEICHLAUF MIT DEN FAMILIENROLLEN: die Arten ehepartner, elternteil_kind,
 * geschwister und sonstige_verwandte haben ein Gegenstueck in
 * customer_family_relations (Registerkarte "Familie"). Jede Aenderung hier
 * schreibt die Rolle ueber den FamilyRelationService mit - und umgekehrt
 * ruft der FamilyRelationService bei jeder Rollenaenderung hierher zurueck.
 * Beides passiert in EINER Transaktion; jede Tabelle hat genau einen
 * Schreiber.
 *
 * Abbildung Art -> Rolle (nie geraten):
 *  - ehepartner          -> ehepartner / ehepartner
 *  - lebenspartnerschaft -> lebenspartner / lebenspartner
 *  - lebensgefaehrten    -> partner / partner
 *  - grosseltern_enkel   -> Enkel: enkel/enkelin nach dem Geschlecht des
 *                           ENKELS, sonst "enkelkind"; Grosselternteil:
 *                           grossvater/grossmutter, sonst "grosselternteil".
 *  - geschwister        -> geschwister / geschwister
 *  - sonstige_verwandte -> sonstiges / sonstiges
 *  - elternteil_kind    -> Kind: sohn/tochter nach dem Geschlecht des KINDES,
 *                          unbekannt/divers -> "kind"; Elternteil:
 *                          vater/mutter nach dem Geschlecht des ELTERNTEILS,
 *                          unbekannt/divers -> "elternteil".
 *
 * ZWEI ZUSTAENDE OHNE ROLLE, beide bewusst:
 *  - ALTBESTAND: Familienarten aus der Zeit vor dieser Funktion (v. a. die
 *    frueheren "Ehepaar"-Markierungen) haben keine Rolle. Sie gelten als
 *    UNBESTAETIGT; die Rolle entsteht beim Bestaetigen oder Bearbeiten.
 *  - DOKUMENTEN-EINGANG: automatisch erkannte Verwandtschaft (gleicher
 *    Familienname, Meldebestaetigung, Geburtsurkunde) wird als
 *    sonstige_verwandte OHNE Rolle geschrieben (markRelatedUnconfirmed) -
 *    die Rolle vergibt immer ein Mensch. Sie ueberschreibt nie eine
 *    vorhandene Familienart.
 */
class CustomerRelationshipService
{
    public function __construct(private FamilyRelationService $family) {}

    /**
     * Beziehung festlegen (Dialog "Beziehung festlegen", Kundenakte).
     * Eine andere FAMILIENART desselben Paares wird ersetzt (eine Rolle je
     * Paar), ein "Kein Duplikat" ist damit ueberholt; Haushalt/Nachbar/
     * Sonstiges bleiben daneben bestehen.
     */
    public function set(Customer $a, Customer $b, string $type, ?string $parentId = null, ?string $note = null, ?int $by = null): CustomerRelationship
    {
        return $this->store($a, $b, $type, $parentId, $note, $by);
    }

    /** $reuse: bestehende Zeile, die bei einem Artwechsel ihre ID behaelt. */
    private function store(Customer $a, Customer $b, string $type, ?string $parentId, ?string $note, ?int $by, ?CustomerRelationship $reuse = null): CustomerRelationship
    {
        $this->validate($a, $b, $type, $parentId, $note);

        $rel = DB::transaction(function () use ($a, $b, $type, $parentId, $note, $by, $reuse) {
            [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
            $pair = CustomerRelationship::where('customer_a_id', $x)->where('customer_b_id', $y)
                ->when($reuse, fn ($q) => $q->whereKeyNot($reuse->getKey()));

            if (CustomerRelationship::isFamilyType($type)) {
                (clone $pair)->whereIn('type', CustomerRelationship::FAMILY_TYPES)->where('type', '!=', $type)->delete();
            }
            (clone $pair)->where('type', 'not_duplicate')->delete();

            $rel = CustomerRelationship::where('customer_a_id', $x)->where('customer_b_id', $y)->where('type', $type)->first();
            if ($rel && $reuse && ! $rel->is($reuse)) {
                $reuse->delete(); // dieselbe Art gibt es schon - sie gewinnt
            } elseif (! $rel) {
                $rel = $reuse ?? new CustomerRelationship(['customer_a_id' => $x, 'customer_b_id' => $y]);
            }
            $rel->type = $type;
            $rel->parent_customer_id = CustomerRelationship::isDirected($type) ? $parentId : null;
            $rel->note = $this->clean($note);
            if (! $rel->exists) {
                $rel->created_by = $by;
            }
            $rel->save();

            if (CustomerRelationship::isFamilyType($type)) {
                $this->writeRole($rel, $by);
            }

            return $rel;
        });

        $this->forgetCount();

        return $rel;
    }

    /** Art, Richtung oder Notiz einer bestehenden Beziehung aendern. */
    public function update(CustomerRelationship $rel, string $type, ?string $parentId = null, ?string $note = null, ?int $by = null): CustomerRelationship
    {
        $a = Customer::findOrFail($rel->customer_a_id);
        $b = Customer::findOrFail($rel->customer_b_id);

        if ($type === 'not_duplicate') {
            return DB::transaction(function () use ($rel, $by) {
                if (CustomerRelationship::isFamilyType($rel->type)) {
                    $this->removeRole((string) $rel->customer_a_id, (string) $rel->customer_b_id, $by);
                }
                $andere = CustomerRelationship::where('customer_a_id', $rel->customer_a_id)
                    ->where('customer_b_id', $rel->customer_b_id)->whereKeyNot($rel->getKey())->exists();
                if ($andere) {
                    $rel->delete(); // das Paar ist durch die uebrigen Arten bereits ausgenommen

                    return $rel;
                }
                $rel->fill(['type' => 'not_duplicate', 'parent_customer_id' => null])->save();

                return $rel;
            });
        }

        $this->validate($a, $b, $type, $parentId, $note);

        return DB::transaction(function () use ($rel, $a, $b, $type, $parentId, $note, $by) {
            if ($rel->type !== $type && CustomerRelationship::isFamilyType($rel->type) && ! CustomerRelationship::isFamilyType($type)) {
                $this->removeRole((string) $rel->customer_a_id, (string) $rel->customer_b_id, $by);
            }

            return $this->store($a, $b, $type, $parentId, $note, $by, $rel);
        });
    }

    /** Altbestand bestaetigen: die fehlende Familienrolle wird angelegt. */
    public function confirm(CustomerRelationship $rel, ?int $by = null): void
    {
        if (! CustomerRelationship::isFamilyType($rel->type)) {
            return;
        }
        DB::transaction(fn () => $this->writeRole($rel, $by));
    }

    /** Beziehung entfernen - samt Familienrolle. Das Paar kann wieder als Dublette erscheinen. */
    public function delete(CustomerRelationship $rel, ?int $by = null): void
    {
        DB::transaction(function () use ($rel, $by) {
            if (CustomerRelationship::isFamilyType($rel->type)) {
                $this->removeRole((string) $rel->customer_a_id, (string) $rel->customer_b_id, $by);
            }
            $rel->delete();
        });
        $this->forgetCount();
    }

    /** "Kein Duplikat": nur, wenn das Paar noch gar keine Beziehung hat. */
    public function markNotDuplicate(string $a, string $b, ?int $by = null): bool
    {
        [$x, $y] = CustomerRelationship::pairKey($a, $b);
        if (CustomerRelationship::where('customer_a_id', $x)->where('customer_b_id', $y)->exists()) {
            return false;
        }
        CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'not_duplicate', 'created_by' => $by]);
        $this->forgetCount();

        return true;
    }

    /**
     * Dokumenten-Eingang: Verwandtschaft vermutet, Rolle unbekannt.
     * sonstige_verwandte OHNE Familienrolle (Ausnahme vom Gleichlauf, siehe
     * Klassenkommentar). Eine vorhandene Familienart wird nie ersetzt.
     */
    public function markRelatedUnconfirmed(string $a, string $b, string $note, ?int $by = null): ?CustomerRelationship
    {
        if ($a === $b) {
            return null;
        }
        [$x, $y] = CustomerRelationship::pairKey($a, $b);
        $pair = CustomerRelationship::where('customer_a_id', $x)->where('customer_b_id', $y);
        if ((clone $pair)->whereIn('type', CustomerRelationship::FAMILY_TYPES)->exists()) {
            return null;
        }
        (clone $pair)->where('type', 'not_duplicate')->delete();

        return CustomerRelationship::create([
            'customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'sonstige_verwandte',
            'note' => $this->clean($note), 'created_by' => $by,
        ]);
    }

    /** Hat diese Familienart ihre passende Rolle? Nicht-Familienarten: immer true. */
    public function isConfirmed(CustomerRelationship $rel): bool
    {
        if (! CustomerRelationship::isFamilyType($rel->type)) {
            return true;
        }

        return CustomerRelationship::whereKey($rel->id)->tap(fn ($q) => $this->whereConfirmed($q, true))->exists();
    }

    /** Einschraenkung auf Familienarten OHNE passende Familienrolle (unbestaetigt). */
    public function whereUnconfirmed(Builder $query): Builder
    {
        return $this->whereConfirmed($query->whereIn('customer_relationships.type', CustomerRelationship::FAMILY_TYPES), false);
    }

    // ---------------------------------------------------------------
    // Rueckweg aus dem FamilyRelationService (Registerkarte "Familie")
    // ---------------------------------------------------------------

    /** Familienrolle gesetzt/geaendert: "$relatedId ist $role von $customerId". */
    public function syncFromFamilyRole(string $customerId, string $relatedId, string $role, ?int $by = null): void
    {
        ['type' => $type, 'parent' => $parent] = self::typeForRole($role, $customerId, $relatedId);
        [$x, $y] = CustomerRelationship::pairKey($customerId, $relatedId);
        $pair = CustomerRelationship::where('customer_a_id', $x)->where('customer_b_id', $y);

        (clone $pair)->whereIn('type', CustomerRelationship::FAMILY_TYPES)->where('type', '!=', $type)->delete();
        (clone $pair)->where('type', 'not_duplicate')->delete();

        $rel = CustomerRelationship::firstOrNew(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => $type]);
        $rel->parent_customer_id = $parent;
        if (! $rel->exists) {
            $rel->note = 'Familienzuordnung';
            $rel->created_by = $by;
        }
        $rel->save();
        $this->forgetCount();
    }

    /**
     * Familienrolle geloest: die Familienart verschwindet. "Kein Duplikat"
     * bleibt wahr - bleibt keine andere Beziehung stehen, wird es vermerkt.
     */
    public function syncAfterFamilyUnlink(string $customerId, string $relatedId, ?int $by = null): void
    {
        [$x, $y] = CustomerRelationship::pairKey($customerId, $relatedId);
        $pair = CustomerRelationship::where('customer_a_id', $x)->where('customer_b_id', $y);
        (clone $pair)->whereIn('type', CustomerRelationship::FAMILY_TYPES)->delete();
        if (! (clone $pair)->exists()) {
            CustomerRelationship::create(['customer_a_id' => $x, 'customer_b_id' => $y, 'type' => 'not_duplicate', 'created_by' => $by]);
        }
    }

    /**
     * Familienrolle -> Art. Zeile (X, Y, rolle) heisst "Y ist <rolle> von X".
     *
     * @return array{type: string, parent: ?string}
     */
    public static function typeForRole(string $role, string $customerId, string $relatedId): array
    {
        return match (true) {
            in_array($role, CustomerFamilyRelation::CHILD_ROLES, true) => ['type' => 'elternteil_kind', 'parent' => $customerId],
            in_array($role, CustomerFamilyRelation::PARENT_ROLES, true) => ['type' => 'elternteil_kind', 'parent' => $relatedId],
            in_array($role, CustomerFamilyRelation::GRANDCHILD_ROLES, true) => ['type' => 'grosseltern_enkel', 'parent' => $customerId],
            in_array($role, CustomerFamilyRelation::GRANDPARENT_ROLES, true) => ['type' => 'grosseltern_enkel', 'parent' => $relatedId],
            $role === 'ehepartner' => ['type' => 'ehepartner', 'parent' => null],
            $role === 'lebenspartner' => ['type' => 'lebenspartnerschaft', 'parent' => null],
            $role === 'partner' => ['type' => 'lebensgefaehrten', 'parent' => null],
            $role === 'geschwister' => ['type' => 'geschwister', 'parent' => null],
            default => ['type' => 'sonstige_verwandte', 'parent' => null],
        };
    }

    /** Rolle des ENKELS nach seinem Geschlecht; unbekannt/divers -> "enkelkind". */
    public static function grandchildRole(Customer $enkel): string
    {
        return match ($enkel->gender) {
            'male' => 'enkel',
            'female' => 'enkelin',
            default => 'enkelkind',
        };
    }

    /** Rolle des KINDES nach seinem Geschlecht; unbekannt/divers -> "kind". */
    public static function childRole(Customer $child): string
    {
        return match ($child->gender) {
            'male' => 'sohn',
            'female' => 'tochter',
            default => 'kind',
        };
    }

    // ---------------------------------------------------------------

    private function validate(Customer $a, Customer $b, string $type, ?string $parentId, ?string $note): void
    {
        if ((string) $a->id === (string) $b->id) {
            throw new \InvalidArgumentException('Ein Kunde kann nicht mit sich selbst verknüpft werden.');
        }
        if (! in_array($type, CustomerRelationship::RELATION_TYPES, true)) {
            throw new \InvalidArgumentException('Unbekannte Beziehungsart: '.$type);
        }
        if (CustomerRelationship::isDirected($type) && ! in_array((string) $parentId, [(string) $a->id, (string) $b->id], true)) {
            throw new \InvalidArgumentException($type === 'grosseltern_enkel'
                ? 'Bitte wählen Sie, wer der Großelternteil ist.'
                : 'Bitte wählen Sie, wer der Elternteil ist.');
        }
        if ($type === 'sonstiges' && $this->clean($note) === null) {
            throw new \InvalidArgumentException('Bei „Sonstiges" ist eine Beschreibung erforderlich.');
        }
    }

    /** Familienrolle passend zur Art schreiben (beide Richtungen, ueber den FamilyRelationService). */
    private function writeRole(CustomerRelationship $rel, ?int $by): void
    {
        $a = Customer::findOrFail($rel->customer_a_id);
        $b = Customer::findOrFail($rel->customer_b_id);
        $note = $rel->note;

        match ($rel->type) {
            'ehepartner' => $this->family->link($a, $b, 'ehepartner', $by, $note, false),
            'lebenspartnerschaft' => $this->family->link($a, $b, 'lebenspartner', $by, $note, false),
            'lebensgefaehrten' => $this->family->link($a, $b, 'partner', $by, $note, false),
            'grosseltern_enkel' => (function () use ($rel, $a, $b, $by, $note) {
                [$gross, $enkel] = (string) $rel->parent_customer_id === (string) $a->id ? [$a, $b] : [$b, $a];
                $this->family->link($gross, $enkel, self::grandchildRole($enkel), $by, $note, false);
            })(),
            'geschwister' => $this->family->link($a, $b, 'geschwister', $by, $note, false),
            'sonstige_verwandte' => $this->family->link($a, $b, 'sonstiges', $by, $note, false),
            'elternteil_kind' => (function () use ($rel, $a, $b, $by, $note) {
                [$parent, $child] = (string) $rel->parent_customer_id === (string) $a->id ? [$a, $b] : [$b, $a];
                $this->family->link($parent, $child, self::childRole($child), $by, $note, false);
            })(),
            default => null,
        };
    }

    private function removeRole(string $a, string $b, ?int $by): void
    {
        $relation = CustomerFamilyRelation::where(function ($q) use ($a, $b) {
            $q->where('customer_id', $a)->where('related_customer_id', $b);
        })->orWhere(function ($q) use ($a, $b) {
            $q->where('customer_id', $b)->where('related_customer_id', $a);
        })->first();

        if ($relation) {
            $this->family->unlink($relation, $by, false);
        }
    }

    private function whereConfirmed(Builder $query, bool $confirmed): Builder
    {
        $kind = "'".implode("','", array_merge(CustomerFamilyRelation::CHILD_ROLES, CustomerFamilyRelation::PARENT_ROLES))."'";
        $enkel = "'".implode("','", array_merge(CustomerFamilyRelation::GRANDCHILD_ROLES, CustomerFamilyRelation::GRANDPARENT_ROLES))."'";
        $sub = function ($q) use ($kind, $enkel) {
            $q->select(DB::raw(1))->from('customer_family_relations as cfr')
                ->whereColumn('cfr.customer_id', 'customer_relationships.customer_a_id')
                ->whereColumn('cfr.related_customer_id', 'customer_relationships.customer_b_id')
                ->whereRaw("(
                    (customer_relationships.type = 'ehepartner' AND cfr.relationship_type = 'ehepartner')
                    OR (customer_relationships.type = 'geschwister' AND cfr.relationship_type = 'geschwister')
                    OR (customer_relationships.type = 'sonstige_verwandte' AND cfr.relationship_type = 'sonstiges')
                    OR (customer_relationships.type = 'lebenspartnerschaft' AND cfr.relationship_type = 'lebenspartner')
                    OR (customer_relationships.type = 'lebensgefaehrten' AND cfr.relationship_type = 'partner')
                    OR (customer_relationships.type = 'elternteil_kind' AND cfr.relationship_type IN ($kind))
                    OR (customer_relationships.type = 'grosseltern_enkel' AND cfr.relationship_type IN ($enkel))
                )");
        };

        return $confirmed ? $query->whereExists($sub) : $query->whereNotExists($sub);
    }

    private function clean(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 255);
    }

    private function forgetCount(): void
    {
        app(DuplicateDetectionService::class)->forgetCount();
    }
}
