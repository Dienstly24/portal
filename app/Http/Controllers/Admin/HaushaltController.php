<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Haushalt;
use App\Models\HaushaltMitglied;
use App\Services\Haushalt\HaushaltService;
use Illuminate\Http\Request;

/**
 * Haushalt in der Kundenakte (PR-5b). Jede Aktion verlangt Zugriff auf JEDE
 * beteiligte Akte - ueber einen Haushalt darf kein fremder Kunde sichtbar
 * oder veraenderbar werden.
 */
class HaushaltController extends Controller
{
    use ScopesCustomerAccess;

    public function __construct(private readonly HaushaltService $haushalte) {}

    /** Neuen Haushalt mit diesem Kunden gruenden. */
    public function gruenden(Request $request, string $id)
    {
        $data = $request->validate(['name' => 'nullable|string|max:120']);
        $this->authorizeCustomerAccess($id);
        $kunde = Customer::findOrFail($id);

        try {
            $this->haushalte->gruenden($kunde, $data['name'] ?? null, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Haushalt angelegt.');
    }

    /** Weiteren Kunden in den Haushalt aufnehmen. */
    public function aufnehmen(Request $request, string $haushaltId)
    {
        $data = $request->validate([
            'customer_id' => 'required|string',
            'von_kunde' => 'required|string',
        ], ['customer_id.required' => 'Bitte zuerst einen Kunden aus der Suche auswählen.']);
        $haushalt = Haushalt::findOrFail($haushaltId);
        $this->authorizeCustomerAccess($data['von_kunde']);
        $this->authorizeCustomerAccess($data['customer_id']);
        $this->pruefeMitglied($haushalt, $data['von_kunde']);
        $kunde = Customer::findOrFail($data['customer_id']);

        try {
            $this->haushalte->aufnehmen($haushalt, $kunde, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', ($kunde->user?->name ?: 'Kunde').' in den Haushalt aufgenommen.');
    }

    public function austragen(int $id)
    {
        $mitglied = HaushaltMitglied::findOrFail($id);
        $this->authorizeCustomerAccess($mitglied->customer_id);
        $this->haushalte->austragen($mitglied, auth()->id());

        return back()->with('success', 'Aus dem Haushalt ausgetragen - die Kundenakte bleibt unverändert.');
    }

    public function hauptansprechpartner(int $id)
    {
        $mitglied = HaushaltMitglied::findOrFail($id);
        $this->authorizeCustomerAccess($mitglied->customer_id);
        try {
            $this->haushalte->hauptansprechpartnerSetzen($mitglied, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Hauptansprechpartner festgelegt.');
    }

    public function beitragszahler(int $id)
    {
        $mitglied = HaushaltMitglied::findOrFail($id);
        $this->authorizeCustomerAccess($mitglied->customer_id);
        try {
            $this->haushalte->beitragszahlerUmschalten($mitglied, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Beitragszahler geändert.');
    }

    /** Wer aufnimmt, muss selbst aus diesem Haushalt kommen (die Akte, von der aus geklickt wurde). */
    private function pruefeMitglied(Haushalt $haushalt, string $kundeId): void
    {
        if (! $haushalt->aktuelleMitglieder()->where('customer_id', $kundeId)->exists()) {
            abort(403, 'Diese Akte gehört nicht zu diesem Haushalt.');
        }
    }
}
