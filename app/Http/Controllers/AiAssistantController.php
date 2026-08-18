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

    // ---------------------------------------------------------------
    // Verkaufsassistent (Betreiber-Auftrag 18.08.2026)
    // ---------------------------------------------------------------

    /**
     * Angebot fuer ein Gespraech hinterlegen (Abschnitt 5).
     *
     * Phase 1: das ist die zentrale Mitarbeiter-Aktion. Sobald das
     * Angebot steht, fuehrt die KI das Gespraech weiter - deshalb wird
     * die Unterhaltung hier auch NICHT stumm geschaltet.
     */
    public function storeOffer(Request $request, $customerId)
    {
        $customer = $this->authorizedCustomer($customerId);

        $data = $request->validate([
            'label' => 'required|string|max:10',
            'provider' => 'nullable|string|max:120',
            'product' => 'required|string|max:160',
            'speed' => 'nullable|string|max:60',
            'price' => 'nullable|numeric|min:0|max:99999',
            'price_period' => 'nullable|string|max:20',
            'duration_months' => 'nullable|integer|min:0|max:120',
            'terms' => 'nullable|string|max:1000',
        ]);

        $conversation = AiConversation::forCustomer($customer->id);

        // Gleiche Kennung nicht doppelt: der Mitarbeiter korrigiert damit
        // ein Angebot, statt ein zweites "A" anzulegen.
        $angebot = \App\Models\AiOffer::updateOrCreate(
            [
                'conversation_id' => $conversation->id,
                'label' => mb_strtoupper(trim($data['label'])),
            ],
            [
                'provider' => $data['provider'] ?? null,
                'product' => $data['product'],
                'speed' => $data['speed'] ?? null,
                'price' => $data['price'] ?? null,
                'price_period' => $data['price_period'] ?: 'monat',
                'duration_months' => $data['duration_months'] ?? null,
                'terms' => $data['terms'] ?? null,
                'origin' => \App\Models\AiOffer::ORIGIN_EMPLOYEE,
                'created_by' => auth()->id(),
            ]
        );

        app(\App\Services\Ai\Assistant\Sales\ConversationJournal::class)->record(
            $conversation,
            \App\Models\AiConversationEvent::EVENT_OFFER_ADDED,
            ['angebot' => $angebot->label, 'produkt' => $angebot->product],
            \App\Models\AiConversationEvent::ACTOR_STAFF,
            auth()->id(),
        );

        // Der Vorgang wartet nicht mehr - die KI darf wieder fuehren.
        $conversation->resume();
        $conversation->moveTo(
            \App\Services\Ai\Assistant\Sales\ConversationState::OFFER_PRESENTED,
            'Angebot hinterlegt'
        );

        $this->log('ai_assistant_offer_added', $customer);

        return back()->with('success', 'Angebot ' . $angebot->label
            . ' hinterlegt. Der Assistent stellt es dem Kunden bei der nächsten Nachricht vor.');
    }

    /** Angebot wieder entfernen (Tippfehler, ueberholtes Angebot). */
    public function destroyOffer($customerId, $offerId)
    {
        $customer = $this->authorizedCustomer($customerId);
        $conversation = AiConversation::forCustomer($customer->id);

        $angebot = \App\Models\AiOffer::where('conversation_id', $conversation->id)
            ->findOrFail($offerId);

        // Ein bereits GEWAEHLTES Angebot bleibt stehen: es ist Teil der
        // Vorgangsgeschichte, und der Kunde hat ihm zugestimmt.
        if ($angebot->isSelected()) {
            return back()->with('error', 'Dieses Angebot hat der Kunde bereits gewählt und '
                . 'kann nicht gelöscht werden.');
        }

        $angebot->delete();
        $this->log('ai_assistant_offer_removed', $customer);

        return back()->with('success', 'Angebot entfernt.');
    }

    /**
     * Antwortvorschlag fuer den Mitarbeiter (Abschnitt 16). Wird NIE
     * automatisch gesendet - der Mitarbeiter entscheidet.
     */
    public function suggestReply($customerId, \App\Services\Ai\Assistant\EmployeeAssistantService $assistant)
    {
        $customer = $this->authorizedCustomer($customerId);
        $conversation = AiConversation::forCustomer($customer->id);

        $ergebnis = $assistant->suggestReply($customer, $conversation);
        $this->log('ai_assistant_reply_suggested', $customer);

        return response()->json([
            'ok' => $ergebnis['vorschlag'] !== null,
            'vorschlag' => $ergebnis['vorschlag'],
            'fehler' => $ergebnis['fehler'],
        ]);
    }

    /**
     * Nach einer Stoerung erneut versuchen (Abschnitt 13): die letzte
     * unbeantwortete Kundennachricht wird noch einmal angestossen.
     */
    public function retry($customerId)
    {
        $customer = $this->authorizedCustomer($customerId);
        $conversation = AiConversation::forCustomer($customer->id);

        $letzte = \App\Models\CustomerMessage::where('customer_id', $customer->id)
            ->fromCustomer()
            ->latest()
            ->first();

        if (!$letzte) {
            return back()->with('error', 'Es liegt keine Kundennachricht vor.');
        }

        // Die Sperre gegen doppelte Antworten haengt am Protokoll-Eintrag
        // der Nachricht - fuer einen bewussten neuen Versuch wird er
        // entfernt, sonst passiert wieder nichts.
        \App\Models\AiAssistantLog::where('customer_message_id', $letzte->id)->delete();

        $conversation->resume();
        $conversation->reactivate();

        \App\Jobs\AnswerCustomerMessageJob::dispatch($letzte->id);

        $this->log('ai_assistant_retry', $customer);

        return back()->with('success', 'Neuer Versuch gestartet. Die Antwort erscheint in Kürze im Chat.');
    }

    /** Interessenten aus dem Website-Assistenten (Abschnitt 20). */
    public function leads(Request $request)
    {
        $leads = \App\Models\AiLead::query()
            ->when($request->filled('zustand'), fn ($q) => $q->where('state', $request->string('zustand')))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.ai_leads', [
            'leads' => $leads,
            'zustand' => $request->string('zustand')->toString(),
        ]);
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
