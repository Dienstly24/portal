<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\VertragBeteiligter;
use App\Services\Vertrag\VertragBeteiligteService;
use Illuminate\Http\Request;

/**
 * Weitere Personen eines Vertrags (PR-6). Verlangt Zugriff auf den
 * Versicherungsnehmer UND - wenn verknuepft - auf die beteiligte Akte:
 * ueber einen Vertrag darf kein fremder Kunde sichtbar werden.
 */
class VertragBeteiligteController extends Controller
{
    use ScopesCustomerAccess;

    public function __construct(private readonly VertragBeteiligteService $beteiligte) {}

    public function store(Request $request, string $id)
    {
        $vertrag = Contract::findOrFail($id);
        $this->authorizeCustomerAccess($vertrag->customer_id);

        $data = $request->validate([
            'rolle' => 'required|in:'.implode(',', array_keys(VertragBeteiligter::ROLLEN)),
            'customer_id' => 'nullable|string',
            'name' => 'nullable|string|max:160|required_without:customer_id',
            'geburtsdatum' => 'nullable|date|before_or_equal:today',
            'anteil_prozent' => 'nullable|numeric|gt:0|max:100',
            'notiz' => 'nullable|string|max:255',
        ], [
            'name.required_without' => 'Bitte einen Kunden aus der Suche auswählen oder einen Namen eintragen.',
        ]);

        $kunde = null;
        if (! empty($data['customer_id'])) {
            $this->authorizeCustomerAccess($data['customer_id']);
            $kunde = Customer::with('user')->findOrFail($data['customer_id']);
        }

        try {
            $b = $this->beteiligte->hinzufuegen($vertrag, $data['rolle'], $kunde, $data, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $b->rolleLabel().' eingetragen: '.$b->anzeigeName().'.');
    }

    public function destroy(int $id)
    {
        $b = VertragBeteiligter::with('contract')->findOrFail($id);
        $this->authorizeCustomerAccess($b->contract->customer_id);
        $this->beteiligte->entfernen($b, auth()->id());

        return back()->with('success', $b->rolleLabel().' entfernt - die Angabe bleibt im Änderungsverlauf des Vertrags lesbar.');
    }
}
