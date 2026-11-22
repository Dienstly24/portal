<?php

namespace App\Services\Family;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\CustomerRelationship;
use App\Models\CustomerTimeline;
use App\Models\InternalNotification;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Relationships\CustomerRelationshipService;
use App\Support\Anschrift;
use App\Support\FamilienAlter;
use App\Support\FamilienName;
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
 *  3. Das Paar verschwindet aus der Dubletten-Liste, denn eine Familie ist
 *     keine Dublette: die passende Art in customer_relationships schreibt
 *     der CustomerRelationshipService in DERSELBEN Transaktion mit
 *     (Gleichlauf in beide Richtungen, Auftrag 01.10.2026).
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
     * @param  bool      $syncRelationship  false NUR fuer den Aufruf aus dem
     *                   CustomerRelationshipService (er schreibt seine Zeile selbst).
     */
    public function link(Customer $customer, Customer $related, string $role, ?int $byUserId = null, ?string $note = null, bool $syncRelationship = true): CustomerFamilyRelation
    {
        if ((string) $customer->id === (string) $related->id) {
            throw new \InvalidArgumentException('Ein Kunde kann nicht mit sich selbst verknuepft werden.');
        }
        if (! array_key_exists($role, CustomerFamilyRelation::ROLES)) {
            throw new \InvalidArgumentException('Unbekannte Familienrolle: '.$role);
        }

        $forward = DB::transaction(function () use ($customer, $related, $role, $byUserId, $note, $syncRelationship) {
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

            if ($syncRelationship) {
                app(CustomerRelationshipService::class)
                    ->syncFromFamilyRole((string) $customer->id, (string) $related->id, $role, $byUserId);
            }

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
     * wahr, auch wenn die Familienrolle nicht mehr gepflegt wird
     * (CustomerRelationshipService::syncAfterFamilyUnlink).
     */
    public function unlink(CustomerFamilyRelation $relation, ?int $byUserId = null, bool $syncRelationship = true): void
    {
        $customer = $relation->customer;
        $related = $relation->relatedCustomer;

        DB::transaction(function () use ($relation, $byUserId, $syncRelationship) {
            CustomerFamilyRelation::where('customer_id', $relation->related_customer_id)
                ->where('related_customer_id', $relation->customer_id)->delete();
            $relation->delete();
            if ($syncRelationship) {
                app(CustomerRelationshipService::class)->syncAfterFamilyUnlink(
                    (string) $relation->customer_id, (string) $relation->related_customer_id, $byUserId
                );
            }
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
     *   grandparents: Collection, grandchildren: Collection,
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
            // Moegliche Eltern eines Kindes (Betreiber-Vorgabe 22.11.2026).
            'elternVorschlaege' => $this->elternVorschlaege($customer, auth()->user()),
            'spouses' => $relations->whereIn('relationship_type', CustomerFamilyRelation::PARTNER_ROLES)->values(),
            'children' => $relations->whereIn('relationship_type', CustomerFamilyRelation::CHILD_ROLES)->values(),
            'parents' => $relations->whereIn('relationship_type', CustomerFamilyRelation::PARENT_ROLES)->values(),
            'grandchildren' => $relations->whereIn('relationship_type', CustomerFamilyRelation::GRANDCHILD_ROLES)->values(),
            'grandparents' => $relations->whereIn('relationship_type', CustomerFamilyRelation::GRANDPARENT_ROLES)->values(),
            // Alles Uebrige - nie eine Rolle, die in keiner Gruppe auftaucht.
            'others' => $relations->reject(fn (CustomerFamilyRelation $r) => in_array($r->relationship_type, array_merge(
                CustomerFamilyRelation::PARTNER_ROLES, CustomerFamilyRelation::CHILD_ROLES, CustomerFamilyRelation::PARENT_ROLES,
                CustomerFamilyRelation::GRANDCHILD_ROLES, CustomerFamilyRelation::GRANDPARENT_ROLES,
            ), true))->values(),
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

    /** Mindestabstand Elternteil - Kind in Jahren. */
    public const ELTERN_MIN_ABSTAND_JAHRE = 16;

    /**
     * Moegliche ELTERN eines Kindes (Betreiber-Vorgabe 22.11.2026: "bei
     * Kindern soll das System sofort Eltern vorschlagen"). Nur fuer ein
     * Kind (unter 18 oder ohne eigene Kundennummer) und nur, solange noch
     * KEIN Elternteil verknuepft ist.
     *
     * Kandidat ist eine Akte mit GLEICHEM Familiennamen (Skelett,
     * transkriptionstolerant) oder an DERSELBEN Anschrift - die Mutter
     * fuehrt oft einen anderen Nachnamen, wohnt aber dort. Ist bei beiden
     * das Geburtsdatum bekannt, muss der Kandidat mind. 16 Jahre aelter
     * sein; ein bekannt minderjaehriger Kandidat ist nie Elternteil.
     *
     * Vorgeschlagen wird die Rolle nach dem Geschlecht des Kandidaten
     * (Vater/Mutter, sonst Elternteil) - VERKNUEPFT wird nur per Klick.
     * Portfolio-Scope: nur Akten, die der Betrachter sehen darf.
     *
     * @return Collection<int, array<string, mixed>> je Eintrag: customer (Customer), rolle (string), gruende (list<string>)
     */
    public function elternVorschlaege(Customer $kind, ?User $betrachter = null, int $limit = 5): Collection
    {
        $alter = $kind->age();
        $istKind = ($alter !== null && $alter < 18) || blank($kind->customer_number);
        if (! $istKind) {
            return collect();
        }

        $verknuepft = $kind->familyRelations()->get();
        if ($verknuepft->whereIn('relationship_type', CustomerFamilyRelation::PARENT_ROLES)->isNotEmpty()) {
            return collect();
        }
        $bereits = $verknuepft->pluck('related_customer_id')->map(fn ($id) => (string) $id)->all();

        $kindName = (string) $kind->user?->name;
        $kern = FamilienName::kern($kindName);
        $anschrift = $this->anschriftSchluessel($kind);

        if (mb_strlen($kern) < 3 && $anschrift === '') {
            return collect();
        }

        $basis = fn () => Customer::query()->with('user')
            ->whereKeyNot($kind->id)
            ->when($betrachter !== null, fn ($q) => $q->visibleTo($betrachter));

        $kandidaten = collect();
        if (mb_strlen($kern) >= 3) {
            $kandidaten = $kandidaten->merge($basis()
                ->whereHas('user', fn ($q) => $q->where('role', 'customer')->where('name', 'like', '%'.$kern.'%'))
                ->limit(200)->get());
        }
        if ($anschrift !== '' && filled($kind->address_zip)) {
            $kandidaten = $kandidaten->merge($basis()
                ->where('address_zip', $kind->address_zip)
                ->limit(200)->get());
        }

        $kindGeburt = $kind->birth_date ? Carbon::parse($kind->birth_date) : null;

        return $kandidaten->unique(fn (Customer $c) => (string) $c->id)
            ->reject(fn (Customer $c) => in_array((string) $c->id, $bereits, true))
            ->map(function (Customer $c) use ($kindName, $anschrift, $kindGeburt) {
                $gruende = [];
                $nameGleich = FamilienName::gleich($kindName, $c->user?->name);
                $ortGleich = $anschrift !== '' && $this->anschriftSchluessel($c) === $anschrift;
                if ($nameGleich) {
                    $gruende[] = 'Gleicher Familienname';
                }
                if ($ortGleich) {
                    $gruende[] = 'Gleiche Anschrift';
                }
                if ($gruende === []) {
                    return null;
                }

                $cAlter = $c->age();
                if ($cAlter !== null && $cAlter < 18) {
                    return null; // ein Minderjaehriger ist nie Elternteil
                }
                if ($c->birth_date && $kindGeburt) {
                    $abstand = Carbon::parse($c->birth_date)->diffInYears($kindGeburt, false);
                    if ($abstand < self::ELTERN_MIN_ABSTAND_JAHRE) {
                        return null;
                    }
                    $gruende[] = (int) $abstand.' Jahre aelter';
                } else {
                    $gruende[] = 'Geburtsdatum fehlt - bitte pruefen';
                }

                return [
                    'customer' => $c,
                    'rolle' => match ($c->gender) {
                        'male' => 'vater',
                        'female' => 'mutter',
                        default => 'elternteil',
                    },
                    'gruende' => $gruende,
                    'gewicht' => ($nameGleich ? 2 : 0) + ($ortGleich ? 2 : 0) + ($c->birth_date && $kindGeburt ? 1 : 0),
                ];
            })
            ->filter()
            ->sortByDesc('gewicht')
            ->take($limit)
            ->map(fn (array $v) => ['customer' => $v['customer'], 'rolle' => $v['rolle'], 'gruende' => $v['gruende']])
            ->values();
    }

    /** Strasse + Hausnummer + PLZ als Vergleichsschluessel; leer ohne Strasse. */
    private function anschriftSchluessel(Customer $c): string
    {
        if (blank($c->address_street) || blank($c->address_zip)) {
            return '';
        }

        return Anschrift::schluessel(
            $c->address_street.' '.$c->address_house_number.' '.$c->address_zip
        );
    }

    /**
     * Kinder kurz vor dem Selbststaendigkeitsalter (Einstellung, Standard
     * 16), sortiert nach verbleibender Zeit.
     *
     * @param  array<int,string>|null  $customerIds  Portfolio-Begrenzung (null = alle).
     * @return Collection<int, CustomerFamilyRelation>
     */
    public function upcomingTransitions(?array $customerIds = null, ?int $leadMonths = null): Collection
    {
        $lead = $leadMonths ?? $this->leadMonths();
        $today = Carbon::today();
        // Stichtag = Geburtsdatum + N Jahre (N = Selbststaendigkeitsalter).
        // Gesucht sind Stichtage zwischen heute und heute + Vorlauf ->
        // Geburtsdatum zwischen (heute - N J.) und (heute - N J. + Vorlauf).
        $von = $today->copy()->subYears(FamilienAlter::selbststaendig());
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
     * Abhaengige Familienmitglieder, die das Selbststaendigkeitsalter
     * ERREICHT haben.
     *
     * @return Collection<int, CustomerFamilyRelation>
     */
    public function dueTransitions(): Collection
    {
        $grenze = Carbon::today()->subYears(FamilienAlter::selbststaendig());

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
            $alter = FamilienAlter::selbststaendig();
            $this->note(
                $kind,
                'Eigenständiger Kunde ('.$alter.'. Geburtstag)',
                'Das '.$alter.'. Lebensjahr ist erreicht: Status „abhängiges Familienmitglied" → „eigenständiger Kunde". '
                    .'Die Familienbeziehung zu '.($bezug?->user?->name ?: 'der Bezugsperson').' bleibt bestehen ('
                    .CustomerFamilyRelation::roleLabel($relation->relationship_type).'). '
                    .(blank($kind->customer_number)
                        ? 'Eine eigene Kundennummer wird NICHT automatisch vergeben – das Team vergibt sie in der Akte („Kundennummer vergeben") und lädt dann ins Portal ein. '
                        : '')
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
     * UND sein Geburtsdatum ein Alter unter dem Selbststaendigkeitsalter
     * belegt - ODER es (noch) keine eigene Kundennummer traegt (KI-096: als
     * Kind angelegt, Geburtsdatum fehlt). Ohne Geburtsdatum wird
     * nichts angenommen - ein Alter zu raten waere schlimmer als es offen zu
     * lassen.
     */
    private function shouldBeDependent(string $role, Customer $related): bool
    {
        if (! in_array($role, CustomerFamilyRelation::CHILD_ROLES, true)) {
            return false;
        }
        if ($related->exists && blank($related->customer_number) && $related->age() === null) {
            return true;
        }
        $age = $related->age();

        return $age !== null && $age < FamilienAlter::selbststaendig();
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
                        'title' => 'Familienmitglied ist '.FamilienAlter::selbststaendig().' geworden',
                        'body' => ($kind->user?->name ?: 'Ein Familienmitglied')
                            .' gilt jetzt als eigenständiger Kunde.'
                            .(blank($kind->customer_number) ? ' Bitte Kundennummer vergeben und Portal aktivieren.' : '')
                            .' Verträge wurden nicht verändert – bitte prüfen.',
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
            in_array($role, CustomerFamilyRelation::PARTNER_ROLES, true) => 0,
            in_array($role, CustomerFamilyRelation::CHILD_ROLES, true) => 1,
            in_array($role, CustomerFamilyRelation::PARENT_ROLES, true) => 2,
            in_array($role, CustomerFamilyRelation::GRANDCHILD_ROLES, true) => 3,
            in_array($role, CustomerFamilyRelation::GRANDPARENT_ROLES, true) => 4,
            $role === 'geschwister' => 5,
            default => 6,
        };
    }
}
