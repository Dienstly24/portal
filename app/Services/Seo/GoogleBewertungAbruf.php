<?php

namespace App\Services\Seo;

use App\Support\GoogleBewertung;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Holt Bewertung und Anzahl aus der Google Places API und legt sie in
 * den Cache. EINZIGE Stelle im Projekt, die mit Google spricht.
 *
 * Aufgerufen wird sie aus `google:bewertungen-holen` (taeglich), nie aus
 * einem Web-Request.
 */
class GoogleBewertungAbruf
{
    /**
     * Places API (New). Die Felder werden per Feldmaske angefordert -
     * ohne sie antwortet die API mit einem Fehler, und mit zu vielen
     * Feldern kostet der Aufruf unnoetig mehr.
     */
    private const ENDPUNKT = 'https://places.googleapis.com/v1/places/';

    private const FELDER = 'rating,userRatingCount,displayName';

    private const SUCHE = 'https://places.googleapis.com/v1/places:searchText';

    private const SUCHFELDER = 'places.id,places.displayName,places.formattedAddress';

    /**
     * @return array{rating: float, anzahl: int, name: string}
     *
     * @throws \RuntimeException wenn nicht eingerichtet oder die API nicht antwortet
     */
    public function holen(): array
    {
        if (! GoogleBewertung::abrufEingerichtet()) {
            throw new \RuntimeException(
                'Nicht eingerichtet: GOOGLE_PLACES_API_KEY und GOOGLE_PLACE_ID fehlen in der .env.'
            );
        }

        $antwort = $this->http()
            ->withHeaders([
                'X-Goog-Api-Key' => (string) config('services.google_places.api_key'),
                'X-Goog-FieldMask' => self::FELDER,
            ])
            ->get(self::ENDPUNKT.trim((string) config('services.google_places.place_id')));

        if (! $antwort->successful()) {
            throw new \RuntimeException(
                'Google antwortete mit HTTP '.$antwort->status().': '
                .(string) ($antwort->json('error.message') ?? 'ohne Begruendung')
            );
        }

        return [
            'rating' => (float) ($antwort->json('rating') ?? 0),
            'anzahl' => (int) ($antwort->json('userRatingCount') ?? 0),
            'name' => (string) ($antwort->json('displayName.text') ?? ''),
        ];
    }

    /**
     * Holen und speichern. Gibt zurueck, was gespeichert wurde - oder
     * null, wenn der Abruf fehlschlug.
     *
     * DER FEHLSCHLAG LOESCHT NICHTS: der vorhandene Eintrag bleibt bis
     * zu seinem Ablauf stehen. Eine einzelne Stoerung bei Google soll
     * die Bewertung nicht sofort von der Seite nehmen; bleibt sie
     * laenger als `MAX_ALTER_TAGE` bestehen, verschwindet die Zahl von
     * selbst, weil der Eintrag ablaeuft.
     *
     * @return array{rating: float, anzahl: int, name: string}|null
     */
    public function aktualisieren(): ?array
    {
        try {
            $daten = $this->holen();
        } catch (\Throwable $e) {
            Log::warning('Google-Bewertung konnte nicht abgerufen werden: '.$e->getMessage());

            return null;
        }

        Cache::put(GoogleBewertung::CACHE_KEY, [
            'rating' => $daten['rating'],
            'anzahl' => $daten['anzahl'],
            'name' => $daten['name'],
            'stand' => now()->toDateTimeString(),
        ], now()->addDays(GoogleBewertung::MAX_ALTER_TAGE));

        return $daten;
    }

    /**
     * Einrichtungshilfe: Orte zu einem Suchtext finden.
     *
     * Es wird NIE automatisch einer davon uebernommen - der Befehl zeigt
     * die Treffer, ein Mensch waehlt. Dieselbe Regel wie ueberall im
     * Projekt: bei mehreren Kandidaten wird nicht geraten, und eine
     * falsch gewaehlte Kennung zeigt die Bewertungen eines FREMDEN
     * Unternehmens auf unserer Seite.
     *
     * @return list<array{id: string, name: string, adresse: string}>
     */
    public function orteSuchen(string $text): array
    {
        $key = trim((string) config('services.google_places.api_key'));

        if ($key === '') {
            throw new \RuntimeException('GOOGLE_PLACES_API_KEY fehlt in der .env.');
        }

        $antwort = $this->http()
            ->withHeaders([
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => self::SUCHFELDER,
            ])
            ->post(self::SUCHE, ['textQuery' => $text]);

        if (! $antwort->successful()) {
            throw new \RuntimeException(
                'Google antwortete mit HTTP '.$antwort->status().': '
                .(string) ($antwort->json('error.message') ?? 'ohne Begruendung')
            );
        }

        $treffer = [];

        foreach ((array) $antwort->json('places', []) as $ort) {
            $treffer[] = [
                'id' => (string) ($ort['id'] ?? ''),
                'name' => (string) ($ort['displayName']['text'] ?? ''),
                'adresse' => (string) ($ort['formattedAddress'] ?? ''),
            ];
        }

        return $treffer;
    }

    /**
     * Zeitlimit wie bei jedem Fremddienst in diesem Projekt: lieber
     * rechtzeitig aufgeben als einen Hintergrundlauf blockieren.
     */
    private function http(): PendingRequest
    {
        return Http::timeout(10)->connectTimeout(5)->acceptJson();
    }
}
