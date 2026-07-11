<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CustomerDeletionService;
use Illuminate\Console\Command;

/**
 * Leert das System: löscht ALLE Kunden inkl. verknüpfter Daten (Verträge,
 * Tickets, Dokumente, E-Mails, Kunden-Login) über dieselbe DSGVO-Löschlogik
 * wie die Einzel-/Massenlöschung – aber verlässlich im CLI (kein HTTP-Timeout)
 * und in Blöcken. Mitarbeiter-/Admin-Konten bleiben unberührt.
 *
 * Beispiel (ohne Rückfrage):
 *   php artisan customers:purge --force
 */
class PurgeCustomers extends Command
{
    protected $signature = 'customers:purge {--force : Ohne Rückfrage ausführen}';

    protected $description = 'ALLE Kunden endgültig löschen (System leeren). Mitarbeiter-/Admin-Konten bleiben erhalten.';

    public function handle(CustomerDeletionService $service): int
    {
        $total = Customer::count();

        if ($total === 0) {
            $this->info('Keine Kunden vorhanden – nichts zu tun.');
            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Wirklich ALLE {$total} Kunden endgültig löschen? Das kann NICHT rückgängig gemacht werden.")) {
            $this->warn('Abgebrochen.');
            return self::FAILURE;
        }

        $deleted = 0;

        // chunkById ist sicher, obwohl wir währenddessen löschen (Cursor über id).
        Customer::query()->orderBy('id')->chunkById(50, function ($chunk) use ($service, &$deleted) {
            foreach ($chunk as $customer) {
                $service->delete($customer, null);
                $deleted++;
            }
            $this->info("… {$deleted} gelöscht");
        });

        $this->info("Fertig: {$deleted} Kunden gelöscht.");
        return self::SUCCESS;
    }
}
