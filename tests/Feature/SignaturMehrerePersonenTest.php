<?php

namespace Tests\Feature;

use App\Mail\SignatureInvitationMail;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Zwei Personen, EINE E-Mail-Adresse (Betreiber-Meldung 19.11.2026): ein
 * Ehepaar unterschreibt denselben Vertrag und teilt ein Postfach. Beide
 * Einladungen sahen gleich aus, und auf der Unterschreiben-Seite stand nicht,
 * WER gerade unterschreibt und wo die andere Person unterschreibt.
 */
class SignaturMehrerePersonenTest extends TestCase
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

    /** Zwei Unterzeichner mit derselben Adresse, je ein Feld auf eigener Seite. */
    private function paar(string $reihenfolge = 'parallel', string $zweiteAdresse = 'familie@example.com'): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->pdf(3), [
            'title' => 'Stromvertrag',
            'identity_check' => SignatureRequest::IDENTITY_NONE,
            'signing_order' => $reihenfolge,
        ], $this->admin);
        $service->syncSigners($request, [
            ['name' => 'Yamama Althyab', 'email' => 'Familie@example.com'],
            ['name' => 'Anas Alshkar', 'email' => $zweiteAdresse],
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

        return $request->fresh()->load(['signers', 'fields']);
    }

    private function token(SignatureRequest $request, string $name): string
    {
        $signer = $request->signers()->where('name', $name)->firstOrFail();

        return app(SignatureTokenService::class)->issue($signer, $request->expires_at);
    }

    public function test_jede_einladung_nennt_ihre_person_im_betreff_und_oben(): void
    {
        $request = $this->paar();
        app(SignatureRequestService::class)->send($request);

        foreach (['Yamama Althyab', 'Anas Alshkar'] as $name) {
            Mail::assertSent(SignatureInvitationMail::class, function (SignatureInvitationMail $mail) use ($name) {
                if ($mail->signer->name !== $name) {
                    return false;
                }
                $html = $mail->render();

                return str_contains((string) $mail->envelope()->subject, $name)
                    && str_contains($html, 'Diese Einladung ist für: '.e($name))
                    && str_contains($html, 'je Person eine eigene');
            });
        }
    }

    public function test_verschiedene_adressen_ohne_hinweis_auf_geteiltes_postfach(): void
    {
        $request = $this->paar('parallel', 'anas@example.com');
        app(SignatureRequestService::class)->send($request);

        Mail::assertSent(SignatureInvitationMail::class, function (SignatureInvitationMail $mail) {
            $html = $mail->render();

            return ! str_contains((string) $mail->envelope()->subject, $mail->signer->name)
                && str_contains($html, 'Diese Einladung ist für:')
                && ! str_contains($html, 'je Person eine eigene');
        });
    }

    public function test_die_seite_sagt_wer_unterschreibt_und_wo_die_andere_person_unterschreibt(): void
    {
        $request = $this->paar();
        app(SignatureRequestService::class)->send($request);

        $antwort = $this->get(route('signature.show', $this->token($request, 'Anas Alshkar')))->assertOk();

        $antwort->assertSee('Sie unterschreiben als: Anas Alshkar', false)
            ->assertSee('Dieser Link ist NUR für Anas Alshkar', false)
            ->assertSee('Yamama Althyab', false);
        // Die Stelle der anderen Person wird mitgeliefert - grau, mit Namen.
        $this->assertMatchesRegularExpression('/fremdeFelder = \[\{[^\]]*"name":"Yamama Althyab"/', $antwort->getContent());
    }

    public function test_ein_einzelner_unterzeichner_sieht_keinen_personenkasten(): void
    {
        $request = $this->versandfertig();
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();
        $token = app(SignatureTokenService::class)->issue($signer, $request->expires_at);

        $this->get(route('signature.show', $token))->assertOk()
            ->assertDontSee('Sie unterschreiben als', false);
    }

    public function test_nach_der_ersten_unterschrift_wird_die_naechste_person_genannt(): void
    {
        $request = $this->paar('sequential');
        app(SignatureRequestService::class)->send($request);
        $token = $this->token($request, 'Yamama Althyab');

        $this->post(route('signature.sign', $token), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);

        $this->get(route('signature.done', $token))->assertOk()
            ->assertSee('Als Nächstes unterschreibt Anas Alshkar', false);
    }
}
