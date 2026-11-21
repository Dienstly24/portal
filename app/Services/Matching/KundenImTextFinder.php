<?php

namespace App\Services\Matching;

use App\Models\Customer;
use App\Services\Ai\Concerns\RepairsOcrText;

/**
 * Findet VORHANDENE Kunden im kostenlos gelesenen Text eines Dokuments.
 *
 * WARUM (Betreiber-Meldung 21.11.2026, KI-122): eine Familie schickte Karten,
 * Ausweise, Arbeitsvertrag und Heiratsurkunde. Alles lief ueber die
 * kostenlose OCR, keine KI. Ein Scan trug die IBAN und den VOLLEN Namen eines
 * Bestandskunden ("DE.. <IBAN> 1:69 <Vorname> <Name>") - und trotzdem stand
 * "Kein Kunde gefunden" darunter. Ursache: die Heuristik liest bewusst KEINE
 * Namen aus Freitext (sonst entstuenden erfundene Kunden), und die IBAN stand
 * nicht allein auf ihrer Zeile. Ohne Name kein Abgleich.
 *
 * Der Unterschied zum Namen-Raten: hier wird kein Name AUS dem Text
 * erzeugt, sondern geprueft, ob der Name eines Kunden, den es BEREITS gibt,
 * im Text steht. Dabei entsteht nie ein Kunde und nie eine Kundenangabe -
 * nur ein Vorschlag, den der Mitarbeiter bestaetigt.
 *
 * KONSERVATIV:
 *  - Name: mind. zwei Namensteile, zusammen mind. 8 Buchstaben, und sie
 *    stehen im Text DIREKT HINTEREINANDER - in der gespeicherten Reihenfolge
 *    oder mit dem Nachnamen vorn ("MUSTERMANN MAX" auf Karten). Verstreute
 *    Einzelwoerter in einem langen Text zaehlen nicht.
 *  - IBAN: nur mit gueltiger Pruefziffer, Vergleich mit iban/iban2.
 *  - Nie automatisch zuordnen: der Score bleibt <= 90 (Stufe "confirm").
 *  - Gespeichert wird nur Kunden-ID, Art und ein Grund OHNE Namen - der Grund
 *    erscheint auch dort, wo ein Kunde ausserhalb des Portfolios liegt.
 */
class KundenImTextFinder
{
    use RepairsOcrText;

    public const SCORE_NAME = 80;
    public const SCORE_IBAN = 90;

    /** Hoechstens so viele Textwoerter gehen in die Vorauswahl. */
    private const MAX_SUCHWOERTER = 80;

    /**
     * @return list<array{customer_id: string, art: string, score: int, grund: string}>
     */
    public function finde(string $text, ?string $iban = null): array
    {
        $treffer = [];

        $kandidatIban = $this->normalisiereIban($iban);
        if ($kandidatIban === null && $text !== '') {
            $kandidatIban = $this->ocrGermanIban($text);
        }
        if ($kandidatIban !== null) {
            foreach ($this->kundenMitIban($kandidatIban) as $id) {
                $treffer[$id] = ['customer_id' => $id, 'art' => 'iban', 'score' => self::SCORE_IBAN,
                    'grund' => 'IBAN des Kunden steht im Dokument'];
            }
        }

        foreach ($this->kundenMitNamen($text) as $id) {
            if (isset($treffer[$id])) {
                $treffer[$id]['grund'] .= ' (dazu der volle Name)';
                continue;
            }
            $treffer[$id] = ['customer_id' => $id, 'art' => 'name', 'score' => self::SCORE_NAME,
                'grund' => 'Voller Kundenname steht im Dokumenttext'];
        }

        $liste = array_values($treffer);
        usort($liste, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($liste, 0, 5);
    }

    /** @return list<string> */
    private function kundenMitNamen(string $text): array
    {
        $woerter = self::woerter($text);
        if ($woerter === []) {
            return [];
        }
        $folge = ' '.implode(' ', $woerter).' ';

        $suchwoerter = array_values(array_unique(array_filter($woerter, fn ($w) => mb_strlen($w) >= 4)));
        $suchwoerter = array_slice($suchwoerter, 0, self::MAX_SUCHWOERTER);
        if ($suchwoerter === []) {
            return [];
        }

        $kandidaten = Customer::query()
            ->with('user:id,name')
            ->whereHas('user', function ($q) use ($suchwoerter) {
                $q->where('role', 'customer')->where(function ($w) use ($suchwoerter) {
                    foreach ($suchwoerter as $wort) {
                        $w->orWhere('name', 'like', '%'.$wort.'%');
                    }
                });
            })
            ->limit(300)
            ->get(['id', 'user_id']);

        $ids = [];
        foreach ($kandidaten as $kunde) {
            $teile = self::woerter((string) $kunde->user?->name);
            if (count($teile) < 2 || mb_strlen(implode('', $teile)) < 8) {
                continue;
            }
            $vorn = ' '.implode(' ', $teile).' ';
            $nachnameVorn = ' '.implode(' ', [end($teile), ...array_slice($teile, 0, -1)]).' ';
            if (str_contains($folge, $vorn) || str_contains($folge, $nachnameVorn)) {
                $ids[] = (string) $kunde->id;
            }
        }

        return $ids;
    }

    /** @return list<string> */
    private function kundenMitIban(string $iban): array
    {
        $ids = [];
        Customer::query()
            ->where(fn ($q) => $q->whereNotNull('iban')->orWhereNotNull('iban2'))
            ->select(['id', 'iban', 'iban2'])
            ->chunkById(500, function ($kunden) use ($iban, &$ids) {
                foreach ($kunden as $kunde) {
                    if ($this->normalisiereIban($kunde->iban) === $iban
                        || $this->normalisiereIban($kunde->iban2) === $iban) {
                        $ids[] = (string) $kunde->id;
                    }
                }
            });

        return $ids;
    }

    private function normalisiereIban(?string $iban): ?string
    {
        $kompakt = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $iban));
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $kompakt) || ! $this->ibanChecksumValid($kompakt)) {
            return null;
        }

        return $kompakt;
    }

    /**
     * Woerter in Kleinschrift, Umlaute umschrieben, nur Buchstaben.
     *
     * @return list<string>
     */
    public static function woerter(string $text): array
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'ç' => 'c']);
        $text = (string) preg_replace('/[^a-z]+/', ' ', $text);

        return array_values(array_filter(explode(' ', $text), fn ($w) => mb_strlen($w) >= 2));
    }
}
