<?php

namespace Tests\Feature;

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
 * "Ergaenzen" (Betreiber-Meldung 19.11.2026): auf einem FERTIG
 * unterschriebenen Dokument soll ein zweiter Kunde unterschreiben oder eine
 * vergessene Stelle nachgeholt werden. Das unterschriebene PDF bleibt
 * unveraendert; die Ergaenzung ist eine neue Anfrage mit genau diesem
 * Dokument als Basis.
 */
class SignaturErgaenzenTest extends TestCase
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

    public function test_ergaenzung_baut_auf_dem_unterschriebenen_dokument_auf(): void
    {
        $alt = $this->fertig();
        $signedHash = $alt->signed_hash;

        $antwort = $this->actingAs($this->admin)->post(route('admin.signatures.supplement', $alt->id));

        $neu = SignatureRequest::where('ergaenzt_id', $alt->id)->firstOrFail();
        $antwort->assertRedirect(route('admin.signatures.prepare', $neu->id));
        $this->assertSame(SignatureStatus::DRAFT, $neu->status);
        // Basis ist das UNTERSCHRIEBENE Dokument, nicht das Original.
        $basis = app(SignatureStorage::class)->read($neu->original_path);
        $this->assertSame(app(SignatureStorage::class)->read($alt->signed_path), $basis);
        $this->assertNotSame($alt->original_hash, $neu->original_hash);
        // Unterzeichner uebernommen, Felder nicht - die stehen schon im Dokument.
        $this->assertSame('max@example.com', $neu->signers()->first()->email);
        $this->assertSame(0, $neu->fields()->count());
        // Der alte Vorgang bleibt unveraendert der Nachweis.
        $this->assertSame(SignatureStatus::COMPLETED, $alt->fresh()->status);
        $this->assertSame($signedHash, $alt->fresh()->signed_hash);
        $this->assertTrue($alt->events()->where('event', 'supplemented')->exists());
    }

    public function test_die_ergaenzung_schreibt_das_unterschriebene_dokument_fort(): void
    {
        $alt = $this->fertig();
        $altPdf = app(SignatureStorage::class)->read($alt->signed_path);
        $this->actingAs($this->admin)->post(route('admin.signatures.supplement', $alt->id));
        $neu = SignatureRequest::where('ergaenzt_id', $alt->id)->firstOrFail()->load('signers');

        // Ein zweiter Kunde unterschreibt an einer weiteren Stelle.
        $service = app(SignatureRequestService::class);
        $service->syncSigners($neu, [['name' => 'Erika Mustermann', 'email' => 'erika@example.com']]);
        $neu = $neu->fresh()->load('signers');
        $signer = $neu->signers()->firstOrFail();
        $service->syncFields($neu, [[
            'id' => null, 'signer_id' => $signer->id, 'signer_key' => $signer->id,
            'type' => SignatureFieldType::SIGNATURE, 'page' => 2,
            'x' => 0.1, 'y' => 0.5, 'width' => 0.3, 'height' => 0.05, 'required' => 1, 'label' => null,
        ]]);
        $neu = $neu->fresh()->load(['signers', 'fields']);
        $service->send($neu);
        $this->unterschreiben($neu);

        $neu->refresh();
        $this->assertSame(SignatureStatus::COMPLETED, $neu->status);
        $neuPdf = (string) app(SignatureStorage::class)->read($neu->signed_path);
        // FORTSCHREIBUNG: die ersten Bytes sind das bisher unterschriebene
        // Dokument - die erste Unterschrift steht unveraendert darin.
        $this->assertSame($altPdf, substr($neuPdf, 0, strlen((string) $altPdf)));
    }

    public function test_nur_ein_fertiges_dokument_laesst_sich_ergaenzen(): void
    {
        $laufend = $this->versandfertig();
        app(SignatureRequestService::class)->send($laufend);

        $this->actingAs($this->admin)->post(route('admin.signatures.supplement', $laufend->id))->assertForbidden();
        $this->assertFalse(SignatureRequest::where('ergaenzt_id', $laufend->id)->exists());
    }

    public function test_keine_zweite_ergaenzung_solange_eine_offen_ist(): void
    {
        $alt = $this->fertig();

        $this->actingAs($this->admin)->post(route('admin.signatures.supplement', $alt->id));
        $this->actingAs($this->admin)->post(route('admin.signatures.supplement', $alt->id))
            ->assertSessionHas('error');

        $this->assertSame(1, SignatureRequest::where('ergaenzt_id', $alt->id)->count());
    }

    public function test_liste_und_detailseite_bieten_ergaenzen(): void
    {
        $alt = $this->fertig();

        $this->actingAs($this->admin)->get(route('admin.signatures.index'))
            ->assertOk()->assertSee(route('admin.signatures.supplement', $alt->id), false);
        $this->actingAs($this->admin)->get(route('admin.signatures.show', $alt->id))
            ->assertOk()->assertSee('Ergänzen (weitere Unterschrift / Feld)', false);
    }
}
