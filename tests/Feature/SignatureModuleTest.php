<?php

namespace Tests\Feature;

use App\Mail\SignatureCompletedMail;
use App\Mail\SignatureInvitationMail;
use App\Mail\SignatureVerificationMail;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Pdf\PdfDocument;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Das native E-Signatur-Modul (Betreiber-Auftrag 09.09.2026).
 *
 * Die Abnahmefaelle folgen den beiden Szenarien der Spezifikation:
 * A) aus einer bestehenden Kundenakte heraus, B) eigenstaendig ohne Kunden
 * mit Zuordnung NACH dem Unterschreiben. Dazu die Sicherheitsfragen, an
 * denen ein Signatur-Modul steht oder faellt: ungueltige, abgelaufene und
 * widerrufene Zugaenge, die Reihenfolge, die Bestaetigung der Adresse und
 * die Frage, wer ueberhaupt hineinsehen darf.
 */
class SignatureModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Anna Admin']);
    }

    // ------------------------------------------------------------- Hilfsmittel

    private function pdf(int $pages = 1): string
    {
        $objekte = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($i) => ($i + 3).' 0 R', range(0, $pages - 1)))
                .'] /Count '.$pages.' /MediaBox [0 0 595 842] >>',
        ];
        for ($i = 0; $i < $pages; $i++) {
            $objekte[$i + 3] = '<< /Type /Page /Parent 2 0 R >>';
        }

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

        return $pdf."trailer\n<< /Size ".(count($objekte) + 1)." /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";
    }

    private function upload(int $pages = 1): UploadedFile
    {
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $this->pdf($pages));

        return new UploadedFile($pfad, 'vertrag.pdf', 'application/pdf', null, true);
    }

    private function unterschriftsBild(): string
    {
        $bild = imagecreatetruecolor(160, 60);
        imagesavealpha($bild, true);
        imagealphablending($bild, false);
        imagefill($bild, 0, 0, imagecolorallocatealpha($bild, 255, 255, 255, 127));
        imagealphablending($bild, true);
        imagesetthickness($bild, 3);
        imageline($bild, 8, 50, 80, 10, imagecolorallocate($bild, 15, 15, 60));
        imageline($bild, 80, 10, 152, 48, imagecolorallocate($bild, 15, 15, 60));
        ob_start();
        imagepng($bild);
        $png = (string) ob_get_clean();
        imagedestroy($bild);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function customer(string $name = 'Max Mustermann', string $email = 'max@example.com'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'K-'.strtoupper(substr(md5($email), 0, 8)),
        ]);
    }

    /** Eine versandfertige Anfrage: PDF, ein Unterzeichner, ein Unterschriftsfeld. */
    private function anfrage(array $attribute = [], array $unterzeichner = []): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->upload(), array_merge([
            'title' => 'Maklervollmacht',
            'require_email_verification' => false,
        ], $attribute), $this->admin);

        $service->syncSigners($request, $unterzeichner ?: [
            ['name' => 'Max Mustermann', 'email' => 'max@example.com'],
        ]);
        $request->refresh()->load('signers');

        $felder = [];
        foreach ($request->signers as $index => $signer) {
            $felder[] = [
                'signer_id' => $signer->id, 'type' => SignatureFieldType::SIGNATURE, 'page' => 1,
                'x' => 0.1, 'y' => 0.7 + ($index * 0.06), 'width' => 0.3, 'height' => 0.05, 'required' => true,
            ];
        }
        $service->syncFields($request->fresh()->load('signers'), $felder);

        return $request->fresh()->load(['signers', 'fields']);
    }

    /** Token im Klartext - es existiert nur im Moment der Ausgabe. */
    private function token(SignatureSigner $signer): string
    {
        $request = SignatureRequest::findOrFail($signer->signature_request_id);

        return app(SignatureTokenService::class)->issue($signer, $request->expires_at);
    }

    private function unterschreiben(SignatureSigner $signer, ?string $token = null)
    {
        $token ??= $this->token($signer);
        $felder = [];
        foreach ($signer->fields()->get() as $feld) {
            $felder[$feld->id] = $this->unterschriftsBild();
        }

        return $this->post(route('signature.sign', $token), [
            'zustimmung' => '1',
            'felder' => $felder,
        ]);
    }

    // --------------------------------------------------- Szenario A: mit Kunde

    public function test_szenario_a_kunde_vertrag_unterschrift_und_ablage_in_der_akte(): void
    {
        $customer = $this->customer();
        $contract = Contract::create([
            'customer_id' => (string) $customer->id, 'type' => 'kfz', 'insurer' => 'WGV', 'status' => 'active',
        ]);

        $antwort = $this->actingAs($this->admin)->post(route('admin.signatures.store'), [
            'document' => $this->upload(2),
            'title' => 'Maklervollmacht Mustermann',
            'customer_id' => (string) $customer->id,
            'contract_id' => (string) $contract->id,
            'identity_check' => SignatureRequest::IDENTITY_NONE,
            'signers' => [['name' => 'Max Mustermann', 'email' => 'max@example.com']],
        ]);

        $antwort->assertSessionHasNoErrors();
        $request = SignatureRequest::firstOrFail();
        $antwort->assertRedirect(route('admin.signatures.prepare', $request->id));
        $this->assertSame(2, $request->page_count);
        $this->assertSame(SignatureStatus::DRAFT, $request->status);
        $this->assertSame((string) $customer->id, (string) $request->customer_id);
        $this->assertSame(hash('sha256', file_get_contents(
            app(SignatureStorage::class)->disk()->path($request->original_path)
        )), $request->original_hash);

        // Ohne Feld darf nicht versendet werden - eine Signaturanfrage ohne
        // Unterschriftsfeld waere eine E-Mail ohne Zweck.
        $this->assertNotEmpty(app(SignatureRequestService::class)->blockersForSending($request));

        $signer = $request->signers()->firstOrFail();
        app(SignatureRequestService::class)->syncFields($request->load('signers'), [[
            'signer_id' => $signer->id, 'type' => SignatureFieldType::SIGNATURE, 'page' => 2,
            'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05, 'required' => true,
        ]]);

        $this->actingAs($this->admin)->post(route('admin.signatures.send', $request->id))
            ->assertRedirect(route('admin.signatures.show', $request->id));
        Mail::assertSent(SignatureInvitationMail::class);

        $signer->refresh();
        $this->assertSame(SignatureStatus::SENT, $request->fresh()->status);
        $this->assertNotNull($signer->token_hash);
        $this->assertNotNull($signer->invited_at);

        $this->unterschreiben($signer)->assertRedirect();

        $request->refresh();
        $this->assertSame(SignatureStatus::COMPLETED, $request->status);
        $this->assertNotNull($request->signed_path);
        $this->assertSame(64, strlen((string) $request->signed_hash));
        Mail::assertSent(SignatureCompletedMail::class);

        // Das fertige PDF traegt die Protokollseite und beginnt mit dem
        // unveraenderten Original.
        $storage = app(SignatureStorage::class);
        $original = $storage->read($request->original_path);
        $unterschrieben = $storage->read($request->signed_path);
        $this->assertSame($original, substr($unterschrieben, 0, strlen($original)));
        $this->assertSame(3, PdfDocument::open($unterschrieben)->pageCount());

        // Ein Kundenvorgang landet direkt in der Akte - hier war die
        // Zuordnung ja von Anfang an bekannt.
        $this->actingAs($this->admin)->post(route('admin.signatures.assign', $request->id), [
            'aktion' => 'kunde', 'customer_id' => (string) $customer->id,
        ])->assertRedirect();

        $request->refresh();
        $this->assertNotNull($request->completed_document_id);
        $this->assertSame((string) $customer->id, (string) $request->completedDocument->customer_id);
    }

    // ------------------------------------------- Szenario B: ohne Kunden

    public function test_szenario_b_eigenstaendige_anfrage_braucht_keinen_kunden(): void
    {
        $request = $this->anfrage(['title' => 'Vollmacht Interessent']);

        $this->assertNull($request->customer_id);
        $this->assertNull($request->contract_id);

        app(SignatureRequestService::class)->send($request);
        $this->unterschreiben($request->signers()->firstOrFail());

        $request->refresh();
        $this->assertSame(SignatureStatus::COMPLETED, $request->status);
        $this->assertNull($request->customer_id, 'Eine Zuordnung darf NIE von selbst passieren.');
    }

    public function test_zuordnung_zu_einem_bestehenden_kunden_legt_das_dokument_in_die_akte(): void
    {
        $customer = $this->customer();
        $request = $this->anfrage();
        app(SignatureRequestService::class)->send($request);
        $this->unterschreiben($request->signers()->firstOrFail());

        $this->actingAs($this->admin)->post(route('admin.signatures.assign', $request->id), [
            'aktion' => 'kunde', 'customer_id' => (string) $customer->id,
        ])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        $request->refresh();
        $this->assertSame((string) $customer->id, (string) $request->customer_id);
        $this->assertNotNull($request->completed_document_id);
        $this->assertSame('contract', $request->completedDocument->category);
    }

    public function test_neuer_kunde_kann_aus_den_angaben_des_unterzeichners_entstehen(): void
    {
        $request = $this->anfrage([], [['name' => 'Neue Person', 'email' => 'neu@example.com']]);
        app(SignatureRequestService::class)->send($request);
        $this->unterschreiben($request->signers()->firstOrFail());

        $this->actingAs($this->admin)->post(route('admin.signatures.assign', $request->id), [
            'aktion' => 'neuer_kunde', 'signer_id' => $request->signers()->firstOrFail()->id,
        ])->assertRedirect();

        $request->refresh();
        $this->assertNotNull($request->customer_id);
        $this->assertSame('Neue Person', $request->customer->user->name);
    }

    public function test_eine_uebereinstimmende_email_ordnet_nie_von_selbst_zu(): void
    {
        $customer = $this->customer('Max Mustermann', 'max@example.com');
        $request = $this->anfrage();
        app(SignatureRequestService::class)->send($request);
        $this->unterschreiben($request->signers()->firstOrFail());

        // Der Kunde wird VORGESCHLAGEN - zugeordnet wird er nicht. Eine
        // Adresse ist kein Identitaetsnachweis (Familien-/Firmenpostfach).
        $request->refresh();
        $this->assertNull($request->customer_id);

        $antwort = $this->actingAs($this->admin)->get(route('admin.signatures.show', $request->id));
        $antwort->assertOk()->assertSee($customer->customer_number);
    }

    // ------------------------------------------------------ Mehrere Unterzeichner

    public function test_nacheinander_wird_der_zweite_erst_nach_dem_ersten_eingeladen(): void
    {
        $request = $this->anfrage(['signing_order' => 'sequential'], [
            ['name' => 'Max Mustermann', 'email' => 'max@example.com'],
            ['name' => 'Anna Müller', 'email' => 'anna@example.com'],
        ]);
        app(SignatureRequestService::class)->send($request);

        $erster = $request->signers()->orderBy('signing_order')->first();
        $zweiter = $request->signers()->orderBy('signing_order')->skip(1)->first();
        $this->assertNotNull($erster->fresh()->invited_at);
        $this->assertNull($zweiter->fresh()->invited_at, 'Der Zweite darf noch keine Einladung haben.');

        // Der Zweite kommt auch mit gueltigem Zugang noch nicht durch.
        $tokenZwei = $this->token($zweiter);
        $this->get(route('signature.show', $tokenZwei))->assertOk()->assertSee('nacheinander');

        $this->unterschreiben($erster);

        $request->refresh();
        $this->assertSame(SignatureStatus::PARTIALLY_SIGNED, $request->status);
        $this->assertNotNull($zweiter->fresh()->invited_at, 'Nach der ersten Unterschrift muss der Zweite eingeladen werden.');

        $this->unterschreiben($zweiter->fresh());
        $this->assertSame(SignatureStatus::COMPLETED, $request->fresh()->status);
    }

    public function test_gleichzeitig_laedt_alle_sofort_ein(): void
    {
        $request = $this->anfrage(['signing_order' => 'parallel'], [
            ['name' => 'Max Mustermann', 'email' => 'max@example.com'],
            ['name' => 'Anna Müller', 'email' => 'anna@example.com'],
        ]);
        app(SignatureRequestService::class)->send($request);

        foreach ($request->signers as $signer) {
            $this->assertNotNull($signer->fresh()->invited_at);
        }
        Mail::assertSent(SignatureInvitationMail::class, 2);
    }

    public function test_beide_unterschriften_stehen_im_fertigen_pdf(): void
    {
        $request = $this->anfrage(['signing_order' => 'parallel'], [
            ['name' => 'Max Mustermann', 'email' => 'max@example.com'],
            ['name' => 'Anna Müller', 'email' => 'anna@example.com'],
        ]);
        app(SignatureRequestService::class)->send($request);
        foreach ($request->signers as $signer) {
            $this->unterschreiben($signer);
        }

        $pdf = app(SignatureStorage::class)->read($request->fresh()->signed_path);
        $this->assertNotNull($pdf);
        $this->assertStringContainsString('(Unterzeichner 1: Max Mustermann) Tj', $pdf);
        $this->assertStringContainsString('(Unterzeichner 2: Anna M', $pdf);
        // Zwei eingebettete Unterschriftsbilder. Jedes besteht aus einer
        // Farbflaeche mit /SMask (dem Alphakanal der Handschrift) - die
        // Zahl der /SMask-Verweise ist damit die Zahl der Unterschriften.
        $this->assertSame(2, substr_count($pdf, '/SMask '));
    }

    // ------------------------------------------------------------- Sicherheit

    public function test_ein_unbekanntes_token_fuehrt_ins_leere(): void
    {
        $this->get(route('signature.show', str_repeat('a', 40)))->assertNotFound();
    }

    public function test_ein_abgelaufener_zugang_laesst_nicht_mehr_unterschreiben(): void
    {
        $request = $this->anfrage(['expires_at' => now()->addDays(3)]);
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();
        $token = $this->token($signer);

        $request->forceFill(['expires_at' => now()->subDay()])->save();

        $this->get(route('signature.show', $token))->assertOk()->assertSee('Frist');
        $this->unterschreiben($signer, $token)->assertForbidden();
        $this->assertFalse($signer->fresh()->hasSigned());
    }

    public function test_ein_widerrufener_zugang_ist_sofort_tot(): void
    {
        $request = $this->anfrage();
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();
        $token = $this->token($signer);

        $this->actingAs($this->admin)->post(route('admin.signatures.cancel', $request->id), [
            'reason' => 'Falsches Dokument',
        ])->assertRedirect();

        $this->get(route('signature.show', $token))->assertStatus(410);
        $this->assertSame(SignatureStatus::CANCELLED, $request->fresh()->status);
    }

    public function test_der_klartext_des_zugangs_steht_nie_in_der_datenbank(): void
    {
        $request = $this->anfrage();
        $signer = $request->signers()->firstOrFail();
        $token = $this->token($signer);

        $this->assertNotSame($token, $signer->fresh()->token_hash);
        $this->assertSame(hash('sha256', $token), $signer->fresh()->token_hash);
        $this->assertArrayNotHasKey('token_hash', $signer->fresh()->toArray());
    }

    public function test_ohne_bestaetigten_code_gibt_es_weder_seite_noch_pdf(): void
    {
        $request = $this->anfrage(['require_email_verification' => true]);
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();
        $token = $this->token($signer);

        $this->get(route('signature.show', $token))->assertOk()->assertSee('Bestätigungscode');
        $this->get(route('signature.document', $token))->assertForbidden();
        $this->get(route('signature.page', [$token, 1]))->assertForbidden();

        $this->post(route('signature.code', $token))->assertRedirect();
        Mail::assertSent(SignatureVerificationMail::class);

        $this->post(route('signature.verify', $token), ['code' => '000000'])->assertRedirect();
        $this->assertNull($signer->fresh()->verified_at, 'Ein falscher Code darf nie bestaetigen.');

        $code = null;
        Mail::assertSent(SignatureVerificationMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $this->post(route('signature.verify', $token), ['code' => $code])->assertRedirect();
        $this->assertNotNull($signer->fresh()->verified_at);
    }

    public function test_ein_leeres_unterschriftsfeld_zaehlt_nicht_als_unterschrift(): void
    {
        $request = $this->anfrage();
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();
        $token = $this->token($signer);

        // Eine voellig leere Zeichenflaeche: ohne Pruefung genuegte ein Klick
        // auf "Bestaetigen", um ein leeres Feld als Unterschrift auszugeben.
        $leer = imagecreatetruecolor(120, 40);
        imagesavealpha($leer, true);
        imagealphablending($leer, false);
        imagefill($leer, 0, 0, imagecolorallocatealpha($leer, 255, 255, 255, 127));
        ob_start();
        imagepng($leer);
        $png = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
        imagedestroy($leer);

        $this->post(route('signature.sign', $token), [
            'zustimmung' => '1',
            'felder' => [$signer->fields()->firstOrFail()->id => $png],
        ]);

        $this->assertFalse($signer->fresh()->hasSigned());
    }

    public function test_ein_fremdes_feld_kann_nicht_ausgefuellt_werden(): void
    {
        $request = $this->anfrage(['signing_order' => 'parallel'], [
            ['name' => 'Max Mustermann', 'email' => 'max@example.com'],
            ['name' => 'Anna Müller', 'email' => 'anna@example.com'],
        ]);
        app(SignatureRequestService::class)->send($request);

        $max = $request->signers()->orderBy('signing_order')->first();
        $anna = $request->signers()->orderBy('signing_order')->skip(1)->first();
        $annasFeld = $anna->fields()->firstOrFail();

        // Max schickt Annas Feld-ID mit. Der Server ordnet Felder ueber die
        // Zugehoerigkeit zu, nicht ueber das Formular - Annas Feld bleibt leer.
        $this->post(route('signature.sign', $this->token($max)), [
            'zustimmung' => '1',
            'felder' => [
                $max->fields()->firstOrFail()->id => $this->unterschriftsBild(),
                $annasFeld->id => $this->unterschriftsBild(),
            ],
        ]);

        $this->assertTrue($max->fresh()->hasSigned());
        $this->assertFalse($anna->fresh()->hasSigned());
        $this->assertNull($annasFeld->fresh()->image_path);
    }

    public function test_ohne_zustimmung_wird_nicht_unterschrieben(): void
    {
        $request = $this->anfrage();
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();

        $this->post(route('signature.sign', $this->token($signer)), [
            'felder' => [$signer->fields()->firstOrFail()->id => $this->unterschriftsBild()],
        ])->assertSessionHasErrors('zustimmung');

        $this->assertFalse($signer->fresh()->hasSigned());
    }

    public function test_fremde_mitarbeiter_sehen_eine_eigenstaendige_anfrage_nicht(): void
    {
        $request = $this->anfrage();
        $fremder = User::factory()->create(['role' => 'employee']);

        $this->actingAs($fremder)->get(route('admin.signatures.show', $request->id))->assertForbidden();
        $this->actingAs($fremder)->get(route('admin.signatures.index'))->assertOk()->assertDontSee('Maklervollmacht');
    }

    public function test_ein_kunde_kommt_nicht_in_die_beraterwelt(): void
    {
        $request = $this->anfrage();
        $kunde = User::factory()->create(['role' => 'customer']);

        // Die Rollen-Middleware weist Kunden ab, bevor der Controller laeuft
        // (Weiterleitung ins Portal statt 403) - entscheidend ist, dass die
        // Seite NICHT ausgeliefert wird.
        $this->actingAs($kunde)->get(route('admin.signatures.index'))->assertRedirect();
        $this->actingAs($kunde)->get(route('admin.signatures.download', [$request->id, 'signed']))->assertRedirect();
    }

    // ---------------------------------------------------------------- Protokoll

    public function test_das_protokoll_haelt_den_gesamten_hergang_fest(): void
    {
        $request = $this->anfrage();
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();
        $token = $this->token($signer);
        $this->get(route('signature.show', $token));
        $this->unterschreiben($signer, $token);

        $ereignisse = SignatureEvent::where('signature_request_id', $request->id)->pluck('event')->all();
        foreach (['created', 'document_uploaded', 'signer_added', 'fields_saved', 'sent', 'opened',
            'signing_started', 'signed', 'pdf_generated', 'completed'] as $erwartet) {
            $this->assertContains($erwartet, $ereignisse, 'Im Protokoll fehlt: '.$erwartet);
        }

        // Der Nachweis braucht Zeitpunkt, IP und Geraet.
        $unterschrift = SignatureEvent::where('signature_request_id', $request->id)->where('event', 'signed')->firstOrFail();
        $this->assertNotNull($unterschrift->created_at);
        $this->assertNotNull($unterschrift->ip);
    }

    public function test_das_protokoll_kennt_keinen_weg_zum_aendern(): void
    {
        // Ein Protokoll, das der Protokollierte aendern kann, belegt nichts.
        // Deshalb hat die Tabelle bewusst kein updated_at.
        $this->assertNull(SignatureEvent::UPDATED_AT);
        $this->assertFalse(Schema::hasColumn('signature_events', 'updated_at'));
    }

    // ------------------------------------------------------------- Abgelaufen

    public function test_der_tageslauf_schliesst_abgelaufene_anfragen(): void
    {
        $request = $this->anfrage(['expires_at' => now()->addDay()]);
        app(SignatureRequestService::class)->send($request);
        $request->forceFill(['expires_at' => now()->subHour()])->save();

        $this->artisan('signaturen:ablaufen')->assertExitCode(0);

        $request->refresh();
        $this->assertSame(SignatureStatus::EXPIRED, $request->status);
        $this->assertNotNull($request->signers()->firstOrFail()->token_revoked_at);
    }

    public function test_erinnerung_geht_nur_an_wer_noch_nicht_unterschrieben_hat(): void
    {
        $request = $this->anfrage(['signing_order' => 'parallel'], [
            ['name' => 'Max Mustermann', 'email' => 'max@example.com'],
            ['name' => 'Anna Müller', 'email' => 'anna@example.com'],
        ]);
        app(SignatureRequestService::class)->send($request);
        $this->unterschreiben($request->signers()->orderBy('signing_order')->first());

        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.signatures.remind', $request->id))->assertRedirect();

        Mail::assertSent(SignatureInvitationMail::class, 1);
        Mail::assertSent(SignatureInvitationMail::class, fn ($mail) => $mail->signer->email === 'anna@example.com');
    }

    public function test_ablehnen_beendet_den_vorgang(): void
    {
        $request = $this->anfrage();
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();

        $this->post(route('signature.decline', $this->token($signer)), ['grund' => 'Nicht einverstanden'])
            ->assertRedirect();

        $this->assertSame(SignatureStatus::DECLINED, $request->fresh()->status);
        $this->assertTrue($signer->fresh()->hasDeclined());
        $this->assertNull($request->fresh()->signed_path);
    }

    public function test_nach_dem_versand_bleibt_die_aufteilung_unveraendert(): void
    {
        $request = $this->anfrage();
        app(SignatureRequestService::class)->send($request);

        $this->actingAs($this->admin)->post(route('admin.signatures.prepare.save', $request->id), [
            'signers' => [['name' => 'Anderer Name', 'email' => 'anders@example.com']],
            'fields' => [],
        ])->assertRedirect();

        $this->assertSame('max@example.com', $request->signers()->firstOrFail()->email);
        $this->assertSame(1, $request->fields()->count());
    }

    public function test_ein_geschuetztes_oder_kaputtes_pdf_wird_beim_hochladen_abgelehnt(): void
    {
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, "Das ist gar kein PDF.\n");

        $antwort = $this->actingAs($this->admin)->post(route('admin.signatures.store'), [
            'document' => new UploadedFile($pfad, 'kaputt.pdf', 'application/pdf', null, true),
            'title' => 'Kaputt',
        ]);

        // Abgelehnt wird SOFORT beim Hochladen - ein Fehlschlag NACH dem
        // Unterschreiben waere dem Unterzeichner nicht zu erklaeren.
        $antwort->assertRedirect();
        $this->assertSame(0, SignatureRequest::count());
    }

    public function test_der_download_laeuft_immer_ueber_eine_pruefung(): void
    {
        $request = $this->anfrage();
        app(SignatureRequestService::class)->send($request);
        $this->unterschreiben($request->signers()->firstOrFail());

        // Ohne Anmeldung gar nichts.
        $this->get(route('admin.signatures.download', [$request->id, 'signed']))->assertRedirect();

        $antwort = $this->actingAs($this->admin)->get(route('admin.signatures.download', [$request->id, 'signed']));
        $antwort->assertOk();
        $this->assertSame('application/pdf', $antwort->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $antwort->getContent());
    }
}
