<?php

namespace App\Http\Controllers;

use App\Models\ServicePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Adminverwaltung der Leistungsseiten (role:admin,manager). Erlaubt das
 * vollstaendige Pflegen der oeffentlichen Leistungsseiten - Texte (DE/AR),
 * Kurzinfos, FAQ, Bild und Reihenfolge - ohne Codeaenderung.
 */
class ServicePageAdminController extends Controller
{
    public function index()
    {
        $pages = ServicePage::ordered()->get();
        return view('admin.service_pages', compact('pages'));
    }

    public function create()
    {
        $page = new ServicePage(['is_active' => true, 'sort_order' => 0]);
        return view('admin.service_page_form', ['page' => $page, 'mode' => 'create']);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('service-pages', 'public');
        }
        ServicePage::create($data);

        return redirect()->route('admin.service_pages')
            ->with('status', 'Leistungsseite angelegt.');
    }

    public function edit(ServicePage $servicePage)
    {
        return view('admin.service_page_form', ['page' => $servicePage, 'mode' => 'edit']);
    }

    public function update(Request $request, ServicePage $servicePage)
    {
        $data = $this->validated($request, $servicePage->id);
        $data['updated_by'] = auth()->id();
        if ($request->hasFile('image')) {
            $old = $servicePage->image_path;
            $data['image_path'] = $request->file('image')->store('service-pages', 'public');
            if ($old) {
                try { Storage::disk('public')->delete($old); } catch (\Throwable $e) {}
            }
        }
        $servicePage->update($data);

        return redirect()->route('admin.service_pages')
            ->with('status', 'Leistungsseite gespeichert.');
    }

    public function toggle(ServicePage $servicePage)
    {
        $servicePage->update([
            'is_active' => !$servicePage->is_active,
            'updated_by' => auth()->id(),
        ]);
        return back()->with('status', 'Sichtbarkeit geaendert.');
    }

    public function destroy(ServicePage $servicePage)
    {
        if ($servicePage->image_path) {
            try { Storage::disk('public')->delete($servicePage->image_path); } catch (\Throwable $e) {}
        }
        $servicePage->delete();
        return redirect()->route('admin.service_pages')
            ->with('status', 'Leistungsseite geloescht.');
    }

    /** Validierung + Aufbau des FAQ-Arrays aus den parallelen Formularfeldern. */
    private function validated(Request $request, ?int $ignoreId): array
    {
        $data = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:120',
                Rule::unique('service_pages', 'slug')->ignore($ignoreId)],
            'category' => 'nullable|max:60',
            'icon' => 'nullable|max:16',
            'title_de' => 'required|max:255',
            'title_ar' => 'nullable|max:255',
            'subtitle_de' => 'nullable|max:255',
            'subtitle_ar' => 'nullable|max:255',
            'intro_de' => 'nullable|max:5000',
            'intro_ar' => 'nullable|max:5000',
            'highlights_de' => 'nullable|max:3000',
            'highlights_ar' => 'nullable|max:3000',
            'body_de' => 'nullable|max:20000',
            'body_ar' => 'nullable|max:20000',
            'meta_description_de' => 'nullable|max:255',
            'meta_description_ar' => 'nullable|max:255',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:65535',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['faq'] = $this->buildFaq($request);
        $data['fields'] = $this->buildFields($request);
        unset($data['image']);

        return $data;
    }

    /** Zusaetzliche Formularfelder aus den parallelen Formularfeldern aufbauen. */
    private function buildFields(Request $request): array
    {
        $labelDe = (array) $request->input('field_label_de', []);
        $labelAr = (array) $request->input('field_label_ar', []);
        $type = (array) $request->input('field_type', []);
        $optDe = (array) $request->input('field_options_de', []);
        $optAr = (array) $request->input('field_options_ar', []);
        $req = (array) $request->input('field_required', []);

        $fields = [];
        foreach ($labelDe as $i => $l) {
            $l = trim((string) $l);
            if ($l === '') {
                continue;
            }
            $t = (string) ($type[$i] ?? 'text');
            $fields[] = [
                'label_de' => $l,
                'label_ar' => trim((string) ($labelAr[$i] ?? '')),
                'type' => in_array($t, \App\Models\ServicePage::FIELD_TYPES, true) ? $t : 'text',
                'options_de' => trim((string) ($optDe[$i] ?? '')),
                'options_ar' => trim((string) ($optAr[$i] ?? '')),
                'required' => (string) ($req[$i] ?? '0') === '1',
            ];
        }
        return $fields;
    }

    private function buildFaq(Request $request): array
    {
        $qDe = (array) $request->input('faq_q_de', []);
        $qAr = (array) $request->input('faq_q_ar', []);
        $aDe = (array) $request->input('faq_a_de', []);
        $aAr = (array) $request->input('faq_a_ar', []);

        $faq = [];
        foreach ($qDe as $i => $q) {
            $row = [
                'q_de' => trim((string) ($qDe[$i] ?? '')),
                'q_ar' => trim((string) ($qAr[$i] ?? '')),
                'a_de' => trim((string) ($aDe[$i] ?? '')),
                'a_ar' => trim((string) ($aAr[$i] ?? '')),
            ];
            if ($row['q_de'] !== '' || $row['a_de'] !== '' || $row['q_ar'] !== '' || $row['a_ar'] !== '') {
                $faq[] = $row;
            }
        }
        return $faq;
    }
}
