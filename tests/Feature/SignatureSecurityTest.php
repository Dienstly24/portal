<?php

namespace Tests\Feature;

use App\Models\CompanySignatureAsset;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\Signature\CompanySignatureAssetService;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SICHERHEITSPRUEFUNG der Nachbesserung (10.09.2026).
 *
 * Die oeffentliche Unterschriftsseite ist die am staerksten exponierte
 * Stelle des Portals: kein Konto, kein Login, ein Fremder mit einem Link.
 * Diese Faelle halten fest, was dort NICHT geht.
 */
class SignatureSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function pdf(): UploadedFile
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
        $pdf .= "trailer\n<< /Size ".(count($o) + 1)." /Root 1 0 R >>\nstartxref\n".$s."\n%%EOF\n";
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $pdf);

        return new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true);
    }

    /** @return array{0: SignatureRequest, 1: string, 2: string} */
    private function zweiUnterzeichner(): array
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $svc = app(SignatureRequestService::class);
        $request = $svc->createFromUpload($this->pdf(), [
            'title' => 'Vollmacht', 'identity_check' => SignatureRequest::IDENTITY_NONE,
            'signing_order' => 'parallel',
        ], $admin);
        $svc->syncSigners($request, [
            ['name' => 'Person A', 'email' => 'a@example.com'],
            ['name' => 'Person B', 'email' => 'b@example.com'],
        ]);
        $request = $request->fresh()->load('signers');
        $felder = [];
        foreach ($request->signers as $s) {
            $felder[] = ['signer_id' => $s->id, 'type' => SignatureFieldType::SIGNATURE,
                'page' => 1, 'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05, 'required' => true];
        }
        $svc->syncFields($request, $felder);
        $svc->send($request->fresh()->load(['signers', 'fields']));

        $tokens = app(SignatureTokenService::class);
        $a = $request->signers()->where('email', 'a@example.com')->first();
        $b = $request->signers()->where('email', 'b@example.com')->first();

        return [$request->fresh(), $tokens->issue($a, null), $tokens->issue($b, null)];
    }

    public function test_ein_unterzeichner_kann_das_feld_eines_anderen_nicht_ausfuellen(): void
    {
        [$request, $tokenA] = $this->zweiUnterzeichner();
        $frisch = $request->fresh()->load(['signers', 'fields']);
        $b = $frisch->signers->firstWhere('email', 'b@example.com');
        $feldVonB = $frisch->fields->firstWhere('signature_signer_id', $b->id);

        // A schickt das Feld von B mit - der Server entscheidet aus der
        // ZUORDNUNG, nicht aus dem Formular.
        $this->post(route('signature.sign', $tokenA), [
            'zustimmung' => '1',
            'felder' => [$feldVonB->id => 'FREMD'],
        ]);

        $this->assertNull($feldVonB->fresh()->value, 'Ein fremdes Feld wurde beschrieben.');
        $this->assertNull($feldVonB->fresh()->filled_at);
    }

    public function test_ein_geratenes_token_sieht_aus_wie_ein_abgelaufenes(): void
    {
        $this->zweiUnterzeichner();

        // Ein 404 mit derselben Meldung: aus der Antwort darf nicht
        // hervorgehen, ob es diesen Vorgang gibt.
        $this->get(route('signature.show', str_repeat('a', 72)))->assertNotFound();
        $this->get(route('signature.show', 'kurz'))->assertNotFound();
    }

    public function test_ein_widerrufener_zugang_ist_sofort_tot(): void
    {
        [$request, $tokenA] = $this->zweiUnterzeichner();
        app(SignatureTokenService::class)->revoke($request->signers()->first());

        $this->get(route('signature.show', $tokenA))->assertStatus(410);
        $this->get(route('signature.document', $tokenA))->assertStatus(410);
        $this->get(route('signature.page', [$tokenA, 1]))->assertStatus(410);
    }

    public function test_das_original_pdf_wird_nie_ueberschrieben(): void
    {
        [$request, $tokenA, $tokenB] = $this->zweiUnterzeichner();
        $storage = app(SignatureStorage::class);
        $vorher = $storage->read($request->original_path);

        foreach ([$tokenA, $tokenB] as $token) {
            $signer = app(SignatureTokenService::class)->find($token);
            $werte = [];
            foreach ($signer->fields()->get() as $f) {
                $werte[$f->type] = $this->unterschrift();
            }
            $this->post(route('signature.sign', $token), ['zustimmung' => '1', 'zeichnung' => $werte]);
        }

        $frisch = $request->fresh();
        $this->assertSame('completed', $frisch->status);
        $this->assertSame($vorher, $storage->read($frisch->original_path),
            'Das Original wurde veraendert - damit waere der Nachweis wertlos.');
        $this->assertNotSame($frisch->original_path, $frisch->signed_path);
        // Und die Fortschreibung beginnt Byte fuer Byte mit dem Original.
        $this->assertStringStartsWith($vorher, $storage->read($frisch->signed_path));
    }

    public function test_die_dateien_liegen_nie_im_oeffentlichen_verzeichnis(): void
    {
        [$request] = $this->zweiUnterzeichner();

        $this->assertStringStartsWith('signaturen/', $request->original_path);
        $this->assertFalse(file_exists(public_path('storage/'.$request->original_path)),
            'Das Dokument ist ueber eine ratbare URL erreichbar.');
    }

    public function test_ein_fremder_mitarbeiter_kommt_nicht_an_einen_eigenstaendigen_vorgang(): void
    {
        [$request] = $this->zweiUnterzeichner();
        $fremder = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        $this->actingAs($fremder)->get(route('admin.signatures.show', $request->id))->assertForbidden();
        $this->actingAs($fremder)->get(route('admin.signatures.prepare', $request->id))->assertForbidden();
    }

    public function test_ohne_recht_kein_firmenbild_auf_einem_dokument(): void
    {
        [$request] = $this->zweiUnterzeichner();
        $admin = User::factory()->create(['role' => 'admin']);
        $asset = app(CompanySignatureAssetService::class)->store(
            $this->bildDatei(), ['type' => CompanySignatureAsset::STEMPEL], $admin
        );

        // Support darf Signaturen bearbeiten, aber Firmenbilder benutzen?
        // Das entscheidet das Gate - und der Server, nicht das Formular.
        $ohneRecht = User::factory()->create(['role' => 'customer']);
        $antwort = $this->actingAs($ohneRecht)->postJson(
            route('admin.signatures.prepare.save', $request->id),
            ['signers' => [], 'fields' => [[
                'signer_id' => null, 'company_asset_id' => $asset->id,
                'type' => SignatureFieldType::COMPANY, 'page' => 1,
                'x' => 0.1, 'y' => 0.5, 'width' => 0.2, 'height' => 0.05,
            ]]]
        );

        $this->assertTrue($antwort->isRedirect() || $antwort->getStatusCode() >= 400);
        $this->assertSame(0, $request->fresh()->fields()->where('type', SignatureFieldType::COMPANY)->count());
    }

    public function test_die_signaturseite_traegt_keine_fremden_skripte(): void
    {
        [, $tokenA] = $this->zweiUnterzeichner();

        $html = $this->get(route('signature.show', $tokenA))->getContent();

        // SEC-4: keine Inline-Handler, kein Fremdhost im script-src. Ein
        // CDN waere auf dieser Seite besonders teuer - hier liegt ein
        // Vertrag, und die Richtlinie erlaubt seit SEC-4 nur eigene Skripte.
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('onsubmit=', $html);
        $this->assertDoesNotMatchRegularExpression('#<script[^>]+src="https?://(?!127\.0\.0\.1|localhost)#', $html);
    }

    private function unterschrift(): string
    {
        $im = imagecreatetruecolor(200, 70);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagealphablending($im, true);
        imagesetthickness($im, 3);
        imageline($im, 5, 60, 100, 10, imagecolorallocate($im, 10, 10, 60));
        imageline($im, 100, 10, 195, 55, imagecolorallocate($im, 10, 10, 60));
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function bildDatei(): UploadedFile
    {
        $im = imagecreatetruecolor(200, 80);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagefilledellipse($im, 100, 40, 160, 60, imagecolorallocatealpha($im, 20, 60, 160, 40));
        $pfad = tempnam(sys_get_temp_dir(), 'st').'.png';
        imagepng($im, $pfad);
        imagedestroy($im);

        return new UploadedFile($pfad, 'stempel.png', 'image/png', null, true);
    }
}
