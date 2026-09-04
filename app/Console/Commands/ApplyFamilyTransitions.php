<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Services\Family\FamilyRelationService;
use Illuminate\Console\Command;

/**
 * Automatischer Uebergang mit 15 (Betreiber-Vorgabe 28.08.2026).
 *
 * Erreicht ein abhaengiges Familienmitglied das 15. Lebensjahr, wird es zum
 * eigenstaendigen Kunden. Das ist AUSDRUECKLICH nur ein Statuswechsel:
 *  - der Datensatz wird NICHT geloescht und NICHT neu angelegt,
 *  - die Familienbeziehung bleibt vollstaendig bestehen (aus "Kind,
 *    abhaengig" wird "eigenstaendige Kundin, Tochter von ..."),
 *  - VERTRAEGE werden nicht angefasst und keine neuen erzeugt. Der Lauf
 *    weist nur darauf hin; die Aenderung bleibt eine bewusste Entscheidung
 *    des Mitarbeiters.
 *
 * Ein kaputter Datensatz stoppt den Lauf nie (ProcessesRecordsSafely) - sonst
 * bliebe ein einzelnes Familienmitglied ohne Geburtsdatum der Grund dafuer,
 * dass alle anderen Uebergaenge liegen bleiben.
 */
class ApplyFamilyTransitions extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'familie:uebergaenge-anwenden';

    protected $description = 'Abhaengige Familienmitglieder ab '.Customer::DEPENDENT_AGE.' Jahren auf "eigenstaendiger Kunde" umstellen (Beziehung bleibt, Vertraege unveraendert)';

    public function handle(FamilyRelationService $service): int
    {
        $faellig = $service->dueTransitions();

        if ($faellig->isEmpty()) {
            $this->info('Keine faelligen Uebergaenge.');

            return 0;
        }

        $erledigt = $this->verarbeiteEinzeln(
            $faellig,
            function (CustomerFamilyRelation $relation) use ($service) {
                $service->applyTransition($relation);
                $this->line('  '.($relation->relatedCustomer?->user?->name ?? '—').' ist jetzt eigenstaendiger Kunde (Beziehung bleibt bestehen).');
            },
            'Familienbeziehung'
        );

        $this->info($erledigt.' Familienmitglied(er) auf "eigenstaendiger Kunde" umgestellt. Vertraege wurden NICHT veraendert.');

        return $this->ergebnisMitUebersprungenen();
    }
}
