<?php

namespace App\Support;

use App\Models\FacebookBewertung;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Facebook-Empfehlungen, so wie die Website sie liest: freigegebene Texte
 * aus der Datenbank und die Gesamtzahlen aus dem Cache. Kein Seitenaufruf
 * spricht je mit Facebook (gleiche Regel wie `GoogleBewertung`): geholt
 * wird im Hintergrund (`facebook:bewertungen-holen`).
 *
 * Die Gesamtzahl laeuft nach `MAX_ALTER_TAGE` ab - schlaegt der Abruf
 * laenger fehl, verschwindet sie von selbst, statt veraltet stehen zu
 * bleiben (UWG).
 */
class FacebookEmpfehlung
{
    public const CACHE_KEY = 'facebook_empfehlungen_stand';

    public const MAX_ALTER_TAGE = 7;

    public static function seiteUrl(): string
    {
        return (string) config('website.facebook');
    }

    /** @return array{positiv: int, gesamt: int, stand: string}|null */
    public static function stand(): ?array
    {
        $daten = Cache::get(self::CACHE_KEY);
        if (! is_array($daten) || (int) ($daten['gesamt'] ?? 0) < 1) {
            return null;
        }

        return [
            'positiv' => (int) ($daten['positiv'] ?? 0),
            'gesamt' => (int) $daten['gesamt'],
            'stand' => (string) ($daten['stand'] ?? ''),
        ];
    }

    /** @return Collection<int, FacebookBewertung> */
    public static function freigegeben(int $anzahl = FacebookBewertung::MAX_ANZEIGE): Collection
    {
        try {
            return FacebookBewertung::sichtbar()->latest('verfasst_at')->limit($anzahl)->get();
        } catch (\Throwable) {
            // Tabelle fehlt (Migration noch nicht gelaufen): die Startseite
            // darf daran nie scheitern.
            return collect();
        }
    }

    public static function abrufEingerichtet(): bool
    {
        return trim((string) config('services.meta.page_id')) !== ''
            && (trim((string) config('services.meta.page_token')) !== '' || trim((string) config('services.meta.token')) !== '');
    }
}
