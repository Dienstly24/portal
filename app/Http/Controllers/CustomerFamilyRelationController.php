<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\Task;
use App\Services\Family\FamilyRelationService;
use Illuminate\Http\Request;

/**
 * Familien- und Kundenbeziehungen im Kundenprofil (Betreiber-Vorgabe
 * 28.08.2026).
 *
 * Kernpunkt: hier entsteht NIE ein Kunde. Verknuepft werden ausschliesslich
 * BESTEHENDE Akten - genau darum geht es bei der Aufgabe: aus mehreren
 * Gesundheitskarten sind einzelne Kunden geworden, und die sollen jetzt eine
 * Familie ergeben, ohne dass eine Akte verloren geht.
 */
class CustomerFamilyRelationController extends Controller
{
    use ScopesCustomerAccess;

    public function __construct(private FamilyRelationService $service) {}

    /** 403, wenn der eingeloggte Mitarbeiter diesen Kunden nicht sehen darf. */
    private function authorizeCustomerAccess($customerId): void
    {
        $ids = $this->visibleCustomerIds();
        if ($ids !== null && !in_array((string) $customerId, array_map('strval', $ids), true)) {
            abort(403, 'Kein Zugriff auf diesen Kunden.');
        }
    }

    /**
     * Sofort-Suche nach BESTEHENDEN Kunden fuer die Familienzuordnung.
     *
     * Gesucht wird ueber Customer::scopeSearch (Vorname, Nachname,
     * Kundennummer, E-Mail, Telefon, Anschrift) - erweitert um das
     * GEBURTSDATUM, das bei gleichnamigen Familienmitgliedern oft das einzige
     * unterscheidende Merkmal ist.
     *
     * Bereits verknuepfte Kunden und der Kunde selbst tauchen nicht auf: man
     * kann sich weder mit sich selbst verknuepfen noch dieselbe Beziehung
     * zweimal anlegen.
     */
    public function search(Request $request, string $customerId)
    {
        $this->authorizeCustomerAccess($customerId);
        $customer = Customer::findOrFail($customerId);

        $bereitsVerknuepft = $customer->familyRelations()->pluck('related_customer_id')->all();
        $ausschluss = array_merge([(string) $customer->id], array_map('strval', $bereitsVerknuepft));

        $q = trim((string) $request->query('q', ''));
        $ids = $this->visibleCustomerIds();

        $basis = Customer::with('user')
            ->when($ids !== null, fn ($query) => $query->whereIn('customers.id', $ids))
            ->whereNotIn('customers.id', $ausschluss);

        $treffer = ($q === '' ? $basis->latest() : $basis->search($q))->take(10)->get();

        return response()->json([
            'customers' => $treffer->map(function (Customer $c) {
                $status = $c->familyStatus();

                return [
                    'id' => (string) $c->id,
                    'name' => $c->user?->name ?? '—',
                    'number' => $c->customer_number,
                    // Interne Platzhalter-Adressen sind KEIN Kontakt und werden
                    // nirgends als solcher ausgegeben.
                    'email' => $c->user?->hasRealEmail() ? $c->user->email : null,
                    'phone' => $c->mobile ?: $c->phone,
                    'birth_date' => $c->birth_date ? \Illuminate\Support\Carbon::parse($c->birth_date)->format('d.m.Y') : null,
                    'age' => $c->age(),
                    'address' => $c->fullAddress() ?: null,
                    'status' => $status['short'],
                ];
            })->values(),
        ]);
    }

    /**
     * Bestehenden Kunden als Familienmitglied verknuepfen.
     *
     * Es wird NICHTS angelegt, NICHTS kopiert und NICHTS geloescht - nur die
     * Beziehung entsteht (in beiden Richtungen).
     */
    public function link(Request $request, string $customerId)
    {
        $this->authorizeCustomerAccess($customerId);

        $data = $request->validate([
            'related_customer_id' => 'required|uuid|exists:customers,id',
            'relationship_type' => 'required|in:' . implode(',', array_keys(CustomerFamilyRelation::ROLES)),
            'note' => 'nullable|string|max:255',
        ], [], [
            'related_customer_id' => 'Kunde',
            'relationship_type' => 'Beziehung',
        ]);

        // Beide Seiten muessen im Portfolio liegen - sonst koennte man ueber
        // die Verknuepfung einen fremden Kunden sichtbar machen.
        $this->authorizeCustomerAccess($data['related_customer_id']);

        if ((string) $customerId === (string) $data['related_customer_id']) {
            return back()->withErrors(['related_customer_id' => 'Ein Kunde kann nicht mit sich selbst verknüpft werden.']);
        }

        $customer = Customer::with('user')->findOrFail($customerId);
        $related = Customer::with('user')->findOrFail($data['related_customer_id']);

        $relation = $this->service->link($customer, $related, $data['relationship_type'], auth()->id(), $data['note'] ?? null);

        $hinweis = $relation->is_dependent
            ? ' Als abhängiges Familienmitglied geführt (unter ' . Customer::DEPENDENT_AGE . ' Jahren) – die Kundenakte bleibt vollständig erhalten.'
            : '';

        return back()->with('success', ($related->user?->name ?: 'Kunde') . ' wurde als '
            . CustomerFamilyRelation::roleLabel($data['relationship_type']) . ' verknüpft.' . $hinweis);
    }

    /** Rolle einer bestehenden Verknuepfung aendern (beide Richtungen). */
    public function updateRole(Request $request, string $customerId, string $relationId)
    {
        $this->authorizeCustomerAccess($customerId);

        $data = $request->validate([
            'relationship_type' => 'required|in:' . implode(',', array_keys(CustomerFamilyRelation::ROLES)),
        ]);

        $relation = CustomerFamilyRelation::where('customer_id', $customerId)->findOrFail($relationId);
        $this->authorizeCustomerAccess($relation->related_customer_id);

        $this->service->link(
            $relation->customer,
            $relation->relatedCustomer,
            $data['relationship_type'],
            auth()->id(),
            $relation->note
        );

        return back()->with('success', 'Beziehung auf „' . CustomerFamilyRelation::roleLabel($data['relationship_type']) . '" geändert.');
    }

    /** Verknuepfung loesen. Beide Kundenakten bleiben vollstaendig bestehen. */
    public function unlink(string $customerId, string $relationId)
    {
        $this->authorizeCustomerAccess($customerId);

        $relation = CustomerFamilyRelation::with(['customer.user', 'relatedCustomer.user'])
            ->where('customer_id', $customerId)->findOrFail($relationId);
        $this->authorizeCustomerAccess($relation->related_customer_id);

        $name = $relation->relatedCustomer?->user?->name ?: 'Kunde';
        $this->service->unlink($relation, auth()->id());

        return back()->with('success', 'Verknüpfung mit ' . $name . ' aufgehoben. Die Kundenakte bleibt unverändert bestehen.');
    }

    /**
     * "Kinder werden 15" - Familienmitglieder mit bevorstehender
     * Verselbststaendigung, sortiert nach verbleibender Zeit.
     */
    public function transitions(Request $request)
    {
        $lead = (int) $request->query('vorlauf', 0);
        if (in_array($lead, FamilyRelationService::LEAD_MONTH_CHOICES, true)
            && in_array(auth()->user()->role, ['admin', 'manager'], true)
            && $request->boolean('speichern')) {
            $this->service->setLeadMonths($lead);
        }
        $lead = in_array($lead, FamilyRelationService::LEAD_MONTH_CHOICES, true) ? $lead : $this->service->leadMonths();

        return view('admin.family_transitions', [
            'relations' => $this->service->upcomingTransitions($this->visibleCustomerIds(), $lead),
            'leadMonths' => $lead,
            'gespeicherteVorlaufzeit' => $this->service->leadMonths(),
        ]);
    }

    /**
     * "Übergang vorbereiten": legt eine Wiedervorlage an und vermerkt die
     * Vorbereitung an der Beziehung.
     *
     * Es wird bewusst KEIN Vertrag geaendert und keiner angelegt (Betreiber-
     * Vorgabe 15) - das System weist nur auf die noetige Pruefung hin, die
     * Entscheidung bleibt beim Mitarbeiter.
     */
    public function prepareTransition(string $relationId)
    {
        $relation = CustomerFamilyRelation::with(['customer.user', 'relatedCustomer.user'])->findOrFail($relationId);
        $this->authorizeCustomerAccess($relation->related_customer_id);

        $kind = $relation->relatedCustomer;
        $stichtag = $relation->independenceDate();

        $relation->forceFill(['transition_prepared_at' => now()])->save();

        Task::forceCreate([
            'title' => 'Übergang vorbereiten: ' . ($kind?->user?->name ?: 'Familienmitglied') . ' wird 15',
            'description' => 'Familienmitglied von ' . ($relation->customer?->user?->name ?: '—') . '. '
                . '15. Geburtstag: ' . ($stichtag ? $stichtag->format('d.m.Y') : 'unbekannt') . '. '
                . 'Zu prüfen: eigene Verträge/Vorgänge, eigene Kontaktdaten (bisher von der Bezugsperson übernommen), '
                . 'Portal-Zugang. Es wird nichts automatisch geändert.',
            'type' => 'reminder',
            'status' => 'open',
            'priority' => 'medium',
            'due_date' => $stichtag ? $stichtag->toDateString() : now()->addDays(14)->toDateString(),
            'created_by' => auth()->id(),
            'assigned_to' => auth()->id(),
            'customer_id' => $kind?->id,
        ]);

        return back()->with('success', 'Übergang vorgemerkt: eine Wiedervorlage wurde angelegt. '
            . 'Verträge wurden bewusst NICHT verändert – das bleibt eine bewusste Entscheidung.');
    }
}
