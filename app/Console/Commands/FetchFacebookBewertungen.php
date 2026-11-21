<?php

namespace App\Console\Commands;

use App\Services\Social\FacebookBewertungAbruf;
use App\Support\FacebookEmpfehlung;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Holt die Empfehlungen der eigenen Facebook-Seite. Neue Empfehlungen
 * stehen danach unter /admin/facebook-bewertungen zur Freigabe - auf die
 * Website kommt nichts ohne einen Menschen.
 */
class FetchFacebookBewertungen extends Command
{
    protected $signature = 'facebook:bewertungen-holen';

    protected $description = 'Empfehlungen der Facebook-Seite abrufen (Freigabe danach im Admin)';

    public function handle(FacebookBewertungAbruf $abruf): int
    {
        if (! FacebookEmpfehlung::abrufEingerichtet()) {
            $this->info('Nicht eingerichtet (META_PAGE_ID / Token fehlen) - nichts zu tun.');

            return self::SUCCESS;
        }

        try {
            $e = $abruf->aktualisieren();
        } catch (\Throwable $ex) {
            $this->error($ex->getMessage());
            Log::warning('Facebook-Empfehlungen: '.$ex->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Empfehlungen: %d gesamt (%d positiv). Neu: %d, geaendert: %d, bei Facebook entfernt: %d, automatisch freigegeben: %d.',
            $e['gesamt'], $e['positiv'], $e['neu'], $e['geaendert'], $e['entfernt'], $e['automatisch']
        ));
        if ($e['neu'] > 0) {
            $this->line('Neue Empfehlungen warten auf Freigabe: /admin/facebook-bewertungen');
        }

        return self::SUCCESS;
    }
}
