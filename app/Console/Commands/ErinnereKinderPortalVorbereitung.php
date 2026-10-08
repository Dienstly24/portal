<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Models\CustomerFamilyRelation;
use App\Services\Family\AbhaengigesKindService;
use Illuminate\Console\Command;

/**
 * Erinnerung "Kind wird 15 - eigenes Portal vorbereiten" (KI-095,
 * 07.10.2026). Je Kind genau EINE Aufgabe an der Akte des Elternteils plus
 * Glocke an dessen Betreuer. Aendert keine Kundennummer, keinen Vertrag,
 * kein Portal - es ist eine Erinnerung, keine Umstellung.
 */
class ErinnereKinderPortalVorbereitung extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'familie:portal-vorbereitung-erinnern';

    protected $description = 'Abhaengige Kinder im Erinnerungsalter (Einstellung, Standard 15): Aufgabe + Glocke "Portal vorbereiten", einmal je Kind';

    public function handle(AbhaengigesKindService $service): int
    {
        $faellig = $service->faelligeErinnerungen();
        if ($faellig->isEmpty()) {
            $this->info('Keine faelligen Erinnerungen.');

            return 0;
        }

        $erledigt = $this->verarbeiteEinzeln(
            $faellig,
            function (CustomerFamilyRelation $relation) use ($service) {
                $service->erinnern($relation);
                $this->line('  Erinnerung: '.($relation->relatedCustomer->user->name ?? '—').' (Akte des Elternteils: '
                    .($relation->customer->customer_number ?? '—').')');
            },
            'Familienbeziehung'
        );

        $this->info($erledigt.' Erinnerung(en) angelegt.');

        return $this->ergebnisMitUebersprungenen();
    }
}
