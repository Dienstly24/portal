<?php
namespace App\Console\Commands;

use App\Services\Provisionsmanagement\CommissionStatusEngine;
use Illuminate\Console\Command;

/**
 * Taeglicher Lauf: den Provisions-Zustand aller betroffenen Vertraege neu
 * bewerten (§17/§18).
 *
 * WARUM EIN LAUF UND NICHT NUR BEIM IMPORT: Der Zustand aendert sich auch,
 * wenn NICHTS passiert - eine Prueffrist laeuft ab, ohne dass jemand eine
 * Datei hochlaedt. Genau dieser Fall ist der wichtige: "abgeschlossen vor 5
 * Monaten, keine Provision" faellt sonst nie auf.
 *
 * Der Lauf ist rein rechnerisch: er legt nichts an, loescht nichts und
 * beruehrt weder Vertragsstatus noch Buchungen. Er schreibt ausschliesslich
 * `commission_status` samt den beiden Fristdaten - und auch das nur, wenn
 * sich wirklich etwas geaendert hat.
 */
class RefreshCommissionStatus extends Command
{
    protected $signature = 'provisionen:status-aktualisieren {--vertrag=* : Nur diese Vertrags-IDs}';

    protected $description = 'Provisions-Zustand der Verträge neu berechnen (erwartet / überfällig / fehlt)';

    public function handle(CommissionStatusEngine $engine): int
    {
        $ids = $this->option('vertrag');
        $ergebnis = $engine->refreshAll($ids === [] ? null : $ids);

        $this->info(sprintf(
            '%d Verträge geprüft, %d Zustände aktualisiert.',
            $ergebnis['geprueft'],
            $ergebnis['geaendert']
        ));

        return self::SUCCESS;
    }
}
