<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Banner;
use App\Models\BannerSocialPost;
use App\Models\User;
use App\Services\Social\MetaAdsService;
use App\Services\Social\MetaInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 3: Werbeanzeigen-Steuerung (Marketing API) + Kennzahlen von Meta.
 * Alle Graph-Aufrufe gefaked; Geld-Schutzregeln werden explizit geprueft.
 */
class MetaAdsManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'admin']);
        config(['services.meta' => [
            'page_id' => 'PAGE1',
            'ig_user_id' => 'IG1',
            'token' => 'TOK',
            'graph_version' => 'v23.0',
            'ad_account_id' => 'act_777',
            'ads_max_daily_budget' => 100,
        ]]);
    }

    /** Banner mit per API veroeffentlichtem Facebook-Beitrag. */
    private function makePromotedBanner(): Banner
    {
        $banner = Banner::create([
            'title' => 'Strom-Aktion',
            'media_path' => 'banners/x.png',
            'media_type' => 'image',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $post = BannerSocialPost::create(['banner_id' => $banner->id, 'caption_de' => 'Text']);
        $post->channels()->create([
            'platform' => 'facebook',
            'short_code' => 'fb-test01',
            'external_post_id' => 'PAGE1_222',
            'published_at' => now(),
        ]);

        return $banner;
    }

    // ---------------- Anzeige erstellen ----------------

    public function test_anzeige_wird_pausiert_und_mit_budget_in_cent_erstellt(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/search')) {
                return Http::response(['data' => [['key' => 28, 'name' => 'Arabic']]]);
            }
            if (str_contains($url, '/act_777/campaigns')) {
                return Http::response(['id' => 'C1']);
            }
            if (str_contains($url, '/act_777/adsets')) {
                return Http::response(['id' => 'AS1']);
            }
            if (str_contains($url, '/act_777/adcreatives')) {
                return Http::response(['id' => 'CR1']);
            }
            if (str_contains($url, '/act_777/ads')) {
                return Http::response(['id' => 'AD1']);
            }
            return Http::response(['error' => ['message' => 'unerwartet ' . $url]], 400);
        });
        $banner = $this->makePromotedBanner();

        $this->actingAs($this->admin)->post(route('admin.werbung.store', $banner->id), [
            'objective' => 'klicks',
            'daily_budget_eur' => 10,
            'age_min' => 20,
            'age_max' => 60,
            'language' => 'ar',
        ])->assertRedirect(route('admin.werbung'))->assertSessionHas('success');

        // Kampagne: PAUSIERT, Budget in CENT, richtiges Ziel.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/act_777/campaigns')
            && ($r['status'] ?? '') === 'PAUSED'
            && ($r['daily_budget'] ?? 0) == 1000
            && ($r['objective'] ?? '') === 'OUTCOME_TRAFFIC');
        // Anzeigengruppe: Deutschland + Alter + arabische Sprach-ID von Meta.
        Http::assertSent(function ($r) {
            if (!str_contains($r->url(), '/act_777/adsets')) {
                return false;
            }
            $t = $r['targeting'] ?? '';
            return str_contains($t, '"countries":["DE"]')
                && str_contains($t, '"age_min":20')
                && str_contains($t, '"locales":[28]')
                && ($r['status'] ?? '') === 'PAUSED';
        });
        // Anzeige nutzt den echten Beitrag (object_story_id).
        Http::assertSent(fn ($r) => str_contains($r->url(), '/act_777/adcreatives')
            && ($r['object_story_id'] ?? '') === 'PAGE1_222');

        $this->assertSame(1, ActivityLog::where('action', 'meta_ad_created')->count());
    }

    public function test_budget_ueber_der_schutzgrenze_wird_abgelehnt(): void
    {
        Http::fake();
        $banner = $this->makePromotedBanner();

        $this->actingAs($this->admin)->post(route('admin.werbung.store', $banner->id), [
            'objective' => 'klicks',
            'daily_budget_eur' => 500,
            'age_min' => 20,
            'age_max' => 60,
            'language' => 'alle',
        ])->assertSessionHasErrors('daily_budget_eur');

        Http::assertNothingSent();

        // Auch die Service-Schicht selbst blockt (Defense in depth).
        $this->expectException(\RuntimeException::class);
        app(MetaAdsService::class)->updateDailyBudget('C1', 500);
    }

    public function test_halbfertige_kampagne_wird_bei_fehler_aufgeraeumt(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/act_777/campaigns')) {
                return Http::response(['id' => 'C1']);
            }
            if (str_contains($url, '/act_777/adsets')) {
                return Http::response(['error' => ['message' => 'Targeting kaputt']], 400);
            }
            if ($request->method() === 'DELETE') {
                return Http::response(['success' => true]);
            }
            return Http::response(['error' => ['message' => 'unerwartet']], 400);
        });
        $banner = $this->makePromotedBanner();

        $this->actingAs($this->admin)->post(route('admin.werbung.store', $banner->id), [
            'objective' => 'klicks',
            'daily_budget_eur' => 10,
            'age_min' => 20,
            'age_max' => 60,
            'language' => 'alle',
        ])->assertSessionHasErrors('ads');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/C1'));
        $this->assertSame(0, ActivityLog::where('action', 'meta_ad_created')->count());
    }

    public function test_ohne_veroeffentlichten_beitrag_kein_bewerben(): void
    {
        Http::fake();
        $banner = Banner::create([
            'title' => 'Ohne Post', 'media_path' => 'banners/x.png',
            'media_type' => 'image', 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($this->admin)->get(route('admin.werbung.neu', $banner->id))
            ->assertRedirect(route('admin.banners.social', $banner->id));
        Http::assertNothingSent();
    }

    // ---------------- Steuern: Start/Pause/Budget/Loeschen ----------------

    public function test_starten_pausieren_budget_und_loeschen(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $this->actingAs($this->admin)->post(route('admin.werbung.status', '123'), ['action' => 'start'])
            ->assertSessionHas('success');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/123') && ($r['status'] ?? '') === 'ACTIVE');

        $this->actingAs($this->admin)->post(route('admin.werbung.status', '123'), ['action' => 'pause']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/123') && ($r['status'] ?? '') === 'PAUSED');

        $this->actingAs($this->admin)->post(route('admin.werbung.budget', '123'), ['daily_budget_eur' => 25]);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/123') && ($r['daily_budget'] ?? 0) == 2500);

        $this->actingAs($this->admin)->post(route('admin.werbung.delete', '123'))->assertSessionHas('success');
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/123'));

        foreach (['meta_ad_started', 'meta_ad_paused', 'meta_ad_budget_changed', 'meta_ad_deleted'] as $action) {
            $this->assertSame(1, ActivityLog::where('action', $action)->count(), $action);
        }
    }

    public function test_uebersicht_zeigt_kampagnen_mit_ausgaben(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/act_777/campaigns')) {
                return Http::response(['data' => [[
                    'id' => 'C1', 'name' => 'Banner: Strom-Aktion', 'status' => 'ACTIVE',
                    'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_TRAFFIC',
                    'daily_budget' => '1000',
                ]]]);
            }
            if (str_contains($url, '/act_777/insights')) {
                return Http::response(['data' => [[
                    'campaign_id' => 'C1', 'spend' => '12.34', 'impressions' => '5000',
                    'clicks' => '150', 'cpc' => '0.08',
                ]]]);
            }
            return Http::response(['error' => ['message' => 'unerwartet']], 400);
        });

        $this->actingAs($this->admin)->get(route('admin.werbung'))
            ->assertOk()
            ->assertSee('Banner: Strom-Aktion')
            ->assertSee('12,34')
            ->assertSee('Pausieren');
    }

    public function test_uebersicht_ohne_konfiguration_ohne_api_aufruf(): void
    {
        config(['services.meta.ad_account_id' => null]);
        Http::fake();

        $this->actingAs($this->admin)->get(route('admin.werbung'))
            ->assertOk()->assertSee('meta:einrichten');
        Http::assertNothingSent();
    }

    public function test_mitarbeiter_hat_keinen_zugriff(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $this->actingAs($employee)->get(route('admin.werbung'))
            ->assertRedirect(route('admin.dashboard'));
    }

    // ---------------- Kennzahlen (Insights) ----------------

    public function test_kennzahlen_werden_geholt_und_angezeigt(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/PAGE1_222/insights')) {
                return Http::response(['data' => [['values' => [['value' => 500]]]]]);
            }
            if (str_contains($url, '/PAGE1_222')) {
                return Http::response([
                    'reactions' => ['summary' => ['total_count' => 10]],
                    'comments' => ['summary' => ['total_count' => 2]],
                    'shares' => ['count' => 3],
                ]);
            }
            return Http::response(['error' => ['message' => 'unerwartet']], 400);
        });
        $banner = $this->makePromotedBanner();

        $this->actingAs($this->admin)->post(route('admin.banners.social.refresh_insights', $banner->id))
            ->assertSessionHas('success');

        $ch = $banner->socialPost->channels()->first()->fresh();
        $this->assertSame(['likes' => 10, 'comments' => 2, 'shares' => 3, 'reach' => 500], $ch->insights);
        $this->assertNotNull($ch->insights_refreshed_at);

        $this->actingAs($this->admin)->get(route('admin.banners.social', $banner->id))
            ->assertOk()->assertSee('500')->assertSee('erreicht');
    }

    public function test_command_aktualisiert_beitraege_und_seiten_ueberblick(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/PAGE1_222/insights')) {
                return Http::response(['data' => [['values' => [['value' => 700]]]]]);
            }
            if (str_contains($url, '/PAGE1_222')) {
                return Http::response(['reactions' => ['summary' => ['total_count' => 5]], 'comments' => ['summary' => ['total_count' => 1]]]);
            }
            if (str_contains($url, '/PAGE1/insights')) {
                return Http::response(['data' => [['values' => [['value' => 321]]]]]);
            }
            if (str_contains($url, '/PAGE1')) {
                return Http::response(['name' => 'Dienstly24', 'followers_count' => 1500, 'fan_count' => 1400]);
            }
            return Http::response(['error' => ['message' => 'unerwartet']], 400);
        });
        $banner = $this->makePromotedBanner();

        $this->artisan('social:refresh-insights')->assertSuccessful();

        $this->assertSame(5, $banner->socialPost->channels()->first()->fresh()->insights['likes']);
        $page = Cache::get(MetaInsightsService::PAGE_CACHE_KEY);
        $this->assertSame(1500, $page['followers']);
        $this->assertSame(321, $page['page_views_28d']);

        // Statistik-Dashboard zeigt den Seiten-Ueberblick aus dem Cache.
        $this->actingAs($this->admin)->get(route('admin.banners.stats'))
            ->assertOk()->assertSee('Meta-Seite: Dienstly24')->assertSee('1.500');
    }

    public function test_alte_beitraege_werden_nicht_mehr_abgefragt(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/PAGE1/insights')) {
                return Http::response(['data' => []]);
            }
            if (str_contains($request->url(), '/PAGE1')) {
                return Http::response(['name' => 'D24', 'followers_count' => 1, 'fan_count' => 1]);
            }
            return Http::response(['error' => ['message' => 'unerwartet']], 400);
        });
        $banner = $this->makePromotedBanner();
        $banner->socialPost->channels()->first()->forceFill(['published_at' => now()->subDays(90)])->save();

        $this->artisan('social:refresh-insights')->assertSuccessful();

        $this->assertNull($banner->socialPost->channels()->first()->fresh()->insights);
    }
}
