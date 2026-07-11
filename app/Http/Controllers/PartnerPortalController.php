<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\Customer;
use App\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Partnerportal (Grundgerüst). Ein eingeloggter Partner sieht ausschließlich
 * die IHM zugeordneten Kunden und Provisionen sowie sein Firmenprofil/Logo.
 * Alle Abfragen sind hart auf partner_id gescoped – kein Fremdzugriff.
 *
 * Bewusst als Fundament angelegt: Lese-/Übersichtszugriff + Logo. Schreibende
 * Kundenaktionen ("volle Rechte im Umgang mit ihren Kunden") folgen, sobald
 * der genaue Funktionsumfang mit dem Partner abgestimmt ist.
 */
class PartnerPortalController extends Controller
{
    /** Der zum eingeloggten User gehörende Partner (oder 403). */
    private function partner(): Partner
    {
        $partner = Partner::where('user_id', auth()->id())->first();
        abort_if($partner === null, 403, 'Kein Partnerprofil verknüpft.');
        return $partner;
    }

    public function dashboard()
    {
        $partner = $this->partner();

        return view('partner.dashboard', [
            'partner' => $partner,
            'customerCount' => $partner->customers()->count(),
            'bookedTotal' => $partner->bookedTotal(),
            'openCommissions' => $partner->commissions()->where('status', 'pending_review')->count(),
            'recentCommissions' => $partner->commissions()->limit(5)->get(),
        ]);
    }

    public function customers()
    {
        $partner = $this->partner();
        $customers = $partner->customers()
            ->with(['user', 'contracts' => fn ($q) => $q->where('status', 'active')->select('id', 'customer_id', 'type', 'status')])
            ->latest()
            ->paginate(25);

        return view('partner.customers', compact('partner', 'customers'));
    }

    public function customerShow(string $id)
    {
        $partner = $this->partner();
        // Strikt gescoped: nur ein Kunde DIESES Partners, sonst 404.
        $customer = $partner->customers()
            ->with(['user', 'contracts'])
            ->where('customers.id', $id)
            ->firstOrFail();

        return view('partner.customer_show', compact('partner', 'customer'));
    }

    public function commissions()
    {
        $partner = $this->partner();
        $commissions = $partner->commissions()->paginate(25);

        return view('partner.commissions', compact('partner', 'commissions'));
    }

    public function profile()
    {
        return view('partner.profile', ['partner' => $this->partner()]);
    }

    public function profileUpdate(Request $request)
    {
        $partner = $this->partner();

        $request->validate([
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            if ($partner->logo_path) {
                Storage::disk('public')->delete($partner->logo_path);
            }
            $partner->logo_path = $request->file('logo')->store('partner-logos', 'public');
            $partner->save();
        }

        return back()->with('success', 'Profil aktualisiert.');
    }
}
