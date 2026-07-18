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

        $this->validateJson($request, [
            'pages' => 'required_without:pdf|array|min:1|max:20',
            'pages.*' => 'file|mimes:jpg,jpeg,png,webp|max:10240',
            'pdf' => 'required_without:pages|file|mimes:pdf|max:10240',
            'contract_id' => 'nullable|uuid',
        ]);

        // Entweder Seiten ODER eine fertige PDF - beides zusammen waere
        // mehrdeutig (welche Datei ist das Dokument?).
        if ($request->hasFile('pages') && $request->hasFile('pdf')) {
            $this->failJson('Bitte entweder Seiten oder eine PDF-Datei senden, nicht beides.');
        }

        if ($request->hasFile('pages')) {
            $this->guardTotalImageSize($request->file('pages'));
        }

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

        // Datenminimierung: Mitarbeiter mit eingeschraenktem Portfolio sehen
        // im Eingang nur ihre eigenen Uploads (extrahierte Daten koennen
        // sensibel sein); Admin/Manager sehen alles.
        $inbox = Document::inbox()->with('uploader')
            ->when(!$user->canSeeAllCustomers(), fn ($q) => $q->where('uploaded_by', $user->id))
            ->latest()->get();

        // Match-Vorschlaege ausserhalb des eigenen Portfolios bereinigen,
        // BEVOR die View (inkl. des JSON-Blobs fuers Review-Modal) sie
        // sieht - sonst laesst sich Name/Kundennummer per View-Source lesen.
        foreach ($inbox as $doc) {
            $extracted = $doc->ai_extracted;
            if (is_array($extracted) && array_key_exists('match', $extracted)) {
                $extracted['match'] = $this->scrubMatch($extracted['match']);
                $doc->ai_extracted = $extracted;
            }
        }

        return view('admin.documents_inbox', [
            'inboxDocuments' => $inbox,
            'recentDocuments' => $recent,
            'aiEnabled' => $this->analyzer->isEnabled(),
            'providerEnabled' => $this->analyzer->providerEnabled(),
        ]);
    }

    /**
     * Smart-Upload durch Mitarbeiter (Drag&Drop): Bilder werden zu EINEM
     * mehrseitigen Dokument gebuendelt, jede PDF wird ein eigenes
     * Dokument. Ohne customer_id landet alles im Dokumenten-Eingang.
     */
    public function adminStore(Request $request)
    {
        $this->validateJson($request, [
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            'customer_id' => 'nullable|uuid',
            'visibility' => 'nullable|in:customer,internal',
            // 1 = Bilder zu EINEM mehrseitigen Dokument buendeln (Standard),
            // 0 = jedes Bild wird ein eigenes Dokument.
            'bundle_images' => 'nullable|boolean',
        ]);

        $customer = null;
        if ($request->filled('customer_id')) {
            $customer = Customer::find($request->customer_id);
            if (!$customer) {
                return response()->json(['message' => 'Dieser Kunde existiert nicht (mehr).'], 404);
            }
            abort_unless(auth()->user()->canAccessCustomer($customer->id), 403);
        }

        $directory = $customer
            ? 'customers/' . $customer->id . '/documents'
            : 'documents/eingang';
        // Ohne bewusste Wahl bleibt ein Smart-Upload intern, bis ein
        // Mitarbeiter ihn freigibt (DSGVO-schonender Standard).
        $visibility = $request->input('visibility', 'internal');

        // Gesamtgroesse ALLER Dateien begrenzen (Bilder + PDFs), nicht nur
        // der Bilder - sonst waeren pro Request bis zu ~200 MB moeglich
        // (20 Dateien x 10 MB Einzel-Limit).
        $totalBytes = array_sum(array_map(fn ($f) => (int) $f->getSize(), $request->file('files')));
        if ($totalBytes > 60 * 1024 * 1024) {
            $this->failJson('Die Dateien sind zusammen zu gross (max. 60 MB pro Upload).');
        }

        // Aufteilung nach ECHTEM Inhalt (finfo), nicht nach dem vom Client
        // gelieferten Dateinamen - "foto.pdf" mit JPEG-Inhalt ist ein Bild.
        // Nur wenn der Inhalt keinen eindeutigen Typ liefert, entscheidet
        // die Dateiendung.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $images = [];
        $pdfs = [];
        foreach ($request->file('files') as $file) {
            $mime = (string) $finfo->file($file->getRealPath());
            $isPdf = $mime === 'application/pdf'
                || (!str_starts_with($mime, 'image/') && strtolower($file->getClientOriginalExtension()) === 'pdf');
            if ($isPdf) {
                $pdfs[] = $file;
            } else {
                $images[] = $file;
            }
        }

        $created = [];
        try {
            if ($images !== []) {
                $this->guardTotalImageSize($images);
                $imageGroups = $request->boolean('bundle_images', true)
                    ? [$images]
                    : array_map(fn ($f) => [$f], $images);
                foreach ($imageGroups as $group) {
                    $created[] = $this->createScanDocument(
                        array_map(fn ($f) => (string) file_get_contents($f->getRealPath()), $group),
                        directory: $directory,
                        customerId: $customer?->id,
                        visibility: $visibility,
                    );
                }
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

        return response()->json($this->statusPayload($document, internal: true));
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

        $this->validateJson($request, [
            'customer_id' => 'required|uuid',
            'apply_fields' => 'nullable|array',
            'apply_fields.*' => 'string|in:birth_date,birth_place,address,phone,nationality,email2,health_insurance,iban',
            'create_contract' => 'nullable|boolean',
            'visibility' => 'nullable|in:customer,internal',
        ]);

        $customer = Customer::find($request->customer_id);
        if (!$customer) {
            return response()->json(['message' => 'Dieser Kunde existiert nicht (mehr).'], 404);
        }
        abort_unless(auth()->user()->canAccessCustomer($customer->id), 403);

        if ($document->customer_id && (string) $document->customer_id !== (string) $customer->id) {
            return response()->json(['message' => 'Dokument ist bereits einem anderen Kunden zugeordnet.'], 422);
        }

        if (!$document->customer_id && !$this->intake->assignToCustomer($document, $customer, auth()->id())) {
            // Zwei Mitarbeiter gleichzeitig: der andere hat gewonnen.
            return response()->json(['message' => 'Dokument wurde soeben einem anderen Kunden zugeordnet.'], 422);
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

        $this->validateJson($request, [
            'apply_fields' => 'nullable|array',
            'apply_fields.*' => 'string|in:birth_date,birth_place,address,phone,nationality,email2,health_insurance,iban',
            'create_contract' => 'nullable|boolean',
            'visibility' => 'nullable|in:customer,internal',
        ]);

        $person = ($document->ai_extracted ?? [])['person'] ?? [];
        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        if ($name === '') {
            return response()->json(['message' => 'Im Dokument wurde kein Name erkannt - bitte Kunden manuell anlegen.'], 422);
        }

        $criteria = $this->intake->matchCriteria($document->ai_extracted ?? []);
        // Bereits vergebene E-Mail nicht als Login-Adresse verwenden (unique
        // auf users.email); der neue Kunde bekommt dann eine Platzhalter-
        // Adresse, die extrahierte E-Mail kann als email2 uebernommen werden.
        if (!empty($criteria['email']) && User::where('email', $criteria['email'])->exists()) {
            unset($criteria['email']);
        }

        try {
            $customer = app(CustomerAutoCreationService::class)->createFromUnmatched(
                $criteria,
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
        if ($request->filled('visibility')) {
            $document->update(['visibility' => $request->visibility, 'updated_by' => auth()->id()]);
        }
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
     * Neuen Kunden aus MEHREREN Eingangs-Dokumenten anlegen (Ausweis + Bank-
     * karte + Fuehrerschein + Beratungsprotokoll gehoeren zu EINEM Kunden).
     * Die Extraktionen werden zusammengefuehrt (Feld-Hoheit nach Dokumenttyp),
     * ALLE Dokumente werden dem neuen Kunden zugeordnet. Widersprechen sich
     * Ausweis- und Fuehrerschein-Name, wird abgebrochen (manuelle Pruefung).
     */
    public function createCustomerFromDocuments(Request $request)
    {
        $this->validateJson($request, [
            'document_ids' => 'required|array|min:1|max:10',
            'document_ids.*' => 'uuid',
            'apply_fields' => 'nullable|array',
            'apply_fields.*' => 'string|in:birth_date,birth_place,address,phone,nationality,email2,health_insurance,iban',
            'create_contract' => 'nullable|boolean',
            'visibility' => 'nullable|in:customer,internal',
        ]);

        $ids = array_values(array_unique($request->input('document_ids')));
        $documents = Document::whereIn('id', $ids)->get();
        if ($documents->count() !== count($ids)) {
            return response()->json(['message' => 'Mindestens ein Dokument wurde nicht gefunden.'], 404);
        }
        foreach ($documents as $document) {
            $this->authorizeDocument($document);
            if ($document->customer_id) {
                return response()->json(['message' => 'Bereits zugeordnet: ' . $document->file_name], 422);
            }
        }

        $merged = $this->intake->mergeExtractions($documents);
        if (!empty($merged['_conflicts'])) {
            return response()->json([
                'message' => implode(' ', $merged['_conflicts']),
                'conflicts' => $merged['_conflicts'],
            ], 422);
        }

        $person = $merged['person'] ?? [];
        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        if ($name === '') {
            return response()->json(['message' => 'In den Dokumenten wurde kein Name erkannt - bitte Kunden manuell anlegen.'], 422);
        }

        $criteria = $this->intake->matchCriteria($merged);
        if (!empty($criteria['email']) && User::where('email', $criteria['email'])->exists()) {
            unset($criteria['email']);
        }

        try {
            $customer = app(CustomerAutoCreationService::class)->createFromUnmatched($criteria, 'manual', auth()->id());
        } catch (DuplicateCustomerException $e) {
            return response()->json([
                'message' => 'Es existiert bereits ein aehnlicher Kunde (' . ($e->matchResult->customer?->user?->name ?? '?') . ', '
                    . $e->matchResult->score . ' Punkte). Bitte stattdessen zuordnen.',
            ], 422);
        }

        foreach ($documents as $document) {
            $this->intake->assignToCustomer($document, $customer, auth()->id());
        }
        if ($request->filled('visibility')) {
            Document::whereIn('id', $documents->pluck('id'))->update(['visibility' => $request->visibility, 'updated_by' => auth()->id()]);
        }

        // Zusammengefuehrtes Ergebnis auf das erste Dokument beziehen.
        $primary = $documents->first();
        $applied = $this->intake->applyExtractedToCustomer($primary, $customer, $request->input('apply_fields', []), auth()->id(), $merged);
        $contract = $request->boolean('create_contract')
            ? $this->intake->createContractFromExtraction($primary, $customer, auth()->id(), $merged)
            : null;

        foreach ($documents as $document) {
            $this->markDecision($document, 'accepted');
        }

        return response()->json([
            'ok' => true,
            'customer_id' => $customer->id,
            'customer_url' => route('admin.customer', $customer->id),
            'applied_fields' => $applied,
            'contract_id' => $contract?->id,
            'documents' => $documents->count(),
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
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $customers = Customer::with('user')
            ->when(!$user->canSeeAllCustomers(), fn ($query) => $query->whereIn('customers.id', $user->visibleCustomerIdsWithSubstitution()))
            ->where(function ($query) use ($like) {
                $query->whereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like))
                    ->orWhere('customer_number', 'like', $like)
                    ->orWhere('phone', 'like', $like);
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

    /**
     * Analyse erneut anstossen. Ist ein KI-Anbieter konfiguriert, erzwingt
     * die manuelle Wiederholung die kostenpflichtige KI-Stufe (Mitarbeiter-
     * Eskalation ueber den "Mit KI analysieren"-Button) - die kostenlose
     * OCR-Vorstufe wird uebersprungen. Ohne KI-Anbieter laeuft nur die
     * OCR-Analyse erneut (z.B. Retry nach Fehler).
     */
    public function reanalyze($id)
    {
        $document = Document::findOrFail($id);
        $this->authorizeDocument($document);

        if (!$this->analyzer->isEnabled()) {
            return response()->json(['message' => 'Analyse ist nicht konfiguriert (kein KI-Anbieter und keine OCR-Stufe aktiv).'], 422);
        }
        if ($document->aiInProgress()) {
            return response()->json(['message' => 'Analyse laeuft bereits.'], 422);
        }

        $document->update(['ai_status' => 'pending', 'ai_error' => null]);
        AnalyzeDocumentJob::dispatch($document->id, forceAi: $this->analyzer->providerEnabled());

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

    /**
     * Kunden-Dokumente nur mit Portfolio-Zugriff. Eingangs-Dokumente (noch
     * kein Kunde) duerfen Admin/Manager immer oeffnen; Mitarbeiter mit
     * eingeschraenktem Portfolio nur ihre EIGENEN Uploads - konsistent mit
     * der Sichtbarkeits-Einschraenkung in inbox() (Datenminimierung: die
     * Listen-Ansicht war schon gescopet, die Aktions-Endpunkte sonst nicht).
     */
    private function authorizeDocument(Document $document): void
    {
        $user = auth()->user();
        if ($document->customer_id) {
            if (!$user->canAccessCustomer($document->customer_id)) {
                abort(403, 'Kein Zugriff auf diesen Kunden.');
            }
            return;
        }
        if (!$user->canSeeAllCustomers() && (int) $document->uploaded_by !== (int) $user->id) {
            abort(403, 'Kein Zugriff auf dieses Dokument.');
        }
    }

    /**
     * Match-Vorschlag fuer die Anzeige bereinigen: Name/Kundennummer eines
     * Kunden ausserhalb des Portfolios des aktuellen Betrachters duerfen
     * nicht offengelegt werden, auch wenn der Zuordnen-Button ohnehin
     * ausgeblendet ist (sonst per View-Source/JSON-Blob sichtbar).
     */
    private function scrubMatch(?array $match): ?array
    {
        if ($match === null) {
            return null;
        }
        if (auth()->user()?->canAccessCustomer($match['customer_id']) === true) {
            return $match;
        }
        return ['out_of_portfolio' => true, 'score' => $match['score'], 'tier' => $match['tier']];
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

        // Anzeigename immer mit .pdf-Endung, damit Viewer und Analyse den
        // Inhalt korrekt behandeln (Client-Namen sind nicht verlaesslich).
        $fileName = $file->getClientOriginalName();
        if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'pdf') {
            $fileName = (pathinfo($fileName, PATHINFO_FILENAME) ?: 'Dokument') . '.pdf';
        }

        return $this->createDocument(
            customerId: $customerId,
            contractId: $contractId,
            fileName: $fileName,
            path: $path,
            size: (int) $file->getSize(),
            pageCount: null,
            visibility: $visibility,
        );
    }

    /**
     * Obergrenze fuer die Summe der Bilddaten eines Buendels: schuetzt
     * Speicher (alle Seiten werden fuer den PDF-Bau im RAM gehalten) und
     * haelt das Ergebnis unter dem Analyse-Limit von 20 MB.
     *
     * @param list<\Illuminate\Http\UploadedFile> $files
     */
    private function guardTotalImageSize(array $files): void
    {
        $total = array_sum(array_map(fn ($f) => (int) $f->getSize(), $files));
        if ($total > 25 * 1024 * 1024) {
            $this->failJson('Die Bilder sind zusammen zu gross (max. 25 MB pro Dokument).');
        }
    }

    /**
     * Validierung mit garantierter JSON-Fehlerantwort: die App rendert
     * Exceptions nur unter api/* als JSON (bootstrap/app.php), diese
     * XHR-Endpunkte brauchen aber immer strukturierte 422-Antworten.
     */
    private function validateJson(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->failJson((string) $e->validator->errors()->first());
        }
    }

    /** Bricht mit einer JSON-422-Antwort ab (fuer die XHR-Frontends). */
    private function failJson(string $message): never
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(['message' => $message], 422)
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

    /**
     * Status-JSON fuer die Poll-Anzeigen. $internal steuert, ob interne
     * Details (Fehlertext, Match) mitgegeben werden - Kunden im Portal
     * bekommen sie nicht.
     */
    private function statusPayload(Document $document, bool $internal = false): array
    {
        $extracted = $document->ai_extracted ?? [];
        $label = $document->aiTypeLabel();

        return [
            'id' => $document->id,
            'status' => $document->ai_status,
            'type' => $document->ai_type,
            // Typ-Label lokalisiert (Portal kann Arabisch sein; ar.json)
            'type_label' => $label !== null ? __($label) : null,
            'confidence' => $document->ai_confidence,
            'summary' => $document->ai_summary,
            'error' => $internal ? $document->ai_error : null,
            'file_name' => $document->file_name,
            'category_label' => __(Document::CATEGORIES[$document->category] ?? $document->category),
            'customer_id' => $document->customer_id,
            'contract_id' => $document->contract_id,
            'match' => $internal ? $this->scrubMatch($extracted['match'] ?? null) : null,
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
