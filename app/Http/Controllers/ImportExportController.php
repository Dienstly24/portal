<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Writer;

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
    public function import(Request $request) {
        $request->validate(['csv_file' => 'required|file|mimes:csv,txt|max:10240']);

        // Rohinhalt einlesen und robust normalisieren: Exporte anderer
        // Plattformen (Lexoffice u. a.) kommen oft als Windows-1252/Latin-1
        // mit Semikolon-Trennung. Frueher wurden solche Dateien komplett
        // falsch gelesen -> E-Mail-Spalte nicht erkannt -> alle Kunden mit
        // Platzhalter-Adresse angelegt.
        $content = (string) file_get_contents($request->file('csv_file')->getPathname());
        $content = $this->toUtf8($content);

        $csv = Reader::createFromString($content);
        $csv->setDelimiter($this->detectDelimiter($content));
        $csv->setHeaderOffset(0);
        $records = $csv->getRecords();

        $matcher = app(\App\Services\Matching\CustomerMatchingService::class);
        $creator = app(\App\Services\CustomerCreation\CustomerAutoCreationService::class);

        $imported = 0;
        $duplicates = 0;
        $skipped = 0;
        $errors = [];

        foreach ($records as $i => $row) {
            try {
                // Spalten werden ueber mehrere moegliche Kopfzeilen-Namen
                // gefunden. Neben den schlanken Vorlagen-Namen (Straße, PLZ)
                // auch die nummerierten Varianten typischer Fremdexporte
                // (Straße 1, PLZ 1, E-Mail 1, Telefon 1, Firmenname ...).
                $col = fn (array $keys) => collect($keys)
                    ->map(fn ($k) => trim((string) ($row[$k] ?? '')))
                    ->first(fn ($v) => $v !== '') ?: null;

                $firstName = $col(['first_name', 'Vorname']);
                $lastName = $col(['last_name', 'Nachname']);
                $company = $col(['company', 'Firma', 'Firmenname']);
                // Name aus Vor-/Nachname; sonst Kontakt-/Name-Spalte; sonst Firma.
                $name = trim(($firstName ?? '') . ' ' . ($lastName ?? ''))
                    ?: $col(['name', 'Name', 'Kontakt'])
                    ?: $company;
                if (!$name) {
                    $skipped++; // ohne Namen kein sinnvoller Datensatz
                    continue;
                }

                $email = $col(['email', 'E-Mail', 'e-mail', 'E-Mail 1', 'EMail 1', 'Email 1']);
                if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $email = null; // ungültige Adresse ignorieren, Kunde trotzdem anlegen
                }

                $email2 = $col(['email2', 'E-Mail 2', 'EMail 2', 'Email 2']);
                if ($email2 && !filter_var($email2, FILTER_VALIDATE_EMAIL)) {
                    $email2 = null;
                }

                $originalNumber = $col(['customer_number', 'Kundennummer', 'kundennummer', 'Kundennr']);

                $data = array_filter([
                    'full_name'     => $name,
                    'first_name'    => $firstName,
                    'last_name'     => $lastName,
                    'email'         => $email,
                    'email2'        => $email2,
                    'phone'         => $col(['phone', 'Telefon', 'Telefon 1', 'mobile', 'Mobil', 'Telefon 2']),
                    'birth_date'    => $this->parseDate($col(['birth_date', 'Geburtsdatum'])),
                    'street'        => $col(['street', 'Straße', 'Strasse', 'Straße 1', 'Strasse 1']),
                    'house_number'  => $col(['street_nr', 'Hausnummer']),
                    'zip'           => $col(['plz', 'PLZ', 'PLZ 1']),
                    'city'          => $col(['city', 'Ort', 'Stadt', 'Ort 1']),
                    'iban'          => $col(['iban', 'IBAN']),
                    'gender'        => $this->genderFromAnrede($col(['Anrede', 'gender', 'Geschlecht'])),
                    'company_name'  => $company,
                    'company_type'  => $col(['company_type', 'Rechtsform']),
                    'customer_type' => $company ? 'firma' : 'privat',
                    'import_number' => $originalNumber,
                ], fn ($v) => $v !== null && $v !== '');

                if ($originalNumber) {
                    $data['external_references'] = [
                        ['type' => 'import_number', 'value' => $originalNumber, 'source' => 'import'],
                    ];
                }

                // Intelligente Duplikaterkennung statt reinem E-Mail-Vergleich.
                $match = $matcher->match($data);
                if ($match->tier() !== 'manual') {
                    $duplicates++;
                    continue;
                }

                $creator->createFromUnmatched($data, 'import', auth()->id());
                $imported++;
            } catch (\App\Services\CustomerCreation\DuplicateCustomerException $e) {
                $duplicates++;
            } catch (\Exception $e) {
                $errors[] = "Zeile " . ($i + 2) . ": " . $e->getMessage();
            }
        }

        return back()->with('import_result', [
            'imported' => $imported,
            'skipped' => $skipped + $duplicates,
            'errors' => array_slice($errors, 0, 5),
        ]);
    }

    public function export() {
        $customers = Customer::with('user')->when($this->visibleCustomerIds() !== null, fn($q) => $q->whereIn('customers.id', $this->visibleCustomerIds()))->get();

        $csv = Writer::createFromString('');
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

    /** Inhalt nach UTF-8 wandeln; Fremdexporte sind oft Windows-1252/Latin-1. */
    private function toUtf8(string $content): string {
        // BOM entfernen, falls vorhanden.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        return mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /** Trennzeichen aus der Kopfzeile bestimmen (Semikolon, Tab oder Komma). */
    private function detectDelimiter(string $content): string {
        $firstLine = strtok($content, "\r\n") ?: '';
        $counts = [
            ';'  => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
            ','  => substr_count($firstLine, ','),
        ];
        arsort($counts);
        $best = array_key_first($counts);
        return $counts[$best] > 0 ? $best : ',';
    }

    /** Anrede -> Geschlecht (Herr = maennlich, Frau = weiblich). */
    private function genderFromAnrede(?string $anrede): ?string {
        $a = mb_strtolower(trim((string) $anrede));
        if ($a === '') return null;
        if (str_starts_with($a, 'herr')) return 'male';
        if (str_starts_with($a, 'frau')) return 'female';
        return null;
    }

    private function parseDate($date) {
        if (!$date) return null;
        foreach (['d.m.Y','Y-m-d','d/m/Y','m/d/Y'] as $format) {
            $d = \DateTime::createFromFormat($format, trim($date));
            if ($d) return $d->format('Y-m-d');
        }
        return null;
    }

    public function template() {
        $csv = Writer::createFromString('');
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
