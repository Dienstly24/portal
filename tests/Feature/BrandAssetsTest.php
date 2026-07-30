<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\User;
use App\Support\BrandAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Marken-Slots (Auftrag P1-1e): Der Betreiber tauscht Logo und Favicon
 * selbst unter /admin/medien - ohne FTP, ohne Code. Ohne zugewiesenes
 * Bild bleibt der mitgelieferte Bestand aus public/images sichtbar.
 */
class BrandAssetsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Erzeugt ein PNG mit echter Transparenz (wie eine freigestellte Wortmarke). */
    private function transparentPng(int $w, int $h): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        // Ein deckender Balken, damit das Bild nicht voellig leer ist.
        imagefilledrectangle($img, 0, (int) ($h / 3), $w - 1, (int) ($h / 3 * 2), imagecolorallocate($img, 255, 255, 255));
        $path = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        imagepng($img, $path);
        imagedestroy($img);

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }

    private function uploadToSlot(string $slot, int $w = 720, int $h = 254): MediaAsset
    {
        $this->actingAs($this->admin())->post(route('admin.media.store'), [
            'files' => [$this->transparentPng($w, $h)],
            'alt_de' => 'Dienstly24 Logo',
            'alt_ar' => 'شعار Dienstly24',
            'slot' => $slot,
        ])->assertRedirect(route('admin.media'));

        return MediaAsset::where('slot', $slot)->firstOrFail();
    }

    public function test_without_slots_the_shipped_files_are_used(): void
    {
        $this->assertSame('/images/logo-white.png', BrandAssets::logoLight());
        $this->assertSame('/images/logo-transparent.png', BrandAssets::logoDark());
        $this->assertSame('/images/logo-icon-white.png', BrandAssets::logoSymbolLight());
        $this->assertSame('/images/favicon.png', BrandAssets::favicon(32));
        $this->assertSame('/images/apple-touch-icon.png', BrandAssets::favicon(180));

        $this->get('https://www.dienstly24.de/')
            ->assertOk()
            ->assertSee('/images/logo-white.png', false);
    }

    public function test_uploaded_logo_replaces_shipped_file_across_the_app(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $asset = $this->uploadToSlot('logo-hell');
        $this->assertSame('ready', $asset->processing_status);

        // Website (Kopf- und Fusszeile), Login und Leistungsseiten ziehen
        // ab sofort dasselbe hochgeladene Logo.
        $this->get('https://www.dienstly24.de/')
            ->assertOk()
            ->assertSee('/storage/media/' . $asset->id, false)
            ->assertDontSee('src="/images/logo-white.png"', false);

        // Auch die Rechtsseiten der Website (eigenes Layout) ziehen nach.
        $this->get('https://www.dienstly24.de/impressum')
            ->assertOk()
            ->assertSee('/storage/media/' . $asset->id, false);

        // Und der oeffentliche Login-Screen (als Gast geprueft).
        auth()->logout();
        $this->get('/login')
            ->assertOk()
            ->assertSee('/storage/media/' . $asset->id, false);
    }

    public function test_logo_keeps_transparency_no_white_box(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $asset = $this->uploadToSlot('logo-hell');

        // Fallback ist PNG (mit Alphakanal) - ein JPG wuerde einen weissen
        // Kasten hinter die Wortmarke legen (dunkle Kopfzeile!).
        $this->assertSame('png', $asset->fallbackFormat());
        $this->assertNotEmpty($asset->variantsOf('png'));
        $this->assertEmpty($asset->variantsOf('jpg'));
        $this->assertStringEndsWith('.png', (string) $asset->fallbackUrl());
    }

    public function test_favicon_slot_produces_the_required_icon_sizes(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $asset = $this->uploadToSlot('favicon', 512, 512);

        $widths = array_map(fn ($v) => (int) $v['width'], $asset->variantsOf('png'));
        sort($widths);
        $this->assertSame([32, 180, 512], $widths, 'Favicon braucht 32/180/512 px');

        // Der Partial waehlt je Groesse die passende Variante.
        $this->assertStringContainsString('-32.png', BrandAssets::favicon(32));
        $this->assertStringContainsString('-180.png', BrandAssets::favicon(180));
        $this->assertStringContainsString('-512.png', BrandAssets::favicon(512));

        $this->get('https://www.dienstly24.de/')
            ->assertOk()
            ->assertSee('sizes="32x32" href="/storage/media/' . $asset->id, false);
    }

    public function test_assigning_brand_slot_later_rebuilds_the_sizes(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $admin = $this->admin();

        // Zuerst OHNE Slot hochgeladen -> Standardbreiten (kein 32px).
        $this->actingAs($admin)->post(route('admin.media.store'), [
            'files' => [$this->transparentPng(512, 512)],
            'alt_de' => 'Symbol', 'alt_ar' => 'رمز',
        ])->assertRedirect(route('admin.media'));
        $asset = MediaAsset::firstOrFail();
        $this->assertNotContains(32, array_map(fn ($v) => (int) $v['width'], (array) $asset->variants));

        // Nachtraeglich dem Favicon-Slot zuweisen -> Varianten neu erzeugt.
        $this->actingAs($admin)->put(route('admin.media.update', $asset), [
            'title' => 'Symbol', 'alt_de' => 'Symbol', 'alt_ar' => 'رمز', 'slot' => 'favicon',
        ])->assertRedirect(route('admin.media'));

        $widths = array_map(fn ($v) => (int) $v['width'], (array) $asset->fresh()->variants);
        sort($widths);
        $this->assertSame([32, 180, 512], $widths);
    }

    /**
     * Regression: In Produktion laeuft ein SERIALISIERENDER Cache-Store
     * (database/file/redis). Wuerde die Slot-Aufloesung Eloquent-Objekte
     * cachen, kaeme beim zweiten Request ein __PHP_Incomplete_Class
     * zurueck und JEDE Seite mit Bild-Slot antwortete mit 500.
     */
    public function test_slot_cache_survives_a_serializing_cache_store(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        config(['cache.default' => 'file']);
        \Illuminate\Support\Facades\Cache::store('file')->clear();

        $asset = $this->uploadToSlot('logo-hell');

        // Erster Aufruf schreibt in den Cache ...
        $this->get('https://www.dienstly24.de/')->assertOk();
        // ... der zweite liest ihn zurueck (hier brach es vorher).
        $this->get('https://www.dienstly24.de/')
            ->assertOk()
            ->assertSee('/storage/media/' . $asset->id, false);

        $this->assertInstanceOf(MediaAsset::class, MediaAsset::forSlot('logo-hell'));
        $this->assertSame($asset->id, MediaAsset::forSlot('logo-hell')->id);
        // Casts muessen nach dem Cache-Roundtrip weiter greifen.
        $this->assertIsArray(MediaAsset::forSlot('logo-hell')->variants);

        \Illuminate\Support\Facades\Cache::store('file')->clear();
    }

    /**
     * Marken-Slots sind der Verwaltung vorbehalten: Ein getauschtes Logo
     * wirkt auf ALLE Bereiche inklusive Kundenportal, ein Leistungsbild
     * nur auf eine Karte.
     */
    public function test_employee_cannot_touch_brand_slots(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $employee = User::factory()->create(['role' => 'employee']);

        // Zuweisen beim Upload: abgelehnt (Slot steht gar nicht zur Wahl).
        $this->actingAs($employee)
            ->from(route('admin.media'))
            ->post(route('admin.media.store'), [
                'files' => [$this->transparentPng(720, 254)],
                'alt_de' => 'Logo', 'alt_ar' => 'شعار', 'slot' => 'logo-hell',
            ])
            ->assertSessionHasErrors('slot');

        // Inhalts-Slots bleiben fuer Mitarbeiter offen.
        $this->actingAs($employee)->post(route('admin.media.store'), [
            'files' => [$this->transparentPng(900, 600)],
            'alt_de' => 'Strom', 'alt_ar' => 'كهرباء', 'slot' => 'service-strom-gas',
        ])->assertRedirect(route('admin.media'));
        $this->assertNotNull(MediaAsset::forSlot('service-strom-gas'));

        // Ein bereits belegter Marken-Slot bleibt beim Bearbeiten erhalten
        // (kein versehentliches Aushaengen des Logos).
        $logo = $this->uploadToSlot('logo-hell');
        $this->actingAs($employee)->put(route('admin.media.update', $logo), [
            'title' => 'Logo', 'alt_de' => 'Neuer Text', 'alt_ar' => 'نص جديد',
        ])->assertRedirect(route('admin.media'));

        $logo->refresh();
        $this->assertSame('logo-hell', $logo->slot, 'Marken-Slot darf nicht verloren gehen');
        $this->assertSame('Neuer Text', $logo->alt_de, 'Alt-Text-Pflege bleibt erlaubt');

        // Ersetzen der Datei ebenfalls nur fuer die Verwaltung.
        $this->actingAs($employee)
            ->from(route('admin.media'))
            ->post(route('admin.media.replace', $logo), ['file' => $this->transparentPng(720, 254)])
            ->assertSessionHasErrors('file');
    }

    public function test_deleting_the_logo_falls_back_to_shipped_file(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $admin = $this->admin();

        $asset = $this->uploadToSlot('logo-hell');
        $this->actingAs($admin)->delete(route('admin.media.delete', $asset))
            ->assertRedirect(route('admin.media'));

        $this->assertSame('/images/logo-white.png', BrandAssets::logoLight());
        $this->get('https://www.dienstly24.de/')->assertOk()->assertSee('/images/logo-white.png', false);
    }
}
