<?php

namespace App\Console\Commands;

use App\Services\SchutzbriefRenewalReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Versendet die jaehrliche Schutzbrief-/Mobilclub-Verlaengerungs-Erinnerung
 * (z.B. ADAC-Mitgliedschaft): aktive Schutzbrief-Vertraege bekommen ab 5
 * Monaten vor dem Verlaengerungs-Stichtag (= 7 Monate nach Beginn) EINMAL pro
 * Jahr einen Hinweis, dass sich der Vertrag automatisch um ein Jahr
 * verlaengert - inklusive letztem Kuendigungstag (3 Monate vorher) und einer
 * Erklaerung der Leistungen. Idempotent (Unique-Index ueber
 * contract_switch_reminders, stage=schutzbrief_renewal).
 */
class SendSchutzbriefRenewalReminders extends Command
{
    protected $signature = 'schutzbrief:renewal-reminders {--dry-run : Nur anzeigen, nichts senden}';
    protected $description = 'Versendet die jaehrliche Schutzbrief-Verlaengerungs-Erinnerung (mit Kuendigungsfrist)';

    public function handle(SchutzbriefRenewalReminderService $service): int
    {
        if ($this->option('dry-run')) {
            $due = $service->due();
            if ($due === []) {
                $this->info('Keine faelligen Schutzbrief-Erinnerungen.');
                return self::SUCCESS;
            }
            foreach ($due as [$contract, $anchor]) {
                $this->line('- '.($contract->customer?->customer_number ?? '?')
                    .' ('.($contract->insurer ?? 'ohne Anbieter')
                    .($contract->contract_number ? ', Nr. '.$contract->contract_number : '')
                    .') -> Verlaengerung '.$anchor
                    .', letzte Kuendigung '.$service->lastCancellationDate(Carbon::parse($anchor))->toDateString());
            }
            $this->info(count($due).' Schutzbrief-Erinnerung(en) faellig (dry-run).');
            return self::SUCCESS;
        }

        $sent = $service->run();
        $this->info($sent.' Schutzbrief-Erinnerung(en) versendet.');
        return self::SUCCESS;
    }
}
