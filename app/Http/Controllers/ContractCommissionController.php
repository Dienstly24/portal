<?php
namespace App\Http\Controllers;

use App\Models\CommissionAuditLog;
use App\Models\CommissionImport;
use App\Models\Contract;
use App\Models\ContractCommission;
use App\Services\CommissionImport\ColumnMap;
use App\Services\CommissionImport\CommissionAuditLogger;
use App\Services\CommissionImport\CommissionImportService;
use App\Services\CommissionImport\InvoiceCommissionMatcher;
use App\Services\CommissionImport\TableReader;
use App\Support\CommissionStatus;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;

/**
 * Interne Provisionen: Import aus Fremdsystemen, Zuordnung zum Vertrag,
 * Zahlungen, Rechnungsabgleich (Betreiber-Auftrag 26.08.2026).
 *
 * ZUGRIFF: ausschliesslich ueber das Recht `provisionen-verwalten` (Admin
 * oder ausdruecklich vergebenes Recht). Die Pruefung steht an der ROUTE UND
 * hier im Konstruktor - doppelt mit Absicht: eine Route wird beim naechsten
 * Umbau schnell einmal verschoben, der Controller bleibt.
 */
class ContractCommissionController extends Controller implements HasMiddleware
{
    /** @return array<int,Middleware|string> */
    public static function middleware(): array
    {
        return ['can:provisionen-verwalten'];
    }

    // ------------------------------------------------------------- Uebersicht

    /** Liste aller Provisionen mit Suche und Filtern. */
    public function index(Request $request)
    {
        $query = ContractCommission::query()->with(['contract.customer.user', 'import']);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                foreach (['internal_contract_number', 'external_contract_number', 'reference_number',
                    'vermittler_id', 'order_number', 'external_id', 'customer_label', 'recipient_name',
                    'product_name', 'company', 'invoice_number'] as $column) {
                    $w->orWhere($column, 'like', $like);
                }
            });
        }

        if (CommissionStatus::isValid($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }
        // Filter nach QUELLE: die Frage des Betriebs lautet "was hat uns
        // welcher Vermittler gebracht?" - dafuer taugt der Dateiname nicht,
        // er wechselt mit jedem Export.
        if ($request->filled('quelle')) {
            $request->query('quelle') === 'unbekannt'
                ? $query->whereNull('provider')
                : $query->where('provider', $request->query('quelle'));
        }
        if ($request->filled('empfaenger')) {
            $query->where('recipient_name', 'like', '%' . addcslashes((string) $request->query('empfaenger'), '%_\\') . '%');
        }
        if ($request->filled('kunde')) {
            $query->where('customer_id', $request->query('kunde'));
        }
        if ($request->filled('vertrag')) {
            $query->where('contract_id', $request->query('vertrag'));
        }
        // Buchungen EINER Abrechnung - der Weg von der Abrechnungsliste des
        // Provisionsmanagements zu den Zeilen, die daraus entstanden sind.
        if ($request->filled('import')) {
            $query->where('import_id', $request->query('import'));
        }
        if ($request->filled('pool')) {
            $query->where('pool', $request->query('pool'));
        }
        if ($request->query('zuordnung') === 'offen') {
            $query->whereNull('contract_id');
        }
        // Zeitraum auf dem PROVISIONSDATUM - das ist das Datum, nach dem der
        // Betrieb sucht ("die Abrechnung vom August").
        if ($request->filled('von')) {
            $query->whereDate('commission_date', '>=', $request->query('von'));
        }
        if ($request->filled('bis')) {
            $query->whereDate('commission_date', '<=', $request->query('bis'));
        }

        // Die Summen folgen der FILTERUNG, nicht dem Gesamtbestand - eine
        // Zahl, die nicht zum Gezeigten passt, ist schlimmer als keine.
        $totals = (clone $query)->selectRaw(
            'COUNT(*) as anzahl, SUM(amount) as summe, SUM(CASE WHEN status IN (?,?,?) THEN amount ELSE 0 END) as offen',
            [CommissionStatus::OFFEN, CommissionStatus::FAELLIG, CommissionStatus::TEILWEISE]
        )->first();

        return view('admin.commissions_internal.index', [
            'commissions' => $query->orderByDesc('commission_date')->orderByDesc('created_at')
                ->paginate(50)->withQueryString(),
            'totals' => $totals,
            'statuses' => CommissionStatus::ALL,
            'filters' => $request->only(['q', 'status', 'quelle', 'empfaenger', 'kunde', 'vertrag', 'zuordnung', 'von', 'bis', 'import', 'pool']),
            'providers' => \App\Services\CommissionImport\CommissionSourceProfile::PROFILES,
            'unmatchedCount' => ContractCommission::unmatched()->count(),
            'draftCount' => CommissionImport::where('status', CommissionImport::ENTWURF)->count(),
        ]);
    }

    /** Eine Provision im Detail samt Protokoll. */
    public function show(string $id)
    {
        $commission = ContractCommission::with(['contract.customer.user', 'import', 'auditLogs.user'])->findOrFail($id);

        return view('admin.commissions_internal.show', [
            'commission' => $commission,
            'statuses' => CommissionStatus::ALL,
        ]);
    }

    // ----------------------------------------------------------------- Import

    /** Schritt 1: Datei hochladen. */
    public function importForm()
    {
        return view('admin.commissions_internal.import', [
            'imports' => CommissionImport::with('importer')->latest()->limit(20)->get(),
            'extensions' => TableReader::EXTENSIONS,
            // §6: Die Quelle wird VOR dem Lesen gewaehlt - sie entscheidet
            // ueber die Fristen, gegen die spaeter gemessen wird.
            'pools' => app(\App\Services\Provisionsmanagement\PoolRegistry::class)->active(),
        ]);
    }

    /**
     * Schritt 2: Datei lesen, erkennen, als Entwurf ablegen.
     *
     * Die Datei wird NUR fuer den Lesevorgang zwischengespeichert und danach
     * geloescht: alles Gebrauchte steht im Entwurf, und eine Datei mit
     * Kunden- und Provisionsdaten soll nicht laenger auf der Platte liegen
     * als noetig (Datenminimierung).
     */
    public function upload(Request $request, CommissionImportService $service)
    {
        $request->validate([
            // `mimes` allein reicht bei diesen Formaten NICHT: eine von Excel
            // geschriebene CSV kommt je nach Browser als text/plain,
            // application/vnd.ms-excel oder application/octet-stream an -
            // genau daran scheiterte der Upload bisher. Geprueft wird deshalb
            // die ENDUNG, und das echte Format erkennt der Leser danach an
            // den ersten Bytes der Datei.
            'datei' => 'required|file|max:20480|extensions:' . implode(',', TableReader::EXTENSIONS),
        ], [
            'datei.extensions' => 'Es werden CSV- und Excel-Dateien unterstützt (.csv, .txt, .xlsx, .xlsm, .xls).',
            'datei.max' => 'Die Datei ist größer als 20 MB. Bitte in mehrere Dateien aufteilen.',
        ], ['datei' => 'Datei']);

        $stored = $this->stash($request->file('datei'));

        try {
            $import = $service->analyze(
                $stored,
                (string) $request->file('datei')->getClientOriginalName(),
                auth()->id(),
                $request->input('delimiter') ?: null,
                $request->input('encoding') ?: null,
                $request->input('sheet') ?: null,
                null,
                $request->input('modus') ?: null,
                // §6: Der Admin waehlt beim Upload die QUELLE. Sie ist die
                // Grundlage der Fristen - ohne Pool gaebe es kein "erwartet"
                // und damit auch kein "fehlt". Leer bleibt erlaubt: die
                // Erkennung schlaegt dann anhand des Dateiformats einen vor.
                $request->input('pool') ?: null,
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Die Datei konnte nicht gelesen werden: ' . $e->getMessage())->withInput();
        } finally {
            @unlink($stored);
        }

        return redirect()->route('admin.commissions_internal.preview', $import->id);
    }

    /** Schritt 3+4: Erkennung, Spaltenzuordnung und Prueflauf ansehen. */
    public function preview(Request $request, string $id)
    {
        $import = CommissionImport::with('importer')->findOrFail($id);

        $filter = $request->query('zeigen');
        $rows = $import->rows()->with('contract.customer.user')->orderBy('row_number');
        if (in_array($filter, ['neu', 'aktualisiert', 'duplikat', 'nicht_zugeordnet', 'fehlerhaft'], true)) {
            $rows->where('result', $filter);
        }

        return view('admin.commissions_internal.preview', [
            'import' => $import,
            'rows' => $rows->paginate(100)->withQueryString(),
            'fields' => ColumnMap::FIELDS,
            'mapErrors' => ColumnMap::validate((array) $import->column_map, (string) $import->mode),
            'modes' => ColumnMap::MODES,
            'filter' => $filter,
        ]);
    }

    /** Spaltenzuordnung aendern und den Entwurf neu bewerten. */
    public function remap(Request $request, string $id, CommissionImportService $service)
    {
        $import = CommissionImport::findOrFail($id);

        $map = [];
        foreach ((array) $request->input('spalte', []) as $field => $index) {
            if (in_array($field, ColumnMap::keys(), true) && $index !== null && $index !== '') {
                $map[$field] = (int) $index;
            }
        }

        try {
            $service->remap($import, $map, $request->input('modus') ?: null);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.commissions_internal.preview', $import->id)
            ->with('success', 'Zuordnung übernommen – die Vorschau wurde neu berechnet.');
    }

    /** Schritt 5: Entwurf bestaetigen - erst hier werden Daten geschrieben. */
    public function confirm(Request $request, string $id, CommissionImportService $service)
    {
        $import = CommissionImport::findOrFail($id);

        try {
            // Das Anlegen von Vertraegen und Kunden ist ein AUSDRUECKLICHER
            // Haken, kein Standard: ein Lauf kann hunderte Datensaetze
            // erzeugen, und das soll niemand versehentlich ausloesen.
            $import = $service->confirm($import, auth()->id(), $request->boolean('vertraege_anlegen'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $parts = [];
        if ($import->isAbrechnung()) {
            $parts[] = $import->rows_new . ' neu';
            $parts[] = $import->rows_updated . ' aktualisiert';
            if ($import->rows_unlinked_kept > 0) {
                $parts[] = $import->rows_unlinked_kept . ' ohne Vertrag aufbewahrt';
            }
        }
        if ($import->contracts_created > 0) {
            $parts[] = $import->contracts_created . ' Verträge angelegt';
        }
        if ($import->customers_created > 0) {
            $parts[] = $import->customers_created . ' Kunden angelegt';
        }

        return redirect()->route('admin.commissions_internal.preview', $import->id)->with(
            'success',
            'Import übernommen: ' . ($parts === [] ? 'nichts zu übernehmen.' : implode(', ', $parts) . '.')
        );
    }

    public function discard(string $id, CommissionImportService $service)
    {
        $import = CommissionImport::findOrFail($id);
        try {
            $service->discard($import);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        return redirect()->route('admin.commissions_internal.import')->with('success', 'Der Entwurf wurde verworfen – es wurde nichts übernommen.');
    }

    /**
     * Fehlerhafte und nicht zugeordnete Zeilen als CSV herunterladen -
     * korrigieren, erneut hochladen. Ohne diesen Weg muesste der Admin die
     * Fehlerliste am Bildschirm abschreiben.
     */
    public function errorExport(string $id)
    {
        $import = CommissionImport::findOrFail($id);
        $filename = 'fehler-' . Str::slug(pathinfo($import->filename, PATHINFO_FILENAME)) . '.csv';

        app(CommissionAuditLogger::class)->log('export', null, [
            'import_id' => $import->id,
            'source_file' => $import->filename,
            'new_value' => 'Fehlerliste',
        ]);

        return response()->streamDownload(function () use ($import) {
            $out = fopen('php://output', 'w');
            // BOM, damit Excel die Umlaute richtig anzeigt - ohne ihn steht
            // in der korrigierten Datei "Prüfung" als "PrÃ¼fung".
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['Zeile', 'Ergebnis', 'Meldung'], (array) $import->header), ';');
            $import->rows()->whereIn('result', ['fehlerhaft', 'nicht_zugeordnet'])
                ->orderBy('row_number')
                ->chunkById(300, function ($rows) use ($out) {
                    foreach ($rows as $row) {
                        fputcsv($out, array_merge([$row->row_number, $row->resultLabel(), $row->message], (array) $row->raw), ';');
                    }
                });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------- Pflege einzelner

    /** Status setzen (mit Protokoll). */
    public function updateStatus(Request $request, string $id, CommissionAuditLogger $audit)
    {
        $commission = ContractCommission::findOrFail($id);
        $data = $request->validate([
            'status' => 'required|in:' . implode(',', CommissionStatus::keys()),
            'grund' => 'nullable|string|max:255',
        ]);

        $old = $commission->status;
        if ($old === $data['status']) {
            return back()->with('success', 'Der Status war bereits „' . CommissionStatus::label($old) . '“.');
        }

        $commission->update(['status' => $data['status'], 'updated_by' => auth()->id()]);
        $audit->log('status_geaendert', $commission, [
            'field' => 'status',
            'old_value' => CommissionStatus::label($old),
            'new_value' => CommissionStatus::label($data['status']) . (($data['grund'] ?? null) ? ' – ' . $data['grund'] : ''),
        ]);

        return back()->with('success', 'Status geändert auf „' . CommissionStatus::label($data['status']) . '“.');
    }

    /**
     * Zahlung erfassen. Eine TEILzahlung laesst den Status bewusst auf
     * "teilweise bezahlt" stehen - erst der volle Betrag ist "bezahlt".
     */
    public function pay(Request $request, string $id, CommissionAuditLogger $audit)
    {
        $commission = ContractCommission::findOrFail($id);

        // Deutsche Eingabe ("1.234,56") vor der Pruefung normalisieren: das
        // Zahlenfeld liefert je nach Browser und Tastatur beides, und eine
        // Ablehnung mit „muss eine Zahl sein" bei einem korrekt getippten
        // Betrag ist die Art von Huerde, an der niemand den Fehler bei sich
        // sucht.
        $request->merge(['betrag' => \App\Services\CommissionImport\ValueParser::amount((string) $request->input('betrag'))]);

        $data = $request->validate([
            'betrag' => 'required|numeric|min:0',
            'zahlungsdatum' => 'required|date',
            'rechnungsnummer' => 'nullable|string|max:60',
        ], [
            'betrag.required' => 'Der Betrag konnte nicht gelesen werden. Bitte als Zahl eingeben, z. B. 850,00.',
        ], ['betrag' => 'Betrag', 'zahlungsdatum' => 'Zahlungsdatum']);

        $paid = round((float) $data['betrag'], 2);
        $total = (float) $commission->amount;
        $status = $total > 0 && $paid + 0.005 < $total ? CommissionStatus::TEILWEISE : CommissionStatus::BEZAHLT;

        $before = $commission->getOriginal();
        $commission->update([
            'paid_amount' => $paid,
            'payment_date' => $data['zahlungsdatum'],
            'status' => $status,
            'invoice_number' => ($data['rechnungsnummer'] ?? null) ?: $commission->invoice_number,
            'updated_by' => auth()->id(),
        ]);
        $audit->changes('zahlung_erfasst', $commission, $before, $commission->getAttributes(), [
            'paid_amount', 'payment_date', 'status', 'invoice_number',
        ]);

        return back()->with('success', 'Zahlung erfasst: ' . number_format($paid, 2, ',', '.') . ' € – Status „' . CommissionStatus::label($status) . '“.');
    }

    /** Interne Notiz und Kennungen pflegen. */
    public function update(Request $request, string $id, CommissionAuditLogger $audit)
    {
        $commission = ContractCommission::findOrFail($id);
        $data = $request->validate([
            'internal_contract_number' => 'nullable|string|max:60',
            'recipient_name' => 'nullable|string|max:190',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
        ]);

        $before = $commission->getOriginal();
        $commission->update([
            'internal_contract_number' => ($data['internal_contract_number'] ?? null) ?: null,
            'internal_key' => \App\Services\Vermittler\VermittlerReference::key($data['internal_contract_number'] ?? null),
            'recipient_name' => ($data['recipient_name'] ?? null) ?: null,
            'due_date' => ($data['due_date'] ?? null) ?: null,
            'notes' => ($data['notes'] ?? null) ?: null,
            'updated_by' => auth()->id(),
        ]);
        $audit->changes('provision_geaendert', $commission, $before, $commission->getAttributes(), [
            'internal_contract_number', 'recipient_name', 'due_date', 'notes',
        ]);

        return back()->with('success', 'Änderungen gespeichert.');
    }

    /**
     * Eine offene Provision von Hand einem Vertrag zuordnen. Der Weg ist
     * bewusst manuell: was der Abgleich nicht sicher konnte, entscheidet ein
     * Mensch - nicht ein zweiter, weicherer Automatismus.
     */
    public function link(Request $request, string $id, CommissionAuditLogger $audit)
    {
        $commission = ContractCommission::findOrFail($id);
        $data = $request->validate(['contract_id' => 'required|uuid|exists:contracts,id']);

        $contract = Contract::with('customer.user')->findOrFail($data['contract_id']);
        $before = $commission->getOriginal();

        $commission->update([
            'contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'contract_label' => trim($contract->typeLabel() . ' · ' . ($contract->contract_number ?: $contract->reference_number ?: '')),
            'customer_label' => $contract->customer?->user?->name,
            'match_status' => ContractCommission::MATCH_MANUELL,
            'match_reason' => 'Von Hand zugeordnet',
            'updated_by' => auth()->id(),
        ]);
        $audit->changes('vertrag_zugeordnet', $commission, $before, $commission->getAttributes(), ['contract_id']);

        return back()->with('success', 'Provision dem Vertrag ' . ($contract->contract_number ?: $contract->id) . ' zugeordnet.');
    }

    public function unlink(string $id, CommissionAuditLogger $audit)
    {
        $commission = ContractCommission::findOrFail($id);
        $before = $commission->getOriginal();
        $commission->update([
            'contract_id' => null,
            'customer_id' => null,
            'match_status' => ContractCommission::MATCH_OFFEN,
            'match_reason' => null,
            'updated_by' => auth()->id(),
        ]);
        // Klartext-Kopie (contract_label/customer_label) bleibt bewusst
        // stehen: sie belegt, welchem Vertrag die Provision zugeordnet WAR.
        $audit->changes('zuordnung_geloest', $commission, $before, $commission->getAttributes(), ['contract_id']);

        return back()->with('success', 'Zuordnung gelöst. Die Provision steht jetzt in der Liste „Nicht zugeordnet“.');
    }

    /** Vertragssuche fuer die manuelle Zuordnung (Sofort-Suche, JSON). */
    public function contractSearch(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $contracts = Contract::with('customer.user')->search($term)->limit(8)->get();

        return response()->json($contracts->map(fn ($c) => [
            'id' => $c->id,
            'label' => trim($c->typeIcon() . ' ' . $c->typeLabel() . ' · ' . ($c->insurer ?: '—')),
            'number' => $c->contract_number ?: ($c->internal_contract_number ?: $c->reference_number ?: '—'),
            'customer' => $c->customer?->user?->name ?? '—',
        ])->all());
    }

    // -------------------------------------------------------------- Rechnung

    /**
     * Rechnungsabgleich: Kennung eingeben (oder aus einer Rechnung
     * uebernehmen) -> Vertrag, Kunde und erwartete Provisionen sehen.
     */
    public function invoiceMatch(Request $request, InvoiceCommissionMatcher $matcher)
    {
        $identifier = trim((string) $request->query('kennung', ''));
        $result = null;

        if ($identifier !== '') {
            $result = $matcher->lookup($identifier);
        }

        return view('admin.commissions_internal.invoice', [
            'identifier' => $identifier,
            'result' => $result,
        ]);
    }

    /** Rechnung an eine Provision haengen (Zahlung bleibt eine eigene Aktion). */
    public function linkInvoice(Request $request, string $id, CommissionAuditLogger $audit)
    {
        $commission = ContractCommission::findOrFail($id);
        $request->merge(['invoice_amount' => \App\Services\CommissionImport\ValueParser::amount((string) $request->input('invoice_amount'))]);
        $data = $request->validate([
            'invoice_number' => 'required|string|max:60',
            'invoice_date' => 'nullable|date',
            'invoice_amount' => 'nullable|numeric',
        ], [], ['invoice_number' => 'Rechnungsnummer']);

        $before = $commission->getOriginal();
        $commission->update([
            'invoice_number' => $data['invoice_number'],
            'invoice_date' => ($data['invoice_date'] ?? null) ?: null,
            'invoice_amount' => $data['invoice_amount'] ?? null,
            'invoice_linked_at' => now(),
            'updated_by' => auth()->id(),
        ]);
        $audit->changes('rechnung_verknuepft', $commission, $before, $commission->getAttributes(), [
            'invoice_number', 'invoice_date', 'invoice_amount',
        ]);

        return back()->with('success', 'Rechnung ' . $data['invoice_number'] . ' verknüpft. Die Zahlung ist damit noch nicht bestätigt.');
    }

    public function unlinkInvoice(string $id, CommissionAuditLogger $audit)
    {
        $commission = ContractCommission::findOrFail($id);
        $before = $commission->getOriginal();
        $commission->update([
            'invoice_number' => null, 'invoice_date' => null, 'invoice_amount' => null,
            'invoice_linked_at' => null, 'updated_by' => auth()->id(),
        ]);
        $audit->changes('rechnung_geloest', $commission, $before, $commission->getAttributes(), ['invoice_number']);

        return back()->with('success', 'Rechnungsverknüpfung gelöst.');
    }

    // ----------------------------------------------------------- Protokoll

    /** Das Protokoll ist REIN LESEND - es gibt keinen Loeschweg. */
    public function auditLog(Request $request)
    {
        $query = CommissionAuditLog::with(['user', 'commission'])->orderByDesc('created_at');

        if ($request->filled('aktion')) {
            $query->where('action', $request->query('aktion'));
        }
        if ($request->filled('q')) {
            $like = '%' . addcslashes((string) $request->query('q'), '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('internal_contract_number', 'like', $like)
                    ->orWhere('source_file', 'like', $like)
                    ->orWhere('user_label', 'like', $like);
            });
        }

        return view('admin.commissions_internal.audit', [
            'entries' => $query->paginate(100)->withQueryString(),
            'actions' => CommissionAuditLog::ACTIONS,
            'filters' => $request->only(['aktion', 'q']),
        ]);
    }

    /** Export der gefilterten Liste (gestreamt, mit Protokolleintrag). */
    public function export(Request $request, CommissionAuditLogger $audit)
    {
        $query = ContractCommission::query()->with('contract');
        if (CommissionStatus::isValid($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('quelle')) {
            $request->query('quelle') === 'unbekannt'
                ? $query->whereNull('provider')
                : $query->where('provider', $request->query('quelle'));
        }
        if ($request->filled('von')) {
            $query->whereDate('commission_date', '>=', $request->query('von'));
        }
        if ($request->filled('bis')) {
            $query->whereDate('commission_date', '<=', $request->query('bis'));
        }

        // Der Protokolleintrag entsteht VOR dem Streamen: er darf nicht davon
        // abhaengen, dass der Download sauber zu Ende geht (gleiche Regel wie
        // beim Kunden-Export).
        $audit->log('export', null, ['new_value' => 'Provisionsliste ' . now()->format('d.m.Y H:i')]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Interne Vertragsnummer', 'Vertragsnummer', 'Kunde', 'Provisionsempfänger',
                'Provisionsart', 'Produkt', 'Gesellschaft', 'Betrag', 'Währung',
                'Provisionsdatum', 'Fälligkeitsdatum', 'Zahlungsdatum', 'Status',
                'Rechnungsnummer', 'Quelle', 'Datei',
            ], ';');
            $query->orderBy('commission_date')->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $c) {
                    fputcsv($out, [
                        $c->internal_contract_number, $c->contract?->contract_number,
                        $c->customer_label, $c->recipient_name, $c->commission_type,
                        $c->product_name, $c->company,
                        $c->amount === null ? '' : number_format((float) $c->amount, 2, ',', ''),
                        $c->currency,
                        $c->commission_date?->format('d.m.Y'), $c->due_date?->format('d.m.Y'),
                        $c->payment_date?->format('d.m.Y'), $c->statusLabel(),
                        $c->invoice_number, $c->providerLabel(), $c->source_file,
                    ], ';');
                }
            });
            fclose($out);
        }, 'provisionen-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------------------------------------------------------------- Helfer

    /** Upload zwischenspeichern - der Leser braucht einen echten Pfad. */
    private function stash(\Illuminate\Http\UploadedFile $file): string
    {
        $dir = storage_path('app/private/provisions-imports');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . Str::uuid();
        copy($file->getPathname(), $path);
        return $path;
    }
}
