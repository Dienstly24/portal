<?php

namespace App\Console\Commands;

use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerCreation\DuplicateCustomerException;
use App\Services\Lexoffice\LexofficeContactMapper;
use App\Services\Matching\CustomerMatchingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Intelligenter Lexoffice-Import.
 *
 * Nutzt denselben Matching-/Anlage-Pfad wie der Rest des Systems:
 *  - Duplikaterkennung über CustomerMatchingService (Geburtsdatum + Name +
 *    E-Mail), nicht nur per E-Mail-Vergleich wie der alte Import.
 *  - Interne Kundennummer über CustomerNumberGenerator; die Lexoffice-Nummer
 *    landet als externe Referenz.
 *  - Strukturierte Adresse, alle E-Mail-Kübel, IBAN/Geburtsdatum aus Notizen.
 *
 * Optionen:
 *   --dry-run   nichts schreiben, nur zeigen was passieren würde
 *   --limit=N   höchstens N Kontakte verarbeiten (zum Testen)
 */
class ImportLexoffice extends Command
{
    protected $signature = 'lexoffice:import {--dry-run : Nur simulieren, nichts speichern} {--limit=0 : Max. Anzahl Kontakte (0 = alle)}';

    protected $description = 'Importiert Kontakte aus Lexoffice mit intelligenter Duplikaterkennung';

    public function handle(
        LexofficeContactMapper $mapper,
        CustomerMatchingService $matcher,
        CustomerAutoCreationService $creator,
    ): int {
        $apiKey = \App\Models\SystemSetting::get('lexoffice_api_key') ?: config('services.lexoffice.key');
        if (!$apiKey) {
            $this->error('Kein Lexoffice-API-Key hinterlegt (Einstellungen oder services.lexoffice.key).');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $baseUrl = 'https://api.lexware.io/v1';

        $created = 0;
        $duplicates = 0;
        $skipped = 0;
        $errors = 0;
        $processed = 0;

        $this->info($dryRun ? '=== Import-SIMULATION (dry-run) ===' : '=== Import gestartet ===');

        $page = 0;
        $totalPages = 1;

        while ($page < $totalPages) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ])->get("$baseUrl/contacts", ['page' => $page, 'size' => 100]);

            if (!$response->successful()) {
                $this->error('API-Fehler: HTTP ' . $response->status());
                return self::FAILURE;
            }

            $body = $response->json();
            $totalPages = $body['totalPages'] ?? 1;
            $contacts = $body['content'] ?? [];

            $this->line('Seite ' . ($page + 1) . "/$totalPages (" . count($contacts) . ' Kontakte)');

            foreach ($contacts as $contact) {
                if ($limit > 0 && $processed >= $limit) {
                    break 2;
                }
                $processed++;

                $data = $mapper->map($contact);
                if ($data === null) {
                    $skipped++;
                    continue;
                }

                // Duplikaterkennung über den zentralen Matching-Service.
                $match = $matcher->match($data);
                if ($match->tier() !== 'manual') {
                    $duplicates++;
                    $this->line("  ⊘ Duplikat ({$match->score}%): {$data['full_name']}");
                    continue;
                }

                if ($dryRun) {
                    $created++;
                    $this->line("  + NEU (Simulation): {$data['full_name']}"
                        . ($data['birth_date'] ? " · geb. {$data['birth_date']}" : '')
                        . ($data['email'] ? " · {$data['email']}" : ' · (keine E-Mail)'));
                    continue;
                }

                try {
                    $customer = $creator->createFromUnmatched($data, 'lexoffice');
                    $created++;
                    if ($created % 25 === 0) {
                        $this->info("  ✓ $created angelegt …");
                    }
                } catch (DuplicateCustomerException $e) {
                    $duplicates++;
                } catch (\Throwable $e) {
                    $errors++;
                    \Log::warning('Lexoffice-Import Fehler: ' . $e->getMessage());
                }

                usleep(50000); // API schonen
            }

            $page++;
        }

        $this->newLine();
        $this->info('=== Fertig ===');
        $this->table(['Neu angelegt', 'Duplikate übersprungen', 'Ohne Namen übersprungen', 'Fehler'],
            [[$created, $duplicates, $skipped, $errors]]);

        return self::SUCCESS;
    }
}
