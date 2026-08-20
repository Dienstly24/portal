<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\VermittlerImport;
use App\Models\VermittlerSettlement;
use App\Services\Vermittler\VermittlerAbrechnungImporter;
use App\Services\Vermittler\VermittlerLinkService;
use App\Services\Vermittler\VermittlerReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Vermittler-Abrechnung: CSV-Import, Zuordnung und Auswertung
 * (Betreiber-Auftrag 20.08.2026).
 *
 * Zugriff NUR admin/manager - hier stehen Provisionsbetraege, dieselbe
 * Zugriffsregel wie beim uebrigen Provisions-Management.
 */
class VermittlerAbrechnungController extends Controller
{
    /** Uebersicht: Datei hochladen + bisherige Laeufe. */
    public function index()
    {
        return view('admin.vermittler_abrechnung', [
            'imports' => VermittlerImport::with('importer')->latest()->limit(20)->get(),
            'openCount' => VermittlerSettlement::needsReview()->count(),
            'performance' => app(VermittlerReportService::class)->performance(),
        ]);
    }

    /** Datei einlesen, zuordnen und direkt das Ergebnis zeigen. */
    public function import(Request $request, VermittlerAbrechnungImporter $importer)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:20480',
        ], [], ['csv_file' => 'CSV-Datei']);

        // Datei zwischenspeichern: der Import liest sie mehrfach (Hash +
        // Inhalt) und ein temporaerer Upload ist danach nicht mehr da.
        $dir = storage_path('app/private/vermittler-imports');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $stored = $dir . '/' . Str::uuid() . '.csv';
        copy($request->file('csv_file')->getPathname(), $stored);

        try {
            $import = $importer->import(
                $stored,
                (string) $request->file('csv_file')->getClientOriginalName(),
                auth()->id(),
                $request->boolean('reconcile', true),
            );
        } catch (\Throwable $e) {
            @unlink($stored);
            return back()->with('error', 'Die Datei konnte nicht gelesen werden: ' . $e->getMessage());
        }

        // Die Originaldatei wird bewusst NICHT dauerhaft aufbewahrt - die
        // Daten stehen vollstaendig in vermittler_settlements, die Datei
        // enthaelt darueber hinaus nichts.
        @unlink($stored);

        ActivityLog::record('vermittler_import', 'vermittler_import', $import->id, [
            'filename' => $import->filename,
            'rows_total' => $import->rows_total,
            'rows_matched' => $import->rows_matched + $import->rows_new_link,
            'rows_review' => $import->rows_review,
        ]);

        return redirect()->route('admin.vermittler.show', $import->id)
            ->with('success', 'Import abgeschlossen: ' . $import->rows_total . ' Datensätze gelesen.');
    }

    /** Ergebnis eines Laufs: Zusammenfassung + Zeilen. */
    public function show(Request $request, string $id)
    {
        $import = VermittlerImport::with('importer')->findOrFail($id);

        $filter = $request->get('ergebnis');
        $rows = $import->settlements()
            ->with(['contract.customer.user', 'customer.user'])
            ->when(isset(VermittlerSettlement::RESULTS[$filter]), fn ($q) => $q->where('import_result', $filter))
            ->orderByRaw("case import_result when 'review' then 0 when 'unmatched' then 1 when 'linked' then 2 else 3 end")
            ->orderByDesc('statement_date')
            ->paginate(100)->withQueryString();

        return view('admin.vermittler_import_result', compact('import', 'rows', 'filter'));
    }

    /**
     * Pruefliste: alles, was der Import NICHT eindeutig zuordnen konnte -
     * plus die Vertraege, die in keiner Abrechnung auftauchen. Bewusst eine
     * Arbeitsliste, keine Statistik: jede Zeile will eine Entscheidung.
     */
    public function review(Request $request)
    {
        $settlements = VermittlerSettlement::needsReview()
            ->with(['contract.customer.user'])
            ->orderByDesc('statement_date')
            ->paginate(50, ['*'], 'offene')->withQueryString();

        $missing = Contract::with('customer.user')
            ->whereIn('vermittler_status', [Contract::VERMITTLER_NICHT_GEFUNDEN, Contract::VERMITTLER_PRUEFUNG])
            ->orderByDesc('vermittler_last_imported_at')
            ->paginate(50, ['*'], 'vertraege')->withQueryString();

        return view('admin.vermittler_review', compact('settlements', 'missing'));
    }

    /**
     * Vorschlaege fuer die manuelle Zuordnung eines Datensatzes (Sofort-Suche
     * wie in den uebrigen Formularen - nie der gesamte Bestand im HTML).
     */
    public function contractSearch(Request $request)
    {
        $term = trim((string) $request->get('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $contracts = Contract::with('customer.user')
            ->search($term)
            ->orderByDesc('created_at')
            ->limit(8)->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'label' => trim($c->insurer . ' · ' . $c->typeLabel()),
                'customer' => $c->customer?->user?->name,
                'reference' => $c->reference_number,
                'vermittler_id' => $c->vermittler_id,
                'status' => $c->vermittlerStatusLabel(),
            ]);

        return response()->json($contracts);
    }

    /** Manuelle Zuordnung eines Abrechnungs-Datensatzes zu einem Vertrag. */
    public function link(Request $request, string $id, VermittlerLinkService $linker)
    {
        $data = $request->validate(['contract_id' => 'required|uuid|exists:contracts,id']);

        $settlement = VermittlerSettlement::findOrFail($id);
        $contract = Contract::with('customer.user')->findOrFail($data['contract_id']);

        try {
            $linker->linkManually($settlement, $contract, auth()->id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::record('vermittler_manual_link', 'contract', $contract->id, [
            'vermittler_id' => $settlement->vermittler_id,
            'reference_number' => $contract->reference_number,
        ]);

        return back()->with('success', 'Datensatz ' . $settlement->vermittler_id . ' wurde dem Vertrag zugeordnet.');
    }

    /** Auswertung: Produkte, Kunden und die Bestaetigungsquote des Vermittlers. */
    public function report(VermittlerReportService $reports)
    {
        return view('admin.vermittler_report', [
            'performance' => $reports->performance(),
            'products' => $reports->byProduct(),
            'customers' => $reports->byCustomer(),
        ]);
    }
}
