<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\BannerUserView;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BannerManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function makeBanner(array $attrs = []): Banner
    {
        return Banner::create(array_merge([
            'title' => 'Test-Banner',
            'media_path' => 'banners/test.webp',
            'media_type' => 'image',
            'is_active' => true,
            'sort_order' => 0,
        ], $attrs));
    }

    private function makePortalCustomer(string $email = 'kunde@k.de'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => $email]);
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'C-' . strtoupper(substr(md5($email), 0, 8))]);
    }

    // ---------------- Status ----------------

    public function test_status_states_are_computed_correctly(): void
    {
        $this->assertSame('active', $this->makeBanner()->statusInfo()['key']);
        $this->assertSame('draft', $this->makeBanner(['is_draft' => true])->statusInfo()['key']);
        $this->assertSame('disabled', $this->makeBanner(['is_active' => false])->statusInfo()['key']);
        $this->assertSame('scheduled', $this->makeBanner(['start_date' => now()->addDays(3)])->statusInfo()['key']);
        $this->assertSame('expired', $this->makeBanner(['end_date' => now()->subDay()])->statusInfo()['key']);
    }

    public function test_scheduling_is_automatic_via_current_scope(): void
    {
        $this->makeBanner(['title' => 'Läuft', 'start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
        $this->makeBanner(['title' => 'Zukunft', 'start_date' => now()->addDays(5)]);
        $this->makeBanner(['title' => 'Vorbei', 'end_date' => now()->subDay()]);
        $this->makeBanner(['title' => 'Entwurf', 'is_draft' => true]);

        $this->assertSame(['Läuft'], Banner::current()->pluck('title')->all());
    }

    // ---------------- Statistiken ----------------

    public function test_dashboard_records_impressions_and_unique_viewers(): void
    {
        $banner = $this->makeBanner();
        $c1 = $this->makePortalCustomer('a@k.de');
        $c2 = $this->makePortalCustomer('b@k.de');

        $this->actingAs($c1->user)->get(route('portal.dashboard'))->assertOk();
        $this->actingAs($c1->user)->get(route('portal.dashboard'))->assertOk();
        $this->actingAs($c2->user)->get(route('portal.dashboard'))->assertOk();

        $banner->refresh();
        $this->assertSame(3, (int) $banner->total_impressions);
        $this->assertSame(3, $banner->impressionsSince(1)); // heute
        $this->assertSame(2, $banner->uniqueViewers());
        $this->assertNotNull($banner->last_shown_at);
    }

    public function test_click_route_records_click_and_redirects_to_link(): void
    {
        $banner = $this->makeBanner(['link_url' => 'https://beispiel.de/aktion', 'link_target' => 'blank']);
        $customer = $this->makePortalCustomer();

        $this->actingAs($customer->user)->get(route('portal.banner.click', $banner->id))
            ->assertRedirect('https://beispiel.de/aktion');

        $banner->refresh();
        $this->assertSame(1, (int) $banner->total_clicks);
        $this->assertSame(50.0, $this->clickCtr($banner)); // 0 imp? -> siehe Helper
    }

    // Re-Audit SEC-7: protokoll-relative //fremdhost darf NICHT extern
    // umgeleitet werden (Open-Redirect), sondern als interner Pfad landen.
    public function test_click_route_blocks_protocol_relative_open_redirect(): void
    {
        $banner = $this->makeBanner(['link_url' => '//evil.example.com/phish']);
        $customer = $this->makePortalCustomer();

        $res = $this->actingAs($customer->user)->get(route('portal.banner.click', $banner->id));
        $location = $res->headers->get('Location');
        // Nicht auf den fremden Host, sondern intern (eigener Host / Pfad).
        $this->assertStringNotContainsString('//evil.example.com', (string) $location);
    }

    /** CTR-Helfer: erzeugt eine Impression falls nötig, prüft Berechnung. */
    private function clickCtr(Banner $banner): float
    {
        if ($banner->total_impressions === 0) {
            $banner->recordImpression();
            $banner->recordImpression();
            $banner->refresh();
        }
        return $banner->ctr();
    }

    public function test_ctr_calculation(): void
    {
        $banner = $this->makeBanner();
        $banner->recordImpression();
        $banner->recordImpression();
        $banner->recordImpression();
        $banner->recordImpression();
        $banner->recordClick();
        $this->assertSame(25.0, $banner->refresh()->ctr());
    }

    public function test_admin_can_reset_stats(): void
    {
        $banner = $this->makeBanner();
        $banner->recordImpression('user-1');
        $banner->recordClick('user-1');

        $this->actingAs($this->admin)->post(route('admin.banners.reset_stats', $banner->id))
            ->assertRedirect();

        $banner->refresh();
        $this->assertSame(0, (int) $banner->total_impressions);
        $this->assertSame(0, (int) $banner->total_clicks);
        $this->assertSame(0, $banner->impressionsSince(30));
    }

    // ---------------- Dismiss (Schließen) ----------------

    public function test_dismissed_banner_is_hidden_until_period_ends(): void
    {
        $banner = $this->makeBanner(['dismiss_days' => 7]);
        $customer = $this->makePortalCustomer();

        $this->actingAs($customer->user)->post(route('portal.banner.dismiss', $banner->id))
            ->assertOk();

        // Banner erscheint nicht mehr im Dashboard
        $response = $this->actingAs($customer->user)->get(route('portal.dashboard'));
        $this->assertTrue($response->viewData('banners')->isEmpty());

        // Nach Ablauf der Frist wieder sichtbar
        BannerUserView::where('banner_id', $banner->id)->update(['dismissed_until' => now()->subMinute()]);
        $response = $this->actingAs($customer->user)->get(route('portal.dashboard'));
        $this->assertSame(1, $response->viewData('banners')->count());
    }

    // ---------------- Verwaltung ----------------

    public function test_admin_can_create_with_link_gif_and_any_size(): void
    {
        $file = UploadedFile::fake()->image('quadrat.gif', 1080, 1080); // beliebige Maße, GIF

        $this->actingAs($this->admin)->post(route('admin.banners.store'), [
            'title' => 'GIF-Banner',
            'media' => $file,
            'link_url' => '/portal/contracts',
            'link_target' => 'self',
            'dismiss_days' => 14,
        ])->assertRedirect();

        $banner = Banner::first();
        $this->assertSame('image', $banner->media_type);
        $this->assertSame('/portal/contracts', $banner->link_url);
        $this->assertSame(14, (int) $banner->dismiss_days);
        $this->assertSame((string) $this->admin->id, (string) $banner->created_by);
    }

    public function test_jpg_upload_is_converted_to_webp(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD/WebP nicht verfügbar.');
        }

        $file = UploadedFile::fake()->image('foto.jpg', 1200, 628);

        $this->actingAs($this->admin)->post(route('admin.banners.store'), [
            'title' => 'Foto', 'media' => $file,
        ])->assertRedirect();

        $this->assertStringEndsWith('.webp', Banner::first()->media_path);
    }

    public function test_admin_can_edit_banner_and_audit_records_editor(): void
    {
        $banner = $this->makeBanner();

        $this->actingAs($this->admin)->post(route('admin.banners.update', $banner->id), [
            'title' => 'Neuer Titel',
            'link_url' => 'https://neu.de',
            'link_target' => 'blank',
            'start_date' => now()->toDateString(),
        ])->assertRedirect();

        $banner->refresh();
        $this->assertSame('Neuer Titel', $banner->title);
        $this->assertSame('https://neu.de', $banner->link_url);
        $this->assertSame((string) $this->admin->id, (string) $banner->updated_by);
    }

    public function test_move_up_and_down_reorders(): void
    {
        $a = $this->makeBanner(['title' => 'A', 'sort_order' => 0]);
        $b = $this->makeBanner(['title' => 'B', 'sort_order' => 1]);

        $this->actingAs($this->admin)->post(route('admin.banners.move', $b->id), ['direction' => 'up'])
            ->assertRedirect();

        $ordered = Banner::orderBy('sort_order')->pluck('title')->all();
        $this->assertSame(['B', 'A'], $ordered);
    }

    // ---------------- Statistik-Dashboard ----------------

    public function test_stats_dashboard_shows_totals_charts_and_best_banner(): void
    {
        $top = $this->makeBanner(['title' => 'Top-Banner']);
        $low = $this->makeBanner(['title' => 'Low-Banner']);

        // Top: 4 Impressions, 2 Klicks (CTR 50 %) – Low: 4 Impressions, 0 Klicks
        foreach (range(1, 4) as $i) { $top->recordImpression('u' . $i); $low->recordImpression('u' . $i); }
        $top->recordClick();
        $top->recordClick();

        $response = $this->actingAs($this->admin)->get(route('admin.banners.stats'));
        $response->assertOk()
            ->assertSee('Banner-Statistik')
            ->assertSee('impressionsChart', false)
            ->assertSee('clicksChart', false)
            ->assertSee('Top-Banner');

        $this->assertSame(8, $response->viewData('totalImpressions'));
        $this->assertSame(2, $response->viewData('totalClicks'));
        $this->assertSame(25.0, $response->viewData('avgCtr'));
        $this->assertSame('Top-Banner', $response->viewData('best')->title);

        // 30-Tage-Reihen: 30 Punkte, heutiger Wert stimmt
        $impressionSeries = $response->viewData('impressions');
        $clickSeries = $response->viewData('clicks');
        $this->assertCount(30, $impressionSeries);
        $this->assertSame(8, end($impressionSeries));
        $this->assertSame(2, end($clickSeries));
    }

    public function test_stats_dashboard_is_admin_manager_only(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $this->actingAs($employee)->get(route('admin.banners.stats'))
            ->assertRedirect(route('admin.dashboard'));

        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->get(route('admin.banners.stats'))->assertOk();
    }

    public function test_employee_cannot_manage_banners(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $banner = $this->makeBanner();

        $this->actingAs($employee)->post(route('admin.banners.delete', $banner->id))
            ->assertRedirect(route('admin.dashboard'));
        $this->assertDatabaseHas('banners', ['id' => $banner->id]);
    }
}
