<?php

namespace Tests\Feature;

use App\Mail\SignatureCompletedMail;
use App\Mail\SignatureInvitationMail;
use App\Models\ActivityLog;
use App\Models\InternalNotification;
use App\Models\SignatureField;
use App\Models\SignatureHandoff;
use App\Models\SignatureInternalSigning;
use App\Models\SignatureRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\Signature\InterneFreigabe;
use App\Services\Signature\PdfSichtbarkeit;
use App\Services\Signature\SignatureQualityGate;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureSigningService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\UserSignatureService;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * INTERNE UNTERSCHRIFT (Betreiber-Auftrag 04./07.10.2026, Teil B).
 *
 * Ein Mitarbeiter oder der Geschaeftsfuehrer unterschreibt das Dokument
 * selbst, bevor es an den Kunden geht. Die Faelle folgen den Vorgaben:
 *  - Zwei-Faktor ist Pflicht (Zeitfenster 4 h je Sitzung/Geraet, erlischt
 *    bei IP-, Geraete- und Passwortwechsel), der Bestaetigungssatz steht
 *    vor JEDEM Dokument;
 *  - gezeichnet, hochgeladen (Hintergrund weg) oder per Handy-QR
 *    (10 Minuten, einmal, an Sitzung gebunden);
 *  - Person A kann nie die Unterschrift von Person B benutzen;
 *  - eine nur fuer ein Dokument gezeichnete Unterschrift wird nicht
 *    hinterlegt, ausser "Als Standard speichern" ist angehakt;
 *  - Original -> Zwischenstand -> Endfassung, drei Hashes, sichtbar am Bild.
 */
class InterneUnterschriftTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;

    private string $geheimnis;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        SystemSetting::set('company_name', 'Dienstly24 GmbH');
        $this->geheimnis = Totp::generateSecret();
        $this->chef = User::factory()->create([
            'name' => 'Ahmad Albhre', 'role' => 'admin', 'signatur_funktion' => 'Geschäftsführer',
            'two_factor_secret' => $this->geheimnis, 'two_factor_confirmed_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ Bausteine

    private function code(?string $geheimnis = null): string
    {
        return Totp::code($geheimnis ?? $this->geheimnis);
    }

    private function mitarbeiter(array $attr = []): User
    {
        $geheimnis = Totp::generateSecret();

        return User::factory()->create($attr + [
            'role' => 'employee', 'two_factor_secret' => $geheimnis, 'two_factor_confirmed_at' => now(),
        ]);
    }

    /** Zeichnung wie aus der Zeichenflaeche, wahlweise mit Geraeteverhaeltnis. */
    private function zeichnung(int $dpr = 1): string
    {
        $b = imagecreatetruecolor(340 * $dpr, 120 * $dpr);
        imagesavealpha($b, true);
        imagealphablending($b, false);
        imagefill($b, 0, 0, imagecolorallocatealpha($b, 0, 0, 0, 127));
        imagealphablending($b, true);
        imagesetthickness($b, 6 * $dpr);
        $tinte = imagecolorallocate($b, 16, 32, 74);
        imageline($b, 10 * $dpr, 100 * $dpr, 330 * $dpr, 15 * $dpr, $tinte);
        imageline($b, 10 * $dpr, 60 * $dpr, 330 * $dpr, 60 * $dpr, $tinte);
        ob_start();
        imagepng($b);
        $png = (string) ob_get_clean();
        imagedestroy($b);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function pdf(): UploadedFile
    {
        $inhalt = "BT /F1 14 Tf 72 760 Td (Vertrag) Tj ET\n0 0 0 RG 2 w 60 150 m 280 150 l S\n";
        $objekte = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents 5 0 R >>',
            5 => '<< /Length '.strlen($inhalt)." >>\nstream\n".$inhalt.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objekte as $nr => $body) {
            $offsets[$nr] = strlen($pdf);
            $pdf .= $nr." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $pdf);

        return new UploadedFile($pfad, 'vertrag.pdf', 'application/pdf', null, true);
    }

    /**
     * Ein Entwurf mit einem internen Feld fuer $intern und - wenn gewuenscht -
     * einem Kunden-Unterzeichner mit Unterschriftsfeld.
     */
    private function entwurf(User $ersteller, ?User $intern, bool $mitKunde = true): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->pdf(), ['title' => 'Maklervertrag'], $ersteller);
        if ($mitKunde) {
            $service->syncSigners($request, [['name' => 'Max Mustermann', 'email' => 'max@example.com']]);
        }
        $request = $request->fresh()->load('signers');
        $felder = [];
        if ($intern !== null) {
            $felder[] = ['type' => SignatureFieldType::INTERNAL, 'internal_user_id' => $intern->id,
                'page' => 1, 'x' => 0.1, 'y' => 0.72, 'width' => 0.3, 'height' => 0.09];
        }
        if ($mitKunde) {
            $felder[] = ['type' => SignatureFieldType::SIGNATURE, 'signer_id' => $request->signers->first()->id,
                'page' => 1, 'x' => 0.55, 'y' => 0.72, 'width' => 0.3, 'height' => 0.08];
        }
        $service->syncFields($request, $felder);

        return $request->fresh()->load(['signers', 'fields']);
    }

    /** Hinterlegt eine Unterschrift fuer $user direkt ueber den Dienst. */
    private function hinterlege(User $user): UserSignature
    {
        return app(UserSignatureService::class)
            ->speichereZeichnung($user, $this->zeichnung(), UserSignature::UNTERSCHRIFT, 'Testgeraet');
    }

    private function internUnterschreiben(User $user, SignatureRequest $request, array $extra = [], ?string $code = null)
    {
        return $this->actingAs($user)->postJson(route('admin.signatures.internal.sign', $request->id), $extra + [
            'quelle' => 'gespeichert',
            'bestaetigt' => 1,
            'code' => $code ?? $this->code(),
        ]);
    }

    private function sichtbar(string $original, string $pdf, array $box): string
    {
        $s = app(PdfSichtbarkeit::class);

        return $s->vergleiche($s->renderPaar($original, $pdf, 1), $box)['urteil'];
    }

    private function pdfText(string $pdf): string
    {
        $pfad = tempnam(sys_get_temp_dir(), 'txt').'.pdf';
        file_put_contents($pfad, $pdf);
        $p = new Process(['pdftotext', '-layout', $pfad, '-']);
        $p->run();
        @unlink($pfad);
        $this->assertTrue($p->isSuccessful(), 'pdftotext (poppler-utils) gehoert zur Testumgebung.');

        return $p->getOutput();
    }

    // ---------------------------------------------------- Meine Unterschrift

    public function test_zeichnen_im_profil_braucht_zwei_faktor_und_meldet_den_admins(): void
    {
        $andererAdmin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($this->chef)->postJson(route('admin.meine_unterschrift.draw'), [
            'kind' => 'unterschrift', 'zeichnung' => $this->zeichnung(3),
        ])->assertStatus(422)->assertJson(['code_noetig' => true]);
        $this->assertSame(0, UserSignature::count(), 'Ohne zweiten Faktor wird nichts hinterlegt.');

        $this->actingAs($this->chef)->postJson(route('admin.meine_unterschrift.draw'), [
            'kind' => 'unterschrift', 'zeichnung' => $this->zeichnung(3), 'code' => $this->code(),
        ])->assertOk()->assertJson(['ok' => true]);

        $sig = UserSignature::firstOrFail();
        $this->assertSame(UserSignature::GEZEICHNET, $sig->method);
        $this->assertSame(hash('sha256', (string) app(SignatureStorage::class)->read($sig->path)), $sig->hash);
        $this->assertTrue(InternalNotification::where('user_id', $andererAdmin->id)->where('title', 'like', 'Hinterlegte Unterschrift%')->exists(),
            'Jede Aenderung meldet die Glocke an die Admins.');
        $this->assertTrue(ActivityLog::where('action', 'user_signature_changed')->exists());
    }

    public function test_ersetzen_archiviert_die_alte_fassung(): void
    {
        $alt = $this->hinterlege($this->chef);
        $neu = $this->hinterlege($this->chef);

        $this->assertFalse($alt->fresh()->active);
        $this->assertNotNull($alt->fresh()->archived_at);
        $this->assertTrue($neu->fresh()->active);
        $this->assertSame(2, UserSignature::count(), 'Archiviert, nie geloescht.');
    }

    public function test_hochladen_mit_weissem_grund_wird_freigestellt(): void
    {
        $b = imagecreatetruecolor(600, 220);
        imagefill($b, 0, 0, imagecolorallocate($b, 252, 252, 250));
        imagesetthickness($b, 7);
        imageline($b, 40, 170, 560, 60, imagecolorallocate($b, 20, 30, 80));
        $pfad = tempnam(sys_get_temp_dir(), 'u').'.jpg';
        imagejpeg($b, $pfad, 92);
        $datei = fn () => new UploadedFile($pfad, 'unterschrift.jpg', 'image/jpeg', null, true);

        // Vorschau: nichts gespeichert, aber das Bild kommt freigestellt zurueck.
        $vorschau = $this->actingAs($this->chef)->postJson(route('admin.meine_unterschrift.upload'), [
            'kind' => 'unterschrift', 'datei' => $datei(), 'vorschau' => 1,
        ])->assertOk()->json('vorschau');
        $this->assertSame(0, UserSignature::count());
        $this->assertStringStartsWith('data:image/png;base64,', $vorschau);

        $this->actingAs($this->chef)->postJson(route('admin.meine_unterschrift.upload'), [
            'kind' => 'unterschrift', 'datei' => $datei(), 'code' => $this->code(),
        ])->assertOk();

        $sig = UserSignature::firstOrFail();
        $this->assertSame(UserSignature::HOCHGELADEN, $sig->method);
        $bild = imagecreatefromstring((string) app(SignatureStorage::class)->read($sig->path));
        $ecke = imagecolorat($bild, 0, 0);
        $this->assertSame(127, ($ecke >> 24) & 0x7F, 'Der weisse Grund ist durchsichtig.');
        $deckend = 0;
        for ($x = 0; $x < imagesx($bild); $x += 3) {
            for ($y = 0; $y < imagesy($bild); $y += 3) {
                if (((imagecolorat($bild, $x, $y) >> 24) & 0x7F) < 30) {
                    $deckend++;
                }
            }
        }
        $this->assertGreaterThan(20, $deckend, 'Die Tinte bleibt deckend.');
    }

    public function test_zu_helles_foto_wird_abgelehnt(): void
    {
        $b = imagecreatetruecolor(600, 220);
        imagefill($b, 0, 0, imagecolorallocate($b, 250, 250, 250));
        imageline($b, 40, 170, 560, 60, imagecolorallocate($b, 232, 232, 232));
        $pfad = tempnam(sys_get_temp_dir(), 'u').'.png';
        imagepng($b, $pfad);

        $this->actingAs($this->chef)->postJson(route('admin.meine_unterschrift.upload'), [
            'kind' => 'unterschrift', 'datei' => new UploadedFile($pfad, 'u.png', 'image/png', null, true), 'vorschau' => 1,
        ])->assertStatus(422)->assertJsonFragment(['ok' => false]);
        $this->assertStringContainsString('zu hell', (string) $this->actingAs($this->chef)->postJson(route('admin.meine_unterschrift.upload'), [
            'kind' => 'unterschrift', 'datei' => new UploadedFile($pfad, 'u.png', 'image/png', null, true), 'vorschau' => 1,
        ])->json('message'));
    }

    public function test_ohne_recht_kein_profil_kein_hinterlegen(): void
    {
        $ma = $this->mitarbeiter();

        $this->actingAs($ma)->get(route('admin.meine_unterschrift'))->assertForbidden();
        $this->actingAs($ma)->postJson(route('admin.meine_unterschrift.draw'), [
            'kind' => 'unterschrift', 'zeichnung' => $this->zeichnung(),
        ])->assertForbidden();
        $this->assertSame(0, UserSignature::count());

        $ma->forceFill(['can_sign_for_company' => true])->save();
        $this->actingAs($ma)->get(route('admin.meine_unterschrift'))->assertOk()->assertSee('Meine Unterschrift');
    }

    public function test_nur_der_admin_vergibt_das_recht(): void
    {
        $ma = $this->mitarbeiter(['name' => 'Mia Muster']);
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager)->put(route('admin.employees.update', $ma->id), [
            'name' => 'Mia Muster', 'unterschriftsrecht_present' => 1, 'can_sign_for_company' => 1, 'signatur_funktion' => 'Prokuristin',
        ]);
        $this->assertFalse((bool) $ma->fresh()->can_sign_for_company, 'Ein Manager vergibt das Recht nicht.');

        $this->actingAs($this->chef)->put(route('admin.employees.update', $ma->id), [
            'name' => 'Mia Muster', 'unterschriftsrecht_present' => 1, 'can_sign_for_company' => 1, 'signatur_funktion' => 'Prokuristin',
        ]);
        $this->assertTrue((bool) $ma->fresh()->can_sign_for_company);
        $this->assertSame('Prokuristin', $ma->fresh()->signaturFunktion());
    }

    // ------------------------------------------------------- Handy per QR

    public function test_handy_qr_von_anfang_bis_ende_und_nur_einmal(): void
    {
        $antwort = $this->actingAs($this->chef)->postJson(route('admin.meine_unterschrift.handoff'))->assertOk();
        $this->assertStringContainsString('<svg', (string) $antwort->json('qr'));
        $handoff = SignatureHandoff::findOrFail($antwort->json('id'));

        // Die Handy-Seite kennt nur den Link - das Token steckt im QR-Code.
        $token = $this->tokenFuer($handoff);
        auth()->logout();
        $this->get(route('signature.handoff.show', $token))->assertOk()->assertSee('Unterschreiben');
        $this->postJson(route('signature.handoff.store', $token), ['zeichnung' => $this->zeichnung(2)])->assertOk();
        $this->postJson(route('signature.handoff.store', $token), ['zeichnung' => $this->zeichnung(2)])->assertStatus(410);
        $this->get(route('signature.handoff.show', $token))->assertStatus(410);

        // Am Rechner erscheint die Zeichnung ...
        $this->actingAs($this->chef)->getJson(route('admin.meine_unterschrift.handoff.status', $handoff->id))
            ->assertOk()->assertJson(['fertig' => true]);
        // ... und wird erst nach Bestaetigung (Zwei-Faktor) hinterlegt.
        $this->actingAs($this->chef)->postJson(route('admin.meine_unterschrift.draw'), [
            'kind' => 'unterschrift', 'handoff_id' => $handoff->id, 'code' => $this->code(),
        ])->assertOk();
        $this->assertSame(UserSignature::HANDY_QR, UserSignature::firstOrFail()->method);
        $this->assertSame('per Handy-QR gezeichnet', UserSignature::firstOrFail()->methodLabel());
    }

    public function test_abgelaufener_qr_wird_abgelehnt(): void
    {
        $id = $this->actingAs($this->chef)->postJson(route('admin.meine_unterschrift.handoff'))->json('id');
        $token = $this->tokenFuer(SignatureHandoff::findOrFail($id));

        $this->travel(SignatureHandoff::GUELTIG_MINUTEN + 1)->minutes();
        $this->get(route('signature.handoff.show', $token))->assertStatus(410)->assertSee('abgelaufen');
        $this->postJson(route('signature.handoff.store', $token), ['zeichnung' => $this->zeichnung()])->assertStatus(410);
        $this->assertNull(SignatureHandoff::findOrFail($id)->image_path);
    }

    public function test_das_handy_ergebnis_bekommt_nur_konto_und_sitzung_des_erzeugers(): void
    {
        $id = $this->actingAs($this->chef)->postJson(route('admin.meine_unterschrift.handoff'))->json('id');
        $this->postJson(route('signature.handoff.store', $this->tokenFuer(SignatureHandoff::findOrFail($id))), ['zeichnung' => $this->zeichnung()])->assertOk();

        $kollege = $this->mitarbeiter(['can_sign_for_company' => true]);
        $this->actingAs($kollege)->getJson(route('admin.meine_unterschrift.handoff.status', $id))->assertNotFound();

        // Dasselbe Konto in einer ANDEREN Sitzung (z.B. zweiter Browser) bekommt es auch nicht.
        $handoff = SignatureHandoff::findOrFail($id);
        $handoff->forceFill(['session_hash' => hash('sha256', 'andere-sitzung')])->save();
        $this->actingAs($this->chef)->getJson(route('admin.meine_unterschrift.handoff.status', $id))->assertJson(['fertig' => false]);
    }

    /** Das Token steht nur im QR-Code - im Test wird es fuer den Datensatz neu gesetzt. */
    private function tokenFuer(SignatureHandoff $handoff): string
    {
        $token = str_repeat('a', 30).substr(md5($handoff->id), 0, 10);
        $handoff->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    // ---------------------------------------------- Dokument unterschreiben

    public function test_intern_kunde_endfassung_drei_hashes_und_sichtbar(): void
    {
        $this->hinterlege($this->chef);
        $request = $this->entwurf($this->chef, $this->chef);
        $original = (string) app(SignatureStorage::class)->read($request->original_path);

        $this->internUnterschreiben($this->chef, $request, ['und_senden' => 1])->assertOk()->assertJson(['ok' => true]);

        $request->refresh();
        $this->assertSame(SignatureStatus::SENT, $request->status, 'Nach der internen Unterschrift geht es an den Kunden.');
        Mail::assertSent(SignatureInvitationMail::class);
        $zwischen = (string) app(SignatureStorage::class)->read($request->zwischenstand_path);
        $this->assertStringStartsWith($original, $zwischen, 'Der Zwischenstand ist eine Fortschreibung des Originals.');
        $this->assertSame(hash('sha256', $zwischen), $request->zwischenstand_hash);
        $this->assertSame('sichtbar', $this->sichtbar($original, $zwischen, [0.1, 0.72, 0.3, 0.09]),
            'Der Kunde sieht die interne Unterschrift bereits.');

        $eintrag = SignatureInternalSigning::firstOrFail();
        $this->assertSame($request->original_hash, $eintrag->document_hash_before);
        $this->assertSame($request->zwischenstand_hash, $eintrag->document_hash_after);
        $this->assertSame(SignatureInternalSigning::REAUTH_ABGEFRAGT, $eintrag->reauth_method);
        $this->assertSame('Ich unterschreibe dieses Dokument als Ahmad Albhre, Geschäftsführer.', $eintrag->bestaetigung);

        // Der Kunde unterschreibt - die Endfassung baut auf dem Zwischenstand auf.
        $request = $request->fresh()->load(['signers', 'fields']);
        $this->assertSame([], app(SignatureSigningService::class)->sign($request, $request->signers->first(), [],
            [SignatureFieldType::SIGNATURE => $this->zeichnung()]));
        $request->refresh();
        $this->assertSame(SignatureStatus::COMPLETED, $request->status, json_encode($request->quality_findings));
        $ende = (string) app(SignatureStorage::class)->read($request->signed_path);
        $this->assertStringStartsWith($zwischen, $ende, 'Endfassung = Fortschreibung des Zwischenstands.');
        $this->assertSame('sichtbar', $this->sichtbar($original, $ende, [0.1, 0.72, 0.3, 0.09]), 'Interne Unterschrift im fertigen PDF.');
        $this->assertSame('sichtbar', $this->sichtbar($original, $ende, [0.55, 0.72, 0.3, 0.08]), 'Kundenunterschrift im fertigen PDF.');

        $text = $this->pdfText($ende);
        $this->assertStringContainsString('Interne Unterschriften', $text);
        $this->assertStringContainsString('Ahmad Albhre (Geschäftsführer)', $text);
        $this->assertStringContainsString('SHA-256 nach der internen Unterschrift: '.$request->zwischenstand_hash, preg_replace('/\s+/', ' ', $text));
        $this->assertStringContainsString($request->original_hash, preg_replace('/\s+/', '', $text));
    }

    public function test_bestaetigung_ist_bei_jedem_dokument_pflicht_der_code_nicht(): void
    {
        $this->hinterlege($this->chef);
        $a = $this->entwurf($this->chef, $this->chef);
        $b = $this->entwurf($this->chef, $this->chef);

        $this->internUnterschreiben($this->chef, $a, ['bestaetigt' => 0])->assertStatus(422);
        $this->assertNull($a->fresh()->zwischenstand_path);

        $this->internUnterschreiben($this->chef, $a)->assertOk();

        // Zweites Dokument in DERSELBEN Sitzung: kein Code noetig ...
        $this->actingAs($this->chef)->postJson(route('admin.signatures.internal.sign', $b->id), [
            'quelle' => 'gespeichert', 'bestaetigt' => 0,
        ])->assertStatus(422)->assertJson(['code_noetig' => false]);
        $this->actingAs($this->chef)->postJson(route('admin.signatures.internal.sign', $b->id), [
            'quelle' => 'gespeichert', 'bestaetigt' => 1,
        ])->assertOk();
        // ... aber die Bestaetigung steht im Protokoll, je Dokument eine.
        $this->assertSame(2, SignatureInternalSigning::count());
        $this->assertSame(SignatureInternalSigning::REAUTH_ABGEFRAGT, SignatureInternalSigning::where('signature_request_id', $b->id)->value('reauth_method'));
    }

    public function test_falscher_code_und_fehlender_zweiter_faktor(): void
    {
        $this->hinterlege($this->chef);
        $request = $this->entwurf($this->chef, $this->chef);

        $this->internUnterschreiben($this->chef, $request, [], '000000')->assertStatus(422)->assertJson(['code_noetig' => true]);
        $this->assertTrue(ActivityLog::where('action', 'two_factor_failed')->exists(), 'Fehlversuche stehen im Protokoll.');

        $ohne = User::factory()->create(['role' => 'admin']);
        $r2 = $this->entwurf($ohne, $ohne);
        $this->actingAs($ohne)->postJson(route('admin.signatures.internal.sign', $r2->id), [
            'quelle' => 'neu', 'zeichnung' => $this->zeichnung(), 'bestaetigt' => 1,
        ])->assertStatus(422)->assertJsonFragment(['code_noetig' => false]);
        $this->assertSame(0, SignatureInternalSigning::count(), 'Ein Passwort allein genuegt nie.');
    }

    public function test_zeitfenster_erlischt_bei_ip_geraet_passwort_und_nach_vier_stunden(): void
    {
        $freigabe = app(InterneFreigabe::class);
        $neu = function (string $ip = '10.0.0.1', string $ua = 'Browser A') {
            $r = Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua]);
            $r->setLaravelSession(app('session.store'));

            return $r;
        };
        $freigabe->vermerke($neu(), $this->chef, SignatureInternalSigning::REAUTH_LOGIN);
        $this->assertNotNull($freigabe->gueltig($neu(), $this->chef));
        $this->assertNull($freigabe->gueltig($neu('10.0.0.2'), $this->chef), 'Andere IP.');

        $freigabe->vermerke($neu(), $this->chef, SignatureInternalSigning::REAUTH_LOGIN);
        $this->assertNull($freigabe->gueltig($neu('10.0.0.1', 'Browser B'), $this->chef), 'Anderes Geraet.');

        $freigabe->vermerke($neu(), $this->chef, SignatureInternalSigning::REAUTH_LOGIN);
        $this->chef->setPassword('ein-ganz-neues-langes-passwort');
        $this->assertNull($freigabe->gueltig($neu(), $this->chef->fresh()), 'Passwortwechsel.');

        $freigabe->vermerke($neu(), $this->chef->fresh(), SignatureInternalSigning::REAUTH_LOGIN);
        $this->travel(InterneFreigabe::STANDARD_STUNDEN)->hours();
        $this->assertNull($freigabe->gueltig($neu(), $this->chef->fresh()), 'Nach vier Stunden.');
    }

    public function test_person_a_kann_nie_die_unterschrift_von_person_b_benutzen(): void
    {
        $b = $this->mitarbeiter(['name' => 'Bea Berger', 'can_sign_for_company' => true]);
        $sigB = $this->hinterlege($b);
        $request = $this->entwurf($this->chef, $b);

        // A ist nicht zugeordnet: Policy sperrt, kein Bild wird gesetzt.
        $this->internUnterschreiben($this->chef, $request)->assertForbidden();

        // A hat ein eigenes Feld, aber keine eigene Unterschrift - B's wird nie genommen.
        $eigen = $this->entwurf($this->chef, $this->chef);
        $this->internUnterschreiben($this->chef, $eigen, ['user_signature_id' => $sigB->id])->assertStatus(422)
            ->assertJsonFragment(['ok' => false]);
        $this->assertNull($eigen->fresh()->zwischenstand_path);

        // Und das Bild von B ist fuer A nicht abrufbar.
        $this->actingAs($this->chef)->get(route('admin.meine_unterschrift.image', $sigB->id))->assertNotFound();
        $this->assertSame(0, SignatureInternalSigning::count());
    }

    public function test_einmal_gezeichnet_wird_nur_mit_haken_hinterlegt(): void
    {
        $a = $this->entwurf($this->chef, $this->chef);
        $this->internUnterschreiben($this->chef, $a, ['quelle' => 'neu', 'zeichnung' => $this->zeichnung(2)])->assertOk();
        $this->assertSame(0, UserSignature::count(), 'Ohne Haken nur fuer dieses Dokument.');
        $this->assertNull(SignatureInternalSigning::firstOrFail()->user_signature_id);
        $this->assertSame(UserSignature::GEZEICHNET, SignatureInternalSigning::firstOrFail()->image_method);

        $b = $this->entwurf($this->chef, $this->chef);
        $this->internUnterschreiben($this->chef, $b, ['quelle' => 'neu', 'zeichnung' => $this->zeichnung(2), 'als_standard' => 1])->assertOk();
        $this->assertSame(1, UserSignature::where('active', true)->count(), 'Mit Haken als Standard gespeichert.');
    }

    public function test_nur_intern_ist_sofort_fertig_ohne_mail(): void
    {
        $this->hinterlege($this->chef);
        $request = $this->entwurf($this->chef, $this->chef, mitKunde: false);
        $this->assertSame([], app(SignatureRequestService::class)->blockersForSending($request));

        $this->internUnterschreiben($this->chef, $request, ['und_senden' => 1])->assertOk();

        $request->refresh();
        $this->assertSame(SignatureStatus::COMPLETED, $request->status);
        $this->assertNotNull($request->signed_path);
        Mail::assertNothingSent();
        Mail::assertNotSent(SignatureCompletedMail::class);
    }

    public function test_mitarbeiter_bereitet_vor_chef_unterschreibt_dann_der_kunde(): void
    {
        $ma = $this->mitarbeiter(['name' => 'Mia Muster']);
        $this->hinterlege($this->chef);
        $request = $this->entwurf($ma, $this->chef);

        $this->actingAs($ma)->postJson(route('admin.signatures.send', $request->id))
            ->assertOk()->assertJsonFragment(['message' => 'Der Versand wartet auf die interne Unterschrift - die Kollegen wurden benachrichtigt.']);
        $request->refresh();
        $this->assertTrue($request->isDraft());
        $this->assertTrue($request->send_after_internal);
        Mail::assertNothingSent();
        $glocke = InternalNotification::where('user_id', $this->chef->id)->where('title', 'Wartet auf Ihre Unterschrift')->first();
        $this->assertNotNull($glocke, 'Der Chef bekommt die Glocke.');

        // Der Chef oeffnet die Detailseite und unterschreibt mit einem Klick.
        $this->actingAs($this->chef)->get(route('admin.signatures.show', $request->id))
            ->assertOk()->assertSee('Wartet auf Ihre Unterschrift');
        $this->internUnterschreiben($this->chef, $request)->assertOk();

        $request->refresh();
        $this->assertSame(SignatureStatus::SENT, $request->status, 'Der Versand laeuft danach automatisch.');
        Mail::assertSent(SignatureInvitationMail::class, 1);

        $request->load(['signers', 'fields']);
        app(SignatureSigningService::class)->sign($request, $request->signers->first(), [], [SignatureFieldType::SIGNATURE => $this->zeichnung()]);
        $this->assertSame(SignatureStatus::COMPLETED, $request->fresh()->status);
    }

    public function test_eigene_offene_unterschrift_blockiert_den_einfachen_versand(): void
    {
        $this->hinterlege($this->chef);
        $request = $this->entwurf($this->chef, $this->chef);

        $this->actingAs($this->chef)->postJson(route('admin.signatures.send', $request->id))
            ->assertStatus(422)->assertJsonFragment(['message' => 'Ihre eigene interne Unterschrift fehlt noch - bitte zuerst unterschreiben.']);
        $this->assertTrue($request->fresh()->isDraft());
    }

    public function test_ohne_recht_keine_interne_feldart_und_kein_feld(): void
    {
        $ma = $this->mitarbeiter(['name' => 'Mia Muster']);
        $request = $this->entwurf($ma, null);

        $html = $this->actingAs($ma)->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Meine Unterschrift', $html);
        $this->assertStringNotContainsString('data-h-click="sigInternJetzt"', $html);
        $this->assertStringNotContainsString('id="intern-dialog"', $html);
        $this->assertStringContainsString('Ahmad Albhre', $html, 'Die Geschaeftsleitung ist als interne Person waehlbar.');

        // Ein Feld fuer jemanden ohne Recht entsteht nicht - auch nicht ueber ein nachgebautes Formular.
        app(SignatureRequestService::class)->syncFields($request, [
            ['type' => SignatureFieldType::INTERNAL, 'internal_user_id' => $ma->id, 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.08],
        ]);
        $this->assertSame(0, SignatureField::where('type', SignatureFieldType::INTERNAL)->count());

        $html = $this->actingAs($this->chef)->get(route('admin.signatures.prepare', $request->id))->getContent();
        $this->assertStringContainsString('karte-intern', $html);
        $this->assertStringContainsString('Meine Unterschrift', $html);
    }

    public function test_gesetzte_unterschrift_bleibt_stehen_und_laesst_sich_zuruecknehmen(): void
    {
        $this->hinterlege($this->chef);
        $request = $this->entwurf($this->chef, $this->chef);
        $this->internUnterschreiben($this->chef, $request)->assertOk();
        $feld = SignatureField::where('type', SignatureFieldType::INTERNAL)->firstOrFail();

        // Der Editor schickt eine verschobene Fassung - die gesetzte bleibt, wo sie ist.
        app(SignatureRequestService::class)->syncFields($request->fresh(), [
            ['id' => $feld->id, 'type' => SignatureFieldType::INTERNAL, 'internal_user_id' => $this->chef->id, 'page' => 1, 'x' => 0.5, 'y' => 0.1, 'width' => 0.3, 'height' => 0.09],
        ]);
        $this->assertEqualsWithDelta(0.1, $feld->fresh()->pos_x, 0.0001);
        $this->assertTrue($feld->fresh()->isFilled());

        $this->actingAs($this->chef)->post(route('admin.signatures.internal.reset', $request->id))->assertRedirect();
        $request->refresh();
        $this->assertNull($request->zwischenstand_path);
        $this->assertFalse($feld->fresh()->isFilled());
        $this->assertSame(1, SignatureInternalSigning::count(), 'Das Protokoll bleibt (append-only).');
        $this->assertCount(0, $request->gueltigeInterneUnterschriften(), 'Im Dokument steht sie nicht mehr.');
    }

    public function test_langsame_fertigstellung_wird_gemeldet(): void
    {
        // KI-090: ab 5 s steht eine Warnung im Log - der Hinweis, wann der
        // Abschluss in einen Hintergrund-Job gehoert.
        Log::spy();
        $request = $this->entwurf($this->chef, null);
        app(SignatureQualityGate::class)->vermerke($request, [], 'Abschluss', 900);
        Log::shouldNotHaveReceived('warning');

        app(SignatureQualityGate::class)->vermerke($request, [], 'Abschluss', 6200);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, '6200 ms') && str_contains($m, 'KI-090'))->once();
    }
}
