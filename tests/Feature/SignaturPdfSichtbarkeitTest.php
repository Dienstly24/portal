<?php

namespace Tests\Feature;

use App\Mail\SignatureCompletedMail;
use App\Models\CompanySignatureAsset;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Pdf\PdfEingangspruefung;
use App\Services\Signature\PdfSichtbarkeit;
use App\Services\Signature\SignatureDiagnostics;
use App\Services\Signature\SignaturePageRenderer;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureSigningService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignedPdfBuilder;
use App\Services\Signature\SignedPdfVerifier;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * DIE UNTERSCHRIFT MUSS IM FERTIGEN PDF ZU SEHEN SEIN - gemessen am
 * gerenderten Bild, nicht am PDF-Text (KI-061).
 *
 * Betreiber-Meldung 02.10.2026, Diagnose auf dem Server 03.10.2026:
 * 11 von 17 abgeschlossenen Vorgaengen zeigten die Unterschrift nicht.
 * Drei Ursachen, jede hier mit einem Fall, der ohne den Fix scheitert:
 *  - KI-062: /Resources als Referenz -> Bilder in ein verschachteltes
 *    /Resources geschrieben, "XObject 'D24Sig1x0' is unknown" (8 von 11)
 *  - KI-055: deckendes Firmenbild -> einfarbige (weisse) Flaeche
 *  - KI-056: indirektes /Contents-Array -> "Weird page contents"
 *
 * Jeder Fall prueft die Position: das Feld ist im RICHTIGEN Kasten
 * sichtbar UND an einer falschen Stelle (gespiegelter Kasten) aendert sich
 * nichts - sonst bestuende eine verdrehte Unterschrift die Pruefung.
 */
class SignaturPdfSichtbarkeitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        SystemSetting::set('company_name', 'Dienstly24 GmbH');
    }

    // ------------------------------------------------------------ Bausteine

    /**
     * Ein PDF mit Text und einer schwarzen Formularlinie auf jeder Seite.
     *
     * @param  int  $seiten  Seitenzahl
     * @param  int  $drehung  /Rotate jeder Seite
     * @param  bool  $ressourcenAlsReferenz  /Resources 9 0 R statt inline (Word, LibreOffice ...)
     * @param  bool  $contentsIndirekt  /Contents zeigt auf ein Array-Objekt
     */
    private function pdf(int $seiten = 1, int $drehung = 0, bool $ressourcenAlsReferenz = false, bool $contentsIndirekt = false): UploadedFile
    {
        // Linie bei y=200 (PDF-Koordinaten, unten links) von x=100 bis 500.
        $inhalt = "BT /F1 14 Tf 72 760 Td (Vertrag) Tj ET\n0 0 0 RG 3 w 100 200 m 500 200 l S\n";
        $objekte = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = [];
        $naechste = 10;
        $ressourcen = '<< /Font << /F1 3 0 R >> /ProcSet [/PDF /Text] >>';
        $objekte[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        if ($ressourcenAlsReferenz) {
            $objekte[9] = $ressourcen;
        }
        for ($i = 0; $i < $seiten; $i++) {
            $seite = $naechste++;
            $strom = $naechste++;
            $objekte[$strom] = '<< /Length '.strlen($inhalt)." >>\nstream\n".$inhalt.'endstream';
            $contents = $strom.' 0 R';
            if ($contentsIndirekt) {
                $array = $naechste++;
                $objekte[$array] = '['.$strom.' 0 R]';
                $contents = $array.' 0 R';
            }
            $objekte[$seite] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Rotate '.$drehung
                .' /Resources '.($ressourcenAlsReferenz ? '9 0 R' : $ressourcen).' /Contents '.$contents.' >>';
            $kids[] = $seite.' 0 R';
        }
        $objekte[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$seiten.' >>';
        ksort($objekte);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objekte as $nummer => $koerper) {
            $offsets[$nummer] = strlen($pdf);
            $pdf .= $nummer." 0 obj\n".$koerper."\nendobj\n";
        }
        $max = max(array_keys($objekte));
        $start = strlen($pdf);
        $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) {
            $pdf .= isset($offsets[$n]) ? sprintf("%010d 00000 n \n", $offsets[$n]) : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $pdf);

        return new UploadedFile($pfad, 'vertrag.pdf', 'application/pdf', null, true);
    }

    /** Zeichenflaeche wie im Browser auf einem Telefon mit Geraeteverhaeltnis 3. */
    private function zeichnung(int $dpr = 1): string
    {
        $b = imagecreatetruecolor(340 * $dpr, 120 * $dpr);
        imagesavealpha($b, true);
        imagealphablending($b, false);
        imagefill($b, 0, 0, imagecolorallocatealpha($b, 0, 0, 0, 127));
        imagealphablending($b, true);
        imagesetthickness($b, 6 * $dpr);
        $tinte = imagecolorallocate($b, 16, 24, 40);
        imageline($b, 10 * $dpr, 100 * $dpr, 330 * $dpr, 15 * $dpr, $tinte);
        imageline($b, 10 * $dpr, 15 * $dpr, 330 * $dpr, 100 * $dpr, $tinte);
        imageline($b, 10 * $dpr, 60 * $dpr, 330 * $dpr, 60 * $dpr, $tinte);
        ob_start();
        imagepng($b);
        $png = (string) ob_get_clean();
        imagedestroy($b);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** Firmenlogo wie hochgeladen: JPG, weisser Grund, rotes Zeichen mit weissem Innenteil. */
    private function opakesLogo(): UploadedFile
    {
        $b = imagecreatetruecolor(400, 160);
        imagefill($b, 0, 0, imagecolorallocate($b, 255, 255, 255));
        imagefilledrectangle($b, 40, 20, 360, 140, imagecolorallocate($b, 200, 20, 20));
        imagefilledrectangle($b, 160, 60, 240, 100, imagecolorallocate($b, 255, 255, 255));
        $pfad = tempnam(sys_get_temp_dir(), 'logo').'.jpg';
        imagejpeg($b, $pfad, 95);
        imagedestroy($b);

        return new UploadedFile($pfad, 'logo.jpg', 'image/jpeg', null, true);
    }

    private function firmenbild(): CompanySignatureAsset
    {
        $this->actingAs($this->admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::STEMPEL, 'name' => 'Stempel', 'bild' => $this->opakesLogo(), 'is_default' => 1,
        ])->assertSessionHasNoErrors();

        return CompanySignatureAsset::firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $felder
     */
    private function vorgang(UploadedFile $pdf, array $felder, bool $mitUnterzeichner = true): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($pdf, [
            'title' => 'Vertrag', 'identity_check' => SignatureRequest::IDENTITY_NONE,
        ], $this->admin);
        if ($mitUnterzeichner) {
            $service->syncSigners($request, [['name' => 'Max Mustermann', 'email' => 'max@example.com']]);
        }
        $request = $request->fresh()->load('signers');
        $signer = $request->signers->first();
        $service->syncFields($request, array_map(fn ($f) => $f + [
            'signer_id' => ($f['type'] ?? '') === SignatureFieldType::COMPANY ? null : $signer?->id,
            'required' => true,
        ], $felder));

        return $request->fresh()->load(['signers', 'fields']);
    }

    private function senden(SignatureRequest $request, int $dpr = 1): SignatureRequest
    {
        app(SignatureRequestService::class)->send($request);
        $request = $request->fresh()->load(['signers', 'fields']);
        $fehler = app(SignatureSigningService::class)->sign(
            $request, $request->signers->first(), [], [SignatureFieldType::SIGNATURE => $this->zeichnung($dpr)]
        );
        $this->assertSame([], $fehler);

        return $request->fresh()->load('fields');
    }

    /** @return array<string, mixed> */
    private function renderPaar(SignatureRequest $request, int $seite): array
    {
        $storage = app(SignatureStorage::class);

        return app(PdfSichtbarkeit::class)->renderPaar(
            (string) $storage->read($request->original_path),
            (string) $storage->read($request->signed_path),
            $seite,
        );
    }

    /** @param  array{0: float, 1: float, 2: float, 3: float}  $box */
    private function urteil(array $paar, array $box): string
    {
        return app(PdfSichtbarkeit::class)->vergleiche($paar, $box)['urteil'];
    }

    private function unterschriftsfeld(int $seite = 1, float $x = 0.1, float $y = 0.1): array
    {
        return ['type' => SignatureFieldType::SIGNATURE, 'page' => $seite, 'x' => $x, 'y' => $y, 'width' => 0.3, 'height' => 0.08];
    }

    // ---------------------------------------------------------------- Faelle

    public function test_ressourcen_als_referenz_die_haeufigste_ursache_auf_dem_server(): void
    {
        // KI-062: 8 von 11 betroffenen Vorgaengen. Ohne den Fix:
        // "XObject 'D24Sig1x0' is unknown", Feld unsichtbar.
        $request = $this->senden($this->vorgang($this->pdf(ressourcenAlsReferenz: true), [$this->unterschriftsfeld()]));

        $this->assertSame(SignatureStatus::COMPLETED, $request->status);
        $paar = $this->renderPaar($request, 1);
        $this->assertSame('', $paar['stderr_signiert'], 'poppler darf nichts melden.');
        $this->assertSame('sichtbar', $this->urteil($paar, [0.1, 0.1, 0.3, 0.08]));
        $this->assertSame('unsichtbar', $this->urteil($paar, [0.6, 0.82, 0.3, 0.08]), 'An der gespiegelten Stelle darf nichts stehen.');
    }

    public function test_indirektes_contents_array(): void
    {
        $request = $this->senden($this->vorgang($this->pdf(contentsIndirekt: true), [$this->unterschriftsfeld()]));

        $paar = $this->renderPaar($request, 1);
        $this->assertSame('', $paar['stderr_signiert']);
        $this->assertSame('sichtbar', $this->urteil($paar, [0.1, 0.1, 0.3, 0.08]));
    }

    public function test_mehrseitig_die_unterschrift_steht_auf_der_richtigen_seite(): void
    {
        $request = $this->senden($this->vorgang($this->pdf(seiten: 3), [$this->unterschriftsfeld(seite: 3, x: 0.55, y: 0.7)]));

        $this->assertSame('sichtbar', $this->urteil($this->renderPaar($request, 3), [0.55, 0.7, 0.3, 0.08]));
        $this->assertSame('unsichtbar', $this->urteil($this->renderPaar($request, 1), [0.55, 0.7, 0.3, 0.08]), 'Seite 1 bleibt unberuehrt.');
        $this->assertSame('unsichtbar', $this->urteil($this->renderPaar($request, 2), [0.55, 0.7, 0.3, 0.08]), 'Seite 2 bleibt unberuehrt.');
    }

    /**
     * Gedrehte Seiten: die Anteile beziehen sich auf die ANZEIGE (nach
     * /Rotate). Ein Fehler in der Drehung setzt die Unterschrift an eine
     * andere Ecke - deshalb zusaetzlich die Gegenprobe an der falschen.
     */
    public function test_gedrehte_seiten_90_180_270(): void
    {
        foreach ([90, 180, 270] as $drehung) {
            $request = $this->senden($this->vorgang($this->pdf(drehung: $drehung), [$this->unterschriftsfeld(x: 0.05, y: 0.05)]));
            $paar = $this->renderPaar($request, 1);

            $this->assertSame('sichtbar', $this->urteil($paar, [0.05, 0.05, 0.3, 0.08]), 'Drehung '.$drehung);
            $this->assertSame('unsichtbar', $this->urteil($paar, [0.65, 0.87, 0.3, 0.08]), 'Drehung '.$drehung.' - falsche Ecke');
        }
    }

    public function test_zeichnung_vom_telefon_mit_geraeteverhaeltnis_2_und_3(): void
    {
        foreach ([2, 3] as $dpr) {
            $request = $this->senden($this->vorgang($this->pdf(), [$this->unterschriftsfeld()]), $dpr);

            $this->assertSame(SignatureStatus::COMPLETED, $request->status, 'DPR '.$dpr);
            $this->assertSame('sichtbar', $this->urteil($this->renderPaar($request, 1), [0.1, 0.1, 0.3, 0.08]), 'DPR '.$dpr);
        }
    }

    public function test_kunde_und_stempel_der_stempel_ist_farbig_und_verdeckt_die_formularlinie_nicht(): void
    {
        // KI-055: ohne den Fix ein weisser Kasten (unsichtbar). Jetzt rot -
        // und der weisse Hintergrund des JPG ist freigestellt, die
        // Formularlinie darunter (y=200 pt = 642 pt von oben) bleibt sichtbar.
        $asset = $this->firmenbild();
        $stempel = ['type' => SignatureFieldType::COMPANY, 'company_asset_id' => $asset->id,
            'page' => 1, 'x' => 0.2, 'y' => 0.72, 'width' => 0.5, 'height' => 0.1];
        $request = $this->senden($this->vorgang($this->pdf(), [$this->unterschriftsfeld(), $stempel]));

        $this->assertSame(SignatureStatus::COMPLETED, $request->status);
        $paar = $this->renderPaar($request, 1);
        $bild = $paar['signiert'];
        $w = imagesx($bild);
        $h = imagesy($bild);

        $rot = 0;
        for ($y = (int) (0.72 * $h); $y < (int) (0.82 * $h); $y++) {
            for ($x = (int) (0.2 * $w); $x < (int) (0.7 * $w); $x++) {
                $c = imagecolorat($bild, $x, $y);
                if ((($c >> 16) & 0xFF) > 150 && (($c >> 8) & 0xFF) < 90 && ($c & 0xFF) < 90) {
                    $rot++;
                }
            }
        }
        $this->assertGreaterThan(50, $rot, 'Der Stempel muss in seiner Farbe erscheinen.');

        // Linie bei 642/842 der Hoehe: links vom Stempelbild (x 0.2-0.25)
        // liegt freigestellter Hintergrund - die Linie muss dort dunkel bleiben.
        $linieY = (int) round((842 - 200) / 842 * $h);
        $dunkel = 0;
        for ($x = (int) (0.2 * $w); $x < (int) (0.24 * $w); $x++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                if ((imagecolorat($bild, $x, $linieY + $dy) & 0xFF) < 100) {
                    $dunkel++;

                    break;
                }
            }
        }
        $this->assertGreaterThan(0, $dunkel, 'Die Formularlinie unter dem Stempel darf nicht weiss verdeckt sein.');
        $this->assertSame('sichtbar', $this->urteil($paar, [0.1, 0.1, 0.3, 0.08]), 'Kundenunterschrift daneben.');
    }

    public function test_nur_stempel_ohne_handschrift(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($this->pdf(ressourcenAlsReferenz: true), [[
            'type' => SignatureFieldType::COMPANY, 'company_asset_id' => $asset->id,
            'page' => 1, 'x' => 0.3, 'y' => 0.3, 'width' => 0.4, 'height' => 0.12,
        ]], mitUnterzeichner: false);

        $storage = app(SignatureStorage::class);
        $original = (string) $storage->read($request->original_path);
        $result = app(SignedPdfBuilder::class)->build($request);

        $this->assertSame([], app(SignedPdfVerifier::class)->pruefe($request, $original, $result['pdf'], $result['bilder']));
        $paar = app(PdfSichtbarkeit::class)->renderPaar($original, $result['pdf'], 1);
        $this->assertSame('sichtbar', $this->urteil($paar, app(PdfSichtbarkeit::class)->bildBereich($request->fields->first(), 842)));
    }

    public function test_scheitert_der_selbsttest_wird_nicht_abgeschlossen_und_laesst_sich_neu_erzeugen(): void
    {
        // KI-058: "Abgeschlossen" erst NACH bestandenem Selbsttest.
        $this->app->bind(SignedPdfVerifier::class, fn () => new class(app(PdfSichtbarkeit::class), app(PdfEingangspruefung::class)) extends SignedPdfVerifier {
            public function pruefe(SignatureRequest $request, string $original, string $signiert, array $bilder, ?array $nurFelder = null): array
            {
                return ['Testfehler'];
            }
        });
        $request = $this->senden($this->vorgang($this->pdf(), [$this->unterschriftsfeld()]));

        $this->assertSame(SignatureStatus::COMPLETION_FAILED, $request->status);
        $this->assertNull($request->signed_path, 'Ein ungeprueftes Dokument wird nicht gespeichert.');
        $this->assertNull($request->completed_at);
        $this->assertTrue(SignatureEvent::where('signature_request_id', $request->id)
            ->where('event', 'pdf_generated')->where('description', 'like', 'FEHLGESCHLAGEN%Testfehler%')->exists());
        Mail::assertNotSent(SignatureCompletedMail::class);

        $this->actingAs($this->admin)->get(route('admin.signatures.show', $request->id))
            ->assertOk()->assertSee('Fehler bei Fertigstellung')->assertSee('Erneut erzeugen');

        // Echte Pruefung zurueck - "Erneut erzeugen" schliesst ab.
        $this->app->forgetInstance(SignedPdfVerifier::class);
        $this->app->offsetUnset(SignedPdfVerifier::class);
        $this->actingAs($this->admin)->post(route('admin.signatures.regenerate', $request->id))->assertRedirect();

        $request->refresh();
        $this->assertSame(SignatureStatus::COMPLETED, $request->status);
        $this->assertNotNull($request->signed_path);
        $this->assertSame('sichtbar', $this->urteil($this->renderPaar($request, 1), [0.1, 0.1, 0.3, 0.08]));
    }

    public function test_reparaturbefehl_erzeugt_neu_und_behaelt_das_alte_pdf(): void
    {
        $request = $this->senden($this->vorgang($this->pdf(), [$this->unterschriftsfeld()]));
        // Ein ALTES, fehlerhaftes Dokument nachstellen (Befund vom Server).
        $storage = app(SignatureStorage::class);
        $kaputt = (string) preg_replace('#(/XObject\s*<<\s*)/D24Sig#', '$1/X24Sig', (string) $storage->read($request->signed_path));
        $storage->disk()->put($request->signed_path, $kaputt);
        $request->forceFill(['signed_hash' => hash('sha256', $kaputt)])->save();
        $altPfad = $request->signed_path;
        $altHash = $request->signed_hash;

        // Probelauf aendert nichts.
        Artisan::call('signaturen:neu-erzeugen', ['--alle' => true]);
        $this->assertStringContainsString($request->id, Artisan::output());
        $this->assertSame($altHash, $request->fresh()->signed_hash);

        Artisan::call('signaturen:neu-erzeugen', ['--alle' => true, '--ausfuehren' => true]);
        $request->refresh();

        $this->assertNotSame($altHash, $request->signed_hash);
        $this->assertNotSame($altPfad, $request->signed_path);
        $this->assertTrue($storage->disk()->exists($altPfad), 'Das alte PDF bleibt erhalten.');
        $this->assertSame(hash('sha256', (string) $storage->read($request->signed_path)), $request->signed_hash);
        $this->assertStringContainsString('Neu erzeugt', (string) $storage->read($request->signed_path));
        $event = SignatureEvent::where('signature_request_id', $request->id)->where('event', 'pdf_regenerated')->firstOrFail();
        $this->assertSame($altHash, $event->meta['sha256_alt'] ?? null);

        $bericht = app(SignatureDiagnostics::class)->diagnose($request, true, false);
        $this->assertSame(0, $bericht['zusammenfassung']['unsichtbar']);
        $this->assertSame(SignatureStatus::COMPLETED, $request->status, 'Status und Unterschriften bleiben.');
        // Nur die EINE Abschluss-Mail vom urspruenglichen Abschluss - die
        // Neuerzeugung verschickt nichts ohne --kopie-senden.
        Mail::assertSent(SignatureCompletedMail::class, 1);
    }

    public function test_die_vorschau_zeigt_nach_abschluss_das_unterschriebene_dokument(): void
    {
        // KI-057: vorher kam auch nach dem Abschluss das Original.
        $request = $this->vorgang($this->pdf(), [$this->unterschriftsfeld()]);
        $renderer = app(SignaturePageRenderer::class);
        $vorher = $renderer->page($request, 1);
        $this->assertNotNull($vorher);

        $request = $this->senden($request);
        $nachher = $renderer->page($request->fresh(), 1);

        $this->assertNotNull($nachher);
        $this->assertNotSame(md5((string) $vorher), md5((string) $nachher), 'Die Vorschau muss die Unterschrift zeigen.');
    }
}
