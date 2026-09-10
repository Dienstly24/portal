<?php

namespace Tests\Feature;

use App\Mail\SignatureInvitationMail;
use App\Mail\SignatureVerificationMail;
use App\Models\Customer;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * DREI SPRACHEN FUER DEN UNTERZEICHNER (de/ar/en).
 *
 * Der Unterzeichner ist die einzige Person im System ohne Konto, ohne
 * Einweisung und ohne zweiten Versuch. Eine deutsche Zeile auf einer
 * arabischen Seite liest sich fuer ihn wie ein Defekt - deshalb wird hier
 * nicht nur geprueft, DASS uebersetzt wird, sondern auch, dass nichts
 * Deutsches uebrig bleibt.
 */
class SignatureLocalizationTest extends TestCase
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

    private function bild(): string
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

    /** @return array{0: SignatureRequest, 1: string} */
    private function vorbereiten(string $sprache): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf());
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload(
            new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true),
            ['title' => 'Vollmacht', 'require_email_verification' => false], $admin
        );
        $service->syncSigners($request, [
            ['name' => 'Person', 'email' => 'p@example.com', 'locale' => $sprache],
        ]);
        $request = $request->fresh()->load('signers');
        $service->syncFields($request, [[
            'signer_id' => $request->signers->first()->id, 'type' => SignatureFieldType::SIGNATURE,
            'page' => 1, 'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05, 'required' => true,
        ]]);
        $service->send($request->fresh()->load(['signers', 'fields']));

        return [$request->fresh(), app(SignatureTokenService::class)->issue($request->signers->first(), null)];
    }

    public function test_die_unterschriftsseite_erscheint_in_der_sprache_des_unterzeichners(): void
    {
        Mail::fake();
        [, $token] = $this->vorbereiten('ar');

        $antwort = $this->get(route('signature.show', $token));

        $antwort->assertOk();
        $antwort->assertSee('dir="rtl"', false);
        $antwort->assertSee('lang="ar"', false);
        $antwort->assertSee('تأكيد التوقيع', false);
        // KEINE MISCHSPRACHE: die deutschen Bedienelemente duerfen nicht
        // daneben stehen bleiben.
        $antwort->assertDontSee('Unterschrift bestätigen', false);
        $antwort->assertDontSee('Neu zeichnen', false);
    }

    public function test_englisch_bleibt_von_links_nach_rechts(): void
    {
        Mail::fake();
        [, $token] = $this->vorbereiten('en');

        $antwort = $this->get(route('signature.show', $token));

        $antwort->assertOk();
        $antwort->assertSee('lang="en"', false);
        $antwort->assertSee('dir="ltr"', false);
        $antwort->assertSee('Confirm signature', false);
    }

    public function test_deutsch_bleibt_der_standard(): void
    {
        Mail::fake();
        [, $token] = $this->vorbereiten('de');

        $this->get(route('signature.show', $token))
            ->assertOk()
            ->assertSee('lang="de"', false)
            ->assertSee('Unterschrift bestätigen', false);
    }

    public function test_die_einladung_kommt_in_der_sprache_des_unterzeichners(): void
    {
        Mail::fake();
        $this->vorbereiten('ar');

        Mail::assertSent(SignatureInvitationMail::class, function (SignatureInvitationMail $mail) {
            $gerendert = $mail->render();

            return str_contains($gerendert, 'dir="rtl"')
                && str_contains($gerendert, 'مستند للتوقيع')
                && ! str_contains($gerendert, 'Dokument zur Unterschrift');
        });
    }

    public function test_zwei_unterzeichner_koennen_verschiedene_sprachen_haben(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf());
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload(
            new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true),
            ['title' => 'Vollmacht', 'require_email_verification' => false], $admin
        );
        $service->syncSigners($request, [
            ['name' => 'Deutsch', 'email' => 'de@example.com', 'locale' => 'de'],
            ['name' => 'Arabisch', 'email' => 'ar@example.com', 'locale' => 'ar'],
        ]);

        $frisch = $request->fresh()->load('signers');
        $this->assertSame('de', $frisch->signers->firstWhere('email', 'de@example.com')->localeCode());
        $this->assertSame('ar', $frisch->signers->firstWhere('email', 'ar@example.com')->localeCode());
        $this->assertFalse($frisch->signers->firstWhere('email', 'de@example.com')->isRtl());
        $this->assertTrue($frisch->signers->firstWhere('email', 'ar@example.com')->isRtl());
    }

    public function test_das_pdf_wird_nicht_gespiegelt(): void
    {
        Mail::fake();
        [$request, $token] = $this->vorbereiten('ar');
        $signer = app(SignatureTokenService::class)->find($token);
        $werte = [];
        foreach ($signer->fields()->get() as $f) {
            $werte[$f->id] = $this->bild();
        }
        $this->post(route('signature.sign', $token), ['zustimmung' => '1', 'felder' => $werte]);

        $frisch = $request->fresh();
        $this->assertSame('completed', $frisch->status);
        $pdf = app(SignatureStorage::class)->read($frisch->signed_path);

        // DAS DOKUMENT IST KEINE OBERFLAECHE. Die Protokollseite bleibt
        // deutsch und von links nach rechts, auch wenn der Unterzeichner
        // arabisch bedient wurde: ein gespiegelter Vertrag waere kein
        // uebersetzter Vertrag, sondern ein unlesbarer.
        $this->assertStringContainsString('Signaturprotokoll', $pdf);
        // Keine Richtungssteuerzeichen im Dokument.
        foreach (["\u{200F}", "\u{202B}", "\u{202E}"] as $zeichen) {
            $this->assertStringNotContainsString($zeichen, $pdf);
        }
    }

    public function test_der_bestaetigungscode_steht_nicht_mehr_im_betreff(): void
    {
        Mail::fake();
        [, $token] = $this->vorbereiten('de');
        $signer = app(SignatureTokenService::class)->find($token);
        $signer->request->forceFill(['require_email_verification' => true])->save();

        $this->post(route('signature.code', $token));

        Mail::assertSent(SignatureVerificationMail::class, function ($mail) {
            // Ein Betreff erscheint in der Sperrbildschirm-Vorschau und in
            // jedem Weiterleitungs-Kopf - der zweite Faktor gehoert dort
            // nicht hin.
            return ! preg_match('/\d{6}/', $mail->envelope()->subject);
        });
    }

    public function test_alle_drei_sprachdateien_tragen_dieselben_schluessel(): void
    {
        $de = require base_path('lang/de/signing.php');
        foreach (['ar', 'en'] as $sprache) {
            $andere = require base_path('lang/'.$sprache.'/signing.php');
            $this->assertSame([], array_diff(array_keys($de), array_keys($andere)),
                'In lang/'.$sprache.'/signing.php fehlen Schluessel - dort erschiene der rohe Schluessel.');
            $this->assertSame([], array_diff(array_keys($andere), array_keys($de)),
                'lang/'.$sprache.'/signing.php hat Schluessel, die es auf Deutsch nicht gibt.');
            foreach ($andere as $schluessel => $wert) {
                $this->assertNotSame('', trim((string) $wert), 'Leerer Text: '.$sprache.'.'.$schluessel);
            }
        }
    }

    public function test_eine_unbekannte_sprache_faellt_auf_deutsch_zurueck(): void
    {
        Mail::fake();
        [$request] = $this->vorbereiten('de');
        // Direkt in der Spalte, wie es ein Altbestand oder ein Importfehler
        // hinterlassen koennte.
        $signer = $request->signers()->first();
        $signer->forceFill(['locale' => 'xx'])->save();

        $this->assertSame('de', $signer->fresh()->localeCode());
        $this->assertFalse($signer->fresh()->isRtl());
    }

    public function test_die_sprache_des_kunden_im_crm_wird_nie_geaendert(): void
    {
        Mail::fake();
        $kundenUser = User::factory()->create(['role' => 'customer', 'email' => 'k@example.com']);
        $kunde = Customer::create([
            'user_id' => $kundenUser->id,
            'customer_number' => 'K-SPRACHE',
            'preferred_lang' => 'ar',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf());
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload(
            new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true),
            ['title' => 'Vollmacht', 'customer_id' => (string) $kunde->id], $admin
        );
        // Fuer DIESEN Vorgang bewusst Englisch - das ist eine Aussage ueber
        // den Vorgang, nicht ueber den Kunden.
        $service->syncSigners($request, [
            ['name' => 'Kunde', 'email' => 'k@example.com', 'locale' => 'en'],
        ]);

        $this->assertSame('en', $request->fresh()->signers->first()->localeCode());
        $this->assertSame('ar', $kunde->fresh()->preferred_lang);
    }

    public function test_die_sprachliste_ist_die_eine_quelle(): void
    {
        $this->assertSame(['de', 'ar', 'en'], array_keys(SignatureSigner::LOCALES));
        foreach (array_keys(SignatureSigner::LOCALES) as $sprache) {
            $this->assertFileExists(base_path('lang/'.$sprache.'/signing.php'),
                'Fuer '.$sprache.' gibt es keine Uebersetzungsdatei - die Seite erschiene auf Deutsch.');
        }
    }
}
