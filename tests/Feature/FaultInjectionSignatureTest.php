<?php

namespace Tests\Feature;

use App\Mail\SignatureCompletedMail;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureTokenService;
use App\Services\Signature\SignedPdfBuilder;
use App\Support\SignatureFieldType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * FEHLEREINSPEISUNG im Unterschreiben-Pfad.
 *
 * Diese Faelle sind der Nachweis fuer den gemeldeten HTTP 500: nicht EIN
 * Fehler war die Ursache, sondern das FEHLEN einer Schranke - jede Stoerung
 * hinter dem Absenden (Platte, Glocke, PDF) schlug ungefiltert bis zum
 * Unterzeichner durch. Sie bleiben stehen, damit die Schranke nicht
 * unbemerkt wieder verschwindet.
 */
class FaultInjectionSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function pdf(int $pages = 1): string
    {
        $o = [1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($i) => ($i + 3).' 0 R', range(0, $pages - 1))).'] /Count '.$pages.' /MediaBox [0 0 595 842] >>'];
        for ($i = 0; $i < $pages; $i++) { $o[$i + 3] = '<< /Type /Page /Parent 2 0 R >>'; }
        $pdf = "%PDF-1.4\n";
        $off = [];
        foreach ($o as $n => $k) { $off[$n] = strlen($pdf);
        $pdf .= $n." 0 obj\n".$k."\nendobj\n"; }
        $s = strlen($pdf);
        $pdf .= "xref\n0 ".(count($o) + 1)."\n0000000000 65535 f \n";
        foreach ($off as $x) { $pdf .= sprintf("%010d %05d n \n", $x, 0); }
        return $pdf."trailer\n<< /Size ".(count($o) + 1)." /Root 1 0 R >>\nstartxref\n".$s."\n%%EOF\n";
    }

    private function bild(int $w = 200, int $h = 70): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagealphablending($im, true);
        imagesetthickness($im, 3);
        imageline($im, 5, $h - 10, $w / 2, 10, imagecolorallocate($im, 10, 10, 60));
        imageline($im, $w / 2, 10, $w - 5, $h - 15, imagecolorallocate($im, 10, 10, 60));
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** @return array{0: SignatureRequest, 1: string} */
    private function vorbereiten(int $anzahlUnterzeichner = 1): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf());
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload(
            new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true),
            ['title' => 'Vollmacht', 'require_email_verification' => false], $admin
        );
        $unterzeichner = [];
        for ($i = 0; $i < $anzahlUnterzeichner; $i++) {
            $unterzeichner[] = ['name' => 'Person '.$i, 'email' => 'p'.$i.'@example.com'];
        }
        $service->syncSigners($request, $unterzeichner);
        $request = $request->fresh()->load('signers');
        $felder = [];
        foreach ($request->signers as $s) {
            $felder[] = ['signer_id' => $s->id, 'type' => SignatureFieldType::SIGNATURE, 'page' => 1,
                'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05, 'required' => true];
        }
        $service->syncFields($request->fresh()->load('signers'), $felder);
        $service->send($request->fresh()->load(['signers', 'fields']));
        $erster = $request->signers()->orderBy('signing_order')->first();

        return [$request->fresh(), app(SignatureTokenService::class)->issue($erster, null)];
    }

    private function unterschreiben(string $token, ?string $bild = null)
    {
        $signer = app(SignatureTokenService::class)->find($token);
        $werte = [];
        foreach ($signer->fields()->get() as $f) { $werte[$f->type] = $bild ?? $this->bild(); }

        return $this->post(route('signature.sign', $token), ['zustimmung' => '1', 'zeichnung' => $werte]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_a_pdf_erzeugung_wirft(): void
    {
        [, $token] = $this->vorbereiten(1);
        $this->mock(SignedPdfBuilder::class, fn ($m) => $m->shouldReceive('build')
            ->andThrow(new \RuntimeException('PDF kaputt')));

        $antwort = $this->unterschreiben($token);
        $this->assertLessThan(500, $antwort->getStatusCode(),
            'PDF-Fehler erreicht den Unterzeichner als '.$antwort->getStatusCode());
    }

    public function test_b_glocke_wirft(): void
    {
        [, $token] = $this->vorbereiten(1);
        $this->mock(NotificationService::class, fn ($m) => $m->shouldReceive('push')
            ->andThrow(new \RuntimeException('Glocke kaputt')));

        $antwort = $this->unterschreiben($token);
        $this->assertLessThan(500, $antwort->getStatusCode(),
            'Glocken-Fehler erreicht den Unterzeichner als '.$antwort->getStatusCode());
    }

    public function test_c_speicher_nicht_schreibbar(): void
    {
        [, $token] = $this->vorbereiten(1);
        // Platte, deren put() eine Ausnahme wirft (voller Datentraeger).
        Storage::shouldReceive('disk')->andReturnUsing(function () {
            $m = \Mockery::mock();
            $m->shouldReceive('put')->andThrow(new \RuntimeException('Disk voll'));
            $m->shouldReceive('exists')->andReturn(true);
            $m->shouldReceive('get')->andReturn('%PDF-1.4');
            return $m;
        });

        $antwort = $this->unterschreiben($token);
        $this->assertLessThan(500, $antwort->getStatusCode(),
            'Speicher-Fehler erreicht den Unterzeichner als '.$antwort->getStatusCode());
    }

    public function test_d_zu_grosse_unterschrift_von_breitem_bildschirm(): void
    {
        [, $token] = $this->vorbereiten(1);
        // 1640 px: Karte 820 CSS-Pixel mal Geraeteverhaeltnis 2.
        $antwort = $this->unterschreiben($token, $this->bild(1640, 380));
        $signer = app(SignatureTokenService::class)->find($token);

        $this->assertTrue($signer->hasSigned(),
            'Eine Unterschrift von einem breiten Bildschirm (1640 px) wurde verworfen.');
    }

    public function test_e_doppelter_versand_erzeugt_nur_eine_unterschrift(): void
    {
        [$request, $token] = $this->vorbereiten(1);
        $this->unterschreiben($token);
        $zweite = $this->unterschreiben($token);

        $this->assertLessThan(500, $zweite->getStatusCode(),
            'Zweiter Versand erreicht den Unterzeichner als '.$zweite->getStatusCode());
        $zweite->assertRedirect(route('signature.done', $token));
        $this->assertSame('completed', $request->fresh()->status);
        // Genau EINE Abschluss-Mail: der zweite Klick darf den Vorgang nicht
        // ein zweites Mal abschliessen.
        Mail::assertSent(SignatureCompletedMail::class, 1);
    }

    public function test_f_unterschrift_ueberlebt_eine_gestoerte_glocke(): void
    {
        [$request, $token] = $this->vorbereiten(1);
        $this->mock(NotificationService::class, fn ($m) => $m->shouldReceive('push')
            ->andThrow(new \RuntimeException('Glocke kaputt')));

        $this->unterschreiben($token);

        $this->assertTrue(app(SignatureTokenService::class)->find($token)->hasSigned(),
            'Eine gestoerte Glocke hat die Unterschrift verhindert.');
        $this->assertTrue($request->fresh()->events()->where('event', 'signed')->exists());
    }

    public function test_g_bei_speicherfehler_gilt_niemand_als_unterschrieben(): void
    {
        [$request, $token] = $this->vorbereiten(1);
        Storage::shouldReceive('disk')->andReturnUsing(function () {
            $m = \Mockery::mock();
            $m->shouldReceive('put')->andThrow(new \RuntimeException('Disk voll'));
            $m->shouldReceive('exists')->andReturn(true);
            $m->shouldReceive('get')->andReturn('%PDF-1.4');

            return $m;
        });

        $this->unterschreiben($token);

        // KEINE FALSCHE ERFOLGSMELDUNG: liegt die Handschrift nirgends, darf
        // der Unterzeichner auch nicht als fertig gefuehrt werden.
        $this->assertFalse(app(SignatureTokenService::class)->find($token)->hasSigned());
        $this->assertNotSame('completed', $request->fresh()->status);
        $this->assertTrue($request->fresh()->events()->where('event', 'signing_failed')->exists(),
            'Der Fehlschlag steht nicht im Protokoll - er waere nur in der Logdatei sichtbar.');
    }

    public function test_h_editor_haelt_die_zuordnung_neuer_unterzeichner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf());
        $request = app(SignatureRequestService::class)->createFromUpload(
            new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true),
            ['title' => 'Vollmacht', 'require_email_verification' => false], $admin
        );

        // GENAU DER FALL AUS DEM EDITOR: Unterzeichner UND Feld entstehen im
        // selben Speichervorgang. Der Browser kennt beide nur unter seiner
        // Behelfs-Kennung "neu-1".
        $this->actingAs($admin)->postJson(route('admin.signatures.prepare.save', $request->id), [
            'signers' => [['id' => null, 'key' => 'neu-1', 'name' => 'Neu Person', 'email' => 'neu@example.com']],
            'fields' => [[
                'id' => null, 'signer_id' => null, 'signer_key' => 'neu-1',
                'type' => SignatureFieldType::SIGNATURE, 'page' => 1,
                'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05, 'required' => 1,
            ]],
        ])->assertOk();

        $frisch = $request->fresh()->load(['signers', 'fields']);
        $this->assertNotNull($frisch->fields->first()->signature_signer_id,
            'Das Feld hat seinen Unterzeichner verloren - der Vorgang liesse sich nicht versenden.');
        $this->assertSame($frisch->signers->first()->id, $frisch->fields->first()->signature_signer_id);
        $this->assertSame([], app(SignatureRequestService::class)->blockersForSending($frisch));
    }
}
