<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Models\CustomerFamilyRelation;
use App\Services\Family\AbhaengigesKindService;
use App\Services\Family\FamilyRelationService;
use Illuminate\Console\Command;

/**
 * Automatischer Uebergang im Selbststaendigkeitsalter (Betreiber-Vorgabe
 * 28.08.2026; seit 07.10.2026 Einstellung, Standard 16 - KI-096).
 *
 * Erreicht ein abhaengiges Familienmitglied dieses Alter, wird es zum
 * eigenstaendigen Kunden. Eine eigene Kundennummer vergibt das Team. Das ist AUSDRUECKLICH nur ein Statuswechsel:
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

    protected $description = 'Abhaengige Familienmitglieder ab dem Selbststaendigkeitsalter (Einstellung, Standard 16) auf "eigenstaendiger Kunde" umstellen (Beziehung bleibt, Vertraege unveraendert, Kundennummer vergibt das Team)';

    public function handle(FamilyRelationService $service, AbhaengigesKindService $kinder): int
    {
        $faellig = $service->dueTransitions();

        if ($faellig->isEmpty()) {
            $this->info('Keine faelligen Uebergaenge.');

            return 0;
        }

        $erledigt = $this->verarbeiteEinzeln(
            $faellig,
            function (CustomerFamilyRelation $relation) use ($service, $kinder) {
                $service->applyTransition($relation);
                // Ohne eigene Kundennummer (KI-096): Aufgabe an das Team -
                // vergeben wird sie bewusst von einem Menschen.
                $kinder->aufgabeBeiSelbststaendigkeit($relation);
                $this->line('  '.($relation->relatedCustomer?->user?->name ?? '—').' ist jetzt eigenstaendiger Kunde (Beziehung bleibt bestehen).');
            },
            'Familienbeziehung'
        );

        $this->info($erledigt.' Familienmitglied(er) auf "eigenstaendiger Kunde" umgestellt. Vertraege wurden NICHT veraendert.');

        return $this->ergebnisMitUebersprungenen();
    }
}
