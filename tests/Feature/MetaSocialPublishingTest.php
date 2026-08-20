<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\BannerSocialChannel;
use App\Models\BannerSocialPost;
use App\Models\InternalNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Social-Publishing Phase 2 (Meta Graph API): Sofort-Posten per Button,
 * geplanter Auto-Versand (genau EIN Versuch), Fehlerbehandlung, Glocke.
 * Alle Graph-Aufrufe sind gefaked - es verlaesst nichts den Test.
 */
class MetaSocialPublishingTest extends TestCase
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
            'token' => 'TESTTOKEN',
            'page_token' => 'PTOK', // Seiten-Posts brauchen das PAGE-Token
            'graph_version' => 'v23.0',
        ]]);
    }

    /** Banner + gespeicherter Social-Post (fb+ig) mit echten Bildformaten. */
    private function makePostedBanner(array $postOverrides = []): Banner
    {
        $img = imagecreatetruecolor(1200, 1200);
        imagefill($img, 0, 0, imagecolorallocate($img, 23, 166, 91));
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);
        Storage::disk('public')->put('banners/meta-test.png', $png);

        $banner = Banner::create([
            'title' => 'Meta-Test-Banner',
            'media_path' => 'banners/meta-test.png',
            'media_type' => 'image',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->admin)->post(route('admin.banners.social.save', $banner->id), array_merge([
            'caption_de' => 'Jetzt wechseln und sparen!',
            'caption_ar' => 'بدّل ووفر الآن',
            'target_url' => 'https://www.dienstly24.de/leistungen/strom',
            'platforms' => ['facebook', 'instagram', 'tiktok'],
        ], $postOverrides));

        return $banner;
    }

    /** Standard-Fake fuer alle Graph-Endpunkte (Erfolgsfall). */
    private function fakeGraphOk(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/PAGE1/photos')) {
                return Http::response(['id' => '111', 'post_id' => 'PAGE1_222']);
            }
            if (str_contains($url, '/IG1/media_publish')) {
                return Http::response(['id' => 'M900']);
            }
            if (str_contains($url, '/IG1/media')) {
                return Http::response(['id' => 'C500']);
            }
            if (str_contains($url, '/C500')) {
                return Http::response(['id' => 'C500', 'status_code' => 'FINISHED']);
            }
            if (str_contains($url, '/M900')) {
                return Http::response(['id' => 'M900', 'permalink' => 'https://www.instagram.com/p/abc123/']);
            }
            return Http::response(['error' => ['message' => 'Unerwarteter Aufruf: ' . $url]], 400);
        });
    }

    // ---------------- Sofort-Posten (Button) ----------------

    public function test_facebook_sofort_posten_erstellt_beitrag(): void
    {
        $this->fakeGraphOk();
        $banner = $this->makePostedBanner();

        $this->actingAs($this->admin)
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']))
            ->assertRedirect(route('admin.banners.social', $banner->id))
            ->assertSessionHas('success');

        $ch = BannerSocialChannel::where('platform', 'facebook')->first();
        $this->assertSame('PAGE1_222', $ch->external_post_id);
        $this->assertSame('https://www.facebook.com/PAGE1_222', $ch->external_url);
        $this->assertNotNull($ch->published_at);
        $this->assertSame((string) $this->admin->id, (string) $ch->published_by);
        $this->assertNull($ch->publish_error);

        // Beitragstext: DE + AR + Tracking-Link; Bild-URL zeigt aufs 1:1-Format.
        Http::assertSent(function ($request) use ($ch) {
            return str_contains($request->url(), '/PAGE1/photos')
                && str_contains($request['message'] ?? '', 'Jetzt wechseln und sparen!')
                && str_contains($request['message'] ?? '', 'بدّل ووفر الآن')
                && str_contains($request['message'] ?? '', $ch->shortUrl())
                && str_contains($request['url'] ?? '', 'banners/social/');
        });
    }

    public function test_instagram_sofort_posten_container_flow(): void
    {
        $this->fakeGraphOk();
        $banner = $this->makePostedBanner();

        $this->actingAs($this->admin)
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'instagram']))
            ->assertSessionHas('success');

        $ch = BannerSocialChannel::where('platform', 'instagram')->first();
        $this->assertSame('M900', $ch->external_post_id);
        $this->assertSame('https://www.instagram.com/p/abc123/', $ch->external_url);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/IG1/media') && !str_contains($r->url(), 'media_publish'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/IG1/media_publish') && ($r['creation_id'] ?? '') === 'C500');
    }

    public function test_api_fehler_wird_am_kanal_gespeichert(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => '(#200) Permissions error']], 400)]);
        $banner = $this->makePostedBanner();

        // Der Versand laeuft seit dem Umbau im Hintergrund: ein Fehler, der
        // erst beim API-Aufruf entsteht, kann die Seite gar nicht mehr
        // erreichen. Entscheidend bleibt, dass er FESTGEHALTEN wird und
        // nichts veroeffentlicht wurde.
        $this->actingAs($this->admin)
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']));

        $ch = BannerSocialChannel::where('platform', 'facebook')->first();
        $this->assertNull($ch->external_post_id);
        $this->assertNull($ch->published_at);
        $this->assertStringContainsString('(#200) Permissions error', $ch->publish_error);
        // Marker geloest -> ein bewusster zweiter Versuch ist moeglich.
        $this->assertNull($ch->publish_started_at);
    }

    public function test_ohne_konfiguration_kein_api_aufruf(): void
    {
        config(['services.meta.token' => null]);
        Http::fake();
        $banner = $this->makePostedBanner();

        $this->actingAs($this->admin)
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']))
            ->assertSessionHasErrors('publish');

        Http::assertNothingSent();
    }

    public function test_tiktok_hat_kein_api_posten(): void
    {
        Http::fake();
        $banner = $this->makePostedBanner();

        $this->actingAs($this->admin)
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'tiktok']))
            ->assertSessionHasErrors('publish');

        Http::assertNothingSent();
    }

    public function test_bereits_erstellter_beitrag_wird_nie_doppelt_gepostet(): void
    {
        $this->fakeGraphOk();
        $banner = $this->makePostedBanner();
        $this->actingAs($this->admin)->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']));

        $this->actingAs($this->admin)->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']));

        Http::assertSentCount(1);
    }

    public function test_instagram_zu_langer_text_wird_abgelehnt_statt_gekuerzt(): void
    {
        Http::fake();
        $banner = $this->makePostedBanner(['caption_de' => str_repeat('Sparen! ', 300)]);

        $this->actingAs($this->admin)
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'instagram']))
            ->assertSessionHasErrors('publish');

        Http::assertNothingSent();
        $this->assertStringContainsString('zu lang', BannerSocialChannel::where('platform', 'instagram')->first()->publish_error);
    }

    public function test_facebook_ohne_page_token_leitet_es_zur_laufzeit_ab(): void
    {
        config(['services.meta.page_token' => null]);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/PAGE1/photos')) {
                return Http::response(['id' => '111', 'post_id' => 'PAGE1_222']);
            }
            if (str_contains($url, '/PAGE1')) {
                return Http::response(['id' => 'PAGE1', 'access_token' => 'ABGELEITET']);
            }
            return Http::response(['error' => ['message' => 'unerwartet']], 400);
        });
        $banner = $this->makePostedBanner(['platforms' => ['facebook']]);

        $this->actingAs($this->admin)
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']))
            ->assertSessionHas('success');

        // Erst das Seiten-Token holen, dann posten.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fields=access_token'));
        $this->assertSame('PAGE1_222', BannerSocialChannel::first()->external_post_id);
    }

    public function test_instagram_container_fehler_wird_verstaendlich_gemeldet(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/IG1/media')) {
                return Http::response(['id' => 'C500']);
            }
            if (str_contains($url, '/C500')) {
                return Http::response(['id' => 'C500', 'status_code' => 'ERROR']);
            }
            return Http::response(['error' => ['message' => 'unerwartet']], 400);
        });
        $banner = $this->makePostedBanner(['platforms' => ['instagram']]);

        // Auch dieser Fehler entsteht erst beim API-Aufruf, also im
        // Hintergrund-Job - er steht am Kanal, nicht in der Session.
        $this->actingAs($this->admin)
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'instagram']));

        $this->assertStringContainsString('verarbeiten', BannerSocialChannel::first()->publish_error);
        $this->assertNull(BannerSocialChannel::first()->external_post_id);
        $this->assertNull(BannerSocialChannel::first()->publish_started_at);
    }

    // ---------------- Geplanter Auto-Versand ----------------

    public function test_speichern_setzt_und_entfernt_den_zeitplan(): void
    {
        $banner = $this->makePostedBanner([
            'auto_publish' => 1,
            'scheduled_for' => now(BannerSocialPost::OPERATOR_TZ)->addDay()->format('Y-m-d\TH:i'),
        ]);
        $post = BannerSocialPost::where('banner_id', $banner->id)->first();
        $this->assertNotNull($post->scheduled_for);

        // Haekchen weg -> Zeitplan weg.
        $this->actingAs($this->admin)->post(route('admin.banners.social.save', $banner->id), [
            'caption_de' => 'x', 'platforms' => ['facebook'],
        ]);
        $this->assertNull($post->fresh()->scheduled_for);
    }

    public function test_auto_publish_ohne_zeitpunkt_wird_abgelehnt(): void
    {
        $banner = $this->makePostedBanner();
        $this->actingAs($this->admin)->post(route('admin.banners.social.save', $banner->id), [
            'caption_de' => 'x', 'platforms' => ['facebook'], 'auto_publish' => 1,
        ])->assertSessionHasErrors('scheduled_for');
    }

    public function test_zeitplan_wird_als_deutsche_zeit_interpretiert(): void
    {
        // Winterdatum (UTC+1): 09:00 deutsche Zeit = 08:00 UTC im Speicher.
        $banner = $this->makePostedBanner([
            'auto_publish' => 1,
            'scheduled_for' => '2027-01-15T09:00',
        ]);

        $post = BannerSocialPost::where('banner_id', $banner->id)->first();
        $this->assertSame('2027-01-15 08:00:00', $post->scheduled_for->toDateTimeString());

        // Die Oberflaeche zeigt wieder deutsche Zeit an.
        $this->actingAs($this->admin)->get(route('admin.banners.social', $banner->id))
            ->assertOk()->assertSee('2027-01-15T09:00');
    }

    public function test_vergangener_zeitpunkt_wird_abgelehnt(): void
    {
        $banner = $this->makePostedBanner();

        $this->actingAs($this->admin)->post(route('admin.banners.social.save', $banner->id), [
            'caption_de' => 'x', 'platforms' => ['facebook'],
            'auto_publish' => 1,
            'scheduled_for' => now(\App\Models\BannerSocialPost::OPERATOR_TZ)->subMinutes(30)->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('scheduled_for');

        $this->assertNull(BannerSocialPost::where('banner_id', $banner->id)->first()->scheduled_for);
    }

    public function test_planer_veroeffentlicht_faellige_posts_und_meldet_die_glocke(): void
    {
        $this->fakeGraphOk();
        $banner = $this->makePostedBanner([
            'auto_publish' => 1,
            'scheduled_for' => now(BannerSocialPost::OPERATOR_TZ)->addHour()->format('Y-m-d\TH:i'),
        ]);
        BannerSocialPost::query()->update(['scheduled_for' => now()->subMinutes(10)]);

        $this->artisan('social:publish-scheduled')->assertSuccessful();

        $fb = BannerSocialChannel::where('platform', 'facebook')->first();
        $ig = BannerSocialChannel::where('platform', 'instagram')->first();
        $tt = BannerSocialChannel::where('platform', 'tiktok')->first();
        $this->assertSame('PAGE1_222', $fb->external_post_id);
        $this->assertSame('M900', $ig->external_post_id);
        $this->assertNull($fb->published_by); // automatisch = System
        $this->assertNull($tt->external_post_id); // TikTok nie per API
        $this->assertNotNull($fb->auto_attempted_at);

        // Glocke an den Ersteller (je Plattform eine Erfolgsmeldung).
        $this->assertSame(2, InternalNotification::where('user_id', $this->admin->id)
            ->where('title', 'like', '%automatisch veroeffentlicht%')->count());

        // Zweiter Lauf: nichts mehr faellig, keine weiteren API-Aufrufe.
        $sent = count(Http::recorded());
        $this->artisan('social:publish-scheduled')->assertSuccessful();
        $this->assertCount($sent, Http::recorded());
    }

    public function test_planer_versucht_bei_fehler_genau_einmal(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Kaputt']], 500)]);
        $this->makePostedBanner([
            'platforms' => ['facebook'],
            'auto_publish' => 1,
            'scheduled_for' => now(BannerSocialPost::OPERATOR_TZ)->addHour()->format('Y-m-d\TH:i'),
        ]);
        BannerSocialPost::query()->update(['scheduled_for' => now()->subMinute()]);

        $this->artisan('social:publish-scheduled')->assertSuccessful();

        $ch = BannerSocialChannel::first();
        $this->assertNotNull($ch->auto_attempted_at);
        $this->assertStringContainsString('Kaputt', $ch->publish_error);
        $this->assertSame(1, InternalNotification::where('title', 'like', '%fehlgeschlagen%')->count());

        // Kein zweiter Auto-Versuch (nie-doppelt-posten).
        $sent = count(Http::recorded());
        $this->artisan('social:publish-scheduled')->assertSuccessful();
        $this->assertCount($sent, Http::recorded());
    }

    public function test_neuer_zeitplan_gibt_fehlgeschlagenem_kanal_einen_neuen_versuch(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Kaputt']], 500)]);
        $banner = $this->makePostedBanner([
            'platforms' => ['facebook'],
            'auto_publish' => 1,
            'scheduled_for' => now(BannerSocialPost::OPERATOR_TZ)->addHour()->format('Y-m-d\TH:i'),
        ]);
        BannerSocialPost::query()->update(['scheduled_for' => now()->subMinute()]);
        $this->artisan('social:publish-scheduled');
        $this->assertNotNull(BannerSocialChannel::first()->auto_attempted_at);

        // Betreiber plant neu -> Versuch und Fehler werden zurueckgesetzt.
        $this->actingAs($this->admin)->post(route('admin.banners.social.save', $banner->id), [
            'caption_de' => 'Jetzt wechseln!', 'platforms' => ['facebook'],
            'auto_publish' => 1, 'scheduled_for' => now(BannerSocialPost::OPERATOR_TZ)->addHour()->format('Y-m-d\TH:i'),
        ]);

        $ch = BannerSocialChannel::first();
        $this->assertNull($ch->auto_attempted_at);
        $this->assertNull($ch->publish_error);
    }

    public function test_manuell_markierter_kanal_blockt_sofort_posten(): void
    {
        Http::fake();
        $banner = $this->makePostedBanner(['platforms' => ['facebook']]);
        $this->actingAs($this->admin)->post(route('admin.banners.social.published', [$banner->id, 'facebook']));

        $this->actingAs($this->admin)
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']))
            ->assertSessionHasErrors('publish');

        Http::assertNothingSent();
    }

    public function test_manuell_als_veroeffentlicht_markierte_kanaele_bleiben_unangetastet(): void
    {
        $this->fakeGraphOk();
        $banner = $this->makePostedBanner([
            'platforms' => ['facebook'],
            'auto_publish' => 1,
            'scheduled_for' => now(BannerSocialPost::OPERATOR_TZ)->addHour()->format('Y-m-d\TH:i'),
        ]);
        BannerSocialPost::query()->update(['scheduled_for' => now()->subMinute()]);
        $this->actingAs($this->admin)->post(route('admin.banners.social.published', [$banner->id, 'facebook']));

        $this->artisan('social:publish-scheduled')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull(BannerSocialChannel::first()->external_post_id);
    }
}
