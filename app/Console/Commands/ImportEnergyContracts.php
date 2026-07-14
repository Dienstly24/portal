<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractEnergyDetail;
use App\Models\Customer;
use App\Models\ExternalReference;
use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerCreation\DuplicateCustomerException;
use App\Services\Matching\CustomerMatchingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import von Energie-Auftraegen (Strom & Gas) aus dem CSV-Export der
 * Fremdplattform (Spalten mit ";" getrennt, Windows-1252-kodiert).
 *
 * Aus jeder Zeile entsteht:
 *  - ein Kunde (Quelle "import") mit Name/Geschlecht/Geburtsdatum/Telefon/
 *    strukturierter Anschrift - Duplikate werden ueber den zentralen
 *    CustomerMatchingService und einen Batch-Schluessel zusammengefuehrt,
 *    sodass mehrere Auftraege desselben Kunden an EINER Kundenakte haengen;
 *  - ein Energievertrag (type "strom_gas") mit Anbieter, Tarif/Produkt,
 *    Status, Zaehlernummer, Verbrauch, Start- und Stornodatum;
 *  - die Auftragsnummer als externe Referenz (Idempotenz + Rueckverfolgung).
 *
 * Bewusst ignorierte Spalten (Betreiber-Vorgabe): VP-Name, Auftr.-Statustext,
 * VAP (Ja/Nein), VP Nummer, UVP Nummer.
 *
 * Optionen:
 *   --dry-run   nichts schreiben, nur zeigen was passieren wuerde
 *   --limit=N   hoechstens N Zeilen verarbeiten (zum Testen)
 */
class ImportEnergyContracts extends Command
{
    protected $signature = 'energie:import {file : Pfad zur CSV-Datei} '
        . '{--dry-run : Nur simulieren, nichts speichern} '
        . '{--limit=0 : Max. Anzahl Zeilen (0 = alle)}';

    protected $description = 'Importiert Strom-/Gas-Auftraege aus dem CSV-Export als Kunden + Energievertraege';

    /** Externe-Referenz-Typ fuer die Auftragsnummer der Energieplattform. */
    public const REF_TYPE = 'energie_auftragsnummer';

    /** Batch-Schluessel -> bereits angelegter/gefundener Kunde (Intra-Batch-Dedup). */
    private array $customerCache = [];

    public function handle(
        CustomerMatchingService $matcher,
        CustomerAutoCreationService $creator,
    ): int {
        $path = (string) $this->argument('file');
        if (!is_file($path) || !is_readable($path)) {
            $this->error("Datei nicht gefunden oder nicht lesbar: $path");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $rows = $this->readRows($path);
        if ($rows === []) {
            $this->error('Keine Datenzeilen in der CSV gefunden.');
            return self::FAILURE;
        }

        $this->info($dryRun ? '=== Import-SIMULATION (dry-run) ===' : '=== Energie-Import gestartet ===');
        $this->line('Zeilen in Datei: ' . count($rows));

        $customersNew = 0;
        $contractsNew = 0;
        $contractsSkipped = 0;
        $errors = 0;
        $processed = 0;

        foreach ($rows as $row) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            $processed++;

            try {
                $customerData = $this->mapCustomer($row);
                $contractData = $this->mapContract($row);

                if ($contractData['order_number'] === '') {
                    // Ohne Auftragsnummer keine eindeutige Idempotenz moeglich.
                    $this->line('  ! Zeile ohne Auftragsnummer uebersprungen.');
                    $contractsSkipped++;
                    continue;
                }

                // Bereits importiert? (idempotent, auch bei erneutem Lauf)
                if (!$dryRun && $this->contractExists($contractData['order_number'])) {
                    $contractsSkipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '  + %s | %s | %s | Auftrag %s (%s)',
                        $customerData['full_name'],
                        $contractData['insurer'],
                        $contractData['tariff'] ?: '-',
                        $contractData['order_number'],
                        $contractData['status'],
                    ));
                    $contractsNew++;
                    continue;
                }

                DB::transaction(function () use (
                    $matcher, $creator, $customerData, $contractData, &$customersNew, &$contractsNew
                ) {
                    [$customer, $wasCreated] = $this->resolveCustomer($matcher, $creator, $customerData);
                    if ($wasCreated) {
                        $customersNew++;
                    }
                    $this->createContract($customer, $contractData);
                    $contractsNew++;
                });

                if ($contractsNew % 50 === 0) {
                    $this->info("  ... $contractsNew Vertraege angelegt");
                }
            } catch (\Throwable $e) {
                $errors++;
                \Log::warning('Energie-Import Fehler: ' . $e->getMessage(), ['zeile' => $row]);
                $this->line('  x Fehler: ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info('=== Fertig ===');
        $this->table(
            ['Neue Kunden', 'Neue Vertraege', 'Uebersprungen (bereits vorhanden)', 'Fehler'],
            [[$customersNew, $contractsNew, $contractsSkipped, $errors]]
        );

        return self::SUCCESS;
    }

    /**
     * CSV einlesen (Windows-1252 -> UTF-8), Kopfzeile verwerfen, nur
     * vollstaendige Datenzeilen zurueckgeben.
     *
     * @return array<int, array<int, string>>
     */
    private function readRows(string $path): array
    {
        $raw = (string) file_get_contents($path);
        // Export ist Windows-1252 (Umlaute als Einzelbyte). Nach UTF-8 wandeln,
        // damit Namen/Strassen korrekt gespeichert werden.
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');

        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        array_shift($lines); // Kopfzeile

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ';', '"', '\\');
            if (count($cells) < 14) {
                continue; // unvollstaendige Zeile
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * Kundendaten aus einer CSV-Zeile.
     *
     * @param array<int, string> $r
     * @return array<string, mixed>
     */
    private function mapCustomer(array $r): array
    {
        $rawName = trim($r[6]);
        $gender = null;
        if (preg_match('/^\s*Herr\b/u', $rawName)) {
            $gender = 'male';
        } elseif (preg_match('/^\s*Frau\b/u', $rawName)) {
            $gender = 'female';
        }
        // Anrede aus dem Namen entfernen (Geschlecht ist die Datenquelle).
        $name = trim(preg_replace('/^\s*(Herr|Frau)\b\s*/u', '', $rawName) ?? $rawName);
        $name = $name !== '' ? $name : 'Unbekannter Kunde';

        $addr = $this->parseAddress(trim($r[7]));

        return [
            'full_name'  => $name,
            'gender'     => $gender,
            'birth_date' => $this->parseDate($r[8]),
            'phone'      => $this->cleanPhone($r[9]),
            'address'    => trim($r[7]) !== '' ? trim($r[7]) : null,
            'street'     => $addr['street'],
            'house_number' => $addr['house_number'],
            'zip'        => $addr['zip'],
            'city'       => $addr['city'],
        ];
    }

    /**
     * Vertragsdaten (Strom/Gas) aus einer CSV-Zeile.
     *
     * @param array<int, string> $r
     * @return array<string, mixed>
     */
    private function mapContract(array $r): array
    {
        // Tarif/Produkt = "Anbieter - Produktname". Anbieter -> insurer,
        // Produktname -> tariff der Energie-Details.
        $product = trim($r[13]);
        $parts = array_map('trim', explode(' - ', $product, 2));
        $insurer = $parts[0] !== '' ? $parts[0] : 'Unbekannter Anbieter';
        $tariff = $parts[1] ?? $product;

        $consumption = preg_replace('/[^0-9]/', '', $r[11]);
        $consumptionNt = preg_replace('/[^0-9]/', '', $r[12] ?? '');

        return [
            'order_number'  => trim($r[1]),
            'status'        => $this->mapStatus($r[4]),
            'status_code'   => trim($r[4]),
            'insurer'       => $insurer,
            'tariff'        => $tariff,
            'meter_number'  => trim($r[10]) !== '' ? trim($r[10]) : null,
            'consumption'   => $consumption !== '' ? (int) $consumption : null,
            'consumption_nt' => $consumptionNt !== '' ? (int) $consumptionNt : 0,
            'start_date'    => $this->parseDate($r[3]),
            'cancellation_date' => $this->parseDate($r[18]),
            'vap_date'      => $this->parseDate($r[15]),
            'rl'            => trim($r[16] ?? ''),
            'rl_date'       => $this->parseDate($r[17] ?? ''),
            'reactivation_date' => $this->parseDate($r[19] ?? ''),
        ];
    }

    /**
     * Auftragsstatus der Plattform (numerisch) auf den Vertragsstatus des
     * Portals abbilden:
     *   7100 (verprovisioniert)        -> active
     *   < 7100 (in Bearbeitung/Kluerung) -> pending
     *   >= 9000 (jegliche Storno-Stufe) -> cancelled
     */
    private function mapStatus(string $code): string
    {
        $c = (int) preg_replace('/[^0-9]/', '', $code);
        return match (true) {
            $c >= 9000 => 'cancelled',
            $c === 7100 => 'active',
            default => 'pending',
        };
    }

    /** Kunde finden (Batch-Cache -> Matching) oder neu anlegen. */
    private function resolveCustomer(
        CustomerMatchingService $matcher,
        CustomerAutoCreationService $creator,
        array $data,
    ): array {
        $key = $this->customerKey($data);
        if (isset($this->customerCache[$key])) {
            return [$this->customerCache[$key], false];
        }

        // Bestehenden Kunden erkennen (Geburtsdatum + Name + Telefon/Adresse).
        $match = $matcher->match($data);
        if ($match->hasMatch() && $match->tier() !== 'manual') {
            $this->customerCache[$key] = $match->customer;
            return [$match->customer, false];
        }

        try {
            $customer = $creator->createFromUnmatched($data, 'import');
        } catch (DuplicateCustomerException $e) {
            // Race/Score-Grenzfall: den gefundenen Kandidaten nutzen.
            $customer = $e->matchResult->customer;
            if ($customer === null) {
                throw $e;
            }
            $this->customerCache[$key] = $customer;
            return [$customer, false];
        }

        // Geschlecht ist im Auto-Creator (noch) nicht vorgesehen -> nachtragen.
        if (!empty($data['gender']) && empty($customer->gender)) {
            $customer->forceFill(['gender' => $data['gender']])->save();
        }

        $this->customerCache[$key] = $customer;
        return [$customer, true];
    }

    /** Energievertrag + Detaildatensatz + externe Auftragsnummer anlegen. */
    private function createContract(Customer $customer, array $c): void
    {
        $notes = $this->buildNotes($c);

        $contract = Contract::create([
            'id'                => (string) Str::uuid(),
            'customer_id'       => $customer->id,
            'contract_number'   => $this->uniqueContractNumber($c['order_number']),
            'type'              => 'strom_gas',
            'insurer'           => $c['insurer'],
            'status'            => $c['status'],
            'start_date'        => $c['start_date'],
            'cancellation_date' => $c['cancellation_date'],
            'notes'             => $notes,
            'added_by'          => 'Energie-Import',
        ]);

        ContractEnergyDetail::create([
            'contract_id'     => $contract->id,
            'tariff'          => $c['tariff'] ?: null,
            'consumption_kwh' => $c['consumption'],
            'meter_number'    => $c['meter_number'],
        ]);

        $contract->externalReferences()->create([
            'type'   => self::REF_TYPE,
            'value'  => $c['order_number'],
            'source' => 'import',
        ]);
    }

    /** Auftragsnummer bereits importiert? (externe Referenz vorhanden) */
    private function contractExists(string $orderNumber): bool
    {
        return ExternalReference::where('referenceable_type', Contract::class)
            ->where('type', self::REF_TYPE)
            ->where('value', $orderNumber)
            ->exists();
    }

    /**
     * Auftragsnummer als Vertragsnummer nutzen; bei (unerwarteter) Kollision
     * am Unique-Index eindeutig machen statt fehlzuschlagen.
     */
    private function uniqueContractNumber(string $orderNumber): string
    {
        if (!Contract::where('contract_number', $orderNumber)->exists()) {
            return $orderNumber;
        }
        $suffix = 2;
        while (Contract::where('contract_number', $orderNumber . '-' . $suffix)->exists()) {
            $suffix++;
        }
        return $orderNumber . '-' . $suffix;
    }

    /** Kompakte, deutsche Vertragsnotiz aus den nicht-strukturierten Feldern. */
    private function buildNotes(array $c): string
    {
        $parts = ['Import Energie-Auftrag ' . $c['order_number'] . ' (Status ' . $c['status_code'] . ')'];
        if ($c['vap_date']) {
            $parts[] = 'VAP-Datum ' . $c['vap_date'];
        }
        if (($c['rl'] ?? '') !== '' && $c['rl'] !== '-') {
            $parts[] = 'RL ' . $c['rl'] . ($c['rl_date'] ? ' (' . $c['rl_date'] . ')' : '');
        }
        if ($c['reactivation_date']) {
            $parts[] = 'Wiederanschaltung ' . $c['reactivation_date'];
        }
        if (($c['consumption_nt'] ?? 0) > 0) {
            $parts[] = 'Verbrauch NT ' . $c['consumption_nt'] . ' kWh';
        }
        return implode(' | ', $parts);
    }

    /** Eindeutiger Zusammenfuehrungs-Schluessel eines Kunden im Batch. */
    private function customerKey(array $data): string
    {
        $name = mb_strtolower(trim($data['full_name'] ?? ''));
        $name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $addr = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $data['address'] ?? '') ?? '');
        return $name . '|' . ($data['birth_date'] ?? '') . '|' . $addr;
    }

    /** "Strasse Hausnummer, PLZ Ort" in strukturierte Teile zerlegen. */
    private function parseAddress(string $anschrift): array
    {
        $out = ['street' => null, 'house_number' => null, 'zip' => null, 'city' => null];
        if ($anschrift === '') {
            return $out;
        }

        // Am letzten Komma trennen: links Strasse+Nr, rechts PLZ+Ort.
        $pos = mb_strrpos($anschrift, ',');
        if ($pos !== false) {
            $left = trim(mb_substr($anschrift, 0, $pos));
            $right = trim(mb_substr($anschrift, $pos + 1));
        } else {
            $left = $anschrift;
            $right = '';
        }

        if (preg_match('/^(\d{4,5})\s+(.+)$/', $right, $m)) {
            $out['zip'] = $m[1];
            $out['city'] = trim($m[2]);
        } elseif ($right !== '') {
            $out['city'] = $right;
        }

        // Hausnummer am Ende der Strasse (z. B. "119/1", "6a", "2-10").
        if (preg_match('/^(.*?)\s+(\d+\s*[a-zA-Z]?(?:\s*[\/-]\s*\d+)?\s*[a-zA-Z]?)$/u', $left, $m)) {
            $out['street'] = trim($m[1]);
            $out['house_number'] = trim($m[2]);
        } else {
            $out['street'] = $left !== '' ? $left : null;
        }

        return $out;
    }

    /**
     * Datum "dd.mm.yyyy" -> "Y-m-d"; "-"/leer/ungueltig -> null.
     * Platzhalter wie "00.00.0000" gibt es im Export; die werden zu null,
     * damit MySQL im Strict-Mode sie nicht als "0000-00-00" ablehnt.
     */
    private function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            // Nur echte, kalendarisch gueltige Datumswerte uebernehmen.
            if (!checkdate($month, $day, $year)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        return null;
    }

    /** Telefonnummer bereinigen; reine Vorwahl/Leerwert -> null. */
    private function cleanPhone(?string $value): ?string
    {
        $value = trim((string) $value);
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
        // "+49" allein oder < 5 Ziffern ist keine brauchbare Nummer.
        if (strlen($digits) < 5) {
            return null;
        }
        return $value;
    }
}
