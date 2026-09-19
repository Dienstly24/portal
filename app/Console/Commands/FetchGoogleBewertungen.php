<?php

namespace App\Console\Commands;

use App\Services\Seo\GoogleBewertungAbruf;
use App\Support\GoogleBewertung;
use Illuminate\Console\Command;

/**
 * Holt die Bewertung des eigenen Google-Unternehmensprofils und legt sie
 * in den Cache. Laeuft taeglich; die Website liest danach nur noch den
 * Cache.
 */
class FetchGoogleBewertungen extends Command
{
    protected $signature = 'google:bewertungen-holen';

    protected $description = 'Bewertung und Anzahl aus dem Google-Unternehmensprofil abrufen und zwischenspeichern';

    public function handle(GoogleBewertungAbruf $abruf): int
    {
        if (! GoogleBewertung::abrufEingerichtet()) {
            // KEIN Fehler: ohne Einrichtung zeigt die Website die Karte
            // ohne Zahlen, und das ist ein gueltiger Zustand. Ein roter
            // Exitcode wuerde die Systemzustand-Seite grundlos
            // alarmieren.
            $this->info('Nicht eingerichtet (GOOGLE_PLACES_API_KEY / GOOGLE_PLACE_ID fehlen) - nichts zu tun.');

            return self::SUCCESS;
        }

        $daten = $abruf->aktualisieren();

        if ($daten === null) {
            $this->error('Abruf fehlgeschlagen - Einzelheiten stehen im Log. Der bisherige Stand bleibt bis zu seinem Ablauf erhalten.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Abgerufen: %s - %.1f aus %d Bewertungen.',
            $daten['name'] !== '' ? $daten['name'] : 'Unternehmensprofil',
            $daten['rating'],
            $daten['anzahl']
        ));

        if ($daten['anzahl'] < GoogleBewertung::MIN_ANZAHL) {
            $this->warn('Noch keine Bewertungen - die Website zeigt die Karte ohne Zahlen.');
        }

        return self::SUCCESS;
    }
}
