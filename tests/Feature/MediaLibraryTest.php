<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\SvgSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Medienverwaltung /admin/medien (Arbeitsauftrag P1-1):
 * Upload -> automatische Varianten (WebP/JPG, < 200 KB) -> Slot-Zuweisung
 * -> sofort auf der Website sichtbar. Alt-Texte sind Pflicht, Originale
 * privat, SVGs werden sanitisiert, Papierkorb 30 Tage.
 */
class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function employee(): User
    {
        return User::factory()->create(['role' => 'employee']);
    }

    private function upload(array $overrides = []): array
    {
        return array_merge([
            'files' => [UploadedFile::fake()->image('team-hamburg.png', 1200, 900)],
            'alt_de' => 'Unser Team in Hamburg',
            'alt_ar' => 'فريقنا في هامبورغ',
        ], $overrides);
    }

    public function test_upload_generates_variants_under_size_limit(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $this->actingAs($this->admin())
            ->post(route('admin.media.store'), $this->upload())
            ->assertRedirect(route('admin.media'));

        $asset = MediaAsset::first();
        $this->assertNotNull($asset);
        $this->assertSame('ready', $asset->processing_status);

        // Original liegt PRIVAT, nicht im oeffentlichen Storage.
        Storage::disk('local')->assertExists($asset->original_path);

        // JPG-Fallback + WebP vorhanden, jede Variante unter 200 KB.
        $this->assertNotEmpty($asset->variantsOf('jpg'));
        $this->assertNotEmpty($asset->variantsOf('webp'));
        foreach ((array) $asset->variants as $variant) {
            Storage::disk('public')->assertExists($variant['path']);
            $this->assertLessThanOrEqual(200 * 1024, $variant['bytes']);
        }

        // Ausgelieferte URLs sind IMMER relativ (P0-6-Lehre).
        $this->assertStringStartsWith('/storage/media/', $asset->fallbackUrl());
    }

    public function test_upload_requires_both_alt_texts(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $this->actingAs($this->admin())
            ->from(route('admin.media'))
            ->post(route('admin.media.store'), $this->upload(['alt_de' => '', 'alt_ar' => '']))
            ->assertSessionHasErrors(['alt_de', 'alt_ar']);

        $this->assertSame(0, MediaAsset::count());
    }

    public function test_disguised_php_file_is_rejected_by_mime_sniffing(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        // Echte Datei mit PHP-Inhalt und .png-Endung: getMimeType() sniffed
        // den INHALT (finfo) - genau das soll den Upload ablehnen.
        $path = tempnam(sys_get_temp_dir(), 'evil');
        file_put_contents($path, "<?php echo 'boese';");
        $evil = new UploadedFile($path, 'bild.png', 'image/png', null, true);

        $this->actingAs($this->admin())
            ->from(route('admin.media'))
            ->post(route('admin.media.store'), $this->upload(['files' => [$evil]]))
            ->assertSessionHasErrors('files');

        $this->assertSame(0, MediaAsset::count());
    }

    public function test_svg_scripts_are_stripped(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script>'
            . '<rect width="10" height="10" onclick="alert(2)"/>'
            . '<a href="javascript:alert(3)"><circle r="5"/></a></svg>';

        $clean = SvgSanitizer::sanitize($dirty);

        $this->assertNotNull($clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('<rect', $clean);
    }

    public function test_slot_assignment_is_exclusive_and_archives_previous(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.media.store'), $this->upload(['slot' => 'service-strom-gas']));
        $first = MediaAsset::first();
        $this->assertSame('service-strom-gas', $first->slot);

        $this->actingAs($admin)->post(route('admin.media.store'), $this->upload(['slot' => 'service-strom-gas']));

        $this->assertNull($first->fresh()->slot, 'Vorheriges Slot-Bild muss ins Archiv wandern');
        $this->assertSame('service-strom-gas', MediaAsset::orderByDesc('id')->first()->slot);
        // Archiviert heisst NICHT geloescht.
        $this->assertSame(2, MediaAsset::count());
    }

    public function test_slot_image_appears_on_website_homepage(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $this->actingAs($this->admin())
            ->post(route('admin.media.store'), $this->upload(['slot' => 'service-strom-gas']));

        $this->get('https://www.dienstly24.de/')
            ->assertOk()
            ->assertSee('/storage/media/', false)
            ->assertSee('alt="Unser Team in Hamburg"', false);
    }

    public function test_employee_can_upload_but_not_delete(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $employee = $this->employee();

        $this->actingAs($employee)
            ->post(route('admin.media.store'), $this->upload())
            ->assertRedirect(route('admin.media'));
        $asset = MediaAsset::first();

        // EnsureUserRole leitet bei falscher Rolle um (kein 403-Abort).
        $this->actingAs($employee)
            ->delete(route('admin.media.delete', $asset))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertNotNull(MediaAsset::find($asset->id));
    }

    public function test_delete_moves_to_trash_and_restore_works(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.media.store'), $this->upload(['slot' => 'ueber-uns-hamburg']));
        $asset = MediaAsset::first();

        $this->actingAs($admin)->delete(route('admin.media.delete', $asset))
            ->assertRedirect(route('admin.media'));

        $this->assertSoftDeleted('media_assets', ['id' => $asset->id]);
        $this->assertNull(MediaAsset::forSlot('ueber-uns-hamburg'));

        $this->actingAs($admin)->post(route('admin.media.restore', $asset->id))
            ->assertRedirect(route('admin.media'));
        $this->assertNotNull(MediaAsset::find($asset->id));
    }

    public function test_trash_purge_deletes_files_after_30_days(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.media.store'), $this->upload());
        $asset = MediaAsset::first();
        $variantPath = $asset->variants[0]['path'];

        $this->actingAs($admin)->delete(route('admin.media.delete', $asset));
        MediaAsset::withTrashed()->where('id', $asset->id)->update(['deleted_at' => now()->subDays(31)]);

        $this->artisan('media:purge-trash')->assertSuccessful();

        $this->assertSame(0, MediaAsset::withTrashed()->count());
        Storage::disk('public')->assertMissing($variantPath);
        Storage::disk('local')->assertMissing($asset->original_path);
    }

    public function test_replace_creates_new_asset_taking_over_slot(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.media.store'), $this->upload(['slot' => 'og-image-social']));
        $old = MediaAsset::first();

        $this->actingAs($admin)
            ->post(route('admin.media.replace', $old), ['file' => UploadedFile::fake()->image('neu.jpg', 1200, 630)])
            ->assertRedirect(route('admin.media'));

        $new = MediaAsset::orderByDesc('id')->first();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('og-image-social', $new->slot);
        $this->assertNull($old->fresh()->slot);
        $this->assertSame($old->alt_de, $new->alt_de);
    }
}
