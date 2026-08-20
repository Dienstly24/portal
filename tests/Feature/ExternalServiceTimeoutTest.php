<?php

namespace Tests\Feature;

use App\Jobs\PublishSocialChannelJob;
use App\Models\Banner;
use App\Models\BannerSocialChannel;
use App\Models\BannerSocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Kein Web-Request wartet minutenlang auf einen fremden Dienst.
 *
 * Zwei Stellen taten genau das: die Lexoffice-Aufrufe ohne ausdrueckliches
 * Zeitlimit (bis 90 s) und der Instagram-Sofortpost (bis rund drei Minuten,
 * laenger als jede uebliche PHP-Laufzeitgrenze).
 */
class ExternalServiceTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kanal(array $attrs = []): BannerSocialChannel
    {
        $admin = $this->admin();
        $banner = Banner::create([
            'title' => 'Testbanner',
            'media_path' => 'banners/test.jpg',
            'media_type' => 'image',
            'is_active' => true,
        ]);
        $post = BannerSocialPost::create([
            'banner_id' => $banner->id,
            'created_by' => $admin->id,
            'caption_de' => 'Testtext',
            'target_url' => 'https://www.dienstly24.de',
        ]);

        return BannerSocialChannel::create(array_merge([
            'banner_social_post_id' => $post->id,
            'platform' => 'facebook',
            'short_code' => 'fb' . uniqid(),
        ], $attrs));
    }

    // ------------------------------------------------------- Lexoffice

    public function test_lexoffice_aufrufe_haben_ein_ausdrueckliches_zeitlimit(): void
    {
        // Ohne Zeitlimit wartet Laravel 30 s je Versuch - mit retry(2) also
        // bis zu 90 s, in denen ein Mitarbeiter vor einer haengenden Seite
        // sitzt und ein PHP-Prozess blockiert ist.
        $this->assertLessThanOrEqual(15, (int) config('services.lexoffice.timeout'));
        $this->assertGreaterThan(0, (int) config('services.lexoffice.timeout'));
        $this->assertLessThanOrEqual(10, (int) config('services.lexoffice.connect_timeout'));
    }

    public function test_ein_lexoffice_ausfall_wirft_keinen_fehler_nach_aussen(): void
    {
        config(['services.lexoffice.key' => 'test-key']);
        Http::fake(fn () => Http::response('', 500));

        // Der Aufrufer bekommt einen leeren Fallback, keine Ausnahme.
        $ergebnis = app(\App\Services\LexofficeService::class)->renderInvoicePdf('irgendeine-id');

        $this->assertNull($ergebnis);
    }

    // ------------------------------------------- Social-Sofortpost als Job

    public function test_sofort_posten_laeuft_im_hintergrund_statt_im_request(): void
    {
        Queue::fake();
        config([
            'services.meta.token' => 'token',
            'services.meta.page_id' => '123',
        ]);

        $kanal = $this->kanal();
        $banner = $kanal->post->banner;

        $this->actingAs($this->admin())
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']))
            ->assertRedirect(route('admin.banners.social', $banner->id));

        Queue::assertPushed(PublishSocialChannelJob::class);
        // Der Versuch ist beansprucht - ein zweiter Klick darf nichts ausloesen.
        $this->assertNotNull($kanal->fresh()->publish_started_at);
    }

    public function test_ein_zweiter_klick_startet_keinen_zweiten_versand(): void
    {
        Queue::fake();
        config([
            'services.meta.token' => 'token',
            'services.meta.page_id' => '123',
        ]);

        // Ein Versand laeuft bereits.
        $kanal = $this->kanal(['publish_started_at' => now()->subMinute()]);
        $banner = $kanal->post->banner;

        $this->actingAs($this->admin())
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']))
            ->assertSessionHasErrors('publish');

        Queue::assertNothingPushed();
    }

    public function test_ein_haengengebliebener_versand_gibt_den_kanal_wieder_frei(): void
    {
        Queue::fake();
        config([
            'services.meta.token' => 'token',
            'services.meta.page_id' => '123',
        ]);

        // Worker gestorben: der Marker steht seit einer Stunde.
        $kanal = $this->kanal(['publish_started_at' => now()->subHour()]);
        $banner = $kanal->post->banner;

        // Lieber ein spaeterer zweiter Versuch als ein Beitrag, den niemand
        // mehr anstossen kann.
        $this->actingAs($this->admin())
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(PublishSocialChannelJob::class);
    }

    public function test_ein_bereits_veroeffentlichter_beitrag_wird_nie_erneut_gepostet(): void
    {
        Queue::fake();
        config([
            'services.meta.token' => 'token',
            'services.meta.page_id' => '123',
        ]);

        $kanal = $this->kanal(['external_post_id' => '999_888']);
        $banner = $kanal->post->banner;

        $this->actingAs($this->admin())
            ->post(route('admin.banners.social.publish_now', [$banner->id, 'facebook']))
            ->assertSessionHasErrors('publish');

        Queue::assertNothingPushed();
    }

    public function test_der_job_versucht_es_nie_ein_zweites_mal(): void
    {
        // Ein Retry koennte einen bereits abgesetzten Beitrag doppelt
        // veroeffentlichen - nie-doppelt-posten geht vor Retry.
        $job = new PublishSocialChannelJob(1, null);

        $this->assertSame(1, $job->tries);
    }

    public function test_ein_abgebrochener_job_gibt_den_kanal_frei(): void
    {
        $kanal = $this->kanal(['publish_started_at' => now()]);

        (new PublishSocialChannelJob($kanal->id, null))->failed(new \RuntimeException('Worker getoetet'));

        $frisch = $kanal->fresh();
        $this->assertNull($frisch->publish_started_at);
        $this->assertStringContainsString('Abgebrochen', (string) $frisch->publish_error);
    }

    public function test_der_geplante_lauf_fasst_einen_laufenden_versand_nicht_an(): void
    {
        config([
            'services.meta.token' => 'token',
            'services.meta.page_id' => '123',
        ]);

        $kanal = $this->kanal(['publish_started_at' => now()]);
        $kanal->post->forceFill(['scheduled_for' => now()->subMinutes(5)])->save();

        $this->artisan('social:publish-scheduled')->assertSuccessful();

        // Kein zweiter Post, kein verbrauchter Auto-Versuch.
        $this->assertNull($kanal->fresh()->auto_attempted_at);
        $this->assertNull($kanal->fresh()->external_post_id);
    }
}
