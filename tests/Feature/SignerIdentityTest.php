<?php

namespace Tests\Feature;

use App\Mail\SignatureInvitationMail;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureTokenService;
use App\Services\Signature\SignerIdentityService;
use App\Support\SignatureFieldType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ZUSAETZLICHE IDENTITAETSPRUEFUNG ueber das GEBURTSDATUM.
 *
 * Das Geburtsdatum ist KEIN Geheimnis - es steht auf jedem Ausweis und in
 * jedem Versicherungsschein. Es leistet genau eine Sache: es haelt den
 * zufaelligen Empfaenger eines weitergeleiteten Links auf. Diese Faelle
 * halten fest, dass es genau so viel leistet und keinen Schritt mehr - und
 * dass der Wert nirgends auftaucht, wo ihn jemand mitlesen koennte.
 */
class SignerIdentityTest extends TestCase
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

    /** @return array{0: SignatureRequest, 1: string} */
    private function vorbereiten(?string $geburtstag = '01.05.1980', string $pruefung = SignatureRequest::IDENTITY_DOB): array
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf());
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload(
            new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true),
            ['title' => 'Vollmacht', 'identity_check' => $pruefung], $admin
        );
        $service->syncSigners($request, [
            ['name' => 'Person', 'email' => 'p@example.com', 'date_of_birth' => $geburtstag],
        ]);
        $request = $request->fresh()->load('signers');
        $service->syncFields($request, [[
            'signer_id' => $request->signers->first()->id, 'type' => SignatureFieldType::SIGNATURE,
            'page' => 1, 'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05, 'required' => true,
        ]]);
        $service->send($request->fresh()->load(['signers', 'fields']));

        return [$request->fresh(), app(SignatureTokenService::class)->issue($request->signers->first(), null)];
    }

    public function test_ohne_geburtsdatum_kommt_niemand_an_das_dokument(): void
    {
        [, $token] = $this->vorbereiten();

        $antwort = $this->get(route('signature.show', $token));

        $antwort->assertOk();
        $antwort->assertSee('Geburtsdatum', false);
        // Das Dokument selbst bleibt zu - auch die Seitenbilder.
        $this->get(route('signature.page', [$token, 1]))->assertForbidden();
        $this->get(route('signature.document', $token))->assertForbidden();
    }

    public function test_das_richtige_datum_oeffnet_das_dokument(): void
    {
        [, $token] = $this->vorbereiten();

        $this->post(route('signature.identity', $token), ['geburtsdatum' => '01.05.1980'])
            ->assertRedirect(route('signature.show', $token));

        $this->get(route('signature.show', $token))->assertOk()->assertSee('Unterschrift bestätigen', false);
    }

    public function test_mehrere_schreibweisen_werden_angenommen(): void
    {
        foreach (['01.05.1980', '1.5.1980', '1980-05-01', '01/05/1980'] as $schreibweise) {
            [, $token] = $this->vorbereiten();
            $this->post(route('signature.identity', $token), ['geburtsdatum' => $schreibweise])
                ->assertRedirect(route('signature.show', $token));
            $this->assertNotNull(app(SignatureTokenService::class)->find($token)->dob_verified_at,
                'Schreibweise abgelehnt: '.$schreibweise);
        }
    }

    public function test_das_falsche_datum_verraet_nichts(): void
    {
        [, $token] = $this->vorbereiten();

        $antwort = $this->post(route('signature.identity', $token), ['geburtsdatum' => '02.05.1980']);

        $antwort->assertRedirect();
        $meldung = (string) session('error');
        // Kein "fast richtig", kein "falsches Jahr", keine Zahl der noch
        // verbleibenden Versuche - jede dieser Angaben waere eine Ratehilfe.
        $this->assertStringNotContainsString('1980', $meldung);
        $this->assertStringNotContainsString('Versuch', $meldung);
    }

    public function test_nach_fuenf_fehlversuchen_ist_gesperrt(): void
    {
        [, $token] = $this->vorbereiten();

        for ($i = 0; $i < SignerIdentityService::MAX_ATTEMPTS; $i++) {
            $this->post(route('signature.identity', $token), ['geburtsdatum' => '02.05.1980']);
        }

        $signer = app(SignatureTokenService::class)->find($token);
        $this->assertTrue(app(SignerIdentityService::class)->isBlocked($signer));

        // Auch das RICHTIGE Datum kommt jetzt nicht mehr durch - sonst waere
        // die Sperre nur eine Verzoegerung fuer den, der weiterraet.
        $this->post(route('signature.identity', $token), ['geburtsdatum' => '01.05.1980']);
        $this->assertNull(app(SignatureTokenService::class)->find($token)->dob_verified_at);
    }

    public function test_der_wert_steht_nie_im_protokoll_und_nie_in_der_datenbank_im_klartext(): void
    {
        [$request, $token] = $this->vorbereiten();
        $this->post(route('signature.identity', $token), ['geburtsdatum' => '03.09.1975']);
        $this->post(route('signature.identity', $token), ['geburtsdatum' => '01.05.1980']);

        foreach ($request->fresh()->events()->get() as $ereignis) {
            $text = $ereignis->description.' '.json_encode($ereignis->meta);
            $this->assertStringNotContainsString('1980', $text, 'Der Wert steht im Protokoll.');
            $this->assertStringNotContainsString('1975', $text, 'Die Falscheingabe steht im Protokoll.');
        }

        // In der Spalte liegt der Wert verschluesselt - wer die Datenbank
        // liest, findet ihn nicht.
        $roh = DB::table('signature_signers')
            ->where('id', app(SignatureTokenService::class)->find($token)->id)->value('dob_check');
        $this->assertNotSame('1980-05-01', $roh);
        $this->assertStringNotContainsString('1980', (string) $roh);
    }

    public function test_das_datum_steht_in_keiner_url_und_in_keiner_mail(): void
    {
        [, $token] = $this->vorbereiten();

        // Der Zugangslink traegt nur das Token.
        $this->assertStringNotContainsString('1980', route('signature.show', $token));

        Mail::assertSent(SignatureInvitationMail::class, function ($mail) {
            return ! str_contains($mail->render(), '1980');
        });
    }

    public function test_ohne_hinterlegtes_datum_wird_nicht_gefragt(): void
    {
        // Sonst waere es eine Sackgasse: niemand koennte die Frage richtig
        // beantworten. "Irgendwas genuegt" waere eine Pruefung, die nichts
        // prueft - also wird gar nicht gefragt.
        [, $token] = $this->vorbereiten(null);

        $this->get(route('signature.show', $token))->assertOk()->assertSee('Unterschrift bestätigen', false);
    }

    public function test_die_pruefung_gilt_auch_fuer_das_unterschreiben_selbst(): void
    {
        [, $token] = $this->vorbereiten();
        $signer = app(SignatureTokenService::class)->find($token);
        $werte = [];
        foreach ($signer->fields()->get() as $f) {
            $werte[$f->id] = 'x';
        }

        // Ein direkt abgesetztes Formular umgeht die Seite - der Server
        // laesst es trotzdem nicht durch.
        $this->post(route('signature.sign', $token), ['zustimmung' => '1', 'felder' => $werte])
            ->assertRedirect(route('signature.show', $token));

        $this->assertFalse(app(SignatureTokenService::class)->find($token)->hasSigned());
    }

    public function test_keine_pruefung_heisst_direkt_zum_dokument(): void
    {
        [, $token] = $this->vorbereiten(null, SignatureRequest::IDENTITY_NONE);

        $this->get(route('signature.show', $token))->assertOk()->assertSee('Unterschrift bestätigen', false);
    }

    public function test_es_gibt_keinen_sms_weg(): void
    {
        // Betreiber-Vorgabe: kein SMS. Ein weiterer Dienstleister, ein
        // weiterer Vertrag, eine weitere Datenspur - und der Nutzen waere
        // gering, weil die Nummer meist aus derselben Quelle stammt wie die
        // E-Mail-Adresse.
        $this->assertSame(
            ['keine', 'geburtsdatum', 'email'],
            SignatureRequest::identityCheckKeys()
        );
    }
}
