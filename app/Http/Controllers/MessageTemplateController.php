<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\MessageTemplate;
use Illuminate\Http\Request;

/**
 * Verwaltung der Nachrichten-/E-Mail-Vorlagen.
 * CRUD nur admin/manager (Routen-Middleware); die JSON-Endpunkte
 * (Liste + Rendern) nutzen alle Staff-Rollen in den Composern.
 */
class MessageTemplateController extends Controller
{
    public function index()
    {
        $templates = MessageTemplate::orderBy('category')->orderBy('sort')->orderBy('name')->get();
        return view('admin.message_templates', [
            'templates' => $templates,
            'placeholders' => MessageTemplate::PLACEHOLDERS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        MessageTemplate::create($data + ['created_by' => auth()->id()]);
        return redirect()->route('admin.templates')->with('success', 'Vorlage angelegt.');
    }

    public function update(Request $request, $id)
    {
        $template = MessageTemplate::findOrFail($id);
        $template->update($this->validated($request));
        return redirect()->route('admin.templates')->with('success', 'Vorlage aktualisiert.');
    }

    public function destroy($id)
    {
        MessageTemplate::findOrFail($id)->delete();
        return redirect()->route('admin.templates')->with('success', 'Vorlage gelöscht.');
    }

    /** Startpaket deutscher Standard-Vorlagen anlegen (idempotent). */
    public function seedDefaults()
    {
        $created = MessageTemplate::seedDefaults(auth()->id());
        return redirect()->route('admin.templates')->with('success',
            $created > 0 ? $created . ' Standard-Vorlagen angelegt.' : 'Alle Standard-Vorlagen sind bereits vorhanden.');
    }

    /** JSON-Liste fuer die Composer-Dropdowns (alle Staff-Rollen). */
    public function list(Request $request)
    {
        $templates = MessageTemplate::query()
            ->when($request->filled('category'), fn($q) => $q->where('category', $request->category))
            ->orderBy('sort')->orderBy('name')
            ->get(['id', 'name', 'category', 'subject']);
        return response()->json(['templates' => $templates]);
    }

    /**
     * Vorlage fuer einen konkreten Kunden rendern (Platzhalter ersetzen).
     * Ohne customer_id werden nur Berater/Datum ersetzt.
     */
    public function render(Request $request, $id)
    {
        $template = MessageTemplate::findOrFail($id);
        $customer = null;
        if ($request->filled('customer_id')) {
            abort_unless(auth()->user()->canAccessCustomer($request->customer_id), 403);
            $customer = Customer::with('user')->findOrFail($request->customer_id);
        }
        return response()->json([
            'subject' => $template->renderSubject($customer, auth()->user()),
            'body' => $template->renderBody($customer, auth()->user()),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'category' => 'required|in:' . implode(',', array_keys(MessageTemplate::CATEGORIES)),
            'subject' => 'nullable|string|max:200',
            'body' => 'required|string|max:10000',
            'sort' => 'nullable|integer|min:0|max:9999',
        ]);
    }
}
