<?php
namespace App\Http\Controllers;

use App\Jobs\AnalyzeDocumentJob;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\InternalNotification;
use App\Models\User;
use App\Services\Ai\DocumentAnalyzer;
use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerCreation\DuplicateCustomerException;
use App\Services\DocumentIntake\DocumentIntakeService;
use App\Services\Pdf\ImagesToPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Smart Document Upload: Mehrseiten-Scanner im Kundenportal und
 * Drag&Drop-Eingang im CRM. Hochgeladene Seiten werden zu EINEM PDF
 * gebuendelt, privat gespeichert und (falls konfiguriert) von der
 * KI analysiert: Typ erkennen, Daten extrahieren, Kunde/Vertrag
 * zuordnen. Uebernahme in die Kundenakte nur per Mitarbeiter-Freigabe.
 */
class SmartDocumentUploadController extends Controller
{
    public function __construct(
        private readonly ImagesToPdfService $pdfBuilder,
        private readonly DocumentAnalyzer $analyzer,
        private readonly DocumentIntakeService $intake,
    ) {
    }

    /* ---------------------------------------------------------------
     | Kundenportal
     * -------------------------------------------------------------- */

    /** Mehrseiten-Scan oder PDF aus dem Kundenportal entgegennehmen. */
    public function portalStore(Request $request)
    {
        $customer = $this->portalCustomer();

        $request->validate([
            'pages' => 'required_without:pdf|array|min:1|max:20',
            'pages.*' => 'file|mimes:jpg,jpeg,png,webp|max:10240',
            'pdf' => 'required_without:pages|file|mimes:pdf|max:10240',
            'contract_id' => 'nullable|uuid',
        ]);

        $contractId = $request->filled('contract_id')
            ? Contract::where('customer_id', $customer->id)->where('id', $request->contract_id)->value('id')
            : null;

        try {
            $document = $this->storeAsDocument(
                $request,
                directory: 'customers/' . $customer->id . '/documents',
                customerId: (string) $customer->id,
                contractId: $contractId,
                visibility: 'customer',
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        foreach (User::whereIn('role', ['admin', 'manager', 'support'])->where('is_active', true)->get() as $recipient) {
            InternalNotification::create([
                'user_id' => $recipient->id,
                'title' => 'Neues Kundendokument (Scan)',
                'body' => ($customer->user?->name ?? 'Ein Kunde') . ' hat „' . $document->file_name . '" hochgeladen.',
                'link' => route('admin.customer', $customer->id) . '#tab-dokumente',
            ]);
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_uploaded_by_customer',
            'entity_type' => 'document',
            'entity_id' => $document->id,
            'meta' => json_encode([
                'customer_id' => (string) $customer->id,
                'file' => $document->file_name,
                'pages' => $document->page_count,
                'smart_upload' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return response()->json([
            'id' => $document->id,
            'ai_enabled' => $this->analyzer->isEnabled(),
        ]);
    }

    /** Analyse-Status fuer die Portal-Anzeige ("Dokument wird analysiert..."). */
    public function portalStatus($id)
    {
        $customer = $this->portalCustomer();
        $document = Document::where('customer_id', $customer->id)->customerVisible()->findOrFail($id);

        return response()->json($this->statusPayload($document));
    }

    /* ---------------------------------------------------------------
     | CRM (Mitarbeiter)
     * -------------------------------------------------------------- */

    /** Dokumenten-Eingang: unzugeordnete Uploads + zuletzt analysierte Dokumente. */
    public function inbox()
    {
        $user = auth()->user();

        $recent = Document::whereNotNull('customer_id')
            ->where('ai_status', '!=', 'none')
            ->when(!$user->canSeeAllCustomers(), fn ($q) => $q->whereIn('customer_id', $user->visibleCustomerIdsWithSubstitution()))
            ->with(['customer.user', 'contract'])
            ->latest()->limit(30)->get();

        return view('admin.documents_inbox', [
            'inboxDocuments' => Document::inbox()->with('uploader')->latest()->get(),
            'recentDocuments' => $recent,
            'aiEnabled' => $this->analyzer->isEnabled(),
        ]);
    }

    /**
     * Smart-Upload durch Mitarbeiter (Drag&Drop): Bilder werden zu EINEM
     * mehrseitigen Dokument gebuendelt, jede PDF wird ein eigenes
     * Dokument. Ohne customer_id landet alles im Dokumenten-Eingang.
     */
    public function adminStore(Request $request)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            'customer_id' => 'nullable|uuid',
            'visibility' => 'nullable|in:customer,internal',
        ]);

        $customer = null;
        if ($request->filled('customer_id')) {
            $customer = Customer::findOrFail($request->customer_id);
            abort_unless(auth()->user()->canAccessCustomer($customer->id), 403);
        }

        $directory = $customer
            ? 'customers/' . $customer->id . '/documents'
            : 'documents/eingang';
        // Ohne bewusste Wahl bleibt ein Smart-Upload intern, bis ein
        // Mitarbeiter ihn freigibt (DSGVO-schonender Standard).
        $visibility = $request->input('visibility', 'internal');

        $images = [];
        $pdfs = [];
        foreach ($request->file('files') as $file) {
            if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
                $pdfs[] = $file;
            } else {
                $images[] = $file;
            }
        }

        $created = [];
        try {
            if ($images !== []) {
                $created[] = $this->createScanDocument(
                    array_map(fn ($f) => (string) file_get_contents($f->getRealPath()), $images),
                    directory: $directory,
                    customerId: $customer?->id,
                    visibility: $visibility,
                );
            }
            foreach ($pdfs as $pdf) {
                $created[] = $this->createPdfDocument($pdf, $directory, $customer?->id, $visibility);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        foreach ($created as $document) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'document_uploaded',
                'entity_type' => 'document',
                'entity_id' => $document->id,
                'meta' => json_encode([
                    'customer_id' => $customer?->id ? (string) $customer->id : null,
                    'file' => $document->file_name,
                    'smart_upload' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return response()->json([
            'ids' => array_map(fn ($d) => $d->id, $created),
            'ai_enabled' => $this->analyzer->isEnabled(),
        ]);
    }

    /** Analyse-Status fuer CRM-Ansichten (Eingang, Kundenakte). */
    public function adminStatus($id)
    {
        $document = Document::findOrFail($id);
        $this->authorizeDocument($document);

        return response()->json($this->statusPayload($document));
    }

    /**
     * Eingangs-Dokument einem Kunden zuordnen (Mitarbeiter-Freigabe).
     * Optional: extrahierte Daten in leere Kundenfelder uebernehmen und
     * einen Vertrag anlegen/verknuepfen.
     */
    public function assign(Request $request, $id)
    {
        $document = Document::findOrFail($id);
        $this->authorizeDocument($document);

        $request->validate([
            'customer_id' => 'required|uuid',
            'apply_fields' => 'nullable|array',
            'apply_fields.*' => 'string|in:birth_date,birth_place,address,phone,nationality,email2,health_insurance,iban',
            'create_contract' => 'nullable|boolean',
            'visibility' => 'nullable|in:customer,internal',
        ]);

        $customer = Customer::findOrFail($request->customer_id);
        abort_unless(auth()->user()->canAccessCustomer($customer->id), 403);

        if ($document->customer_id && (string) $document->customer_id !== (string) $customer->id) {
            return response()->json(['message' => 'Dokument ist bereits einem anderen Kunden zugeordnet.'], 422);
        }

        if (!$document->customer_id) {
            $this->intake->assignToCustomer($document, $customer, auth()->id());
        }
        if ($request->filled('visibility')) {
            $document->update(['visibility' => $request->visibility, 'updated_by' => auth()->id()]);
        }

        $applied = $this->intake->applyExtractedToCustomer($document, $customer, $request->input('apply_fields', []), auth()->id());

        $contract = null;
        if ($request->boolean('create_contract')) {
            $contract = $this->intake->createContractFromExtraction($document, $customer, auth()->id());
        } else {
            $contract = $this->intake->linkMatchingContract($document, $customer);
        }

        $this->markDecision($document, 'accepted');

        return response()->json([
            'ok' => true,
            'customer_id' => $customer->id,
            'customer_url' => route('admin.customer', $customer->id) . '#tab-dokumente',
            'applied_fields' => $applied,
            'contract_id' => $contract?->id,
        ]);
    }

    /**
     * Neuen Kunden aus den extrahierten Daten anlegen ("Kein Kunde
     * gefunden -> Neuen Kunden erstellen") und das Dokument zuordnen.
     */
    public function createCustomer(Request $request, $id)
    {
        $document = Document::findOrFail($id);
        $this->authorizeDocument($document);

        if ($document->customer_id) {
            return response()->json(['message' => 'Dokument ist bereits einem Kunden zugeordnet.'], 422);
        }

        $request->validate([
            'apply_fields' => 'nullable|array',
            'apply_fields.*' => 'string|in:birth_date,birth_place,address,phone,nationality,email2,health_insurance,iban',
            'create_contract' => 'nullable|boolean',
        ]);

        $person = ($document->ai_extracted ?? [])['person'] ?? [];
        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        if ($name === '') {
            return response()->json(['message' => 'Im Dokument wurde kein Name erkannt - bitte Kunden manuell anlegen.'], 422);
        }

        try {
            $customer = app(CustomerAutoCreationService::class)->createFromUnmatched(
                $this->intake->matchCriteria($document->ai_extracted ?? []),
                'manual',
                auth()->id(),
            );
        } catch (DuplicateCustomerException $e) {
            return response()->json([
                'message' => 'Es existiert bereits ein aehnlicher Kunde (' . ($e->matchResult->customer?->user?->name ?? '?') . ', '
                    . $e->matchResult->score . ' Punkte). Bitte stattdessen zuordnen.',
            ], 422);
        }

        $this->intake->assignToCustomer($document, $customer, auth()->id());
        $applied = $this->intake->applyExtractedToCustomer($document, $customer, $request->input('apply_fields', []), auth()->id());

        $contract = null;
        if ($request->boolean('create_contract')) {
            $contract = $this->intake->createContractFromExtraction($document, $customer, auth()->id());
        }

        $this->markDecision($document, 'accepted');

        return response()->json([
            'ok' => true,
            'customer_id' => $customer->id,
            'customer_url' => route('admin.customer', $customer->id),
            'applied_fields' => $applied,
            'contract_id' => $contract?->id,
        ]);
    }

    /**
     * Kundensuche fuer die Zuordnungs-UI - beschraenkt auf Kunden, die
     * der Mitarbeiter sehen darf (Portfolio + Vertretungen).
     */
    public function customerSearch(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $user = auth()->user();
        $customers = Customer::with('user')
            ->when(!$user->canSeeAllCustomers(), fn ($query) => $query->whereIn('customers.id', $user->visibleCustomerIdsWithSubstitution()))
            ->where(function ($query) use ($q) {
                $query->whereHas('user', fn ($u) => $u->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%"))
                    ->orWhere('customer_number', 'like', "%$q%")
                    ->orWhere('phone', 'like', "%$q%");
            })
            ->limit(15)->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->user?->name,
                'number' => $c->customer_number,
                'email' => $c->user?->email,
            ]);

        return response()->json($customers);
    }

    /** Analyse erneut anstossen (z.B. nach Fehler oder Modellwechsel). */
    public function reanalyze($id)
    {
        $document = Document::findOrFail($id);
        $this->authorizeDocument($document);

        if (!$this->analyzer->isEnabled()) {
            return response()->json(['message' => 'KI-Analyse ist nicht konfiguriert (ANTHROPIC_API_KEY fehlt).'], 422);
        }
        if ($document->aiInProgress()) {
            return response()->json(['message' => 'Analyse laeuft bereits.'], 422);
        }

        $document->update(['ai_status' => 'pending', 'ai_error' => null]);
        AnalyzeDocumentJob::dispatch($document->id);

        return response()->json(['ok' => true]);
    }

    /* ---------------------------------------------------------------
     | Intern
     * -------------------------------------------------------------- */

    private function portalCustomer(): Customer
    {
        return Customer::firstOrCreate(
            ['user_id' => auth()->id()],
            ['customer_number' => 'C-' . strtoupper(Str::random(8))]
        );
    }

    /** Eingangs-Dokumente darf jeder Mitarbeiter sehen, Kunden-Dokumente nur mit Portfolio-Zugriff. */
    private function authorizeDocument(Document $document): void
    {
        if ($document->customer_id && !auth()->user()->canAccessCustomer($document->customer_id)) {
            abort(403, 'Kein Zugriff auf diesen Kunden.');
        }
    }

    /** Portal-/CRM-Upload in ein Document verwandeln (Seiten -> PDF oder Original-PDF). */
    private function storeAsDocument(Request $request, string $directory, ?string $customerId, ?string $contractId, string $visibility): Document
    {
        if ($request->hasFile('pdf')) {
            return $this->createPdfDocument($request->file('pdf'), $directory, $customerId, $visibility, $contractId);
        }

        $binaries = array_map(
            fn ($f) => (string) file_get_contents($f->getRealPath()),
            $request->file('pages', [])
        );
        return $this->createScanDocument($binaries, $directory, $customerId, $visibility, $contractId);
    }

    /** @param list<string> $imageBinaries */
    private function createScanDocument(array $imageBinaries, string $directory, ?string $customerId, string $visibility, ?string $contractId = null): Document
    {
        $pdfBytes = $this->pdfBuilder->build($imageBinaries);
        $path = $directory . '/' . Str::uuid() . '.pdf';
        Storage::disk('local')->put($path, $pdfBytes);

        return $this->createDocument(
            customerId: $customerId,
            contractId: $contractId,
            fileName: 'Scan ' . now()->format('d.m.Y H.i') . '.pdf',
            path: $path,
            size: strlen($pdfBytes),
            pageCount: count($imageBinaries),
            visibility: $visibility,
        );
    }

    private function createPdfDocument(\Illuminate\Http\UploadedFile $file, string $directory, ?string $customerId, string $visibility, ?string $contractId = null): Document
    {
        $path = $file->store($directory, 'local');

        return $this->createDocument(
            customerId: $customerId,
            contractId: $contractId,
            fileName: $file->getClientOriginalName(),
            path: $path,
            size: (int) $file->getSize(),
            pageCount: null,
            visibility: $visibility,
        );
    }

    private function createDocument(?string $customerId, ?string $contractId, string $fileName, string $path, int $size, ?int $pageCount, string $visibility): Document
    {
        $aiEnabled = $this->analyzer->isEnabled();

        $document = Document::create([
            'customer_id' => $customerId,
            'contract_id' => $contractId,
            'category' => 'other',
            'file_name' => $fileName,
            'file_path' => $path,
            'disk' => 'local',
            'visibility' => $visibility,
            'color' => 'green',
            'uploaded_by' => auth()->id(),
            'file_size' => $size,
            'ai_status' => $aiEnabled ? 'pending' : 'none',
            'page_count' => $pageCount,
        ]);

        if ($aiEnabled) {
            AnalyzeDocumentJob::dispatch($document->id);
        }

        return $document;
    }

    /** Status-JSON fuer die Poll-Anzeigen in Portal und CRM. */
    private function statusPayload(Document $document): array
    {
        $extracted = $document->ai_extracted ?? [];

        return [
            'id' => $document->id,
            'status' => $document->ai_status,
            'type' => $document->ai_type,
            'type_label' => $document->aiTypeLabel(),
            'confidence' => $document->ai_confidence,
            'summary' => $document->ai_summary,
            'error' => $document->ai_error,
            'file_name' => $document->file_name,
            'category_label' => Document::CATEGORIES[$document->category] ?? $document->category,
            'customer_id' => $document->customer_id,
            'contract_id' => $document->contract_id,
            'match' => $extracted['match'] ?? null,
        ];
    }

    /** Letzten KI-Vorschlag zu diesem Dokument als entschieden markieren. */
    private function markDecision(Document $document, string $status): void
    {
        $document->aiDecisions()->where('status', 'suggested')->latest()->first()?->update([
            'status' => $status,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);
    }
}
