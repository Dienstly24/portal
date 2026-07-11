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

    public function import(Request $request) {
        $request->validate(['csv_file' => 'required|file|mimes:csv,txt|max:10240']);

        $csv = Reader::createFromPath($request->file('csv_file')->getPathname(), 'r');
        $csv->setHeaderOffset(0);
        $records = $csv->getRecords();

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($records as $i => $row) {
            try {
                $email = trim($row['email'] ?? $row['E-Mail'] ?? $row['e-mail'] ?? '');
                if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    continue;
                }
                if (User::where('email', $email)->exists()) {
                    $skipped++;
                    continue;
                }

                $firstName = trim($row['first_name'] ?? $row['Vorname'] ?? '');
                $lastName = trim($row['last_name'] ?? $row['Nachname'] ?? '');
                $name = trim("$firstName $lastName") ?: ($row['name'] ?? $row['Name'] ?? $email);

                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => bcrypt(Str::random(12)),
                    'role' => 'customer',
                ]);

                $address = trim(
                    ($row['street'] ?? $row['Straße'] ?? '') . ' ' .
                    ($row['street_nr'] ?? $row['Nr'] ?? '') . ', ' .
                    ($row['plz'] ?? $row['PLZ'] ?? '') . ' ' .
                    ($row['city'] ?? $row['Ort'] ?? '') . ', ' .
                    ($row['country'] ?? $row['Land'] ?? 'Deutschland')
                , ', ');

                Customer::create([
                    'id' => Str::uuid(),
                    'user_id' => $user->id,
                    'customer_number' => app(\App\Services\CustomerNumberGenerator::class)->generate(),
                    'phone' => $row['phone'] ?? $row['Telefon'] ?? null,
                    'mobile' => $row['mobile'] ?? $row['Mobil'] ?? null,
                    'address' => $address ?: null,
                    'iban' => $row['iban'] ?? $row['IBAN'] ?? null,
                    'birth_date' => $this->parseDate($row['birth_date'] ?? $row['Geburtsdatum'] ?? null),
                    'marital_status' => $row['marital_status'] ?? $row['Familienstand'] ?? null,
                    'preferred_lang' => $row['lang'] ?? $row['Sprache'] ?? 'de',
                    'company_name' => $row['company'] ?? $row['Firma'] ?? null,
                    'company_type' => $row['company_type'] ?? $row['Rechtsform'] ?? null,
                    'customer_type' => ($row['company'] ?? $row['Firma'] ?? '') ? 'firma' : 'privat',
                ]);

                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Zeile " . ($i+2) . ": " . $e->getMessage();
            }
        }

        $msg = "$imported Kunden importiert, $skipped übersprungen.";
        if(count($errors)) $msg .= " " . count($errors) . " Fehler.";

        return back()->with('import_result', [
            'imported' => $imported,
            'skipped' => $skipped,
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
