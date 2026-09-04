<?php

namespace App\Console\Commands;

use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Ergaenzt bei BESTEHENDEN Schutzbrief-/Mobilclub-Vertraegen die fehlenden
 * Laufzeit-Daten (Betreiber-Vorgabe 28.07.2026): Beginn und Ablauf.
 *
 * Diese Vertraege beginnen sofort (0 Uhr des Erfassungstages) und laufen ein
 * Jahr mit automatischer Verlaengerung. Vertraege, die vor dieser Regel
 * angelegt wurden, haben oft kein Beginn-/Ablaufdatum - dann greift die
 * Verlaengerungs-Erinnerung nicht. Der Befehl fuellt NUR leere Felder:
 *
 *  - Beginn fehlt  -> Erstellungsdatum des Vertrags (Anlage im System)
 *  - Ablauf fehlt  -> Beginn + 1 Jahr (Verlaengerungs-Stichtag)
 *
 * Bestehende Daten werden nie ueberschrieben. Mit --dry-run nur anzeigen.
 */
class BackfillSchutzbriefTerms extends Command
{
    protected $signature = 'schutzbrief:backfill-terms {--dry-run : Nur anzeigen, nichts speichern}';
    protected $description = 'Ergaenzt Beginn/Ablauf bei bestehenden Schutzbrief-Vertraegen (1 Jahr, Auto-Verlaengerung)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $contracts = Contract::where('type', 'schutzbrief')
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereNull('end_date'))
            ->with('customer')
            ->get();

        if ($contracts->isEmpty()) {
            $this->info('Keine Schutzbrief-Vertraege ohne Laufzeit-Daten gefunden.');
            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($contracts as $contract) {
            $start = $contract->start_date
                ? Carbon::parse($contract->start_date)->startOfDay()
                : Carbon::parse($contract->created_at ?? now())->startOfDay();
            $end = $contract->end_date
                ? Carbon::parse($contract->end_date)->startOfDay()
                : $start->copy()->addYear();

            $this->line('- '.($contract->customer?->customer_number ?? '?')
                .' ('.($contract->insurer ?? 'ohne Anbieter').')'
                .' Beginn '.$start->toDateString()
                .' / Verlaengerung '.$end->toDateString());

            if (! $dry) {
                // Nur leere Felder fuellen - Bestand nie ueberschreiben.
                if ($contract->start_date === null) {
                    $contract->start_date = $start->toDateString();
                }
                if ($contract->end_date === null) {
                    $contract->end_date = $end->toDateString();
                }
                $contract->save();
            }
            $updated++;
        }

        $this->info($updated.' Schutzbrief-Vertrag/-Vertraege '.($dry ? 'faellig (dry-run)' : 'ergaenzt').'.');
        return self::SUCCESS;
    }
}
