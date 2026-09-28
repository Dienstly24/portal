<?php

namespace Tests\Feature\Security;

use App\Mail\SignatureCompletedMail;
use App\Mail\SignatureVerificationMail;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureSigningService;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * E-Signatur: Bestaetigungscode und gleichzeitiges Absenden
 * (System-Audit 28.09.2026, KI-027 und KI-030).
 */
class SignaturCodeUndGleichzeitigkeitTest extends TestCase
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

    /** @return array{0: SignatureRequest, 1: SignatureSigner, 2: string} */
    private function vorgang(string $identitaet): array
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $svc = app(SignatureRequestService::class);
        $request = $svc->createFromUpload($this->pdf(), [
            'title' => 'Vollmacht', 'identity_check' => $identitaet, 'signing_order' => 'parallel',
        ], $admin);
        $svc->syncSigners($request, [['name' => 'Person A', 'email' => 'a@example.com']]);
        $request = $request->fresh()->load('signers');
        $signer = $request->signers->first();
        $svc->syncFields($request, [['signer_id' => $signer->id, 'type' => SignatureFieldType::SIGNATURE,
            'page' => 1, 'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05, 'required' => true]]);
        $svc->send($request->fresh()->load(['signers', 'fields']));

        return [$request->fresh(), $signer->fresh(), app(SignatureTokenService::class)->issue($signer->fresh(), null)];
    }

    private function unterschrift(): string
    {
        $bild = imagecreatetruecolor(300, 100);
        imagesavealpha($bild, true);
        imagefill($bild, 0, 0, imagecolorallocatealpha($bild, 0, 0, 0, 127));
        imageline($bild, 10, 50, 290, 60, imagecolorallocate($bild, 0, 0, 0));
        ob_start();
        imagepng($bild);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    // ---------------- KI-027 ----------------

    public function test_code_wird_hoechstens_einmal_je_minute_verschickt(): void
    {
        [, , $token] = $this->vorgang(SignatureRequest::IDENTITY_EMAIL);

        $this->post(route('signature.code', $token))->assertRedirect();
        $this->post(route('signature.code', $token))->assertRedirect();
        $this->post(route('signature.code', $token))->assertRedirect();

        Mail::assertSent(SignatureVerificationMail::class, 1);
    }

    public function test_hoechstens_fuenf_codes_je_stunde(): void
    {
        [, , $token] = $this->vorgang(SignatureRequest::IDENTITY_EMAIL);

        for ($i = 0; $i < 9; $i++) {
            $this->post(route('signature.code', $token));
            $this->travel(61)->seconds();
        }

        Mail::assertSent(SignatureVerificationMail::class, 5);
    }

    public function test_fehlversuche_zaehlen_ueber_alle_codes(): void
    {
        [, $signer, $token] = $this->vorgang(SignatureRequest::IDENTITY_EMAIL);

        // Frueher: jeder neue Code setzte den Zaehler auf 0 zurueck.
        for ($runde = 0; $runde < 2; $runde++) {
            $this->post(route('signature.code', $token));
            for ($i = 0; $i < 5; $i++) {
                $this->post(route('signature.verify', $token), ['code' => 'falsch'.$i]);
            }
            $this->travel(61)->seconds();
        }

        // Jetzt stimmt der Code - und wird trotzdem nicht mehr angenommen.
        $code = app(SignatureTokenService::class)->issueVerificationCode($signer->fresh());
        $this->post(route('signature.verify', $token), ['code' => $code]);

        $this->assertNull($signer->fresh()->verified_at);
    }

    // ---------------- KI-030 ----------------

    public function test_veralteter_stand_unterschreibt_nicht_ein_zweites_mal(): void
    {
        [$request, $signer] = $this->vorgang(SignatureRequest::IDENTITY_NONE);
        $service = app(SignatureSigningService::class);

        // Zweiter Reiter: beide haben denselben (noch offenen) Stand geladen.
        $ersterReiter = SignatureSigner::find($signer->id);
        $zweiterReiter = SignatureSigner::find($signer->id);

        $this->assertSame([], $service->sign($request->fresh(), $ersterReiter, [], ['unterschrift' => $this->unterschrift()]));
        $bildNachAbschluss = $request->fresh()->fields()->first()->image_path;
        $pdfHash = $request->fresh()->signed_hash;

        $this->assertSame([], $service->sign($request->fresh(), $zweiterReiter, [], ['unterschrift' => $this->unterschrift()]));

        $this->assertSame($pdfHash, $request->fresh()->signed_hash, 'Der Vorgang wurde ein zweites Mal abgeschlossen.');
        $this->assertSame($bildNachAbschluss, $request->fresh()->fields()->first()->image_path);
        Mail::assertSent(SignatureCompletedMail::class, 1);
    }

    /**
     * Nachpruefung vor dem Merge: die beiden Faelle oben pruefen die
     * Neu-Lese-Logik INNERHALB der Sperre, nicht die Sperre selbst - in einem
     * PHPUnit-Prozess gibt es kein echtes Gleichzeitig. Hier haelt "ein
     * anderer Prozess" die Sperre: dann darf NICHTS geschrieben werden.
     * Ohne `Cache::lock` um das Unterschreiben schlaegt der Fall fehl.
     * (Dass die Sperre des database-Treibers ueber Prozessgrenzen wirkt,
     * ist gesondert mit zwei echten PHP-Prozessen belegt - siehe
     * docs/AUDIT_2026-09-28_SYSTEMPRUEFUNG.md, Teil F.)
     */
    public function test_gehaltene_sperre_verhindert_jedes_schreiben_beim_unterschreiben(): void
    {
        [$request, $signer] = $this->vorgang(SignatureRequest::IDENTITY_NONE);
        $this->sperreGehaltenVonAnderemProzess('signatur-unterschrift:'.$signer->id);

        try {
            app(SignatureSigningService::class)->sign($request->fresh(), $signer, [], ['unterschrift' => $this->unterschrift()]);
            $this->fail('Unterschrift lief trotz gehaltener Sperre durch.');
        } catch (LockTimeoutException) {
        }

        $this->assertNull($signer->fresh()->signed_at);
        $this->assertNull($request->fresh()->fields()->first()->image_path);
        $this->assertSame(0, $request->fresh()->events()->where('event', 'signed')->count());
        Mail::assertNotSent(SignatureCompletedMail::class);
    }

    public function test_gehaltene_sperre_verhindert_einen_zweiten_abschluss(): void
    {
        [$request, $signer] = $this->vorgang(SignatureRequest::IDENTITY_NONE);
        $this->sperreGehaltenVonAnderemProzess('signatur-abschluss:'.$request->id);

        // Die Unterschrift selbst gelingt (andere Sperre); nur der Abschluss
        // wartet auf den "anderen Prozess" und schreibt nichts.
        try {
            app(SignatureSigningService::class)->sign($request->fresh(), $signer, [], ['unterschrift' => $this->unterschrift()]);
        } catch (LockTimeoutException) {
        }

        $this->assertNotNull($signer->fresh()->signed_at);
        $this->assertNull($request->fresh()->completed_at);
        $this->assertNull($request->fresh()->signed_hash);
        Mail::assertNotSent(SignatureCompletedMail::class);
    }

    /** Liefert fuer genau diesen Schluessel eine Sperre, die gerade jemand anderes haelt. */
    private function sperreGehaltenVonAnderemProzess(string $schluessel): void
    {
        $echt = Cache::store();
        $belegt = Mockery::mock(Lock::class);
        $belegt->shouldReceive('block')->andThrow(new LockTimeoutException);
        $belegt->shouldReceive('get')->andReturn(false);

        Cache::partialMock()->shouldReceive('lock')->andReturnUsing(
            fn (string $name, int $sekunden = 0, ?string $owner = null) => $name === $schluessel
                ? $belegt
                : $echt->lock($name, $sekunden, $owner)
        );
    }

    public function test_abschluss_laeuft_genau_einmal(): void
    {
        [$request, $signer] = $this->vorgang(SignatureRequest::IDENTITY_NONE);
        $service = app(SignatureSigningService::class);
        $service->sign($request->fresh(), $signer, [], ['unterschrift' => $this->unterschrift()]);

        // Zweiter Nachlauf mit veraltetem Stand.
        $service->complete(SignatureRequest::find($request->id));

        Mail::assertSent(SignatureCompletedMail::class, 1);
    }
}
