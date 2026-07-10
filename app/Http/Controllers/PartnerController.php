<?php
namespace App\Http\Controllers;

use App\Models\Partner;
use Illuminate\Http\Request;

/**
 * Partnerverwaltung (Architekturplan Abschnitt 16 / Priorität 6).
 * Eigenständiger Controller - bewusst NICHT im AdminController
 * (siehe Architekturplan Abschnitt 20.5, Controller-Größe).
 */
class PartnerController extends Controller
{
    public function index()
    {
        $partners = Partner::withCount('commissions')->orderBy('name')->get();
        return view('admin.partners', compact('partners'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        Partner::create($data);
        return back()->with('success', 'Partner angelegt.');
    }

    public function show($id)
    {
        $partner = Partner::with(['commissions.reviewer', 'externalReferences'])->findOrFail($id);
        return view('admin.partner_show', compact('partner'));
    }

    public function update(Request $request, $id)
    {
        $partner = Partner::findOrFail($id);
        $partner->update($this->validated($request));
        return back()->with('success', 'Partner aktualisiert.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'partner_number' => 'nullable|string|max:100',
            'contact_email' => 'nullable|email|max:255',
            'email_domains' => 'nullable|string|max:1000',
            'iban' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:5000',
            'is_active' => 'nullable|boolean',
        ]);

        // Komma-/zeilengetrennte Eingabe -> normalisierte Domain-Liste
        $data['email_domains'] = collect(preg_split('/[,\s;]+/', (string) ($data['email_domains'] ?? '')))
            ->map(fn ($d) => mb_strtolower(trim($d, " \t@")))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
