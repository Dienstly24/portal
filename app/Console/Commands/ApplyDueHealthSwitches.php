<?php
namespace App\Console\Commands;

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

        foreach ($due as $contract) {
            if ($this->option('dry-run')) {
                $this->line('- ' . ($contract->customer?->customer_number ?? '?') . ' -> ' . $contract->insurer . ' (ab ' . $contract->start_date . ')');
                continue;
            }

            $contract->update(['status' => 'active']);
            $contract->customer?->fill(['health_insurance_company' => $contract->insurer])->save();

            ActivityLog::create([
                'user_id' => null,
                'action' => 'health_switch_applied',
                'entity_type' => 'contract',
                'entity_id' => $contract->id,
                'meta' => json_encode([
                    'customer_id' => (string) $contract->customer_id,
                    'insurer' => $contract->insurer,
                    'start_date' => (string) $contract->start_date,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $this->info($due->count() . ' Krankenkassenwechsel ' . ($this->option('dry-run') ? 'faellig (dry-run).' : 'aktiviert.'));
        return self::SUCCESS;
    }
}
