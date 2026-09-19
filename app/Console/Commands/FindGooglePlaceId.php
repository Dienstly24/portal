<?php

namespace App\Console\Commands;

use App\Services\Seo\GoogleBewertungAbruf;
use Illuminate\Console\Command;

/**
 * Einrichtungshilfe: findet die Ortskennung (Place ID) des eigenen
 * Unternehmensprofils.
 *
 * Der Befehl SCHREIBT NICHTS. Er zeigt die Treffer, der Betreiber traegt
 * die richtige Kennung selbst in die .env ein. Automatisch den ersten
 * Treffer zu nehmen waere Raten - und eine falsche Kennung zeigt die
 * Bewertungen eines FREMDEN Unternehmens auf der eigenen Seite.
 */
class FindGooglePlaceId extends Command
{
    protected $signature = 'google:place-id-finden {suche? : Suchtext, sonst Firmenname und Anschrift aus config/website.php}';

    protected $description = 'Ortskennung (Place ID) des Unternehmensprofils suchen - schreibt nichts, zeigt nur Treffer';

    public function handle(GoogleBewertungAbruf $abruf): int
    {
        $suche = (string) ($this->argument('suche') ?? '');

        if ($suche === '') {
            $adresse = (array) config('website.address');
            $suche = trim(implode(' ', array_filter([
                (string) config('app.name'),
                (string) ($adresse['street'] ?? ''),
                (string) ($adresse['zip'] ?? ''),
                (string) ($adresse['city'] ?? ''),
            ])));
        }

        $this->line('Suche: '.$suche);

        try {
            $treffer = $abruf->orteSuchen($suche);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($treffer === []) {
            $this->warn('Kein Treffer. Suchtext genauer fassen, z. B. Firmenname plus Strasse und Ort.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Place ID', 'Name', 'Anschrift'], array_map(
            fn (array $t) => [$t['id'], $t['name'], $t['adresse']],
            $treffer
        ));

        if (count($treffer) > 1) {
            $this->warn('Mehrere Treffer: es wird NICHTS uebernommen. Waehlen Sie den richtigen selbst aus.');
        }

        $this->newLine();
        $this->line('Passenden Eintrag pruefen und in die Server-.env schreiben:');
        $this->line('  GOOGLE_PLACE_ID="'.$treffer[0]['id'].'"');
        $this->line('Danach: php artisan config:cache && php artisan google:bewertungen-holen');

        return self::SUCCESS;
    }
}
