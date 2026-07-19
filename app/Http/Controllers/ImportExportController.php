<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use League\Csv\Writer;
use League\Csv\EscapeFormula;

class ImportExportController extends Controller
{
    /** null = alle sichtbar; sonst Array der erlaubten Kunden-IDs */
    private function visibleCustomerIds(): ?array {
        $user = auth()->user();
        if (!$user || $user->canSeeAllCustomers()) return null;
        return $user->assignedCustomers()->pluck('customers.id')->toArray();
    }

    public function index() {
        return view('admin.import_export');
    }

    /**
     * CSV-Import über denselben intelligenten Pfad wie der Lexoffice-Import:
     * - Duplikaterkennung per CustomerMatchingService (Geburtsdatum + Name +
     *   E-Mail), nicht mehr nur per E-Mail-Vergleich
     * - Zeilen OHNE E-Mail werden importiert (Platzhalteradresse) statt
     *   stillschweigend verworfen
     * - strukturierte Adressfelder (Straße/Nr./PLZ/Ort) statt nur Sammelfeld
     * - vorhandene Original-Kundennummer bleibt erhalten: "25" + Original;
     *   zusätzlich als ExternalReference (type=import_number) nachvollziehbar
     */
    /**
     * Schritt 1: Vorschau. Die Datei wird gelesen und analysiert, aber es
     * werden KEINE Kunden angelegt. Der Betreiber sieht vorab, welche
     * Datensaetze neu angelegt, welche als Duplikat uebersprungen und
     * welche ohne E-Mail bzw. fehlerhaft sind - und bestaetigt erst dann.
     */
    public function import(Request $request, \App\Services\Import\CustomerCsvImporter $importer) {
        $request->validate(['csv_file' => 'required|file|mimes:csv,txt|max:10240']);

        // Datei fuer den Bestaetigungsschritt zwischenspeichern (Token-Name).
        $token = (string) Str::uuid();
        $dir = storage_path('app/private/imports');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $stored = $dir . '/' . $token . '.csv';
        copy($request->file('csv_file')->getPathname(), $stored);

        $preview = $importer->analyze($stored);

        return view('admin.import_preview', [
            'token' => $token,
            'filename' => $request->file('csv_file')->getClientOriginalName(),
            'preview' => $preview,
        ]);
    }

    /**
     * Schritt 2: Bestaetigung. Der eigentliche Import laeuft als
     * Hintergrund-Job (ImportCustomersJob), damit auch grosse Dateien
     * (> 1000 Kunden) nicht am Webserver-Timeout scheitern. Der Betreiber
     * bekommt nach Abschluss eine interne Benachrichtigung.
     */
    public function confirmImport(Request $request) {
        $request->validate(['token' => 'required|string']);
        $token = (string) $request->input('token');

        // Nur gueltige UUID-Tokens zulassen (kein Pfad-Traversal).
        if (!preg_match('/^[0-9a-f\\-]{36}$/', $token)) {
            abort(400);
        }
        $path = storage_path('app/private/imports/' . $token . '.csv');
        if (!is_file($path)) {
            return redirect()->route('admin.import_export')->with('import_result', [
                'imported' => 0,
                'skipped' => 0,
                'errors' => ['Import-Datei ist abgelaufen. Bitte erneut hochladen.'],
                'queued' => false,
            ]);
        }

        \App\Jobs\ImportCustomersJob::dispatch($path, auth()->id());

        return redirect()->route('admin.import_export')->with('import_result', [
            'queued' => true,
        ]);
    }

    public function export() {
        $customers = Customer::with('user')->when($this->visibleCustomerIds() !== null, fn($q) => $q->whereIn('customers.id', $this->visibleCustomerIds()))->get();

        $csv = Writer::createFromString('');
        // Schutz vor CSV-/Formel-Injection (Audit INT-1): kunden-kontrollierte
        // Felder mit fuehrendem = + - @ werden neutralisiert, sonst fuehren sie
        // beim Oeffnen in Excel/LibreOffice (DDE) Formeln aus.
        $csv->addFormatter(new EscapeFormula());
        $csv->insertOne([
            'Kundennummer','Vorname','Nachname','E-Mail','Telefon','Mobil',
            'Adresse','IBAN','Geburtsdatum','Familienstand','Sprache',
            'Firmenname','Rechtsform','Kundentyp','Erstellt am'
        ]);

        foreach ($customers as $c) {
            $nameParts = explode(' ', $c->user?->name ?? '', 2);
            $csv->insertOne([
                $c->customer_number,
                $nameParts[0] ?? '',
                $nameParts[1] ?? '',
                $c->user?->email ?? '',
                $c->phone ?? '',
                $c->mobile ?? '',
                $c->address ?? '',
                $c->iban ?? '',
                $c->birth_date ?? '',
                $c->marital_status ?? '',
                $c->preferred_lang ?? 'de',
                $c->company_name ?? '',
                $c->company_type ?? '',
                $c->customer_type ?? 'privat',
                $c->created_at?->format('d.m.Y') ?? '',
            ]);
        }

        return response((string) $csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="kunden_' . date('Y-m-d') . '.csv"');
    }

    public function template() {
        $csv = Writer::createFromString('');
        $csv->addFormatter(new EscapeFormula());
        $csv->insertOne([
            'Vorname','Nachname','E-Mail','Telefon','Mobil',
            'Straße','Nr','PLZ','Ort','Land',
            'IBAN','Geburtsdatum','Familienstand','Sprache',
            'Firma','Rechtsform'
        ]);
        $csv->insertOne([
            'Max','Mustermann','max@beispiel.de','+49 40 123456','+49 176 123456',
            'Musterstraße','12','20095','Hamburg','Deutschland',
            'DE89370400440532013000','01.01.1990','ledig','de',
            '','GmbH'
        ]);

        return response((string) $csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="import_vorlage.csv"');
    }
}
