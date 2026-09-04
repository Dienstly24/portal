<?php

namespace App\Services\Social;

use App\Models\Banner;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Anzeigen-Steuerung ueber die Meta Marketing API (Phase 3) - der
 * Betreiber verwaltet Werbung KOMPLETT aus dem System:
 * - Kampagnen-Uebersicht mit Status, Tagesbudget, Ausgaben, Klicks, CPC
 * - Starten/Pausieren, Tagesbudget aendern, Loeschen
 * - "Banner bewerben": erstellt Kampagne + Anzeigengruppe + Anzeige aus
 *   dem bereits veroeffentlichten Facebook-Beitrag (object_story_id) -
 *   automatische Platzierungen (Facebook UND Instagram).
 *
 * Geld-Schutzregeln (echtes Budget!):
 * - JEDE neue Kampagne entsteht PAUSIERT - gestartet wird nur per
 *   bewusstem Klick im System.
 * - Tagesbudget hart gedeckelt (services.meta.ads_max_daily_budget, EUR).
 * - Budgets werden in EUR angezeigt und erst hier in Cent umgerechnet
 *   (die Marketing API rechnet in Minor Units - klassische Fehlerquelle).
 * - Jede Aktion landet im ActivityLog (wer/was/wann).
 * Nur Zahlungsmittel lassen sich prinzipbedingt NICHT per API pflegen.
 */
class MetaAdsService
{
    public function __construct(private MetaGraphClient $graph)
    {
    }

    public static function configured(): bool
    {
        $cfg = config('services.meta', []);

        return ! empty($cfg['token']) && ! empty($cfg['ad_account_id']) && ! empty($cfg['page_id']);
    }

    /** Absolute Obergrenze fuer die Schutzgrenze selbst (Tippfehler-Schutz). */
    public const CAP_CEILING_EUR = 10000;

    /**
     * Schutzgrenze fuers Tagesbudget: vom Admin in der Oberflaeche
     * einstellbar (SystemSetting), Fallback ist der .env-Wert
     * META_ADS_MAX_DAILY_BUDGET (Default 100 EUR).
     */
    public static function maxDailyBudgetEur(): int
    {
        $stored = SystemSetting::get('meta_ads_max_daily_budget');
        $eur = is_numeric($stored) && (int) $stored >= 1
            ? (int) $stored
            : (int) config('services.meta.ads_max_daily_budget', 100);

        return min(max(1, $eur), self::CAP_CEILING_EUR);
    }

    private function account(): string
    {
        $id = (string) config('services.meta.ad_account_id');

        return str_starts_with($id, 'act_') ? $id : 'act_'.$id;
    }

    /**
     * Kampagnen (nicht geloeschte) inkl. Leistungswerten.
     * @return array<int,array<string,mixed>>
     */
    public function listCampaigns(): array
    {
        $campaigns = $this->graph->get($this->account().'/campaigns', [
            'fields' => 'id,name,status,effective_status,daily_budget,stop_time,created_time,objective',
            'limit' => 50,
        ])['data'] ?? [];

        // Leistungswerte gesamt (seit Start) je Kampagne dazu mischen.
        $insights = [];
        try {
            $rows = $this->graph->get($this->account().'/insights', [
                'level' => 'campaign',
                'fields' => 'campaign_id,spend,impressions,clicks,cpc',
                'date_preset' => 'maximum',
                'limit' => 100,
            ])['data'] ?? [];
            foreach ($rows as $row) {
                $insights[$row['campaign_id'] ?? ''] = $row;
            }
        } catch (\Throwable $e) {
            // Ohne Insights bleibt die Liste nutzbar (Status/Budget/Aktionen).
        }

        return array_map(function ($c) use ($insights) {
            $i = $insights[$c['id']] ?? [];

            return [
                'id' => $c['id'],
                'name' => $c['name'] ?? '',
                'status' => $c['status'] ?? '',
                'effective_status' => $c['effective_status'] ?? ($c['status'] ?? ''),
                'objective' => $c['objective'] ?? '',
                'daily_budget_eur' => isset($c['daily_budget']) ? ((int) $c['daily_budget']) / 100 : null,
                'stop_time' => $c['stop_time'] ?? null,
                'created_time' => $c['created_time'] ?? null,
                'spend_eur' => isset($i['spend']) ? (float) $i['spend'] : 0.0,
                'impressions' => (int) ($i['impressions'] ?? 0),
                'clicks' => (int) ($i['clicks'] ?? 0),
                'cpc_eur' => isset($i['cpc']) ? (float) $i['cpc'] : null,
            ];
        }, $campaigns);
    }

    /** Starten oder pausieren. */
    public function setStatus(string $campaignId, string $status): void
    {
        if (! in_array($status, ['ACTIVE', 'PAUSED'], true)) {
            throw new \RuntimeException('Ungültiger Status.');
        }
        $this->graph->post($campaignId, ['status' => $status]);
    }

    /** Tagesbudget aendern (EUR, gedeckelt). */
    public function updateDailyBudget(string $campaignId, float $eur): void
    {
        $this->assertBudget($eur);
        $this->graph->post($campaignId, ['daily_budget' => (int) round($eur * 100)]);
    }

    /** Kampagne endgueltig loeschen. */
    public function deleteCampaign(string $campaignId): void
    {
        $this->graph->delete($campaignId);
    }

    /**
     * "Banner bewerben": Kampagne + Anzeigengruppe + Anzeige aus dem
     * veroeffentlichten Facebook-Beitrag des Banners - PAUSIERT erstellt.
     *
     * @param array{objective:string,daily_budget_eur:float,age_min:int,age_max:int,language:string,end_date:?string} $opts
     * @return string Kampagnen-ID
     */
    public function createPromotion(Banner $banner, array $opts): string
    {
        $channel = $banner->socialPost?->channelFor('facebook');
        $storyId = $channel?->external_post_id;
        if (! $storyId) {
            throw new \RuntimeException('Dieser Banner hat noch keinen per API veröffentlichten Facebook-Beitrag - zuerst posten, dann bewerben.');
        }
        $this->assertBudget($opts['daily_budget_eur']);

        $klicks = ($opts['objective'] ?? 'klicks') === 'klicks';
        $name = 'Banner: '.mb_substr($banner->title, 0, 60);

        // 1) Kampagne (CBO: Budget liegt an der Kampagne), PAUSIERT.
        // special_ad_categories: Versicherung/Energie fallen in der EU nicht
        // unter Metas Sonderkategorien (Kredit/Jobs/Wohnen/Politik).
        // Bei Kampagnen-Budget (CBO) gehoert die bid_strategy an die
        // KAMPAGNE - ein Adset mit eigener bid_strategy lehnt die
        // Marketing API dann ab (Pre-Merge-Review).
        $campaignId = (string) $this->graph->post($this->account().'/campaigns', [
            'name' => $name,
            'objective' => $klicks ? 'OUTCOME_TRAFFIC' : 'OUTCOME_AWARENESS',
            'status' => 'PAUSED',
            'daily_budget' => (int) round($opts['daily_budget_eur'] * 100),
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'special_ad_categories' => '[]',
        ])['id'];

        try {
            $targeting = [
                'geo_locations' => ['countries' => ['DE']],
                'age_min' => $opts['age_min'],
                'age_max' => $opts['age_max'],
            ];
            $locale = $this->localeId($opts['language'] ?? 'alle');
            if ($locale) {
                $targeting['locales'] = [$locale];
            }

            $adsetParams = [
                'name' => $name,
                'campaign_id' => $campaignId,
                'billing_event' => 'IMPRESSIONS',
                'optimization_goal' => $klicks ? 'LINK_CLICKS' : 'REACH',
                'targeting' => json_encode($targeting),
                'status' => 'PAUSED',
                'start_time' => now()->toIso8601String(),
            ];
            if (! empty($opts['end_date'])) {
                $adsetParams['end_time'] = Carbon::parse($opts['end_date'])->endOfDay()->toIso8601String();
            }
            $adsetId = (string) $this->graph->post($this->account().'/adsets', $adsetParams)['id'];

            $creativeId = (string) $this->graph->post($this->account().'/adcreatives', [
                'name' => $name,
                'object_story_id' => $storyId,
            ])['id'];

            $this->graph->post($this->account().'/ads', [
                'name' => $name,
                'adset_id' => $adsetId,
                'creative' => json_encode(['creative_id' => $creativeId]),
                'status' => 'PAUSED',
            ]);
        } catch (\Throwable $e) {
            // Halbfertige Kampagne nicht stehen lassen (waere verwirrend) -
            // Aufraeumen ist best effort, der urspruengliche Fehler zaehlt.
            try {
                $this->graph->delete($campaignId);
            } catch (\Throwable $cleanup) {
            }
            throw $e;
        }

        return $campaignId;
    }

    /** Harte Budgetgrenze - echtes Geld. */
    private function assertBudget(float $eur): void
    {
        $max = self::maxDailyBudgetEur();
        if ($eur < 1 || $eur > $max) {
            throw new \RuntimeException('Tagesbudget muss zwischen 1 und '.$max.' EUR liegen - die Schutzgrenze ändert der Admin unten auf der Seite Werbeanzeigen.');
        }
    }

    /**
     * Sprach-Targeting-ID von Meta aufloesen (nie raten - IDs sind keine
     * Sprachcodes). Ohne Treffer wird breiter ausgeliefert statt falsch.
     */
    private function localeId(string $language): ?int
    {
        $query = match ($language) {
            'de' => 'German',
            'ar' => 'Arabic',
            default => null,
        };
        if ($query === null) {
            return null;
        }

        return Cache::remember('meta_adlocale_'.$language, now()->addWeek(), function () use ($query) {
            try {
                $data = $this->graph->get('search', ['type' => 'adlocale', 'q' => $query])['data'] ?? [];
                $key = $data[0]['key'] ?? null;

                return $key !== null ? (int) $key : null;
            } catch (\Throwable $e) {
                return null;
            }
        });
    }
}
