<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Banner;
use App\Models\SystemSetting;
use App\Services\Social\MetaAdsService;
use App\Services\Social\MetaInsightsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Werbeanzeigen-Steuerung aus dem System (Phase 3, Meta Marketing API):
 * Uebersicht aller Kampagnen mit Ausgaben/Klicks, Starten/Pausieren,
 * Tagesbudget aendern, Loeschen und "Banner bewerben" (aus dem bereits
 * veroeffentlichten Facebook-Beitrag). Nur admin/manager. Jede Aktion,
 * die Geld betrifft, steht im ActivityLog.
 */
class MetaAdsController extends Controller
{
    public function index(MetaAdsService $ads)
    {
        $configured = MetaAdsService::configured();
        $campaigns = [];
        $apiError = null;
        if ($configured) {
            try {
                $campaigns = $ads->listCampaigns();
            } catch (\Throwable $e) {
                $apiError = $e->getMessage();
            }
        }

        return view('admin.social_ads', [
            'configured' => $configured,
            'campaigns' => $campaigns,
            'apiError' => $apiError,
            'pageInsights' => Cache::get(MetaInsightsService::PAGE_CACHE_KEY),
            'maxBudget' => MetaAdsService::maxDailyBudgetEur(),
        ]);
    }

    /** Formular "Banner bewerben". */
    public function create(Banner $banner)
    {
        $fbChannel = $banner->socialPost?->channelFor('facebook');
        if (! MetaAdsService::configured()) {
            return redirect()->route('admin.werbung')
                ->withErrors(['ads' => 'Meta-API/Werbekonto nicht verbunden - einmalig auf dem Server php artisan meta:einrichten ausführen.']);
        }
        if (! $fbChannel?->external_post_id) {
            return redirect()->route('admin.banners.social', $banner->id)
                ->withErrors(['publish' => 'Zuerst den Facebook-Beitrag per API veröffentlichen - der wird dann beworben.']);
        }

        return view('admin.social_ad_create', [
            'banner' => $banner,
            'maxBudget' => MetaAdsService::maxDailyBudgetEur(),
        ]);
    }

    public function store(Request $request, Banner $banner, MetaAdsService $ads)
    {
        $data = $request->validate([
            'objective' => 'required|in:klicks,reichweite',
            'daily_budget_eur' => 'required|numeric|min:1|max:'.MetaAdsService::maxDailyBudgetEur(),
            'age_min' => 'required|integer|min:18|max:65',
            'age_max' => 'required|integer|min:18|max:65|gte:age_min',
            'language' => 'required|in:alle,de,ar',
            'end_date' => 'nullable|date|after:today',
        ], $this->germanMoneyMessages());

        try {
            $campaignId = $ads->createPromotion($banner, [
                'objective' => $data['objective'],
                'daily_budget_eur' => (float) $data['daily_budget_eur'],
                'age_min' => (int) $data['age_min'],
                'age_max' => (int) $data['age_max'],
                'language' => $data['language'],
                'end_date' => $data['end_date'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['ads' => $e->getMessage()])->withInput();
        }

        $this->log('meta_ad_created', $campaignId, [
            'banner' => $banner->title,
            'budget_eur' => $data['daily_budget_eur'],
            'objective' => $data['objective'],
        ]);

        return redirect()->route('admin.werbung')->with('success',
            'Anzeige erstellt - PAUSIERT. Prüfen und dann mit „Starten" live schalten.');
    }

    /** Starten/Pausieren. */
    public function status(Request $request, string $campaignId, MetaAdsService $ads)
    {
        $action = $request->validate(['action' => 'required|in:start,pause'])['action'];

        try {
            $ads->setStatus($campaignId, $action === 'start' ? 'ACTIVE' : 'PAUSED');
        } catch (\Throwable $e) {
            return back()->withErrors(['ads' => $e->getMessage()]);
        }
        $this->log('meta_ad_'.($action === 'start' ? 'started' : 'paused'), $campaignId, []);

        return back()->with('success', $action === 'start' ? 'Anzeige gestartet.' : 'Anzeige pausiert.');
    }

    /** Tagesbudget aendern. */
    public function budget(Request $request, string $campaignId, MetaAdsService $ads)
    {
        $eur = (float) $request->validate([
            'daily_budget_eur' => 'required|numeric|min:1|max:'.MetaAdsService::maxDailyBudgetEur(),
        ], $this->germanMoneyMessages())['daily_budget_eur'];

        try {
            $ads->updateDailyBudget($campaignId, $eur);
        } catch (\Throwable $e) {
            return back()->withErrors(['ads' => $e->getMessage()]);
        }
        $this->log('meta_ad_budget_changed', $campaignId, ['budget_eur' => $eur]);

        return back()->with('success', 'Tagesbudget auf '.number_format($eur, 2, ',', '.').' EUR geändert.');
    }

    /**
     * Schutzgrenze (max. Tagesbudget) aendern - NUR Admin (Route-Middleware):
     * die Grenze schuetzt vor Tippfehlern mit echtem Geld, deshalb liegt sie
     * eine Rolle hoeher als das Anlegen der Anzeigen selbst.
     */
    public function updateCap(Request $request)
    {
        $eur = (int) $request->validate([
            'max_daily_budget_eur' => 'required|integer|min:1|max:'.MetaAdsService::CAP_CEILING_EUR,
        ], [
            'max_daily_budget_eur.required' => 'Bitte eine Schutzgrenze in EUR angeben.',
            'max_daily_budget_eur.integer' => 'Die Schutzgrenze muss eine ganze Zahl in EUR sein.',
            'max_daily_budget_eur.min' => 'Die Schutzgrenze muss mindestens 1 EUR betragen.',
            'max_daily_budget_eur.max' => 'Die Schutzgrenze darf höchstens '.MetaAdsService::CAP_CEILING_EUR.' EUR betragen.',
        ])['max_daily_budget_eur'];

        $alt = MetaAdsService::maxDailyBudgetEur();
        SystemSetting::set('meta_ads_max_daily_budget', (string) $eur);
        $this->log('meta_ads_cap_changed', 'schutzgrenze', ['alt_eur' => $alt, 'neu_eur' => $eur]);

        return redirect()->route('admin.werbung')
            ->with('success', 'Schutzgrenze auf '.$eur.' EUR pro Tag geändert.');
    }

    public function destroy(string $campaignId, MetaAdsService $ads)
    {
        try {
            $ads->deleteCampaign($campaignId);
        } catch (\Throwable $e) {
            return back()->withErrors(['ads' => $e->getMessage()]);
        }
        $this->log('meta_ad_deleted', $campaignId, []);

        return back()->with('success', 'Anzeige gelöscht.');
    }

    /** Deutsche Fehlertexte fuer die Geld-Formulare (kein lang/de im Projekt). */
    private function germanMoneyMessages(): array
    {
        $max = MetaAdsService::maxDailyBudgetEur();

        return [
            'daily_budget_eur.required' => 'Bitte ein Tagesbudget in EUR angeben.',
            'daily_budget_eur.numeric' => 'Das Tagesbudget muss eine Zahl in EUR sein.',
            'daily_budget_eur.min' => 'Das Tagesbudget muss mindestens 1 EUR betragen.',
            'daily_budget_eur.max' => 'Das Tagesbudget darf höchstens '.$max.' EUR betragen (Schutzgrenze - änderbar durch den Admin unten auf der Seite Werbeanzeigen).',
            'age_min.*' => 'Bitte ein Alter zwischen 18 und 65 wählen.',
            'age_max.*' => 'Bitte ein Alter zwischen 18 und 65 wählen ("bis" nicht kleiner als "von").',
            'end_date.after' => 'Das Enddatum muss in der Zukunft liegen.',
            'end_date.date' => 'Bitte ein gültiges Enddatum wählen.',
        ];
    }

    private function log(string $action, string $campaignId, array $meta): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => 'meta_campaign',
            'entity_id' => $campaignId,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
