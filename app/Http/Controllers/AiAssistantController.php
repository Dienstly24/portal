<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AiConversation;
use App\Models\AiKnowledgeEntry;
use App\Models\Customer;
use Illuminate\Http\Request;

/**
 * Mitarbeiter-Steuerung des KI-Kundenassistenten (Spezifikation
 * Abschnitte 15/27) und Pflege der Wissensbasis (Abschnitt 19).
 *
 * Menschliche Kontrolle ist das Leitprinzip: ein Mitarbeiter kann jederzeit
 * uebernehmen, die KI stumm schalten oder wieder freigeben. Jede dieser
 * Aktionen ist bewusst und wird protokolliert.
 *
 * Zugriff auf die Steuerung: alle Staff-Rollen, aber nur fuer Kunden im
 * eigenen Portfolio (canAccessCustomer - gleiche Regel wie im Kunden-Chat).
 * Die Wissensbasis pflegen nur admin/manager (Route-Middleware) - was dort
 * steht, sagt der Assistent allen Kunden.
 */
class AiAssistantController extends Controller
{
    /** Mitarbeiter uebernimmt: KI schweigt ab jetzt. */
    public function takeOver($customerId)
    {
        $customer = $this->authorizedCustomer($customerId);
        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->takeOver(auth()->id());

        $this->log('ai_assistant_take_over', $customer);

        return back()->with('success', 'Sie haben die Unterhaltung übernommen. Der KI-Assistent antwortet nicht mehr automatisch.');
    }

    /** KI fuer diesen Kunden stumm schalten (ohne Uebergabe zu behaupten). */
    public function deactivate($customerId)
    {
        $customer = $this->authorizedCustomer($customerId);
        AiConversation::forCustomer($customer->id)->deactivate();

        $this->log('ai_assistant_deactivated', $customer);

        return back()->with('success', 'Der KI-Assistent ist für diesen Kunden deaktiviert.');
    }

    /** KI wieder freigeben - bewusste Mitarbeiter-Aktion. */
    public function reactivate($customerId)
    {
        $customer = $this->authorizedCustomer($customerId);
        AiConversation::forCustomer($customer->id)->reactivate();

        $this->log('ai_assistant_reactivated', $customer);

        return back()->with('success', 'Der KI-Assistent ist wieder aktiv und beantwortet einfache Anfragen.');
    }

    /**
     * Wissensbasis (Abschnitt 19). Nur was hier steht, darf der Assistent
     * als allgemeine Auskunft geben - alles andere geht an das Team.
     */
    public function knowledgeIndex(Request $request)
    {
        $entries = AiKnowledgeEntry::with(['creator', 'editor'])
            ->when($request->query('kategorie'), fn ($q, $c) => $q->where('category', $c))
            ->when(trim((string) $request->query('q')) !== '', function ($q) use ($request) {
                $term = '%' . trim((string) $request->query('q')) . '%';
                $q->where(fn ($q) => $q->where('title', 'like', $term)
                    ->orWhere('content', 'like', $term)
                    ->orWhere('keywords', 'like', $term));
            })
            ->orderBy('category')->orderBy('title')
            ->paginate(25)
            ->withQueryString();

        return view('admin.ai_knowledge', [
            'entries' => $entries,
            'categories' => AiKnowledgeEntry::CATEGORIES,
            'languages' => AiKnowledgeEntry::LANGUAGES,
        ]);
    }

    public function knowledgeStore(Request $request)
    {
        $data = $this->validateEntry($request);

        $entry = AiKnowledgeEntry::create($data + [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'ai_knowledge_created',
            'entity_type' => 'ai_knowledge_entry',
            'entity_id' => $entry->id,
            'meta' => json_encode(['title' => $entry->title], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Wissensbasis-Eintrag angelegt.');
    }

    public function knowledgeUpdate(Request $request, $id)
    {
        $entry = AiKnowledgeEntry::findOrFail($id);
        $entry->update($this->validateEntry($request) + ['updated_by' => auth()->id()]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'ai_knowledge_updated',
            'entity_type' => 'ai_knowledge_entry',
            'entity_id' => $entry->id,
            'meta' => json_encode(['title' => $entry->title], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Wissensbasis-Eintrag aktualisiert.');
    }

    public function knowledgeDestroy($id)
    {
        $entry = AiKnowledgeEntry::findOrFail($id);
        $title = $entry->title;
        $entry->delete();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'ai_knowledge_deleted',
            'entity_type' => 'ai_knowledge_entry',
            'entity_id' => $id,
            'meta' => json_encode(['title' => $title], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Wissensbasis-Eintrag gelöscht.');
    }

    /** @return array<string,mixed> */
    private function validateEntry(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|string|in:' . implode(',', array_keys(AiKnowledgeEntry::CATEGORIES)),
            'content' => 'required|string|max:8000',
            'language' => 'nullable|string|in:' . implode(',', array_keys(AiKnowledgeEntry::LANGUAGES)),
            'keywords' => 'nullable|string|max:500',
            'active' => 'nullable|boolean',
        ]);

        // Nicht gesendete Felder fehlen im validierten Datensatz: Sprache
        // leer = sprachneutral (gilt fuer alle), Checkbox fehlt = inaktiv.
        $data['active'] = $request->boolean('active');
        $data['language'] = ($data['language'] ?? null) ?: null;
        $data['keywords'] = $data['keywords'] ?? null;

        return $data;
    }

    private function authorizedCustomer($customerId): Customer
    {
        abort_unless(auth()->user()->canAccessCustomer($customerId), 403);

        return Customer::findOrFail($customerId);
    }

    private function log(string $action, Customer $customer): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode(['customer_number' => $customer->customer_number], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
