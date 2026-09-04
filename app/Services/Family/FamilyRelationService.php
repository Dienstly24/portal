<?php

namespace App\Services\Family;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\CustomerRelationship;
use App\Models\CustomerTimeline;
use App\Models\InternalNotification;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Familienstruktur zwischen BESTEHENDEN Kundenakten (Betreiber-Vorgabe
 * 28.08.2026).
 *
 * Ausgangslage: beim Einlesen mehrerer Gesundheitskarten einer Familie ist je
 * Karte eine eigene Kundenakte entstanden. Diese Akten sind RICHTIG - an ihnen
 * haengen Dokumente, Vertraege, Vorgaenge und Historie. Sie duerfen deshalb
 * weder geloescht noch zusammengefuehrt werden; sie werden VERKNUEPFT.
 *
 * Dieser Dienst ist die EINE Stelle, an der eine Familienbeziehung entsteht,
 * sich aendert oder endet. Er garantiert vier Dinge:
 *  1. Beide Richtungen existieren immer gemeinsam (Eltern -> Kind UND
 *     Kind -> Eltern), sonst waere die Navigation einseitig.
 *  2. Es entsteht NIE ein neuer Kundendatensatz - verknuepft werden
 *     ausschliesslich vorhandene Akten.
 *  3. Das Paar verschwindet aus der Dubletten-Liste
 *     (customer_relationships), denn eine Familie ist keine Dublette.
 *  4. Jede Aenderung steht in der Kundenakte (Timeline) und im ActivityLog.
 */
class FamilyRelationService
{
    /** Standard-Vorlauf der Uebergangsliste in Monaten. */
    public const DEFAULT_LEAD_MONTHS = 6;

    /** Erlaubte Vorlaufzeiten (Betreiber-Vorgabe: 6 oder 12 Monate). */
    public const LEAD_MONTH_CHOICES = [3, 6, 12];

    /**
     * Zwei bestehende Kunden als Familie verknuepfen.
     *
     * @param  Customer  $customer  Bezugsperson (z. B. der Vater/Hauptkunde).
     * @param  Customer  $related   Bereits vorhandene Akte, die verknuepft wird.
     * @param  string    $role      Rolle des VERKNUEPFTEN aus Sicht der Bezugsperson.
     */
    public function link(Customer $customer, Customer $related, string $role, ?int $byUserId = null, ?string $note = null): CustomerFamilyRelation
    {
        if ((string) $customer->id === (string) $related->id) {
            throw new \InvalidArgumentException('Ein Kunde kann nicht mit sich selbst verknuepft werden.');
        }
        if (! array_key_exists($role, CustomerFamilyRelation::ROLES)) {
            throw new \InvalidArgumentException('Unbekannte Familienrolle: '.$role);
        }

        $forward = DB::transaction(function () use ($customer, $related, $role, $byUserId, $note) {
            // Hinrichtung: "related ist <role> von customer".
            $forward = CustomerFamilyRelation::updateOrCreate(
                ['customer_id' => $customer->id, 'related_customer_id' => $related->id],
                [
                    'relationship_type' => $role,
                    'is_dependent' => $this->shouldBeDependent($role, $related),
                    'note' => $note,
                    'created_by' => $byUserId,
                ]
            );

            // Rueckrichtung: "customer ist <Gegenrolle> von related". Ohne sie
            // fuehrte der Weg nur in eine Richtung - vom Kind kaeme man nie zu
            // den Eltern zurueck.
            CustomerFamilyRelation::updateOrCreate(
                ['customer_id' => $related->id, 'related_customer_id' => $customer->id],
                [
                    'relationship_type' => CustomerFamilyRelation::inverseRole($role, $customer->gender),
                    // Abhaengigkeit steht immer NUR an der Zeile der
                    // Bezugsperson - ein Elternteil ist nie vom Kind abhaengig.
                    'is_dependent' => false,
                    'note' => $note,
                    'created_by' => $byUserId,
                ]
            );

            $this->exemptFromDuplicates($customer, $related, $role, $byUserId);

            return $forward;
        });

        $this->note(
            $customer,
            'Familie verknüpft',
            ($related->user?->name ?: 'Kunde').' ('.($related->customer_number ?: '—').') als '
                .CustomerFamilyRelation::roleLabel($role).' verknüpft.',
            $byUserId
        );
        $this->note(
            $related,
            'Familie verknüpft',
            'Mit '.($customer->user?->name ?: 'Kunde').' ('.($customer->customer_number ?: '—').') als '
                .CustomerFamilyRelation::roleLabel(CustomerFamilyRelation::inverseRole($role, $customer->gender)).' verknüpft.',
            $byUserId
        );

        ActivityLog::record('family_relation_linked', 'customer', (string) $customer->id, [
            'related_customer_id' => (string) $related->id,
            'role' => $role,
            'is_dependent' => $forward->is_dependent,
        ], $byUserId);

        return $forward->refresh();
    }

    /**
     * Verknuepfung wieder aufheben (beide Richtungen). Es wird ausschliesslich
     * die BEZIEHUNG entfernt - beide Kundenakten samt Vertraegen, Dokumenten
     * und Historie bleiben unangetastet.
     *
     * Die Dubletten-Ausnahme bleibt bewusst bestehen: "kein Duplikat" bleibt
     * wahr, auch wenn die Familienrolle nicht mehr gepflegt wird.
     */
    public function unlink(CustomerFamilyRelation $relation, ?int $byUserId = null): void
    {
        $customer = $relation->customer;
        $related = $relation->relatedCustomer;

        DB::transaction(function () use ($relation) {
            CustomerFamilyRelation::where('customer_id', $relation->related_customer_id)
                ->where('related_customer_id', $relation->customer_id)->delete();
            $relation->delete();
        });

        if ($customer) {
            $this->note($customer, 'Familienverknüpfung entfernt',
                'Verknüpfung mit '.($related?->user?->name ?: 'Kunde').' aufgehoben. Beide Kundenakten bleiben unverändert bestehen.', $byUserId);
        }
        if ($related) {
            $this->note($related, 'Familienverknüpfung entfernt',
                'Verknüpfung mit '.($customer?->user?->name ?: 'Kunde').' aufgehoben. Beide Kundenakten bleiben unverändert bestehen.', $byUserId);
        }

        ActivityLog::record('family_relation_unlinked', 'customer', (string) $relation->customer_id, [
            'related_customer_id' => (string) $relation->related_customer_id,
            'role' => $relation->relationship_type,
        ], $byUserId);
    }

    /**
     * Familienuebersicht einer Akte: alle verknuepften Kunden mit Rolle,
     * Alter und Abhaengigkeit, gruppiert nach Rollengruppe.
     *
     * @return array{
     *   spouses: Collection, children: Collection, parents: Collection,
     *   others: Collection, all: Collection, guardians: Collection
     * }
     */
    public function overview(Customer $customer): array
    {
        $relations = $customer->familyRelations()
            ->with(['relatedCustomer.user'])
            ->get()
            ->filter(fn (CustomerFamilyRelation $r) => $r->relatedCustomer !== null)
            ->sortBy(fn (CustomerFamilyRelation $r) => [
                $this->roleOrder($r->relationship_type),
                (string) ($r->relatedCustomer->birth_date ?? '9999-12-31'),
            ])
            ->values();

        return [
            'all' => $relations,
            'suggestions' => $this->linkSuggestions($customer),
            'spouses' => $relations->where('relationship_type', 'ehepartner')->values(),
            'children' => $relations->whereIn('relationship_type', CustomerFamilyRelation::CHILD_ROLES)->values(),
            'parents' => $relations->whereIn('relationship_type', CustomerFamilyRelation::PARENT_ROLES)->values(),
            'others' => $relations->whereIn('relationship_type', ['geschwister', 'sonstiges'])->values(),
            'guardians' => $customer->familyGuardians(),
        ];
    }

    /**
     * Vorschlaege: Kunden, die bereits als "verwandt / kein Duplikat"
     * gekennzeichnet sind (z. B. durch den gleichen Familiennamen beim
     * Einlesen mehrerer Gesundheitskarten), aber noch KEINE Familienrolle
     * haben.
     *
     * Genau hier setzt die Aufgabe an: die Akten existieren laengst und sind
     * als zusammengehoerig erkannt - es fehlt nur die Rolle. Die Rolle
     * vergibt IMMER ein Mensch; vorgeschlagen wird nie eine.
     *
     * @return Collection<int, Customer>
     */
    public function linkSuggestions(Customer $customer): Collection
    {
        $bereits = $customer->familyRelations()->pluck('related_customer_id')
            ->map(fn ($id) => (string) $id)->all();

        $ids = CustomerRelationship::query()
            ->where(fn ($q) => $q->where('customer_a_id', $customer->id)->orWhere('customer_b_id', $customer->id))
            ->get()
            ->map(fn (CustomerRelationship $r) => (string) $r->customer_a_id === (string) $customer->id
                ? (string) $r->customer_b_id
                : (string) $r->customer_a_id)
            ->reject(fn (string $id) => $id === (string) $customer->id || in_array($id, $bereits, true))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Customer::with('user')->whereIn('id', $ids)->get();
    }

    /**
     * Kinder kurz vor dem 15. Geburtstag ("Familienmitglieder mit
     * bevorstehender Verselbststaendigung"), sortiert nach verbleibender Zeit.
     *
     * @param  array<int,string>|null  $customerIds  Portfolio-Begrenzung (null = alle).
     * @return Collection<int, CustomerFamilyRelation>
     */
    public function upcomingTransitions(?array $customerIds = null, ?int $leadMonths = null): Collection
    {
        $lead = $leadMonths ?? $this->leadMonths();
        $today = Carbon::today();
        // 15. Geburtstag = Geburtsdatum + 15 Jahre. Gesucht sind Geburtstage
        // zwischen heute und heute + Vorlauf -> Geburtsdatum zwischen
        // (heute - 15 J.) und (heute - 15 J. + Vorlauf).
        $von = $today->copy()->subYears(Customer::DEPENDENT_AGE);
        $bis = $von->copy()->addMonths($lead);

        return CustomerFamilyRelation::query()
            ->where('is_dependent', true)
            ->with(['customer.user', 'relatedCustomer.user'])
            ->whereHas('relatedCustomer', function ($q) use ($von, $bis, $customerIds) {
                $q->whereNotNull('birth_date')
                    ->whereDate('birth_date', '>', $von)
                    ->whereDate('birth_date', '<=', $bis);
                if ($customerIds !== null) {
                    $q->whereIn('customers.id', $customerIds);
                }
            })
            ->get()
            ->filter(fn (CustomerFamilyRelation $r) => $r->isCurrent() && $r->independenceDate() !== null)
            ->sortBy(fn (CustomerFamilyRelation $r) => $r->independenceDate()->timestamp)
            ->values();
    }

    /**
     * Abhaengige Familienmitglieder, die das 15. Lebensjahr ERREICHT haben.
     *
     * @return Collection<int, CustomerFamilyRelation>
     */
    public function dueTransitions(): Collection
    {
        $grenze = Carbon::today()->subYears(Customer::DEPENDENT_AGE);

        return CustomerFamilyRelation::query()
            ->where('is_dependent', true)
            ->with(['customer.user', 'relatedCustomer.user'])
            ->whereHas('relatedCustomer', fn ($q) => $q->whereNotNull('birth_date')->whereDate('birth_date', '<=', $grenze))
            ->get();
    }

    /**
     * Uebergang anwenden: aus dem abhaengigen Familienmitglied wird ein
     * eigenstaendiger Kunde.
     *
     * WICHTIG (Betreiber-Vorgabe 15): es wird NICHTS geloescht, NICHTS neu
     * angelegt und KEIN Vertrag angefasst. Es faellt lediglich die
     * Abhaengigkeit weg - die FAMILIENBEZIEHUNG bleibt vollstaendig bestehen
     * (aus "Kind, abhaengig" wird "eigenstaendige Kundin, Tochter von ...").
     */
    public function applyTransition(CustomerFamilyRelation $relation, ?int $byUserId = null): void
    {
        $relation->forceFill([
            'is_dependent' => false,
            'independent_since' => now(),
        ])->save();

        $kind = $relation->relatedCustomer;
        $bezug = $relation->customer;

        if ($kind) {
            $this->note(
                $kind,
                'Eigenständiger Kunde (15. Geburtstag)',
                'Das 15. Lebensjahr ist erreicht: Status „abhängiges Familienmitglied" → „eigenständiger Kunde". '
                    .'Die Familienbeziehung zu '.($bezug?->user?->name ?: 'der Bezugsperson').' bleibt bestehen ('
                    .CustomerFamilyRelation::roleLabel($relation->relationship_type).'). '
                    .'Verträge wurden NICHT verändert – bitte eigene Verträge/Vorgänge prüfen.',
                $byUserId
            );
            $this->notifyBetreuer($kind, $relation);
        }

        ActivityLog::record('family_dependency_ended', 'customer', (string) $relation->related_customer_id, [
            'guardian_customer_id' => (string) $relation->customer_id,
            'role' => $relation->relationship_type,
        ], $byUserId);
    }

    /** Vorlaufzeit der Uebergangsliste in Monaten (Einstellung, Standard 6). */
    public function leadMonths(): int
    {
        $value = (int) SystemSetting::get('family_transition_lead_months', self::DEFAULT_LEAD_MONTHS);

        return in_array($value, self::LEAD_MONTH_CHOICES, true) ? $value : self::DEFAULT_LEAD_MONTHS;
    }

    public function setLeadMonths(int $months): void
    {
        if (in_array($months, self::LEAD_MONTH_CHOICES, true)) {
            SystemSetting::set('family_transition_lead_months', (string) $months);
        }
    }

    /**
     * Abhaengig ist ein Familienmitglied nur, wenn es als KIND verknuepft ist
     * UND sein Geburtsdatum ein Alter unter 15 belegt. Ohne Geburtsdatum wird
     * nichts angenommen - ein Alter zu raten waere schlimmer als es offen zu
     * lassen.
     */
    private function shouldBeDependent(string $role, Customer $related): bool
    {
        if (! in_array($role, CustomerFamilyRelation::CHILD_ROLES, true)) {
            return false;
        }
        $age = $related->age();

        return $age !== null && $age < Customer::DEPENDENT_AGE;
    }

    /**
     * Eine Familie ist keine Dublette: das Paar wird zusaetzlich in
     * customer_relationships eingetragen, damit es aus der Dubletten-Pruefung
     * verschwindet. Eine bereits praezisere Kennzeichnung (Ehepaar) wird nicht
     * wieder verallgemeinert.
     */
    private function exemptFromDuplicates(Customer $a, Customer $b, string $role, ?int $byUserId): void
    {
        [$x, $y] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
        $type = CustomerFamilyRelation::duplicateExemptionType($role);

        $vorhanden = CustomerRelationship::where('customer_a_id', $x)->where('customer_b_id', $y)->first();
        if ($vorhanden && $vorhanden->type === 'spouse' && $type !== 'spouse') {
            return;
        }

        CustomerRelationship::updateOrCreate(
            ['customer_a_id' => $x, 'customer_b_id' => $y],
            ['type' => $type, 'note' => 'Familienzuordnung', 'created_by' => $byUserId]
        );
    }

    /** Eintrag in der Kundenakte-Timeline. Darf den Vorgang nie scheitern lassen. */
    private function note(Customer $customer, string $title, string $description, ?int $byUserId): void
    {
        try {
            CustomerTimeline::create([
                'customer_id' => $customer->id,
                'user_id' => $byUserId,
                'type' => 'family',
                'title' => $title,
                'description' => $description,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Familien-Timeline-Eintrag fehlgeschlagen: '.$e->getMessage());
        }
    }

    /** Glocke an die Betreuer des Kindes - der Uebergang braucht eine Pruefung. */
    private function notifyBetreuer(Customer $kind, CustomerFamilyRelation $relation): void
    {
        try {
            foreach ($kind->betreuer as $user) {
                InternalNotification::updateOrCreate(
                    ['dedup_key' => 'family-transition-'.$relation->id],
                    [
                        'user_id' => $user->id,
                        'type' => 'family_transition',
                        'title' => 'Familienmitglied ist 15 geworden',
                        'body' => ($kind->user?->name ?: 'Ein Familienmitglied')
                            .' gilt jetzt als eigenständiger Kunde. Verträge wurden nicht verändert – bitte prüfen.',
                        'link' => route('admin.customer', $kind->id),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Glocke zum Familien-Uebergang fehlgeschlagen: '.$e->getMessage());
        }
    }

    /** Sortierreihenfolge der Rollengruppen in der Familienkarte. */
    private function roleOrder(?string $role): int
    {
        return match (true) {
            $role === 'ehepartner' => 0,
            in_array($role, CustomerFamilyRelation::CHILD_ROLES, true) => 1,
            in_array($role, CustomerFamilyRelation::PARENT_ROLES, true) => 2,
            $role === 'geschwister' => 3,
            default => 4,
        };
    }
}
