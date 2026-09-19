<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Die Bewertung aus dem Google-Unternehmensprofil - so, wie die Website
 * sie liest: AUSSCHLIESSLICH aus dem Cache.
 *
 * WARUM DIESE TRENNUNG (Lehre 20.08.2026, "kein Web-Request wartet
 * minutenlang auf einen fremden Dienst"): wuerde die Seite den Wert bei
 * Bedarf selbst holen, haenge der Seitenaufbau an der Erreichbarkeit von
 * Google. Ein langsamer oder gestoerter Fremddienst wuerde die
 * Startseite ausbremsen - fuer eine Zahl, die sich einmal am Tag
 * aendert. Geholt wird deshalb im Hintergrund
 * (`google:bewertungen-holen`), gelesen wird hier.
 *
 * UND DER EIGENTLICHE GRUND, warum es diesen Umweg ueberhaupt gibt:
 * ein eingebetteter Google-Kartenrahmen wuerde die IP JEDES Besuchers an
 * Google senden, bevor er zugestimmt hat - genau die Klasse Fremdzugriff,
 * die beim Wechsel der Schriften auf lokale Dateien entfernt wurde, und
 * sie wuerde `frame-src`/`script-src` aus SEC-4 wieder oeffnen. Der
 * Server holt die Zahl, der Browser des Besuchers spricht nie mit Google.
 *
 * ERFUNDEN WIRD NICHTS: ohne abgerufenen Wert gibt es keine Zahl, nur
 * den Hinweis auf das Profil. Und der Cache-Eintrag laeuft nach
 * `MAX_ALTER_TAGE` ab - schlaegt der Abruf laenger fehl, verschwindet
 * die Zahl von selbst, statt dass eine veraltete Bewertung als
 * aktuelle stehen bleibt (UWG).
 */
class GoogleBewertung
{
    public const CACHE_KEY = 'google_bewertung';

    /**
     * Nach so vielen Tagen ohne erfolgreichen Abruf gilt die Zahl als
     * nicht mehr belegt. Die Ablaufzeit des Cache-Eintrags IST die
     * Regel - es braucht keine zweite Pruefung, die man vergessen kann.
     */
    public const MAX_ALTER_TAGE = 7;

    /** Mindestanzahl Bewertungen, ab der eine Zahl ueberhaupt etwas sagt. */
    public const MIN_ANZAHL = 1;

    /**
     * Ist das Unternehmensprofil verlinkt? Ohne Link gibt es keine
     * Karte - ein Vertrauenshinweis ohne Ziel ist keiner.
     */
    public static function profilUrl(): ?string
    {
        $url = trim((string) config('website.google_business'));

        return $url !== '' ? $url : null;
    }

    /**
     * Der zuletzt abgerufene Stand oder null.
     *
     * @return array{rating: float, anzahl: int, stand: string}|null
     */
    public static function stand(): ?array
    {
        $daten = Cache::get(self::CACHE_KEY);

        if (! is_array($daten)) {
            return null;
        }

        $rating = (float) ($daten['rating'] ?? 0);
        $anzahl = (int) ($daten['anzahl'] ?? 0);

        // Eine "0,0 bei 0 Bewertungen" ist schlechter als gar keine
        // Angabe: sie sieht aus wie ein schlechtes Ergebnis, obwohl sie
        // nur bedeutet, dass noch niemand bewertet hat.
        if ($rating <= 0 || $anzahl < self::MIN_ANZAHL) {
            return null;
        }

        return [
            'rating' => round($rating, 1),
            'anzahl' => $anzahl,
            'stand' => (string) ($daten['stand'] ?? ''),
        ];
    }

    /** Ist der Abruf ueberhaupt eingerichtet (Schluessel + Ort)? */
    public static function abrufEingerichtet(): bool
    {
        return trim((string) config('services.google_places.api_key')) !== ''
            && trim((string) config('services.google_places.place_id')) !== '';
    }

    /**
     * Sternreihe als Text: volle, halbe und leere Sterne.
     * Rein rechnerisch aus der Zahl - es gibt keinen zweiten Wert, der
     * mit ihr auseinanderlaufen koennte.
     *
     * @return list<string> je Eintrag 'voll'|'halb'|'leer'
     */
    public static function sterne(float $rating): array
    {
        $sterne = [];

        for ($i = 1; $i <= 5; $i++) {
            if ($rating >= $i - 0.25) {
                $sterne[] = 'voll';
            } elseif ($rating >= $i - 0.75) {
                $sterne[] = 'halb';
            } else {
                $sterne[] = 'leer';
            }
        }

        return $sterne;
    }
}
