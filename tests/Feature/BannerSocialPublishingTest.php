<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\BannerSocialChannel;
use App\Models\BannerSocialPost;
use App\Models\Task;
use App\Models\User;
use App\Services\Social\SocialFormatGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Social-Publishing der Bannerverwaltung (Phase 1): Beitrag + Plattformen
 * speichern, Bildformate erzeugen, Tracking-Kurzlinks zaehlen, Rechte.
 */
class BannerSocialPublishingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** Banner mit ECHTEM Bild (GD) in der Fake-Disk anlegen. */
    private function makeImageBanner(int $w = 1600, int $h = 500, array $attrs = []): Banner
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 23, 166, 91));
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);
        Storage::disk('public')->put('banners/test-' . $w . 'x' . $h . '.png', $png);

        return Banner::create(array_merge([
            'title' => 'Sommer-Aktion Strom',
            'media_path' => 'banners/test-' . $w . 'x' . $h . '.png',
            'media_type' => 'image',
            'is_active' => true,
            'sort_order' => 0,
        ], $attrs));
    }

    private function savePost(Banner $banner, array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(
            route('admin.banners.social.save', $banner->id),
            array_merge([
                'caption_de' => 'Jetzt Stromtarif sichern!',
                'caption_ar' => 'وفر في فاتورة الكهرباء الآن',
                'target_url' => 'https://www.dienstly24.de/leistungen/strom',
                'platforms' => ['facebook', 'instagram', 'tiktok'],
            ], $overrides)
        );
    }

    // ---------------- Speichern + Formate ----------------

    public function test_speichern_legt_post_kanaele_und_bildformate_an(): void
    {
        $banner = $this->makeImageBanner();

        $this->savePost($banner)->assertRedirect()->assertSessionHas('success');

        $post = BannerSocialPost::where('banner_id', $banner->id)->first();
        $this->assertNotNull($post);
        $this->assertSame('Jetzt Stromtarif sichern!', $post->caption_de);
        $this->assertSame((string) $this->admin->id, (string) $post->created_by);

        // Je Plattform ein Kanal mit erkennbarem, eindeutigem Kurzlink-Code.
        $this->assertSame(3, $post->channels()->count());
        foreach (['facebook' => 'fb-', 'instagram' => 'ig-', 'tiktok' => 'tt-'] as $platform => $prefix) {
            $ch = $post->channels()->where('platform', $platform)->first();
            $this->assertNotNull($ch);
            $this->assertStringStartsWith($prefix, $ch->short_code);
        }
        $this->assertSame(3, $post->channels()->distinct('short_code')->count());

        // Alle drei Bildformate liegen auf der Disk.
        foreach (array_keys(SocialFormatGenerator::FORMATS) as $format) {
            Storage::disk('public')->assertExists(SocialFormatGenerator::path($banner, $format));
        }
    }

    public function test_formate_haben_die_richtigen_masse(): void
    {
        $banner = $this->makeImageBanner();
        $this->savePost($banner);

        foreach (SocialFormatGenerator::FORMATS as $format => [$w, $h]) {
            $file = Storage::disk('public')->path(SocialFormatGenerator::path($banner, $format));
            [$iw, $ih] = getimagesize($file);
            $this->assertSame([$w, $h], [$iw, $ih], 'Format ' . $format);
        }
    }

    public function test_abgewaehlte_plattform_wird_entfernt_bestehende_behaelt_code_und_klicks(): void
    {
        $banner = $this->makeImageBanner();
        $this->savePost($banner, ['platforms' => ['facebook', 'instagram']]);

        $post = BannerSocialPost::where('banner_id', $banner->id)->first();
        $fb = $post->channels()->where('platform', 'facebook')->first();
        $fb->recordClick();

        $this->savePost($banner, ['platforms' => ['facebook']]);

        $this->assertSame(1, $post->channels()->count());
        $fbNeu = $post->channels()->where('platform', 'facebook')->first();
        $this->assertSame($fb->short_code, $fbNeu->short_code);
        $this->assertSame(1, $fbNeu->clicks);
        $this->assertNull($post->channels()->where('platform', 'instagram')->first());
    }

    public function test_veroeffentlichte_kanaele_ueberleben_das_abwaehlen(): void
    {
        $banner = $this->makeImageBanner();
        $this->savePost($banner, ['platforms' => ['facebook', 'instagram']]);
        // Facebook manuell als veroeffentlicht markieren -> Kurzlink ist im Live-Beitrag.
        $this->actingAs($this->admin)->post(route('admin.banners.social.published', [$banner->id, 'facebook']));

        // Beide abwaehlen: Instagram (unveroeffentlicht) verschwindet,
        // Facebook bleibt samt Kurzlink erhalten + Hinweis in der Meldung.
        $this->savePost($banner, ['platforms' => ['tiktok']])
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'bereits ver'));

        $post = BannerSocialPost::where('banner_id', $banner->id)->first();
        $this->assertNotNull($post->channels()->where('platform', 'facebook')->first());
        $this->assertNull($post->channels()->where('platform', 'instagram')->first());
        $this->assertNotNull($post->channels()->where('platform', 'tiktok')->first());
    }

    public function test_klick_ziel_muss_oeffentliche_https_url_sein(): void
    {
        $banner = $this->makeImageBanner();

        foreach (['/portal/contracts', 'http://unsicher.de/x', 'javascript:alert(1)'] as $bad) {
            $this->savePost($banner, ['target_url' => $bad])->assertSessionHasErrors('target_url');
        }
        $this->assertNull(BannerSocialPost::where('banner_id', $banner->id)->first());
    }

    public function test_video_banner_speichert_ohne_bildformate(): void
    {
        Storage::disk('public')->put('banners/spot.mp4', 'video-bytes');
        $banner = Banner::create([
            'title' => 'Video-Banner',
            'media_path' => 'banners/spot.mp4',
            'media_type' => 'video',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->savePost($banner)->assertRedirect()->assertSessionHas('success');

        $this->assertNotNull(BannerSocialPost::where('banner_id', $banner->id)->first());
        foreach (array_keys(SocialFormatGenerator::FORMATS) as $format) {
            Storage::disk('public')->assertMissing(SocialFormatGenerator::path($banner, $format));
        }

        $this->actingAs($this->admin)->get(route('admin.banners.social', $banner->id))
            ->assertOk()->assertSee('Original-Video herunterladen');
    }

    public function test_medienwechsel_erzeugt_formate_neu(): void
    {
        $banner = $this->makeImageBanner();
        $this->savePost($banner);
        $vorher = Storage::disk('public')->get(SocialFormatGenerator::path($banner, 'quadrat'));

        $this->actingAs($this->admin)->post(route('admin.banners.update', $banner->id), [
            'title' => $banner->title,
            'media' => UploadedFile::fake()->image('neu.jpg', 900, 900),
            'link_target' => 'self',
        ])->assertRedirect();

        $nachher = Storage::disk('public')->get(SocialFormatGenerator::path($banner->fresh(), 'quadrat'));
        $this->assertNotSame($vorher, $nachher);
    }

    public function test_wiedervorlage_wird_optional_angelegt(): void
    {
        $banner = $this->makeImageBanner(1600, 500, ['start_date' => now()->addDays(5)->toDateString()]);

        $this->savePost($banner, ['create_task' => 1]);

        $task = Task::where('assigned_to', $this->admin->id)->first();
        $this->assertNotNull($task);
        $this->assertStringContainsString('Sommer-Aktion Strom', $task->title);
        $this->assertSame(now()->addDays(5)->toDateString(), $task->due_date->toDateString());

        // Ohne Haekchen keine weitere Aufgabe.
        $this->savePost($banner);
        $this->assertSame(1, Task::count());
    }

    // ---------------- Kurzlink-Redirect (oeffentlich) ----------------

    public function test_kurzlink_zaehlt_klick_und_leitet_mit_utm_weiter(): void
    {
        $banner = $this->makeImageBanner();
        $this->savePost($banner);
        $ch = BannerSocialPost::where('banner_id', $banner->id)->first()
            ->channels()->where('platform', 'instagram')->first();

        $response = $this->get('/s/' . $ch->short_code);
        $response->assertRedirect();
        $ziel = $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.dienstly24.de/leistungen/strom?', $ziel);
        $this->assertStringContainsString('utm_source=instagram', $ziel);
        $this->assertStringContainsString('utm_medium=social', $ziel);
        $this->assertStringContainsString('utm_campaign=banner-' . $banner->id, $ziel);

        $this->get('/s/' . $ch->short_code);
        $ch->refresh();
        $this->assertSame(2, $ch->clicks);
        $this->assertNotNull($ch->last_click_at);
    }

    public function test_kurzlink_haelt_fragment_am_ende_der_url(): void
    {
        $banner = $this->makeImageBanner();
        $this->savePost($banner, ['target_url' => 'https://www.dienstly24.de/#kontakt', 'platforms' => ['facebook']]);
        $ch = BannerSocialChannel::first();

        $ziel = $this->get('/s/' . $ch->short_code)->headers->get('Location');
        $this->assertMatchesRegularExpression('#^https://www\.dienstly24\.de/\?utm_source=facebook.*\#kontakt$#', $ziel);
    }

    public function test_kurzlink_fallback_ohne_ziel(): void
    {
        $banner = $this->makeImageBanner(1600, 500, ['link_url' => '/portal/contracts']);
        $this->savePost($banner, ['target_url' => null, 'platforms' => ['facebook']]);
        $ch = BannerSocialChannel::first();

        // Portal-interner Banner-Link ist hinter dem Login -> Startseite.
        $ziel = $this->get('/s/' . $ch->short_code)->headers->get('Location');
        $this->assertStringStartsWith(url('/') . '?utm_source=facebook', $ziel);
    }

    public function test_kurzlink_funktioniert_auch_fuer_beendete_banner(): void
    {
        $banner = $this->makeImageBanner(1600, 500, [
            'is_active' => false,
            'end_date' => now()->subMonth()->toDateString(),
        ]);
        $this->savePost($banner, ['platforms' => ['tiktok']]);
        $ch = BannerSocialChannel::first();

        $this->get('/s/' . $ch->short_code)->assertRedirect();
        $this->assertSame(1, $ch->fresh()->clicks);
    }

    public function test_unbekannter_kurzlink_gibt_404(): void
    {
        $this->get('/s/fb-gibtsnicht')->assertNotFound();
    }

    // ---------------- Veroeffentlichung + Paket ----------------

    public function test_veroeffentlichung_markieren_und_zuruecksetzen(): void
    {
        $banner = $this->makeImageBanner();
        $this->savePost($banner, ['platforms' => ['facebook']]);

        $url = route('admin.banners.social.published', [$banner->id, 'facebook']);
        $this->actingAs($this->admin)->post($url)->assertRedirect();

        $ch = BannerSocialChannel::first()->fresh();
        $this->assertNotNull($ch->published_at);
        $this->assertSame((string) $this->admin->id, (string) $ch->published_by);

        $this->actingAs($this->admin)->post($url)->assertRedirect();
        $this->assertNull($ch->fresh()->published_at);
    }

    public function test_zip_paket_enthaelt_formate_texte_und_links(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive nicht verfuegbar.');
        }
        $banner = $this->makeImageBanner();
        $this->savePost($banner);

        $response = $this->actingAs($this->admin)->get(route('admin.banners.social.zip', $banner->id));
        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        $zip = new \ZipArchive();
        $zip->open($response->getFile()->getPathname());
        $namen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $namen[] = $zip->getNameIndex($i);
        }
        $zip->close();
        foreach (['bild-quadrat.jpg', 'bild-story.jpg', 'bild-quer.jpg', 'text-deutsch.txt', 'text-arabisch.txt', 'tracking-links.txt'] as $erwartet) {
            $this->assertContains($erwartet, $namen);
        }
    }

    // ---------------- Aufraeumen + Rechte ----------------

    public function test_banner_loeschen_entfernt_social_formate_und_daten(): void
    {
        $banner = $this->makeImageBanner();
        $this->savePost($banner);
        $formatPfad = SocialFormatGenerator::path($banner, 'quadrat');
        Storage::disk('public')->assertExists($formatPfad);

        $this->actingAs($this->admin)->post(route('admin.banners.delete', $banner->id))->assertRedirect();

        Storage::disk('public')->assertMissing($formatPfad);
        $this->assertSame(0, BannerSocialPost::count());
        $this->assertSame(0, BannerSocialChannel::count());
    }

    // Audit BANNER-1: ein Banner mit VEROEFFENTLICHTEN Social-Kanaelen darf
    // nicht geloescht werden - die FK-Kaskade wuerde short_code/external_post_id
    // und die Klickzahlen der Live-Beitraege vernichten und die /s/-Kurzlinks
    // toeten. Stattdessen deaktivieren.
    public function test_banner_mit_veroeffentlichtem_kanal_wird_nicht_geloescht(): void
    {
        $banner = $this->makeImageBanner();
        $this->savePost($banner);
        $post = BannerSocialPost::where('banner_id', $banner->id)->first();
        $fb = $post->channels()->where('platform', 'facebook')->first();
        $fb->forceFill(['published_at' => now(), 'published_by' => $this->admin->id])->save();
        $fb->recordClick();
        $code = $fb->short_code;

        $this->actingAs($this->admin)->post(route('admin.banners.delete', $banner->id))
            ->assertRedirect()->assertSessionHas('error');

        // Banner UND Kanal (Kurzlink + Klicks) bleiben erhalten.
        $this->assertNotNull(Banner::find($banner->id));
        $this->assertNotNull(BannerSocialChannel::find($fb->id));
        $this->assertSame(1, (int) BannerSocialChannel::find($fb->id)->clicks);

        // Der Live-Kurzlink funktioniert weiterhin (kein 404).
        $this->get('/s/' . $code)->assertRedirect();
    }

    public function test_mitarbeiter_hat_keinen_zugriff(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $banner = $this->makeImageBanner();

        $this->actingAs($employee)->get(route('admin.banners.social', $banner->id))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($employee)->post(route('admin.banners.social.save', $banner->id), ['platforms' => ['facebook']])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertSame(0, BannerSocialPost::count());
    }

    public function test_social_seite_und_statistik_rendern(): void
    {
        $banner = $this->makeImageBanner();
        $this->savePost($banner);
        BannerSocialChannel::where('platform', 'facebook')->first()->recordClick();

        $this->actingAs($this->admin)->get(route('admin.banners.social', $banner->id))
            ->assertOk()->assertSee('Tracking-Link')->assertSee('Feed-Post');

        $this->actingAs($this->admin)->get(route('admin.banners'))
            ->assertOk()->assertSee('Social-Media (1 Klick)');

        $this->actingAs($this->admin)->get(route('admin.banners.stats'))
            ->assertOk()->assertSee('Social-Media – Klicks über Tracking-Links');
    }
}
