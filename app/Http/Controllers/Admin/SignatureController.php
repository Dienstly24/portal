<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSignatureRequestRequest;
use App\Models\CompanySignatureAsset;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Services\CustomerCreation\DuplicateCustomerException;
use App\Services\Pdf\PdfException;
use App\Services\Signature\SignatureAuditService;
use App\Services\Signature\SignatureDocumentService;
use App\Services\Signature\SignaturePageRenderer;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureStorage;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Die Beraterwelt-Seite des Signatur-Moduls.
 *
 * Der Controller liest, prueft und leitet weiter - die Fachlogik liegt in
 * den Diensten. Dieselbe Aufteilung wie im uebrigen Projekt: derselbe
 * Vorgang wird aus der Kundenakte, aus der Vertragsakte und aus der
 * Uebersicht angestossen, und alle drei muessen identisch ablaufen.
 */
class SignatureController extends Controller
{
    use ScopesCustomerAccess;

    public function __construct(
        private readonly SignatureRequestService $requests,
        private readonly SignatureDocumentService $documents,
        private readonly SignatureStorage $storage,
        private readonly SignaturePageRenderer $renderer,
    ) {
    }

    /** Uebersicht mit Reitern, Filtern und Suche. */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', SignatureRequest::class);

        $tab = array_key_exists((string) $request->query('reiter'), SignatureStatus::TABS)
            ? (string) $request->query('reiter')
            : 'alle';

        $base = fn () => $this->scopeRequests(SignatureRequest::query())
            ->with(['customer.user', 'signers', 'creator']);

        $query = $base();
        $statuses = SignatureStatus::TABS[$tab]['statuses'];
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $search = trim((string) $request->query('suche', ''));
        if ($search !== '') {
            $this->applySearch($query, $search);
        }

        foreach (['von' => '>=', 'bis' => '<='] as $key => $operator) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '' && strtotime($value) !== false) {
                $query->whereDate('created_at', $operator, $value);
            }
        }
        if (($creator = $request->query('ersteller')) !== null && $creator !== '') {
            $query->where('created_by', (int) $creator);
        }
        if (($customer = $request->query('kunde')) !== null && $customer !== '') {
            $query->where('customer_id', $customer);
        }

        // Die Zaehler folgen der SUCHE, damit die Zahl am Reiter zu dem
        // passt, was die Liste zeigt (Lehre aus der Vertragsliste,
        // 20.08.2026). Reine COUNT-Abfragen - es wird keine Zeile geladen.
        $counts = [];
        foreach (SignatureStatus::TABS as $key => $definition) {
            $countQuery = $this->scopeRequests(SignatureRequest::query());
            if ($definition['statuses'] !== []) {
                $countQuery->whereIn('status', $definition['statuses']);
            }
            if ($search !== '') {
                $this->applySearch($countQuery, $search);
            }
            $counts[$key] = $countQuery->count();
        }

        return view('admin.signatures.index', [
            'signatures' => $query->latest('created_at')->paginate(30)->withQueryString(),
            'tab' => $tab,
            'counts' => $counts,
            'search' => $search,
            'filters' => $request->only(['von', 'bis', 'ersteller', 'kunde']),
        ]);
    }

    /**
     * Suche ueber Titel, Referenz, Unterzeichner und Kunde. Mehrere Woerter
     * sind UND-verknuepft; Platzhalter aus der Eingabe werden maskiert -
     * eine Nutzereingabe erzeugt nie ein LIKE-Sonderzeichen.
     */
    private function applySearch($query, string $search): void
    {
        foreach (array_slice(preg_split('/\s+/', $search) ?: [], 0, 5) as $word) {
            if ($word === '') {
                continue;
            }
            $like = '%'.addcslashes($word, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhereHas('signers', fn ($s) => $s->where('name', 'like', $like)->orWhere('email', 'like', $like))
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_number', 'like', $like)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)));
            });
        }
    }

    /**
     * Sichtbarkeit: Kundenvorgaenge folgen dem Portfolio, eigenstaendige
     * Vorgaenge gehoeren ihrem Ersteller. Ohne den zweiten Teil saehe jeder
     * Mitarbeiter jeden Vorgang ohne Kunden - die Nullbarkeit von
     * customer_id waere dann ein Loch statt einer Erleichterung.
     */
    private function scopeRequests($query)
    {
        $ids = $this->visibleCustomerIds();
        if ($ids === null) {
            return $query;
        }
        $userId = (int) auth()->id();

        return $query->where(function ($q) use ($ids, $userId) {
            $q->where(function ($inner) use ($ids) {
                $inner->whereNotNull('customer_id')->whereIn('customer_id', $ids);
            })->orWhere(function ($inner) use ($userId) {
                $inner->whereNull('customer_id')->where('created_by', $userId);
            });
        });
    }

    public function create(Request $request)
    {
        Gate::authorize('create', SignatureRequest::class);

        $customer = null;
        $contract = null;
        if (($customerId = $request->query('kunde')) !== null) {
            $this->authorizeCustomerAccess($customerId);
            $customer = Customer::with('user')->findOrFail($customerId);
        }
        if (($contractId = $request->query('vertrag')) !== null) {
            $contract = Contract::with('customer.user')->findOrFail($contractId);
            $this->authorizeCustomerAccess($contract->customer_id);
            $customer ??= $contract->customer;
        }

        return view('admin.signatures.create', [
            'customer' => $customer,
            'contract' => $contract,
            'consentText' => $this->requests->defaultConsentText(),
        ]);
    }

    public function store(StoreSignatureRequestRequest $request)
    {
        Gate::authorize('create', SignatureRequest::class);
        $data = $request->validated();

        if (! empty($data['customer_id'])) {
            $this->authorizeCustomerAccess($data['customer_id']);
        }

        try {
            $signature = $this->requests->createFromUpload($request->file('document'), [
                'title' => $data['title'],
                'customer_id' => $data['customer_id'] ?? null,
                'contract_id' => $data['contract_id'] ?? null,
                'signing_order' => $data['signing_order'] ?? 'sequential',
                'identity_check' => $data['identity_check'] ?? SignatureRequest::IDENTITY_EMAIL,
                'consent_text' => $data['consent_text'] ?? null,
                'document_type' => $data['document_type'] ?? null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'expires_at' => ! empty($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
            ], $request->user());
        } catch (PdfException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->requests->syncSigners($signature, array_map(
            fn ($s) => [
                'name' => $s['name'],
                'email' => $s['email'],
                'locale' => $s['locale'] ?? 'de',
                'date_of_birth' => $s['date_of_birth'] ?? null,
            ],
            array_values(array_filter($data['signers'] ?? [], fn ($s) => ! empty($s['email'])))
        ));

        return redirect()->route('admin.signatures.prepare', $signature->id)
            ->with('success', 'Signaturanfrage angelegt. Jetzt die Felder auf dem Dokument setzen.');
    }

    public function show(string $id)
    {
        $signature = $this->find($id);
        Gate::authorize('view', $signature);

        return view('admin.signatures.show', [
            'signature' => $signature->load(['signers', 'fields.signer', 'customer.user', 'contract', 'creator', 'completedDocument']),
            'events' => $signature->events()->with('user')->limit(200)->get(),
            'blockers' => $signature->isDraft() ? $this->requests->blockersForSending($signature) : [],
            'suggestions' => $signature->isCompleted() && $signature->customer_id === null
                ? $this->documents->suggestions($signature, $this->visibleCustomerIds())
                : [],
        ]);
    }

    /**
     * Sofort-Suche fuer das Anlage-Formular.
     *
     * BEWUSST EIN EIGENER ENDPUNKT und nicht admin.customers.search: hier
     * werden zusaetzlich Vorname/Nachname getrennt, Geburtsdatum und
     * Portal-Sprache geliefert - genau die Felder, die das Formular
     * ausfuellt. Denselben Datensatz an ALLE Kundensuchen zu haengen hiesse,
     * das Geburtsdatum ueberall dort mitzuliefern, wo es niemand braucht
     * (dieselbe Abwaegung wie bei TaskController und ComposeEmailController).
     *
     * Der Portfolio-Scope gilt wie ueberall: ein Mitarbeiter findet hier
     * niemanden, den er nicht auch in seiner Kundenliste sieht.
     */
    public function customerSearch(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $basis = $this->scopeCustomers(Customer::with('user'));
        $customers = $q === ''
            ? $basis->latest()->take(8)->get()
            : $basis->search($q)->take(8)->get();

        return response()->json([
            'customers' => $customers->map(function (Customer $c) {
                $name = trim((string) ($c->user->name ?? ''));
                $teile = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $nachname = count($teile) > 1 ? array_pop($teile) : '';

                return [
                    'id' => (string) $c->id,
                    'name' => $name !== '' ? $name : '—',
                    'vorname' => implode(' ', $teile),
                    'nachname' => $nachname,
                    // INTERNE PLATZHALTER sind keine Kontaktadresse: sie
                    // koennen technisch keine Mail empfangen, und eine
                    // Einladung dorthin waere ein stiller Fehlschlag.
                    'email' => $c->user?->hasRealEmail() ? $c->user->email : null,
                    'number' => $c->customer_number,
                    // birth_date ist NICHT als Datum gecastet (Altbestand
                    // traegt dort Zeichenketten) - deshalb hier parsen statt
                    // ->format() auf gut Glueck aufzurufen.
                    'geburtsdatum' => $this->geburtsdatum($c),
                    'sprache' => in_array($c->preferred_lang, ['de', 'ar'], true) ? $c->preferred_lang : 'de',
                ];
            })->values(),
        ]);
    }

    /** Geburtsdatum als TT.MM.JJJJ; null, wenn keins oder unlesbar. */
    private function geburtsdatum(Customer $customer): ?string
    {
        if (empty($customer->birth_date)) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($customer->birth_date)->format('d.m.Y');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Der Editor: Seiten ansehen, Felder setzen, Unterzeichner pflegen. */
    public function prepare(string $id)
    {
        $signature = $this->find($id);
        Gate::authorize('update', $signature);

        $darfFirma = Gate::allows('firmensignatur-benutzen');
        $typen = SignatureFieldType::LABELS;
        if (! $darfFirma) {
            unset($typen[SignatureFieldType::COMPANY]);
        }

        return view('admin.signatures.prepare', [
            'signature' => $signature->load(['signers', 'fields']),
            'geometry' => $this->renderer->geometry($signature),
            'previewAvailable' => $this->renderer->available(),
            'fieldTypes' => $typen,
            // Nur AKTIVE Bilder zur Auswahl: ein stillgelegtes steckt zwar
            // noch in alten Dokumenten, soll aber in kein neues mehr.
            'companyAssets' => $darfFirma
                ? CompanySignatureAsset::where('active', true)->orderBy('type')->orderByDesc('is_default')->get()
                : collect(),
        ]);
    }

    /** Speichert Unterzeichner UND Felder in einem Zug (der Editor kennt beides). */
    public function savePrepare(Request $request, string $id)
    {
        $signature = $this->find($id);
        Gate::authorize('update', $signature);

        $data = $request->validate([
            'signers' => ['array', 'max:10'],
            'signers.*.id' => ['nullable', 'string', 'max:64'],
            'signers.*.key' => ['nullable', 'string', 'max:64'],
            'signers.*.name' => ['required', 'string', 'max:160'],
            'signers.*.email' => ['required', 'email:filter', 'max:190'],
            'signers.*.locale' => ['nullable', 'string', 'in:'.implode(',', array_keys(SignatureSigner::LOCALES))],
            'fields' => ['array', 'max:200'],
            'fields.*.id' => ['nullable', 'string', 'max:64'],
            'fields.*.signer_id' => ['nullable', 'string', 'max:64'],
            'fields.*.signer_key' => ['nullable', 'string', 'max:64'],
            'fields.*.company_asset_id' => ['nullable', 'string', 'max:64'],
            'fields.*.type' => ['required', 'string', 'in:'.implode(',', SignatureFieldType::keys())],
            'fields.*.page' => ['required', 'integer', 'min:1', 'max:200'],
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.width' => ['required', 'numeric', 'min:0.005', 'max:1'],
            'fields.*.height' => ['required', 'numeric', 'min:0.003', 'max:1'],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.label' => ['nullable', 'string', 'max:120'],
        ]);

        if (! $signature->isDraft()) {
            return $this->respond($request, false, 'Nach dem Versand kann die Aufteilung nicht mehr geändert werden.');
        }

        // Wer Firmenbilder nicht benutzen darf, kann auch keine setzen -
        // geprueft wird das HIER, nicht nur im Editor: ein Formular laesst
        // sich nachbauen, eine Serverpruefung nicht.
        $felder = $data['fields'] ?? [];
        if (! Gate::allows('firmensignatur-benutzen')) {
            $felder = array_values(array_filter(
                $felder,
                fn ($f) => ($f['type'] ?? '') !== SignatureFieldType::COMPANY
            ));
        }

        $keys = $this->requests->syncSigners($signature, $data['signers'] ?? []);
        $this->requests->syncFields($signature->fresh(), $felder, $keys);

        return $this->respond($request, true, 'Gespeichert.');
    }

    /** Seitenbild fuer den Editor. Laeuft ueber den Controller, nie ueber einen Dateipfad. */
    public function pageImage(string $id, int $page)
    {
        $signature = $this->find($id);
        Gate::authorize('view', $signature);

        $png = $this->renderer->page($signature, max(1, min($page, (int) $signature->page_count)));
        if ($png === null) {
            abort(404);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    public function send(Request $request, string $id)
    {
        $signature = $this->find($id);
        Gate::authorize('send', $signature);

        try {
            $this->requests->send($signature->load(['signers', 'fields']));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.signatures.show', $signature->id)
            ->with('success', 'Die Einladung wurde versendet.');
    }

    public function remind(string $id)
    {
        $signature = $this->find($id);
        Gate::authorize('send', $signature);

        try {
            $count = $this->requests->remind($signature->load('signers'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($count > 0 ? 'success' : 'error', $count > 0
            ? 'Erinnerung an '.$count.' Unterzeichner versendet.'
            : 'Es konnte keine Erinnerung versendet werden.');
    }

    public function cancel(Request $request, string $id)
    {
        $signature = $this->find($id);
        Gate::authorize('cancel', $signature);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $this->requests->cancel($signature->load('signers'), $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Die Signaturanfrage wurde abgebrochen.');
    }

    /**
     * Herunterladen - IMMER ueber diesen Weg, nie ueber einen Pfad im
     * oeffentlichen Verzeichnis. Der Zugriff wird bei jedem Aufruf geprueft.
     */
    public function download(string $id, string $which = 'signed')
    {
        $signature = $this->find($id);
        Gate::authorize('download', $signature);

        $path = $which === 'original' ? $signature->original_path : $signature->signed_path;
        $binary = $this->storage->read($path);
        if ($binary === null) {
            abort(404, 'Die Datei ist nicht (mehr) vorhanden.');
        }
        if ($which !== 'original') {
            app(SignatureAuditService::class)
                ->record($signature, 'downloaded', description: 'Mitarbeiter-Download');
        }

        $name = ($which === 'original' ? 'Original-' : 'Unterschrieben-').Str::slug($signature->title).'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function audit(string $id)
    {
        $signature = $this->find($id);
        Gate::authorize('audit', $signature);

        return view('admin.signatures.audit', [
            'signature' => $signature,
            'events' => $signature->events()->with(['user', 'signer'])->limit(500)->get(),
        ]);
    }

    /**
     * Die Zuordnung nach dem Unterschreiben. Vier Wege, wie vom Betrieb
     * gefordert: bestehender Kunde, neuer Kunde, Vertrag, oder gar nichts.
     */
    public function assign(Request $request, string $id)
    {
        $signature = $this->find($id);
        Gate::authorize('assignCustomer', $signature);

        $data = $request->validate([
            'aktion' => ['required', 'in:kunde,neuer_kunde,vertrag,keine'],
            'customer_id' => ['nullable', 'string', 'max:64'],
            'contract_id' => ['nullable', 'string', 'max:64'],
            'signer_id' => ['nullable', 'string', 'max:64'],
        ]);

        if ($data['aktion'] === 'keine') {
            return back()->with('success', 'Das Dokument bleibt unter Signaturen.');
        }

        if (! $signature->isCompleted()) {
            return back()->with('error', 'Zugeordnet wird erst, wenn das Dokument unterschrieben ist.');
        }

        try {
            if ($data['aktion'] === 'neuer_kunde') {
                $customer = $this->documents->createCustomer($signature->load('signers'), (string) ($data['signer_id'] ?? ''));

                return back()->with('success', 'Kunde '.$customer->customer_number.' angelegt und Dokument zugeordnet.');
            }

            if ($data['aktion'] === 'vertrag') {
                $contract = Contract::with('customer')->findOrFail($data['contract_id']);
                $this->authorizeCustomerAccess($contract->customer_id);
                $this->documents->assignCustomer($signature->load('signers'), $contract->customer, $contract);

                return back()->with('success', 'Dokument dem Vertrag zugeordnet.');
            }

            $customer = Customer::with('user')->findOrFail($data['customer_id']);
            $this->authorizeCustomerAccess($customer->id);
            $this->documents->assignCustomer($signature->load('signers'), $customer);

            return back()->with('success', 'Dokument der Kundenakte zugeordnet.');
        } catch (DuplicateCustomerException $e) {
            return back()->with('error', 'Es gibt bereits einen ähnlichen Kunden ('
                .($e->matchResult->customer->customer_number ?? '—').'). Bitte diesen zuordnen statt neu anzulegen.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function find(string $id): SignatureRequest
    {
        return SignatureRequest::findOrFail($id);
    }

    private function respond(Request $request, bool $ok, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }
}
