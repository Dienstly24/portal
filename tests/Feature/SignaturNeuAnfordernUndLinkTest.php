<?php

namespace Tests\Feature;

use App\Mail\SignatureInvitationMail;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Betreiber-Meldung 19.11.2026 - drei Luecken im Signatur-Alltag:
 *
 *  1. Falsch unterschrieben -> es gab keinen Weg, neu anzufordern
 *     (ein unterschriebener Vorgang wird zu Recht nie geloescht).
 *  2. Der Kunde sitzt im Buero oder ist per WhatsApp erreichbar -> es gab
 *     nur den Weg ueber die E-Mail.
 *  3. Kunden hielten die Einladung fuer Betrug -> die Mail sagt jetzt,
 *     woher sie kommt (WhatsApp-Gespraech) und wie man nachfragt.
 */
class SignaturNeuAnfordernUndLinkTest extends TestCase
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

    private function tokenAus(string $html): string
    {
        $this->assertSame(1, preg_match('#/unterschreiben/([A-Za-z0-9]{40,})#', $html, $treffer), 'Kein Link auf der Seite.');

        return $treffer[1];
    }

    // ------------------------------------------------ 1: Erneut anfordern

    public function test_falsch_unterschrieben_neue_anfrage_aus_dem_original(): void
    {
        $alt = $this->versandfertig();
        app(SignatureRequestService::class)->send($alt);
        $this->unterschreiben($alt);
        $this->assertSame(SignatureStatus::COMPLETED, $alt->fresh()->status);

        $antwort = $this->actingAs($this->admin)->post(route('admin.signatures.reissue', $alt->id));

        $neu = SignatureRequest::where('replaces_id', $alt->id)->firstOrFail();
        $antwort->assertRedirect(route('admin.signatures.prepare', $neu->id));
        $this->assertSame(SignatureStatus::DRAFT, $neu->status);
        // Das ORIGINAL, nicht das unterschriebene PDF - sonst stuende die
        // falsche Unterschrift im neuen Dokument.
        $this->assertSame($alt->original_hash, $neu->original_hash);
        $this->assertSame(1, $neu->signers()->count());
        $this->assertSame('max@example.com', $neu->signers()->first()->email);
        $this->assertSame(1, $neu->fields()->count());
        $this->assertSame(0, $neu->fields()->whereNotNull('image_path')->count(), 'Keine Unterschrift darf uebernommen werden.');
        // Der alte Vorgang bleibt unveraendert - er ist der Nachweis.
        $this->assertSame(SignatureStatus::COMPLETED, $alt->fresh()->status);
        $this->assertTrue($alt->events()->where('event', 'reissued')->exists());
    }

    public function test_ein_offener_vorgang_wird_beim_neu_anfordern_storniert(): void
    {
        $alt = $this->versandfertig();
        app(SignatureRequestService::class)->send($alt);

        $this->actingAs($this->admin)->post(route('admin.signatures.reissue', $alt->id));

        $this->assertSame(SignatureStatus::CANCELLED, $alt->fresh()->status);
        $this->assertNotNull($alt->signers()->first()->token_revoked_at);
        $this->assertTrue(SignatureRequest::where('replaces_id', $alt->id)->exists());
    }

    public function test_zweimal_neu_anfordern_legt_keine_zweite_kopie_an(): void
    {
        $alt = $this->versandfertig();
        app(SignatureRequestService::class)->send($alt);
        $this->unterschreiben($alt);

        $this->actingAs($this->admin)->post(route('admin.signatures.reissue', $alt->id));
        $this->actingAs($this->admin)->post(route('admin.signatures.reissue', $alt->id))
            ->assertSessionHas('error');

        $this->assertSame(1, SignatureRequest::where('replaces_id', $alt->id)->count());
    }

    public function test_ein_entwurf_wird_nicht_neu_angefordert(): void
    {
        $entwurf = $this->entwurf();

        $this->actingAs($this->admin)->post(route('admin.signatures.reissue', $entwurf->id))->assertForbidden();
        $this->assertFalse(SignatureRequest::where('replaces_id', $entwurf->id)->exists());
    }

    // ------------------------------------- 2: Ohne E-Mail / WhatsApp / vor Ort

    public function test_ohne_email_bereitstellen_verschickt_keine_mail(): void
    {
        $request = $this->versandfertig();

        $this->actingAs($this->admin)
            ->post(route('admin.signatures.send', $request->id), ['ohne_mail' => '1'])
            ->assertRedirect(route('admin.signatures.show', $request->id));

        $this->assertSame(SignatureStatus::SENT, $request->fresh()->status);
        Mail::assertNotSent(SignatureInvitationMail::class);
    }

    public function test_link_fuer_whatsapp_fuehrt_zum_dokument(): void
    {
        $request = $this->versandfertig();
        app(SignatureRequestService::class)->send($request, perMail: false);
        $signer = $request->signers()->firstOrFail();

        $antwort = $this->actingAs($this->admin)
            ->post(route('admin.signatures.link', [$request->id, $signer->id]))
            ->assertOk()
            ->assertSee('wa.me/', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        $token = $this->tokenAus($antwort->getContent());
        // Das Token steht NIE im Klartext in der Datenbank.
        $this->assertDatabaseMissing('signature_signers', ['token_hash' => $token]);
        $this->assertTrue($request->events()->where('event', 'link_created')->exists());

        auth()->logout();
        $this->get(route('signature.show', $token))->assertOk()->assertSee('Unterschrift bestätigen', false);
    }

    public function test_im_buero_entfaellt_der_code_und_der_mitarbeiter_steht_im_protokoll(): void
    {
        $request = $this->versandfertig(pruefung: SignatureRequest::IDENTITY_EMAIL);
        app(SignatureRequestService::class)->send($request, perMail: false);
        $signer = $request->signers()->firstOrFail();

        // Gegenprobe: der normale Link verlangt den Code aus der E-Mail.
        $normal = $this->actingAs($this->admin)
            ->post(route('admin.signatures.link', [$request->id, $signer->id]));
        auth()->logout();
        $this->get(route('signature.show', $this->tokenAus($normal->getContent())))
            ->assertOk()->assertDontSee('Unterschrift bestätigen', false);

        $vorOrt = $this->actingAs($this->admin)
            ->post(route('admin.signatures.link', [$request->id, $signer->id]), ['vor_ort' => '1']);
        auth()->logout();
        $this->get(route('signature.show', $this->tokenAus($vorOrt->getContent())))
            ->assertOk()->assertSee('Unterschrift bestätigen', false);

        $ereignis = SignatureEvent::where('signature_request_id', $request->id)
            ->where('event', 'onsite_identity_confirmed')->firstOrFail();
        $this->assertSame((string) $this->admin->id, (string) $ereignis->user_id);
    }

    public function test_kein_link_fuer_einen_abgeschlossenen_vorgang(): void
    {
        $request = $this->versandfertig();
        app(SignatureRequestService::class)->send($request);
        $this->unterschreiben($request);
        $signer = $request->signers()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.signatures.link', [$request->id, $signer->id]))
            ->assertRedirect(route('admin.signatures.show', $request->id))
            ->assertSessionHas('error');
    }

    // --------------------------------------------------- 3: Die Einladung

    public function test_die_arabische_einladung_nennt_whatsapp_und_den_weg_fuer_fragen(): void
    {
        $request = $this->versandfertig(sprache: 'ar');
        app(SignatureRequestService::class)->send($request);

        Mail::assertSent(SignatureInvitationMail::class, function (SignatureInvitationMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'كما تواصلنا معك عبر واتساب')
                && str_contains($html, 'https://wa.me/'.preg_replace('/\D+/', '', (string) config('website.whatsapp')))
                && str_contains($html, e($this->admin->name))
                && ! str_contains($html, 'per WhatsApp besprochen');
        });
    }

    public function test_die_deutsche_einladung_erklaert_herkunft_und_zweck(): void
    {
        $request = $this->versandfertig();
        app(SignatureRequestService::class)->send($request);

        Mail::assertSent(SignatureInvitationMail::class, function (SignatureInvitationMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'per WhatsApp besprochen')
                && str_contains($html, 'Warum erhalten Sie diese E-Mail?')
                && str_contains((string) $mail->envelope()->subject, 'WhatsApp');
        });
    }

    // ------------------------------------------------------ 4: Die Liste

    public function test_die_liste_bietet_stornieren_und_neu_anfordern(): void
    {
        $offen = $this->versandfertig();
        app(SignatureRequestService::class)->send($offen);
        $fertig = $this->versandfertig();
        app(SignatureRequestService::class)->send($fertig);
        $this->unterschreiben($fertig);

        $this->actingAs($this->admin)->get(route('admin.signatures.index'))
            ->assertOk()
            ->assertSee(route('admin.signatures.cancel', $offen->id), false)
            ->assertSee(route('admin.signatures.reissue', $fertig->id), false);
    }
}
