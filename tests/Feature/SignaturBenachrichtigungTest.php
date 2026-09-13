<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InternalNotification;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * DER MITARBEITER ERFAEHRT ES IN DER GLOCKE - nicht per Nachfragen.
 *
 * Betreiber-Vorgabe 13.09.2026: wenn ein Kunde fertig unterschrieben hat,
 * soll das im SELBEN Aktivitaetscenter stehen wie Tickets und Chat - kein
 * zweites Benachrichtigungssystem, keine neue Tabelle.
 *
 * Die heikle Stelle ist die HAEUFIGKEIT: sieben Unterschriftsstellen sind
 * EIN Ereignis, nicht sieben. Und ein Neuladen der Seite darf keine zweite
 * Meldung erzeugen. Genau daran haengen die Faelle hier.
 */
class SignaturBenachrichtigungTest extends TestCase
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
            $objekte[$i + 3] = '<< /Type /Page /Parent 2 0 R >>';
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

    /**
     * @param  array<int,array{name: string, email: string}>  $unterzeichner
     * @param  array<string,array<int,int>>  $seitenJeMail
     */
    private function anfrage(array $unterzeichner, array $seitenJeMail): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->pdf(), [
            'title' => 'Arbeitsvertrag',
            'signing_order' => 'parallel',
            'identity_check' => SignatureRequest::IDENTITY_NONE,
        ], $this->admin);

        $service->syncSigners($request, $unterzeichner);
        $request->refresh()->load('signers');

        $felder = [];
        foreach ($request->signers as $signer) {
            foreach ($seitenJeMail[$signer->email] ?? [] as $seite) {
                $felder[] = [
                    'signer_id' => $signer->id, 'type' => SignatureFieldType::SIGNATURE,
                    'page' => $seite, 'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.05,
                    'required' => true,
                ];
            }
        }
        $service->syncFields($request->fresh()->load('signers'), $felder);

        return $request->fresh()->load(['signers', 'fields']);
    }

    private function token(SignatureSigner $signer): string
    {
        $request = SignatureRequest::findOrFail($signer->signature_request_id);

        return app(SignatureTokenService::class)->issue($signer, $request->expires_at);
    }

    private function unterschreiben(SignatureSigner $signer, ?string $token = null): void
    {
        $this->post(route('signature.sign', $token ?? $this->token($signer)), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);
    }

    /** @return Collection<int,InternalNotification> */
    private function meldungen(string $titel)
    {
        return InternalNotification::where('user_id', $this->admin->id)
            ->where('title', $titel)->get();
    }

    // ------------------------------------------------------------ Abnahme

    public function test_das_unterschreiben_erzeugt_eine_meldung_in_der_glocke(): void
    {
        $request = $this->anfrage(
            [['name' => 'Max Mustermann', 'email' => 'max@example.com']],
            ['max@example.com' => [1, 2, 3]],
        );
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();

        $this->unterschreiben($signer);

        $meldung = $this->meldungen('Dokument unterschrieben')->first();
        $this->assertNotNull($meldung, 'Der Ersteller muss es in der Glocke sehen.');
        $this->assertStringContainsString('Max Mustermann', (string) $meldung->body);
        $this->assertStringContainsString('Arbeitsvertrag', (string) $meldung->body);
        // Der Link fuehrt auf DEN Vorgang, nicht auf eine Uebersicht.
        $this->assertSame(route('admin.signatures.show', $request->id), $meldung->link);
        $this->assertSame('signature', $meldung->type);
        $this->assertNull($meldung->read_at);
    }

    public function test_sieben_stellen_derselben_gruppe_erzeugen_nur_EINE_meldung(): void
    {
        $request = $this->anfrage(
            [['name' => 'Max Mustermann', 'email' => 'max@example.com']],
            ['max@example.com' => [1, 2, 3]],
        );
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();

        $this->unterschreiben($signer);

        // Drei Stellen, EINE Zeichnung, EIN Ereignis. Eine Meldung je
        // Stelle waere genau die Glockenflut, die das Center unbrauchbar
        // macht.
        $this->assertSame(3, $signer->fields()->whereNotNull('image_path')->count());
        $this->assertCount(1, $this->meldungen('Dokument unterschrieben'));
    }

    public function test_erneutes_absenden_erzeugt_keine_zweite_meldung(): void
    {
        $request = $this->anfrage(
            [['name' => 'Max Mustermann', 'email' => 'max@example.com']],
            ['max@example.com' => [1, 2]],
        );
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();
        $token = $this->token($signer);

        $this->unterschreiben($signer, $token);
        // Neuladen / zweiter Klick: das Unterschreiben ist idempotent, also
        // darf auch die Glocke nicht erneut laeuten.
        $this->unterschreiben($signer, $token);

        $this->assertCount(1, $this->meldungen('Dokument unterschrieben'));
    }

    public function test_ein_offener_vorgang_meldet_nicht_abgeschlossen(): void
    {
        $request = $this->anfrage([
            ['name' => 'Max Mustermann', 'email' => 'max@example.com'],
            ['name' => 'Anna Müller', 'email' => 'anna@example.com'],
        ], [
            'max@example.com' => [1],
            'anna@example.com' => [2],
        ]);
        app(SignatureRequestService::class)->send($request);
        $max = $request->signers()->where('email', 'max@example.com')->firstOrFail();

        $this->unterschreiben($max);

        // Max ist fertig - das Dokument NICHT. "Signatur abgeschlossen"
        // darf erst stehen, wenn die bestehende Regel des Systems es sagt.
        $this->assertCount(1, $this->meldungen('Dokument unterschrieben'));
        $this->assertCount(0, $this->meldungen('Signatur abgeschlossen'));

        $anna = $request->signers()->where('email', 'anna@example.com')->firstOrFail();
        $this->unterschreiben($anna);

        $this->assertCount(1, $this->meldungen('Signatur abgeschlossen'));
    }

    public function test_das_versenden_meldet_die_erste_stufe(): void
    {
        $request = $this->anfrage(
            [['name' => 'Max Mustermann', 'email' => 'max@example.com']],
            ['max@example.com' => [1]],
        );

        $this->assertCount(0, $this->meldungen('Signatur angefordert'));
        app(SignatureRequestService::class)->send($request);

        // Zwei Stufen im Center: angefordert (gelb) und unterschrieben.
        $this->assertCount(1, $this->meldungen('Signatur angefordert'));
    }

    public function test_die_meldung_steht_im_bestehenden_center_mit_eigenem_symbol(): void
    {
        $request = $this->anfrage(
            [['name' => 'Max Mustermann', 'email' => 'max@example.com']],
            ['max@example.com' => [1]],
        );
        app(SignatureRequestService::class)->send($request);
        $this->unterschreiben($request->signers()->firstOrFail());

        // DASSELBE Center wie fuer Tickets und Chat - kein zweiter Weg.
        $antwort = $this->actingAs($this->admin)
            ->getJson(route('admin.notifications'))->assertOk();

        $eintraege = collect($antwort->json('items') ?? $antwort->json());
        $treffer = $eintraege->firstWhere('title', 'Dokument unterschrieben');

        $this->assertNotNull($treffer, 'Die Meldung muss im vorhandenen Center auftauchen.');
        // Ohne eigenes Symbol geht sie im Stapel gleich aussehender
        // Meldungen unter - genau das war der Grund fuer die Tabelle.
        $this->assertSame('✍️', $treffer['icon']);
        $this->assertStringContainsString('/signaturen/', (string) $treffer['url']);
    }

    public function test_das_protokoll_nennt_person_und_firma_wenn_bekannt(): void
    {
        $nutzer = User::factory()->create(['role' => 'customer', 'name' => 'Max Mustermann']);
        $customer = Customer::create([
            'user_id' => $nutzer->id,
            'customer_number' => 'K-2600999',
            'company_name' => 'Muster Bau GmbH',
        ]);

        $request = $this->anfrage(
            [['name' => 'Max Mustermann', 'email' => 'max@example.com']],
            ['max@example.com' => [1, 2]],
        );
        $request->forceFill(['customer_id' => (string) $customer->id])->save();
        app(SignatureRequestService::class)->send($request->fresh());
        $this->unterschreiben($request->signers()->firstOrFail());

        $ereignis = $request->events()->where('event', 'signed')->firstOrFail();

        // Wer - und fuer wen. Beides stand vorher nicht da.
        $this->assertSame('Max Mustermann', $ereignis->actor);
        $this->assertStringContainsString('Muster Bau GmbH', (string) $ereignis->description);
        $this->assertStringContainsString('2 Stelle', (string) $ereignis->description);
    }

    public function test_ohne_firma_wird_keine_erfunden(): void
    {
        $request = $this->anfrage(
            [['name' => 'Max Mustermann', 'email' => 'max@example.com']],
            ['max@example.com' => [1]],
        );
        app(SignatureRequestService::class)->send($request);
        $this->unterschreiben($request->signers()->firstOrFail());

        $ereignis = $request->events()->where('event', 'signed')->firstOrFail();
        $this->assertStringNotContainsString('für', (string) $ereignis->description);
    }
}
