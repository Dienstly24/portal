<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AiConversation;
use App\Models\AiKnowledgeEntry;
use App\Models\AiKnowledgeGap;
use App\Models\Customer;
use App\Services\Ai\Assistant\KnowledgeBase;
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

        // Die Uebernahme gilt dem VORGANG, nicht dem Kunden
        // (Betreiber-Vorgabe 20.08.2026): der juengste offene Vorgang gibt
        // die KI wieder frei, sobald er abgeschlossen ist; ersatzweise
        // greift die Ruhefrist. Wer die KI dauerhaft aus haben will,
        // nutzt "KI deaktivieren".
        $einstellungen = app(\App\Services\Ai\Assistant\AssistantSettings::class);
        $vorgang = \App\Models\Ticket::where('customer_id', $customer->id)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->latest()->first();

        $conversation->takeOver(auth()->id(), $vorgang?->id, $einstellungen->resumeQuietHours());

        $this->log('ai_assistant_take_over', $customer);

        $hinweis = 'Sie haben die Unterhaltung übernommen. Der KI-Assistent antwortet nicht mehr automatisch';
        if ($einstellungen->autoResume()) {
            $hinweis .= $vorgang
                ? ' – bis Vorgang #' . $vorgang->ticket_number . ' abgeschlossen ist oder '
                    . $einstellungen->resumeQuietHours() . ' Stunden ohne Ihre Nachricht vergehen.'
                : ' – bis ' . $einstellungen->resumeQuietHours() . ' Stunden ohne Ihre Nachricht vergangen sind.';
        } else {
            $hinweis .= '.';
        }

        return back()->with('success', $hinweis);
    }

    /** KI fuer diesen Kunden stumm schalten (ohne Uebergabe zu behaupten). */
    public function deactivate($customerId)
    {
        $customer = $this->authorizedCustomer($customerId);
        AiConversation::forCustomer($customer->id)->deactivate();

        $this->log('ai_assistant_deactivated', $customer);

        return back()->with('success', 'Der KI-Assistent ist für diesen Kunden dauerhaft deaktiviert – er kommt erst mit "KI wieder aktivieren" zurück.');
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
            // Entwuerfe zuerst durchsehen zu koennen ist der Normalfall,
            // nachdem ki:wissensbasis-vorschlag gelaufen ist.
            ->when($request->query('status') === 'entwurf', fn ($q) => $q->where('active', false))
            ->when($request->query('status') === 'aktiv', fn ($q) => $q->where('active', true))
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
            'draftCount' => AiKnowledgeEntry::drafts()->count(),
            'activeCount' => AiKnowledgeEntry::active()->count(),
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

        // Deckt der neue Eintrag eine gemeldete Luecke ab, ist sie erledigt.
        $geschlossen = $entry->active ? $this->closeCoveredGaps() : 0;

        return back()->with('success', 'Wissensbasis-Eintrag angelegt.'
            . ($geschlossen > 0 ? ' ' . $geschlossen . ' offene Wissenslücke(n) sind damit beantwortet.' : ''));
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

    /**
     * Sammelaktion fuer Entwuerfe (Betreiber-Auftrag 18.08.2026).
     *
     * Nach ki:wissensbasis-vorschlag liegen Dutzende Entwuerfe bereit;
     * jeden einzeln zu speichern waere die eigentliche Huerde vor dem
     * Livegang. Freigegeben wird trotzdem NUR, was der Mitarbeiter
     * ausdruecklich ankreuzt - es gibt bewusst kein "alles freigeben"
     * ueber ungelesene Eintraege hinweg.
     */
    public function knowledgeBulk(Request $request)
    {
        $data = $request->validate([
            'aktion' => 'required|in:freigeben,deaktivieren,loeschen',
            'ids' => 'required|array|min:1',
            'ids.*' => 'string',
        ]);

        $entries = AiKnowledgeEntry::whereIn('id', $data['ids'])->get();
        if ($entries->isEmpty()) {
            return back()->with('success', 'Kein Eintrag ausgewählt.');
        }

        $aktion = $data['aktion'];
        foreach ($entries as $entry) {
            if ($aktion === 'loeschen') {
                $entry->delete();
                continue;
            }
            $entry->update([
                'active' => $aktion === 'freigeben',
                'updated_by' => auth()->id(),
            ]);
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'ai_knowledge_bulk_' . $aktion,
            'entity_type' => 'ai_knowledge_entry',
            'entity_id' => null,
            'meta' => json_encode([
                'anzahl' => $entries->count(),
                'titel' => $entries->pluck('title')->take(20)->all(),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $geschlossen = $aktion === 'freigeben' ? $this->closeCoveredGaps() : 0;

        $text = [
            'freigeben' => 'Einträge freigegeben - der Assistent nutzt sie ab sofort.'
                . ($geschlossen > 0 ? ' ' . $geschlossen . ' offene Wissenslücke(n) sind damit beantwortet.' : ''),
            'deaktivieren' => 'Einträge deaktiviert - der Assistent nutzt sie nicht mehr.',
            'loeschen' => 'Einträge gelöscht.',
        ][$aktion];

        return back()->with('success', $entries->count() . ' ' . $text);
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

    // ---------------------------------------------------------------
    // Wissensluecken: wonach gefragt wurde, ohne dass eine Antwort
    // hinterlegt ist (Betreiber-Auftrag 18.08.2026).
    //
    // Der Assistent lernt NICHT von selbst - er wiederholt nur, was ein
    // Mensch freigegeben hat. Er kann aber melden, was ihm gefehlt hat.
    // Diese Liste ist genau diese Rueckmeldung, nach Haeufigkeit sortiert.
    // ---------------------------------------------------------------

    public function knowledgeGaps(Request $request)
    {
        $status = $request->query('status', AiKnowledgeGap::STATUS_OPEN);

        $gaps = AiKnowledgeGap::with('resolver')
            ->when($status !== 'alle', fn ($q) => $q->where('status', $status))
            ->when($request->query('bereich'), fn ($q, $b) => $q->where('scope', $b))
            // Haeufigstes zuerst: das ist die Reihenfolge, in der sich die
            // Arbeit an der Wissensbasis am schnellsten auszahlt.
            ->orderByDesc('hits')->orderByDesc('last_seen_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.ai_knowledge_gaps', [
            'gaps' => $gaps,
            'openCount' => AiKnowledgeGap::open()->count(),
            'categories' => AiKnowledgeEntry::CATEGORIES,
            'languages' => AiKnowledgeEntry::LANGUAGES,
            'scopes' => AiKnowledgeGap::SCOPE_LABELS,
            'status' => $status,
        ]);
    }

    /**
     * Antwort zu einer Luecke schreiben: legt den Wissenseintrag an und
     * schliesst die Luecke in einem Schritt. Genau der Weg, den sich der
     * Betreiber vorstellt - einmal beantworten, ab dann beantwortet es
     * der Assistent selbst.
     */
    public function knowledgeGapAnswer(Request $request, $id)
    {
        $gap = AiKnowledgeGap::findOrFail($id);
        $data = $this->validateEntry($request);

        $entry = AiKnowledgeEntry::create($data + [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $gap->update([
            'status' => AiKnowledgeGap::STATUS_DONE,
            'resolved_entry_id' => $entry->id,
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'ai_knowledge_gap_answered',
            'entity_type' => 'ai_knowledge_entry',
            'entity_id' => $entry->id,
            'meta' => json_encode(['thema' => $gap->topic, 'titel' => $entry->title], JSON_UNESCAPED_UNICODE),
        ]);

        $hinweis = $entry->active
            ? 'Antwort gespeichert und aktiv - der Assistent nutzt sie ab sofort.'
            : 'Antwort als Entwurf gespeichert - sie wirkt erst nach der Freigabe.';

        return back()->with('success', $hinweis);
    }

    /** Luecke ohne Antwort erledigen (ignorieren) oder wieder oeffnen. */
    public function knowledgeGapStatus(Request $request, $id)
    {
        $gap = AiKnowledgeGap::findOrFail($id);
        $data = $request->validate([
            'status' => 'required|in:' . implode(',', [
                AiKnowledgeGap::STATUS_OPEN,
                AiKnowledgeGap::STATUS_IGNORED,
            ]),
        ]);

        $gap->update([
            'status' => $data['status'],
            'resolved_by' => $data['status'] === AiKnowledgeGap::STATUS_IGNORED ? auth()->id() : null,
            'resolved_at' => $data['status'] === AiKnowledgeGap::STATUS_IGNORED ? now() : null,
        ]);

        return back()->with('success', $data['status'] === AiKnowledgeGap::STATUS_IGNORED
            ? 'Thema ignoriert - es taucht wieder auf, wenn erneut danach gefragt wird.'
            : 'Thema wieder geöffnet.');
    }

    /**
     * Mehrere Fragen und Antworten auf einmal erfassen.
     *
     * Ohne das ist jede Frage ein eigenes Formular - bei 40 Fragen ist
     * genau das der Grund, warum die Wissensbasis leer bleibt.
     *
     * Format (Bloecke durch Leerzeile getrennt), deutsch oder arabisch
     * beschriftet:
     *   F: Habt ihr Stromangebote?
     *   A: Ja - wir vergleichen ...
     */
    public function knowledgeImport(Request $request)
    {
        $data = $request->validate([
            'text' => 'required|string|max:100000',
            'category' => 'required|string|in:' . implode(',', array_keys(AiKnowledgeEntry::CATEGORIES)),
            'language' => 'nullable|string|in:' . implode(',', array_keys(AiKnowledgeEntry::LANGUAGES)),
        ]);

        $paare = $this->parseQuestionAnswerText($data['text']);
        if ($paare === []) {
            return back()->withErrors([
                'text' => 'Keine Frage/Antwort-Paare erkannt. Jede Frage beginnt mit "F:" (oder "س:"), '
                    . 'jede Antwort mit "A:" (oder "ج:"), Paare durch eine Leerzeile getrennt.',
            ]);
        }

        $sofortAktiv = $request->boolean('active');
        $angelegt = 0;
        foreach ($paare as $paar) {
            AiKnowledgeEntry::create([
                'title' => mb_substr($paar['frage'], 0, 250),
                'category' => $data['category'],
                'language' => ($data['language'] ?? null) ?: null,
                'content' => $paar['antwort'],
                'keywords' => null,
                'active' => $sofortAktiv,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            $angelegt++;
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'ai_knowledge_imported',
            'entity_type' => 'ai_knowledge_entry',
            'entity_id' => null,
            'meta' => json_encode(['anzahl' => $angelegt, 'aktiv' => $sofortAktiv], JSON_UNESCAPED_UNICODE),
        ]);

        $geschlossen = $sofortAktiv ? $this->closeCoveredGaps() : 0;

        return back()->with('success', $angelegt . ' Einträge angelegt'
            . ($sofortAktiv ? ' und aktiv' : ' (Entwürfe – bitte freigeben)') . '.'
            . ($geschlossen > 0 ? ' ' . $geschlossen . ' offene Wissenslücke(n) sind damit beantwortet.' : ''));
    }

    /**
     * Frage/Antwort-Bloecke aus Fliesstext lesen. Bewusst streng: ein
     * Block ohne beides wird uebersprungen, statt eine halbe Antwort in
     * die Wissensbasis zu schreiben.
     *
     * @return list<array{frage:string,antwort:string}>
     */
    private function parseQuestionAnswerText(string $text): array
    {
        $zeilen = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $paare = [];
        $frage = null;
        $antwort = [];

        $sichern = function () use (&$paare, &$frage, &$antwort) {
            $inhalt = trim(implode("\n", $antwort));
            if ($frage !== null && $frage !== '' && $inhalt !== '') {
                $paare[] = ['frage' => $frage, 'antwort' => $inhalt];
            }
            $frage = null;
            $antwort = [];
        };

        foreach ($zeilen as $zeile) {
            $zeile = rtrim($zeile);
            if (preg_match('/^\s*(?:F|Frage|س|سؤال)\s*[:：]\s*(.*)$/u', $zeile, $m)) {
                $sichern();
                $frage = trim($m[1]);
                continue;
            }
            if (preg_match('/^\s*(?:A|Antwort|ج|جواب|إجابة)\s*[:：]\s*(.*)$/u', $zeile, $m)) {
                $antwort = [trim($m[1])];
                continue;
            }
            if (trim($zeile) === '') {
                // Leerzeile trennt Bloecke - aber nur, wenn schon eine
                // Antwort begonnen hat (mehrzeilige Antworten bleiben heil).
                if ($antwort !== []) {
                    $sichern();
                }
                continue;
            }
            if ($antwort !== []) {
                $antwort[] = $zeile;
            }
        }
        $sichern();

        return $paare;
    }

    /**
     * Offene Luecken schliessen, die von der Wissensbasis inzwischen
     * abgedeckt sind. Massstab ist die ECHTE Suche des Assistenten - eine
     * Luecke gilt erst als beantwortet, wenn er den Eintrag auch findet.
     */
    private function closeCoveredGaps(): int
    {
        $suche = app(KnowledgeBase::class);
        $geschlossen = 0;

        foreach (AiKnowledgeGap::open()->get() as $gap) {
            $treffer = $suche->search(
                $gap->topic,
                $gap->language,
                1,
                publicOnly: $gap->scope === AiKnowledgeGap::SCOPE_WEBSITE
            );
            if ($treffer->isEmpty()) {
                continue;
            }
            $gap->update([
                'status' => AiKnowledgeGap::STATUS_DONE,
                'resolved_entry_id' => $treffer->first()->id,
                // Kein resolved_by: das war das System, kein Mitarbeiter.
                'resolved_at' => now(),
            ]);
            $geschlossen++;
        }

        return $geschlossen;
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
