<?php
namespace App\Console\Commands;

use App\Services\EscooterRenewalReminderService;
use Illuminate\Console\Command;

/**
 * Versendet die jaehrliche E-Scooter-Erneuerungs-Erinnerung: aktive
 * E-Scooter-Vertraege bekommen Anfang Februar (Fenster bis Saison-Ende) EINMAL
 * pro Saison einen Hinweis, dass das Versicherungskennzeichen Ende Februar
 * auslaeuft und ab dem 1. Maerz ein neues noetig ist. Idempotent (Unique-Index
 * ueber contract_switch_reminders, stage=renewal). Laeuft taeglich - das Fenster
 * liegt naturgemaess nur im Februar.
 */
class SendEscooterRenewalReminders extends Command
{
    protected $signature = 'escooter:renewal-reminders {--dry-run : Nur anzeigen, nichts senden}';
    protected $description = 'Versendet die jaehrliche E-Scooter-Erneuerungs-Erinnerung (Kennzeichenwechsel zum 01.03.)';

    public function handle(EscooterRenewalReminderService $service): int
    {
        if ($this->option('dry-run')) {
            $due = $service->due();
            if ($due === []) {
                $this->info('Keine faelligen E-Scooter-Erinnerungen.');
                return self::SUCCESS;
            }
            foreach ($due as [$contract, $anchor]) {
                $this->line('- ' . ($contract->customer?->customer_number ?? '?')
                    . ' (' . ($contract->vehicleDetail?->license_plate ?? 'ohne Kennzeichen') . ') -> Saison-Ende ' . $anchor);
            }
            $this->info(count($due) . ' E-Scooter-Erinnerung(en) faellig (dry-run).');
            return self::SUCCESS;
        }

        $sent = $service->run();
        $this->info($sent . ' E-Scooter-Erinnerung(en) versendet.');
        return self::SUCCESS;
    }
}
