<?php

namespace Tests\Feature;

use App\Models\CompanySignatureAsset;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignedPdfBuilder;
use App\Support\SignatureFieldType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * UNTERNEHMENSSIGNATUR, FIRMENSTEMPEL, FIRMENLOGO.
 *
 * Der Kern dieser Faelle ist die TRENNUNG: ein Firmenbild ist eine Grafik,
 * die ein Mitarbeiter aufbringt - kein Mensch, der etwas erklaert. Wo diese
 * Trennung verschwimmt, steht am Ende der Stempel des Betriebs unter einem
 * Dokument, das niemand gelesen hat.
 */
class CompanySignatureAssetTest extends TestCase
{
    use RefreshDatabase;

    private function pdf(): string
    {
        $o = [1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 595 842] >>',
            3 => '<< /Type /Page /Parent 2 0 R >>'];
        $pdf = "%PDF-1.4\n";
        $off = [];
        foreach ($o as $n => $k) {
            $off[$n] = strlen($pdf);
            $pdf .= $n." 0 obj\n".$k."\nendobj\n";
        }
        $s = strlen($pdf);
        $pdf .= "xref\n0 ".(count($o) + 1)."\n0000000000 65535 f \n";
        foreach ($off as $x) {
            $pdf .= sprintf("%010d %05d n \n", $x, 0);
        }

        return $pdf."trailer\n<< /Size ".(count($o) + 1)." /Root 1 0 R >>\nstartxref\n".$s."\n%%EOF\n";
    }

    /** Ein PNG MIT Alphakanal - genau das, was ein Stempel braucht. */
    private function pngDatei(int $w = 300, int $h = 120): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagealphablending($im, true);
        imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), $w - 20, $h - 20,
            imagecolorallocatealpha($im, 20, 60, 160, 40));
        $pfad = tempnam(sys_get_temp_dir(), 'stempel').'.png';
        imagepng($im, $pfad);
        imagedestroy($im);

        return new UploadedFile($pfad, 'stempel.png', 'image/png', null, true);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_kann_ein_firmenbild_hinterlegen(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.signatures.company.store'), [
                'type' => CompanySignatureAsset::STEMPEL,
                'name' => 'Stempel Zentrale',
                'bild' => $this->pngDatei(),
            ])->assertRedirect();

        $asset = CompanySignatureAsset::first();
        $this->assertNotNull($asset);
        $this->assertSame(CompanySignatureAsset::STEMPEL, $asset->type);
        $this->assertSame(64, strlen($asset->hash));
        // Die Datei liegt auf der PRIVATEN Platte, nie im oeffentlichen Verzeichnis.
        $this->assertTrue(app(SignatureStorage::class)->disk()->exists($asset->path));
        $this->assertStringStartsWith('signaturen/firma/', $asset->path);
    }

    public function test_die_transparenz_ueberlebt_das_hochladen(): void
    {
        $this->actingAs($this->admin())->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::UNTERSCHRIFT, 'bild' => $this->pngDatei(),
        ]);

        $asset = CompanySignatureAsset::first();
        $png = app(SignatureStorage::class)->disk()->get($asset->path);
        $bild = imagecreatefromstring($png);
        // Ecke oben links: im Original voll durchsichtig. Waere sie jetzt
        // deckend, laege ein weisser Kasten ueber dem Vertragstext.
        $alpha = (imagecolorat($bild, 1, 1) >> 24) & 0x7F;
        imagedestroy($bild);
        $this->assertGreaterThan(100, $alpha, 'Der Alphakanal ist beim Hochladen verloren gegangen.');
    }

    public function test_ein_pdf_wird_als_bild_abgelehnt(): void
    {
        $pfad = tempnam(sys_get_temp_dir(), 'x').'.pdf';
        file_put_contents($pfad, $this->pdf());

        $this->actingAs($this->admin())
            ->post(route('admin.signatures.company.store'), [
                'type' => CompanySignatureAsset::LOGO,
                'bild' => new UploadedFile($pfad, 'x.pdf', 'application/pdf', null, true),
            ])->assertSessionHasErrors('bild');

        $this->assertSame(0, CompanySignatureAsset::count());
    }

    public function test_mitarbeiter_ohne_recht_kommt_nicht_an_die_verwaltung(): void
    {
        $mitarbeiter = User::factory()->create(['role' => 'employee']);

        $this->actingAs($mitarbeiter)->get(route('admin.signatures.company.index'))->assertForbidden();
        $this->actingAs($mitarbeiter)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::STEMPEL, 'bild' => $this->pngDatei(),
        ])->assertForbidden();
        $this->assertSame(0, CompanySignatureAsset::count());
    }

    public function test_ein_kunde_kommt_nirgends_heran(): void
    {
        $kunde = User::factory()->create(['role' => 'customer']);
        $antwort = $this->actingAs($kunde)->get(route('admin.signatures.company.index'));
        // Kunden werden von der Rollen-Middleware umgeleitet, nicht mit 403
        // abgewiesen - beides ist "kommt nicht rein".
        $this->assertTrue($antwort->isRedirect() || $antwort->getStatusCode() === 403);
    }

    public function test_je_art_gibt_es_genau_eine_voreinstellung(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::STEMPEL, 'name' => 'A', 'bild' => $this->pngDatei(), 'is_default' => 1,
        ]);
        $this->actingAs($admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::STEMPEL, 'name' => 'B', 'bild' => $this->pngDatei(), 'is_default' => 1,
        ]);

        $this->assertSame(1, CompanySignatureAsset::where('type', CompanySignatureAsset::STEMPEL)
            ->where('is_default', true)->count());
        $this->assertSame('B', CompanySignatureAsset::where('is_default', true)->first()->name);
    }

    public function test_das_firmenbild_landet_im_fertigen_pdf_und_im_protokoll(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::STEMPEL, 'name' => 'Stempel Zentrale', 'bild' => $this->pngDatei(),
        ]);
        $asset = CompanySignatureAsset::first();

        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf());
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload(
            new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true),
            ['title' => 'Vollmacht', 'require_email_verification' => false], $admin
        );
        $service->syncSigners($request, [['name' => 'Person', 'email' => 'p@example.com']]);
        $request = $request->fresh()->load('signers');
        $service->syncFields($request, [
            ['signer_id' => $request->signers->first()->id, 'type' => SignatureFieldType::SIGNATURE,
                'page' => 1, 'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05, 'required' => true],
            ['signer_id' => null, 'company_asset_id' => $asset->id, 'type' => SignatureFieldType::COMPANY,
                'page' => 1, 'x' => 0.6, 'y' => 0.7, 'width' => 0.25, 'height' => 0.06],
        ]);

        $frisch = $request->fresh()->load('fields');
        $firmenfeld = $frisch->fields->firstWhere('type', SignatureFieldType::COMPANY);
        $this->assertNotNull($firmenfeld);
        $this->assertSame($asset->id, $firmenfeld->company_asset_id);
        $this->assertNull($firmenfeld->signature_signer_id, 'Ein Firmenbild darf keinem Menschen gehoeren.');
        // Ein Firmenbild wartet auf niemanden - es ist sofort fertig.
        $this->assertTrue($firmenfeld->isFilled());

        // Es blockiert den Versand NICHT und verlangt keine Unterschrift.
        $this->assertSame([], $service->blockersForSending($frisch->load(['signers', 'fields'])));

        $ergebnis = app(SignedPdfBuilder::class)->build($frisch);
        // Das Protokoll benennt es als EINGESETZT, nie als unterschrieben.
        $this->assertStringContainsString('Firmenbilder', $ergebnis['pdf']);
        $this->assertStringContainsString('eingesetzt von', $ergebnis['pdf']);
        $this->assertStringContainsString('KEINE Unterschrift einer Person', $ergebnis['pdf']);
    }

    public function test_ein_firmenbild_wird_nie_zum_unterzeichner(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::UNTERSCHRIFT, 'bild' => $this->pngDatei(),
        ]);
        $asset = CompanySignatureAsset::first();

        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf());
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload(
            new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true),
            ['title' => 'Vollmacht'], $admin
        );
        // Versuch, das Bild einem Unterzeichner-Feld unterzuschieben.
        $service->syncSigners($request, [['name' => 'Person', 'email' => 'p@example.com']]);
        $request = $request->fresh()->load('signers');
        $service->syncFields($request, [[
            'signer_id' => $request->signers->first()->id, 'company_asset_id' => $asset->id,
            'type' => SignatureFieldType::COMPANY,
            'page' => 1, 'x' => 0.1, 'y' => 0.5, 'width' => 0.2, 'height' => 0.05,
        ]]);

        $feld = $request->fresh()->load('fields')->fields->first();
        $this->assertNull($feld->signature_signer_id,
            'Ein Feld kann nicht zugleich einem Menschen gehoeren und einen Stempel tragen.');
        $this->assertSame(0, $request->fresh()->signers()->count() - 1);
    }

    public function test_ohne_zugewiesenes_bild_entsteht_kein_firmenfeld(): void
    {
        $admin = $this->admin();
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf());
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload(
            new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true), ['title' => 'V'], $admin
        );

        $service->syncFields($request, [[
            'signer_id' => null, 'company_asset_id' => null, 'type' => SignatureFieldType::COMPANY,
            'page' => 1, 'x' => 0.1, 'y' => 0.5, 'width' => 0.2, 'height' => 0.05,
        ]]);

        // Ein leeres Firmenfeld koennte niemand mehr fuellen - es wartet ja
        // auf niemanden. Es entsteht deshalb gar nicht erst.
        $this->assertSame(0, $request->fresh()->fields()->count());
    }

    public function test_ein_benutztes_bild_wird_stillgelegt_statt_geloescht(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::LOGO, 'bild' => $this->pngDatei(),
        ]);
        $asset = CompanySignatureAsset::first();

        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf());
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload(
            new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true), ['title' => 'V'], $admin
        );
        $service->syncFields($request, [[
            'signer_id' => null, 'company_asset_id' => $asset->id, 'type' => SignatureFieldType::COMPANY,
            'page' => 1, 'x' => 0.1, 'y' => 0.5, 'width' => 0.2, 'height' => 0.05,
        ]]);

        $this->actingAs($admin)->delete(route('admin.signatures.company.destroy', $asset->id))->assertRedirect();

        $frisch = $asset->fresh();
        $this->assertNotNull($frisch, 'Ein Bild in einem Dokument darf nicht verschwinden.');
        $this->assertFalse($frisch->active);
        $this->assertTrue(app(SignatureStorage::class)->disk()->exists($frisch->path),
            'Die Datei ist Teil eines Belegs und muss erhalten bleiben.');
    }

    public function test_ein_unbenutztes_bild_wird_wirklich_geloescht(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::LOGO, 'bild' => $this->pngDatei(),
        ]);
        $asset = CompanySignatureAsset::first();
        $pfad = $asset->path;

        $this->actingAs($admin)->delete(route('admin.signatures.company.destroy', $asset->id));

        $this->assertNull($asset->fresh());
        $this->assertFalse(app(SignatureStorage::class)->disk()->exists($pfad));
    }

    public function test_das_bild_laeuft_nur_ueber_den_controller(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::STEMPEL, 'bild' => $this->pngDatei(),
        ]);
        $asset = CompanySignatureAsset::first();

        $antwort = $this->actingAs($admin)->get(route('admin.signatures.company.image', $asset->id));
        $antwort->assertOk();
        $antwort->assertHeader('Content-Type', 'image/png');

        // Ohne Anmeldung gar nichts.
        $this->post(route('logout'));
        $this->assertTrue($this->get(route('admin.signatures.company.image', $asset->id))->isRedirect());
    }
}
