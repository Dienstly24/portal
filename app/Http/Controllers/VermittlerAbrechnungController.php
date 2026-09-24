<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Document;
use App\Models\VermittlerImport;
use App\Models\VermittlerInvoice;
use App\Models\VermittlerSettlement;
use App\Services\CommissionImport\CommissionSourceProfile;
use App\Services\CommissionImport\TableReader;
use App\Services\Vermittler\VermittlerAbrechnungImporter;
use App\Services\Vermittler\VermittlerLinkService;
use App\Services\Vermittler\VermittlerListeReader;
use App\Services\Vermittler\VermittlerRechnungAbgleich;
use App\Services\Vermittler\VermittlerReportService;
use App\Services\Vermittler\VermittlerVorgangslisteImporter;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Vermittler-Abrechnung: CSV-Import, Zuordnung und Auswertung
 * (Betreiber-Auftrag 20.08.2026).
 *
 * Zugriff NUR ueber das Recht `provisionen-verwalten` (Route UND hier) -
 * hier stehen Provisionsbetraege, dieselbe Regel wie im uebrigen
 * Provisionsbereich (Betreiber-Vorgabe 23.09.2026).
 */
class VermittlerAbrechnungController extends Controller implements HasMiddleware
{
    /** Provisionsdaten sehen nur Berechtigte - geprueft an Route UND hier. */
    public static function middleware(): array
    {
        return ['can:provisionen-verwalten'];
    }

    /** Uebersicht: Datei hochladen + bisherige Laeufe. */
    public function index()
    {
        return view('admin.vermittler_abrechnung', [
            'imports' => VermittlerImport::with('importer')->latest()->limit(20)->get(),
            'openCount' => VermittlerSettlement::needsReview()->count(),
            'performance' => app(VermittlerReportService::class)->performance(),
            'ocrAvailable' => app(VermittlerListeReader::class)->ocrAvailable(),
            'invoices' => VermittlerInvoice::with('uploader')->latest()->limit(20)->get(),
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
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $stored = $dir.'/'.Str::uuid().'.csv';
        copy($request->file('csv_file')->getPathname(), $stored);

        try {
            $import = $importer->import(
                $stored,
                (string) $request->file('csv_file')->getClientOriginalName(),
                auth()->id(),
                $request->boolean('reconcile', true),
            );
        } catch (\Throwable $e) {
            // SACKGASSE VERMEIDEN (Betreiber-Meldung 26.08.2026): Diese Seite
            // liest ausschliesslich das Format EINES Vermittlers
            // (TARIFCHECK24, Pflichtspalte "Id"). Der Betrieb bekommt aber
            // Dateien aus mehreren Quellen - Maklerpool, Energie-Portal - und
            // sah bisher nur "Die Spalte Id fehlt", ohne zu erfahren, wohin
            // die Datei stattdessen gehoert. Deshalb wird hier erkannt, WAS
            // die Datei ist, und der richtige Weg genannt.
            $hinweis = $this->wrongImporterHint($stored);
            @unlink($stored);

            return back()->with('error', 'Die Datei konnte nicht gelesen werden: '.$e->getMessage().$hinweis);
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
            ->with('success', 'Import abgeschlossen: '.$import->rows_total.' Datensätze gelesen.');
    }

    /**
     * Rechnung/Gutschrift des Vermittlers hochladen (PDF, Bild oder Text) und
     * gegen die eingelesene Abrechnung pruefen (Betreiber-Auftrag
     * 23.09.2026). Ergebnis ist ein ENTWURF - geschrieben wird erst mit
     * `confirmInvoice`. Die Datei bleibt als Beleg auf der privaten Platte.
     */
    public function uploadInvoice(Request $request, VermittlerListeReader $reader, VermittlerRechnungAbgleich $abgleich)
    {
        $request->validate([
            'rechnung_datei' => 'required|file|mimes:pdf,png,jpg,jpeg,webp,csv,txt|max:20480',
        ], [], ['rechnung_datei' => 'Rechnung']);

        $file = $request->file('rechnung_datei');
        $binary = (string) file_get_contents($file->getPathname());
        $hash = hash('sha256', $binary);
        $name = (string) $file->getClientOriginalName();

        $bereits = VermittlerInvoice::where('file_hash', $hash)
            ->where('status', VermittlerInvoice::STATUS_BESTAETIGT)->first();
        if ($bereits) {
            return redirect()->route('admin.vermittler.invoice', $bereits->id)
                ->with('error', 'Diese Rechnung wurde bereits am '.LocalTime::for($bereits->confirmed_at)?->format('d.m.Y')
                    .' übernommen. Es wurde nichts doppelt gebucht.');
        }

        try {
            $text = $reader->textFromBinary($binary, (string) $file->getMimeType(), $name);
        } catch (\Throwable $e) {
            return back()->with('error', 'Die Rechnung konnte nicht gelesen werden: '.$e->getMessage());
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = 'vermittler-rechnungen/'.$hash.'.'.$extension;
        // put() meldet einen Fehlschlag auch ohne Ausnahme mit false - dann
        // laeuft der Abgleich trotzdem, nur ohne abgelegten Beleg.
        if (! Storage::disk('local')->put($path, $binary)) {
            $path = null;
        }

        $invoice = $abgleich->analyze($text, $name, $hash, $path, $extension, auth()->id());

        ActivityLog::record('vermittler_rechnung_gelesen', 'vermittler_invoice', $invoice->id, [
            'filename' => $invoice->filename,
            'rows_found' => $invoice->rows_found,
        ]);

        if ($invoice->rows_found === 0) {
            return redirect()->route('admin.vermittler.invoice', $invoice->id)
                ->with('error', 'In der Rechnung wurde keine bekannte Id oder Referenz-Nr. gefunden. '
                    .'Zuerst muss die monatliche CSV eingelesen sein - die Rechnung wird nur gegen Vorgänge geprüft, die wir kennen.');
        }

        return redirect()->route('admin.vermittler.invoice', $invoice->id)
            ->with('success', 'Rechnung gelesen: '.$invoice->rows_found.' Positionen gefunden. Bitte prüfen und bestätigen.');
    }

    public function showInvoice(string $id)
    {
        $invoice = VermittlerInvoice::with(['uploader', 'confirmer'])->findOrFail($id);

        return view('admin.vermittler_invoice', ['invoice' => $invoice]);
    }

    public function confirmInvoice(string $id, VermittlerRechnungAbgleich $abgleich)
    {
        $invoice = VermittlerInvoice::findOrFail($id);
        if (! $invoice->isDraft()) {
            return back()->with('error', 'Diese Rechnung ist bereits übernommen.');
        }

        $abgleich->confirm($invoice, auth()->id());

        ActivityLog::record('vermittler_rechnung_bestaetigt', 'vermittler_invoice', $invoice->id, [
            'rows_confirmed' => $invoice->rows_confirmed,
            'rows_deviation' => $invoice->rows_deviation,
        ]);

        return redirect()->route('admin.vermittler.invoice', $invoice->id)
            ->with('success', $invoice->rows_confirmed.' Zahlung(en) durch die Rechnung belegt'
                .($invoice->rows_deviation > 0 ? ', '.$invoice->rows_deviation.' Abweichung(en) in die Prüfliste gestellt' : '').'.');
    }

    /** Die Rechnung selbst - nur ueber diesen Controller, nie ueber eine oeffentliche URL. */
    public function invoiceFile(string $id)
    {
        $invoice = VermittlerInvoice::findOrFail($id);
        abort_if(! $invoice->file_path || ! Storage::disk('local')->exists($invoice->file_path), 404);

        return Storage::disk('local')->download($invoice->file_path, $invoice->filename);
    }

    /**
     * Vorgangsliste einlesen (Screenshot, PDF oder CSV der offenen
     * Vorgaenge). Sie stellt die Bruecke Referenz-Nr. -> Vermittler-ID her,
     * BEVOR die erste Abrechnung kommt - und rechnet bewusst nichts ab.
     */
    public function importVorgangsliste(Request $request, VermittlerListeReader $reader, VermittlerVorgangslisteImporter $importer)
    {
        $request->validate([
            'liste_datei' => 'required|file|mimes:csv,txt,pdf,jpg,jpeg,png,webp|max:20480',
        ], [], ['liste_datei' => 'Datei']);

        $file = $request->file('liste_datei');

        try {
            $parsed = $reader->rows(
                $file->getPathname(),
                (string) $file->getMimeType(),
                (string) $file->getClientOriginalName(),
            );
            $import = $importer->importRows(
                $parsed['rows'],
                $parsed['ambiguous'],
                $parsed['notes'],
                (string) $file->getClientOriginalName(),
                auth()->id(),
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Die Vorgangsliste konnte nicht gelesen werden: '.$e->getMessage());
        }

        if ($import->rows_total === 0) {
            $import->delete();
            return back()->with('error', 'In der Datei wurde kein einziger Vorgang erkannt. '
                .'Erwartet wird die Liste mit Spalten Datum / Produkt / ID / Status und der Referenznummer je Vorgang.');
        }

        ActivityLog::record('vermittler_vorgangsliste_import', 'vermittler_import', $import->id, [
            'filename' => $import->filename,
            'rows_total' => $import->rows_total,
            'rows_new_link' => $import->rows_new_link,
        ]);

        return redirect()->route('admin.vermittler.show', $import->id)
            ->with('success', 'Vorgangsliste gelesen: '.$import->rows_total.' Vorgänge, '
                .$import->rows_new_link.' neu mit einem Vertrag verknüpft.');
    }

    /**
     * Eine Vorgangsliste einlesen, die BEREITS im Dokumenten-Eingang liegt
     * (Betreiber-Wunsch 21.08.2026: dort arbeiten die Mitarbeiter, dort soll
     * der Knopf sein - nicht in einem Verwaltungsbereich, den man erst
     * finden muss).
     *
     * Die Datei wird dafuer erneut gelesen: der Eingang speichert bewusst
     * KEINEN Rohtext (Datenminimierung), also entsteht er hier neu und
     * verschwindet nach dem Lauf wieder.
     */
    public function importFromDocument(string $id, VermittlerListeReader $reader, VermittlerVorgangslisteImporter $importer)
    {
        $document = Document::findOrFail($id);

        if ($document->customer_id !== null) {
            return back()->with('error', 'Dieses Dokument ist bereits einem Kunden zugeordnet – eine Vorgangsliste gehört zu keinem einzelnen Kunden.');
        }
        if ($document->vermittler_import_id !== null) {
            return redirect()->route('admin.vermittler.show', $document->vermittler_import_id);
        }

        $disk = Storage::disk($document->disk ?: 'local');
        if (! $disk->exists($document->file_path)) {
            return back()->with('error', 'Die Datei ist nicht mehr vorhanden.');
        }

        try {
            $parsed = $reader->rowsFromBinary($disk->get($document->file_path), '', (string) $document->file_name);
            if ($parsed['rows'] === []) {
                return back()->with('error', 'In dieser Datei wurde kein einziger Vorgang erkannt. '
                    .'Erwartet wird die Liste mit Datum, Produkt, ID und Status sowie der Referenznummer je Vorgang – '
                    .'am zuverlässigsten als CSV-Export aus dem Vermittler-Portal.');
            }
            $import = $importer->importRows(
                $parsed['rows'],
                $parsed['ambiguous'],
                $parsed['notes'],
                (string) $document->file_name,
                auth()->id(),
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Die Vorgangsliste konnte nicht gelesen werden: '.$e->getMessage());
        }

        // Das Dokument bleibt erhalten (nie automatisch loeschen), verlaesst
        // aber "Nicht zugeordnet" - es ist erledigt, nicht offen.
        $document->update(['vermittler_import_id' => $import->id]);

        ActivityLog::record('vermittler_vorgangsliste_import', 'vermittler_import', $import->id, [
            'document_id' => $document->id,
            'filename' => $import->filename,
            'rows_total' => $import->rows_total,
            'rows_new_link' => $import->rows_new_link,
        ]);

        return redirect()->route('admin.vermittler.show', $import->id)
            ->with('success', 'Vorgangsliste gelesen: '.$import->rows_total.' Vorgänge, '
                .$import->rows_new_link.' neu mit einem Vertrag verknüpft.');
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
                'label' => trim($c->insurer.' · '.$c->typeLabel()),
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

        return back()->with('success', 'Datensatz '.$settlement->vermittler_id.' wurde dem Vertrag zugeordnet.');
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

    /**
     * Erkennt, ob die hochgeladene Datei zu einer ANDEREN Quelle gehoert, und
     * liefert einen Klartext-Hinweis mit dem richtigen Weg.
     *
     * Bewusst rein LESEND und fehlertolerant: der Hinweis ist eine Zugabe zu
     * einer bereits fehlgeschlagenen Verarbeitung. Scheitert auch er, bleibt
     * es bei der urspruenglichen Meldung - er darf sie nie ersetzen oder
     * einen zweiten Fehler erzeugen.
     */
    private function wrongImporterHint(string $path): string
    {
        try {
            $table = app(TableReader::class)->read($path);
            $provider = CommissionSourceProfile::detect($table->header);
        } catch (\Throwable) {
            return '';
        }

        if (CommissionSourceProfile::belongsToVermittlerImport($provider)) {
            return ''; // gehoert hierher - dann ist wirklich die Datei kaputt
        }

        $quelle = $provider !== null
            ? 'Erkannt wurde: '.CommissionSourceProfile::label($provider).'. '
            : 'Die Spalten passen zu keiner hier bekannten Vermittler-Abrechnung. ';

        return ' '.$quelle
            .'Diese Seite liest ausschließlich die Abrechnung von TARIFCHECK24. '
            .'Dateien aus anderen Quellen (Maklerpool, Energie-Vertriebsportal, weitere Portale) '
            .'liest „Interne Provisionen“ – dort werden die Spalten erkannt und lassen sich vor dem '
            .'Import zuordnen: '.route('admin.commissions_internal.import');
    }
}
