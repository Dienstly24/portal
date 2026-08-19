<?php
namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Models\ActivityLog;
use App\Models\Contract;
use Illuminate\Console\Command;

/**
 * Aktiviert faellige Krankenkassenwechsel: pending Kranken-Vertraege, deren
 * Startdatum erreicht ist, werden aktiv gesetzt und die Krankenkasse des
 * Kunden wird auf den neuen Versicherer umgestellt (Betreiber-Ablauf: bis
 * zum Stichtag bleibt die alte Kasse eingetragen). Laeuft taeglich.
 */
class ApplyDueHealthSwitches extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'health:apply-due-switches {--dry-run : Nur anzeigen, nichts aendern}';
    protected $description = 'Aktiviert faellige Krankenkassenwechsel (pending Kranken-Vertraege am Stichtag)';

    public function handle(): int
    {
        $due = Contract::where('type', 'krankenversicherung')
            ->where('status', 'pending')
            ->whereNotNull('start_date')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->with('customer')
            ->get();

        if ($due->isEmpty()) {
            $this->info('Keine faelligen Krankenkassenwechsel.');
            return self::SUCCESS;
        }

        // Je Vertrag abgesichert: bricht der Lauf beim ersten Problem ab,
        // behalten ALLE folgenden Kunden ihre alte Krankenkasse in der Akte -
        // ab dem Stichtag ist das schlicht falsch, und niemand sieht es.
        $erledigt = $this->verarbeiteEinzeln($due, function (Contract $contract) {
            if ($this->option('dry-run')) {
                $this->line('- ' . ($contract->customer?->customer_number ?? '?') . ' -> ' . $contract->insurer . ' (ab ' . $contract->start_date . ')');

                return;
            }

            $contract->update(['status' => 'active']);
            $contract->customer?->fill(['health_insurance_company' => $contract->insurer])->save();

            // ueber record(): meta ist als Array gecastet - ein vorab
            // json_encode()-ter String wuerde ein ZWEITES Mal kodiert.
            ActivityLog::record('health_switch_applied', 'contract', $contract->id, [
                'customer_id' => (string) $contract->customer_id,
                'insurer' => $contract->insurer,
                'start_date' => (string) $contract->start_date,
            ]);
        }, 'Vertrag');

        $this->info($erledigt . ' Krankenkassenwechsel ' . ($this->option('dry-run') ? 'faellig (dry-run).' : 'aktiviert.'));

        return $this->ergebnisMitUebersprungenen(self::SUCCESS);
    }
}
