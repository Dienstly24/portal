<?php

namespace App\Services\Social;

use App\Models\FacebookBewertung;
use App\Support\FacebookEmpfehlung;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Holt die Empfehlungen der eigenen Facebook-Seite (Graph API
 * `/{page-id}/ratings`, Page Access Token) und gleicht sie mit
 * `facebook_bewertungen` ab. Aufgerufen aus `facebook:bewertungen-holen`
 * und dem Knopf "Jetzt abrufen" im Admin - nie aus einem Seitenaufruf
 * der Website.
 *
 * VORAUSSETZUNG: das Seiten-Token braucht die Berechtigung
 * `pages_read_user_content`. Fehlt sie, meldet Facebook (#10)/(#200);
 * die Meldung wird in einen verstaendlichen Satz uebersetzt.
 */
class FacebookBewertungAbruf
{
    private const FELDER = 'created_time,recommendation_type,rating,review_text,reviewer{name},open_graph_story{id}';

    /** Sicherung gegen eine Endlosschleife beim Blaettern. */
    private const MAX_SEITEN = 20;

    public function __construct(private MetaGraphClient $graph) {}

    /**
     * @return array{neu: int, geaendert: int, entfernt: int, positiv: int, gesamt: int}
     *
     * @throws \RuntimeException
     */
    public function aktualisieren(): array
    {
        if (! FacebookEmpfehlung::abrufEingerichtet()) {
            throw new \RuntimeException('Nicht eingerichtet: META_PAGE_ID und ein Token fehlen in der .env.');
        }

        $eintraege = $this->holen();

        $neu = $geaendert = 0;
        $gesehen = [];

        DB::transaction(function () use ($eintraege, &$neu, &$geaendert, &$gesehen) {
            foreach ($eintraege as $e) {
                $gesehen[] = $e['fb_kennung'];
                $zeile = FacebookBewertung::where('fb_kennung', $e['fb_kennung'])->first();

                if (! $zeile) {
                    FacebookBewertung::create($e + ['status' => 'neu']);
                    $neu++;

                    continue;
                }

                $aenderung = [];
                foreach (['name_kurz', 'text', 'empfehlung', 'sterne'] as $feld) {
                    if ($zeile->{$feld} !== $e[$feld]) {
                        $aenderung[$feld] = $e[$feld];
                    }
                }
                // Wieder bei Facebook sichtbar
                if ($zeile->entfernt_at !== null) {
                    $aenderung['entfernt_at'] = null;
                }
                if ($aenderung === []) {
                    continue;
                }
                // Freigegeben wurde der ALTE Text - ein neuer braucht eine
                // neue Freigabe.
                if (array_key_exists('text', $aenderung) && $zeile->status === 'freigegeben') {
                    $aenderung['status'] = 'neu';
                    $aenderung['geprueft_von'] = null;
                    $aenderung['geprueft_at'] = null;
                }
                $zeile->update($aenderung);
                $geaendert++;
            }
        });

        // Nicht mehr bei Facebook: nie mehr zeigen, nichts loeschen.
        // Nur, weil die Liste VOLLSTAENDIG geholt wurde (holen() wirft
        // sonst) - aus einer halben Liste folgt nichts.
        $entfernt = FacebookBewertung::whereNull('entfernt_at')
            ->whereNotIn('fb_kennung', $gesehen === [] ? [''] : $gesehen)
            ->update(['entfernt_at' => now()]);

        $positiv = count(array_filter($eintraege, fn ($e) => $e['empfehlung'] === 'positive' || (int) $e['sterne'] >= 4));

        Cache::put(FacebookEmpfehlung::CACHE_KEY, [
            'positiv' => $positiv,
            'gesamt' => count($eintraege),
            'stand' => now()->toDateTimeString(),
        ], now()->addDays(FacebookEmpfehlung::MAX_ALTER_TAGE));

        return ['neu' => $neu, 'geaendert' => $geaendert, 'entfernt' => $entfernt, 'positiv' => $positiv, 'gesamt' => count($eintraege)];
    }

    /**
     * Alle Empfehlungen, seitenweise. Wirft, wenn die Liste nicht
     * vollstaendig geholt werden konnte.
     *
     * @return list<array{fb_kennung: string, name_kurz: ?string, text: ?string, empfehlung: ?string, sterne: ?int, verfasst_at: ?string}>
     */
    public function holen(): array
    {
        $pageId = trim((string) config('services.meta.page_id'));
        $params = ['fields' => self::FELDER, 'limit' => 100];
        $alle = [];

        try {
            $token = $this->graph->pageToken();
            for ($seite = 0; $seite < self::MAX_SEITEN; $seite++) {
                $antwort = $this->graph->get($pageId.'/ratings', $params, $token);
                foreach ((array) ($antwort['data'] ?? []) as $roh) {
                    $alle[] = $this->normalisieren((array) $roh);
                }
                $weiter = $antwort['paging']['cursors']['after'] ?? null;
                if (empty($antwort['paging']['next']) || ! $weiter) {
                    return $alle;
                }
                $params['after'] = $weiter;
            }
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(self::verstaendlich($e->getMessage()), 0, $e);
        }

        throw new \RuntimeException('Mehr als '.self::MAX_SEITEN.' Seiten Empfehlungen - Abruf abgebrochen, nichts als entfernt markiert.');
    }

    /** @return array{fb_kennung: string, name_kurz: ?string, text: ?string, empfehlung: ?string, sterne: ?int, verfasst_at: ?string} */
    private function normalisieren(array $roh): array
    {
        $text = trim((string) ($roh['review_text'] ?? ''));
        $zeit = (string) ($roh['created_time'] ?? '');
        $kennung = (string) ($roh['open_graph_story']['id'] ?? '');
        if ($kennung === '') {
            $kennung = 'h:'.sha1($zeit.'|'.$text);
        }
        $empfehlung = $roh['recommendation_type'] ?? null;

        return [
            'fb_kennung' => mb_substr($kennung, 0, 120),
            'name_kurz' => FacebookBewertung::kurzname($roh['reviewer']['name'] ?? null),
            'text' => $text !== '' ? $text : null,
            'empfehlung' => in_array($empfehlung, ['positive', 'negative'], true) ? $empfehlung : null,
            'sterne' => isset($roh['rating']) ? max(1, min(5, (int) $roh['rating'])) : null,
            'verfasst_at' => $zeit !== '' ? Carbon::parse($zeit)->utc()->toDateTimeString() : null,
        ];
    }

    public static function verstaendlich(string $meldung): string
    {
        if (preg_match('/\(#(10|200)\)|pages_read_user_content|permission/i', $meldung)) {
            return 'Facebook verweigert das Lesen der Empfehlungen: dem Seiten-Token fehlt die Berechtigung '
                .'"pages_read_user_content". Bitte im Meta Business Manager ergaenzen (Anleitung docs/ANLEITUNG_FACEBOOK_BEWERTUNGEN_AR.md). '
                .'Original: '.$meldung;
        }

        return $meldung;
    }
}
