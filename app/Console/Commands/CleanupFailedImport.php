<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CustomerDeletionService;
use Illuminate\Console\Command;

/**
 * Bereinigt die Datensaetze eines fehlgeschlagenen CSV-Imports. Beim frueheren
 * Import wurde die E-Mail-Spalte nicht erkannt (falsches Encoding/Trennzeichen),
 * sodass alle Kunden mit einer internen Platzhalter-Adresse
 * (import-...@dienstly24.internal) angelegt wurden. Dieser Befehl entfernt
 * genau diese Datensaetze wieder - ueber dieselbe DSGVO-Loeschlogik wie jede
 * andere Kundenloeschung - damit ein sauberer Neuimport keine Dubletten erzeugt.
 *
 * Zielgruppe (streng eingegrenzt): source = 'import' UND Platzhalter-E-Mail.
 * Kunden mit echter E-Mail bleiben unberuehrt; Mitarbeiter-/Admin-Konten
 * werden vom Loesch-Service ohnehin nie mitgeloescht.
 *
 * Beispiel:
 *   php artisan customers:cleanup-import          # nur anzeigen (Trockenlauf)
 *   php artisan customers:cleanup-import --force   # tatsaechlich loeschen
 */
class CleanupFailedImport extends Command
{
    protected $signature = 'customers:cleanup-import
        {--force : Tatsaechlich loeschen (ohne Rueckfrage)}
        {--source=import : Nur Kunden dieser Quelle beruecksichtigen}';

    protected $description = 'Fehlgeschlagenen Import bereinigen: Kunden mit Platzhalter-E-Mail (@dienstly24.internal) aus dem Import loeschen.';

    public function handle(CustomerDeletionService $service): int
    {
        $source = (string) $this->option('source');

        $query = Customer::query()
            ->where('source', $source)
            ->whereHas('user', fn ($u) => $u->where('email', 'like', '%@dienstly24.internal'));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("Keine betroffenen Datensaetze (Quelle '{$source}', Platzhalter-E-Mail) gefunden - nichts zu tun.");
            return self::SUCCESS;
        }

        $this->warn("Gefunden: {$total} Kunden aus Quelle '{$source}' mit Platzhalter-E-Mail.");

        // Kleine Vorschau zur Kontrolle.
        (clone $query)->with('user')->limit(10)->get()->each(function ($c) {
            $this->line("  - {$c->customer_number}  {$c->user?->name}  <{$c->user?->email}>");
        });
        if ($total > 10) {
            $this->line('  … und ' . ($total - 10) . ' weitere.');
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->info('Trockenlauf - es wurde nichts geloescht. Zum Loeschen erneut mit --force ausfuehren.');
            return self::SUCCESS;
        }

        if (! $this->confirm("Wirklich diese {$total} Import-Datensaetze endgueltig loeschen?", false)) {
            $this->warn('Abgebrochen.');
            return self::FAILURE;
        }

        $deleted = 0;
        $query->orderBy('id')->chunkById(50, function ($chunk) use ($service, &$deleted) {
            foreach ($chunk as $customer) {
                $service->delete($customer, null);
                $deleted++;
            }
            $this->info("… {$deleted} geloescht");
        });

        $this->info("Fertig: {$deleted} Import-Datensaetze geloescht. Der Neuimport kann jetzt sauber laufen.");
        return self::SUCCESS;
    }
}
