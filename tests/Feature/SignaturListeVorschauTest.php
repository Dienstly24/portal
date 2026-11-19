<?php

namespace Tests\Feature;

use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Signaturen-Liste (Betreiber-Meldung 19.11.2026): beim Ueberfahren von
 * "Anzeigen" soll man sehen, was der Kunde wo unterschrieben hat - auch
 * bei einem erst TEILWEISE unterschriebenen Vorgang.
 */
class SignaturListeVorschauTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function pdf(int $seiten = 3): UploadedFile
    {
        $objekte = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = [];
        for ($i = 0; $i < $seiten; $i++) {
            $kids[] = ($i + 3).' 0 R';
            $objekte[$i + 3] = '<< /Type /Page /Parent 2 0 R /Resources << >> >>';
        }
        $objekte[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$seiten.' /MediaBox [0 0 595 842] >>';
        ksort($objekte);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objekte as $nummer => $koerper) {
            $offsets[$nummer] = strlen($pdf);
            $pdf .= $nummer." 0 obj\n".$koerper."\nendobj\n";
        }
        $start = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objekte) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d %05d n \n", $offset, 0);
        }
        $pdf .= "trailer\n<< /Size ".(count($objekte) + 1)." /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $pdf);

        return new UploadedFile($pfad, 'vertrag.pdf', 'application/pdf', null, true);
    }

    private function bild(): string
    {
        $b = imagecreatetruecolor(200, 90);
        imagesavealpha($b, true);
        imagealphablending($b, false);
        imagefill($b, 0, 0, imagecolorallocatealpha($b, 0, 0, 0, 127));
        imagealphablending($b, true);
        imagesetthickness($b, 4);
        imageline($b, 30, 60, 170, 25, imagecolorallocate($b, 20, 20, 70));
        ob_start();
        imagepng($b);
        $png = (string) ob_get_clean();
        imagedestroy($b);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** Ein Entwurf mit einem Unterzeichner - ohne Felder. */
    private function entwurf(int $seiten = 3, string $pruefung = SignatureRequest::IDENTITY_NONE, string $sprache = 'de'): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->pdf($seiten), [
            'title' => 'Stromvertrag',
            'identity_check' => $pruefung,
        ], $this->admin);
        $service->syncSigners($request, [['name' => 'Max Mustermann', 'email' => 'max@example.com', 'locale' => $sprache]]);

        return $request->fresh()->load('signers');
    }

    /** @return array<int,array<string,mixed>> Die Nutzlast des Editors. */
    private function nutzlast(SignatureRequest $request, array $seiten): array
    {
        $signer = $request->signers()->firstOrFail();
        $felder = [];
        foreach ($seiten as $seite) {
            $felder[] = [
                'id' => null, 'signer_id' => $signer->id, 'signer_key' => $signer->id,
                'type' => SignatureFieldType::SIGNATURE, 'page' => $seite,
                'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05, 'required' => 1, 'label' => null,
            ];
        }

        return [
            'signers' => [[
                'id' => $signer->id, 'key' => $signer->id,
                'name' => $signer->name, 'email' => $signer->email, 'locale' => 'de',
            ]],
            'fields' => $felder,
        ];
    }

    private function versandfertig(int $seiten = 3, string $pruefung = SignatureRequest::IDENTITY_NONE, string $sprache = 'de'): SignatureRequest
    {
        $request = $this->entwurf($seiten, $pruefung, $sprache);
        $daten = $this->nutzlast($request, [1]);
        app(SignatureRequestService::class)->syncFields($request, $daten['fields']);

        return $request->fresh()->load(['signers', 'fields']);
    }

    private function unterschreiben(SignatureRequest $request): void
    {
        $signer = $request->signers()->firstOrFail();
        $token = app(SignatureTokenService::class)->issue($signer, $request->expires_at);
        $this->post(route('signature.sign', $token), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);
    }

    private function fertig(): SignatureRequest
    {
        $request = $this->versandfertig();
        app(SignatureRequestService::class)->send($request);
        $this->unterschreiben($request);
        $request->refresh();
        $this->assertSame(SignatureStatus::COMPLETED, $request->status);

        return $request;
    }

    /** Zwei Unterzeichner nacheinander, die erste hat unterschrieben. */
    private function teilweise(): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->pdf(3), [
            'title' => 'Stromvertrag',
            'identity_check' => SignatureRequest::IDENTITY_NONE,
            'signing_order' => 'sequential',
        ], $this->admin);
        $service->syncSigners($request, [
            ['name' => 'Yamama Althyab', 'email' => 'yamama@example.com'],
            ['name' => 'Anas Alshkar', 'email' => 'anas@example.com'],
        ]);
        $request = $request->fresh()->load('signers');
        $felder = [];
        foreach ($request->signers as $i => $s) {
            $felder[] = [
                'id' => null, 'signer_id' => $s->id, 'signer_key' => $s->id,
                'type' => SignatureFieldType::SIGNATURE, 'page' => $i + 1,
                'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05, 'required' => 1, 'label' => null,
            ];
        }
        $service->syncFields($request, $felder);
        $request = $request->fresh()->load(['signers', 'fields']);
        $service->send($request);
        $this->unterschreiben($request);

        return $request->fresh();
    }

    public function test_vorschau_eines_fertigen_vorgangs_ist_das_unterschriebene_pdf(): void
    {
        $request = $this->fertig();

        $antwort = $this->actingAs($this->admin)->get(route('admin.signatures.preview', $request->id))->assertOk();

        $this->assertSame('application/pdf', $antwort->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $antwort->headers->get('Content-Disposition'));
        $this->assertSame(app(SignatureStorage::class)->read($request->signed_path), $antwort->getContent());
    }

    public function test_vorschau_eines_teilweise_unterschriebenen_vorgangs_zeigt_die_erste_unterschrift(): void
    {
        $request = $this->teilweise();
        $this->assertSame(SignatureStatus::PARTIALLY_SIGNED, $request->status);
        $original = (string) app(SignatureStorage::class)->read($request->basisPfad());

        $pdf = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.preview', $request->id))->assertOk()->getContent();

        // Fortschreibung des Originals MIT der gestempelten Unterschrift ...
        $this->assertStringStartsWith($original, $pdf);
        $this->assertGreaterThan(strlen($original), strlen($pdf));
        $this->assertStringContainsString('/Subtype /Image', substr($pdf, strlen($original)));
        // ... aber ohne Protokollseite und ohne den Vorgang anzufassen.
        $this->assertStringNotContainsString('Signaturprotokoll', $pdf);
        $request->refresh();
        $this->assertSame(SignatureStatus::PARTIALLY_SIGNED, $request->status);
        $this->assertNull($request->signed_path);
    }

    public function test_vorschau_eines_entwurfs_ist_das_original(): void
    {
        $request = $this->entwurf();

        $this->actingAs($this->admin)->get(route('admin.signatures.preview', $request->id))
            ->assertOk()
            ->assertContent((string) app(SignatureStorage::class)->read($request->original_path));
    }

    public function test_ein_fremder_mitarbeiter_sieht_keine_vorschau(): void
    {
        $request = $this->entwurf();
        $fremder = User::factory()->create(['role' => 'employee']);

        $this->actingAs($fremder)->get(route('admin.signatures.preview', $request->id))->assertForbidden();
    }

    public function test_wiederholtes_ueberfahren_steht_nur_einmal_im_protokoll(): void
    {
        $request = $this->fertig();

        $this->actingAs($this->admin)->get(route('admin.signatures.preview', $request->id))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.signatures.preview', $request->id))->assertOk();

        $this->assertSame(1, SignatureEvent::where('signature_request_id', $request->id)
            ->where('event', 'downloaded')->where('description', 'Vorschau in der Beraterwelt')->count());
    }

    public function test_die_liste_hat_vorschau_und_menue(): void
    {
        $request = $this->fertig();

        $this->actingAs($this->admin)->get(route('admin.signatures.index'))->assertOk()
            ->assertSee('data-preview-url="'.route('admin.signatures.preview', $request->id).'"', false)
            ->assertSee('data-menu-trigger', false)
            ->assertSee(route('admin.signatures.supplement', $request->id), false);
    }
}
