<?php

namespace App\Console\Commands;

use App\Services\Dokumente\DokumentDublettenService;
use Illuminate\Console\Command;

/** Loescht Papierkorb-Eintraege nach Ablauf der 30 Tage endgueltig (KI-128). */
class DokumentPapierkorbLeeren extends Command
{
    protected $signature = 'dokumente:papierkorb-leeren';

    protected $description = 'Loescht Dokumente endgueltig, die laenger als 30 Tage im Papierkorb liegen';

    public function handle(DokumentDublettenService $service): int
    {
        $this->info($service->abgelaufeneLoeschen().' Dokument(e) endgueltig geloescht.');

        return self::SUCCESS;
    }
}
