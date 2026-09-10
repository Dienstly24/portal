<?php

namespace Tests\Feature;

use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use App\Support\SignatureGroup;
use App\Support\Unterschriftsbild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * EIN UNTERZEICHNER UNTERSCHREIBT EINMAL - egal wie viele Felder.
 *
 * Betreiber-Vorgabe 10.09.2026, Kernsatz: "Signature Field != Signature
 * Event". Sieben Unterschriftsfelder sind KEINE sieben Willenserklaerungen,
 * sondern eine Unterschrift an sieben Stellen. Vorher verlangte das System
 * sieben Zeichnungen: der Kunde malte siebenmal, jedes Mal etwas anders,
 * und auf Seite 1 stand eine andere Unterschrift als auf Seite 9.
 *
 * Die Faelle hier pruefen die Regel dort, wo sie brechen wuerde: bei der
 * Anzahl der Zeichnungen, bei der Gleichheit des Bildes ueber alle Seiten,
 * bei mehreren Unterzeichnern im selben Dokument und beim Einpassen in
 * unterschiedlich grosse Felder.
 */
class SignaturGruppeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function pdf(int $seiten): UploadedFile
    {
        $objekte = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($i) => ($i + 3).' 0 R', range(0, $seiten - 1)))
                .'] /Count '.$seiten.' /MediaBox [0 0 595 842] >>',
        ];
        for ($i = 0; $i < $seiten; $i++) {
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
        $pdf .= "trailer\n<< /Size ".(count($objekte) + 1)." /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $pdf);

        return new UploadedFile($pfad, 'vertrag.pdf', 'application/pdf', null, true);
    }

    /** Eine Handschrift mit echtem Strich - und viel leerem Rand. */
    private function bild(int $breite = 400, int $hoehe = 200): string
    {
        $b = imagecreatetruecolor($breite, $hoehe);
        imagesavealpha($b, true);
        imagealphablending($b, false);
        imagefill($b, 0, 0, imagecolorallocatealpha($b, 0, 0, 0, 127));
        imagealphablending($b, true);
        imagesetthickness($b, 4);
        $tinte = imagecolorallocate($b, 20, 20, 70);
        // Der Strich liegt bewusst nur in der MITTE - der Rand ist leer.
        imageline($b, (int) ($breite * 0.3), (int) ($hoehe * 0.6), (int) ($breite * 0.5), (int) ($hoehe * 0.4), $tinte);
        imageline($b, (int) ($breite * 0.5), (int) ($hoehe * 0.4), (int) ($breite * 0.7), (int) ($hoehe * 0.6), $tinte);
        ob_start();
        imagepng($b);
        $png = (string) ob_get_clean();
        imagedestroy($b);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * Der gemeldete Fall: 19 Seiten, sieben Unterschriftsstellen fuer Ahmad.
     *
     * @param  array<int,array{name: string, email: string}>  $unterzeichner
     * @param  array<string,array<int,int>>  $seitenJeMail  Mail => Seiten
     */
    private function anfrage(array $unterzeichner, array $seitenJeMail, int $seiten = 19): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->pdf($seiten), [
            'title' => 'Maklervollmacht',
            'signing_order' => 'parallel',
            // Ohne Abschaltung fuehrt der Einstieg zuerst auf die
            // Identitaetspruefung - hier geht es um die Zeichenflaeche.
            'identity_check' => SignatureRequest::IDENTITY_NONE,
        ], $this->admin);

        $service->syncSigners($request, $unterzeichner);
        $request->refresh()->load('signers');

        $felder = [];
        foreach ($request->signers as $signer) {
            foreach ($seitenJeMail[$signer->email] ?? [] as $i => $seite) {
                $felder[] = [
                    'signer_id' => $signer->id,
                    'type' => SignatureFieldType::SIGNATURE,
                    'page' => $seite,
                    'x' => 0.1,
                    'y' => 0.70,
                    // ABSICHTLICH verschieden gross: das erste Feld ist
                    // breit und flach, das zweite fast quadratisch. Genau
                    // daran zeigte sich die Verzerrung.
                    'width' => $i === 1 ? 0.12 : 0.34,
                    'height' => $i === 1 ? 0.09 : 0.05,
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

    // ------------------------------------------------------------ Abnahme

    public function test_eine_zeichnung_fuellt_alle_sieben_stellen(): void
    {
        $request = $this->anfrage(
            [['name' => 'Ahmad Albhre', 'email' => 'ahmad@example.com']],
            ['ahmad@example.com' => [1, 2, 3, 4, 6, 7, 9]],
        );
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();

        $this->assertSame(7, $signer->fields()->count());

        // GENAU EINE Zeichnung - unter der Feldart, nicht unter sieben IDs.
        $antwort = $this->post(route('signature.sign', $this->token($signer)), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);
        $antwort->assertRedirect();

        $signer->refresh();
        $this->assertTrue($signer->hasSigned(), 'Eine Zeichnung muss zum Unterschreiben genuegen.');

        $felder = $signer->fields()->get();
        $this->assertCount(7, $felder);
        foreach ($felder as $feld) {
            $this->assertNotNull($feld->image_path, 'Seite '.$feld->page.' blieb leer.');
            $this->assertNotNull($feld->filled_at);
        }
    }

    public function test_auf_allen_seiten_steht_dasselbe_bild(): void
    {
        $request = $this->anfrage(
            [['name' => 'Ahmad Albhre', 'email' => 'ahmad@example.com']],
            ['ahmad@example.com' => [1, 2, 3, 4, 6, 7, 9]],
        );
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();

        $this->post(route('signature.sign', $this->token($signer)), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);

        // Nicht "gleich aussehend", sondern DIESELBE Datei. Kopien koennten
        // auseinanderlaufen; ein einziger Pfad kann es nicht.
        $pfade = $signer->fields()->get()->pluck('image_path')->unique();
        $this->assertCount(1, $pfade, 'Es darf nur EIN Unterschriftsbild geben, nicht sieben Kopien.');
    }

    public function test_jeder_unterzeichner_zeichnet_genau_einmal(): void
    {
        $request = $this->anfrage([
            ['name' => 'Ahmad', 'email' => 'ahmad@example.com'],
            ['name' => 'Mohammed', 'email' => 'mohammed@example.com'],
        ], [
            'ahmad@example.com' => [2, 5, 8],
            'mohammed@example.com' => [3, 8],
        ]);
        app(SignatureRequestService::class)->send($request);

        $ahmad = $request->signers()->where('email', 'ahmad@example.com')->firstOrFail();
        $mohammed = $request->signers()->where('email', 'mohammed@example.com')->firstOrFail();

        $this->post(route('signature.sign', $this->token($ahmad)), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);

        $this->assertTrue($ahmad->fresh()->hasSigned());
        // Ahmads eine Zeichnung erledigt seine drei Stellen - und RUEHRT
        // Mohammeds Felder nicht an, obwohl auf Seite 8 beide stehen.
        $this->assertSame(3, $ahmad->fields()->whereNotNull('image_path')->count());
        $this->assertSame(0, $mohammed->fields()->whereNotNull('image_path')->count());
        $this->assertFalse($mohammed->fresh()->hasSigned());

        $this->post(route('signature.sign', $this->token($mohammed)), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);

        $this->assertSame(2, $mohammed->fresh()->fields()->whereNotNull('image_path')->count());
        // Zwei Menschen, zwei Unterschriften - also zwei verschiedene Bilder.
        $this->assertNotSame(
            $ahmad->fields()->firstOrFail()->image_path,
            $mohammed->fields()->firstOrFail()->image_path,
        );
    }

    public function test_die_gruppe_kennt_ihre_stellen_und_seiten(): void
    {
        $request = $this->anfrage(
            [['name' => 'Ahmad', 'email' => 'ahmad@example.com']],
            ['ahmad@example.com' => [9, 1, 4]],
        );
        $signer = $request->signers()->firstOrFail();

        $gruppen = SignatureGroup::forSigner($signer, $request->fields);
        $this->assertCount(1, $gruppen, 'Eine Feldart = eine Gruppe.');

        $gruppe = $gruppen->first();
        $this->assertSame(3, $gruppe->count());
        // Aufsteigend, nicht in Eingabereihenfolge: der Zaehler steht in
        // der Reihenfolge, in der der Mensch das Dokument liest.
        $this->assertSame([1, 4, 9], $gruppe->pages());
        $this->assertTrue($gruppe->required());
    }

    public function test_der_leere_rand_wird_abgeschnitten(): void
    {
        $roh = base64_decode(explode(',', $this->bild(400, 200))[1], true);
        $vorher = Unterschriftsbild::masse((string) $roh);
        $nachher = Unterschriftsbild::masse(Unterschriftsbild::zuschneiden((string) $roh));

        $this->assertNotNull($vorher);
        $this->assertNotNull($nachher);
        // Der Strich liegt zwischen 30 % und 70 % der Breite - was
        // uebrigbleibt, muss deutlich schmaler sein als das Original.
        $this->assertLessThan($vorher[0], $nachher[0],
            'Ohne Zuschnitt steht die Handschrift winzig in einem fast leeren Feld.');
        $this->assertGreaterThan(8, $nachher[0]);
    }

    public function test_die_unterschrift_wird_nie_verzerrt(): void
    {
        $roh = (string) base64_decode(explode(',', $this->bild(400, 100))[1], true);
        $masse = Unterschriftsbild::masse($roh);
        $this->assertNotNull($masse);
        $verhaeltnisBild = $masse[0] / $masse[1];

        // Ein FLACHES Bild in ein HOHES Feld: gezogen waere es unkenntlich.
        [$x, $y, $breite, $hoehe] = Unterschriftsbild::einpassen($roh, 10.0, 20.0, 100.0, 100.0);

        $this->assertEqualsWithDelta($verhaeltnisBild, $breite / $hoehe, 0.001,
            'Das Seitenverhaeltnis der Handschrift muss erhalten bleiben.');
        // Es passt hinein ...
        $this->assertLessThanOrEqual(100.0, $breite);
        $this->assertLessThanOrEqual(100.0, $hoehe);
        // ... und steht mittig, nicht in der Ecke.
        $this->assertEqualsWithDelta(10.0 + (100.0 - $breite) / 2, $x, 0.001);
        $this->assertEqualsWithDelta(20.0 + (100.0 - $hoehe) / 2, $y, 0.001);
    }

    public function test_das_formular_zeigt_eine_flaeche_und_nennt_die_zahl_der_stellen(): void
    {
        $request = $this->anfrage(
            [['name' => 'Ahmad', 'email' => 'ahmad@example.com']],
            ['ahmad@example.com' => [1, 2, 3, 4, 6, 7, 9]],
        );
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();

        $html = $this->get(route('signature.show', $this->token($signer)))->assertOk()->getContent();

        // GENAU EINE Zeichenflaeche - nicht sieben.
        $this->assertSame(1, substr_count((string) $html, 'data-unterschrift='),
            'Sieben Felder duerfen nur EINE Zeichenflaeche erzeugen.');
        // Und der Unterzeichner erfaehrt VORHER, was gleich passiert.
        $this->assertStringContainsString('7', (string) $html);
    }

    public function test_der_editor_sagt_dem_mitarbeiter_dass_es_eine_unterschrift_ist(): void
    {
        // Betreiber-Vorgabe 19: wer sieben Kaesten setzt, muss verstehen,
        // dass er damit NICHT sieben Unterschriften verlangt. Ohne diesen
        // Satz nimmt der Mitarbeiter genau das an - der Irrtum, aus dem die
        // Meldung entstanden ist. Die Gruppe wird nirgends eingestellt: sie
        // ENTSTEHT dadurch, dass ein Feld einem Unterzeichner gehoert.
        $request = $this->anfrage(
            [['name' => 'Ahmad', 'email' => 'ahmad@example.com']],
            ['ahmad@example.com' => [1, 2, 3, 4, 6, 7, 9]],
        );

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();

        $this->assertStringContainsString('gruppentext', $html,
            'Der Editor muss die Signaturgruppe je Unterzeichner ausweisen.');
        $this->assertStringContainsString('Eine Unterschrift', $html);
    }

    public function test_es_gibt_keinen_weg_die_unterschrift_zu_tippen_oder_hochzuladen(): void
    {
        $request = $this->anfrage(
            [['name' => 'Ahmad', 'email' => 'ahmad@example.com']],
            ['ahmad@example.com' => [1]],
        );
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();

        $html = (string) $this->get(route('signature.show', $this->token($signer)))->getContent();

        // Betreiber-Vorgabe: NUR gezeichnet. Ein Datei-Feld oder ein
        // Namensfeld als "Unterschrift" waere ein anderer Beweiswert - und
        // wurde ausdruecklich ausgeschlossen.
        $this->assertStringNotContainsString('type="file"', $html);
        $this->assertStringNotContainsString('Unterschrift tippen', $html);
    }
}
