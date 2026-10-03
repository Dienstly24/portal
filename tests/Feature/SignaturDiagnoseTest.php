<?php

namespace Tests\Feature;

use App\Console\Commands\DiagnoseSignatures;
use App\Models\CompanySignatureAsset;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Signature\SignatureDiagnostics;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureSigningService;
use App\Services\Signature\SignatureStorage;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * signaturen:diagnose - Betreiber-Meldung 02.10.2026: "Kundenunterschrift
 * und Firmenstempel fehlen im fertigen PDF".
 *
 * Der Befehl ist die VORBEDINGUNG jeder Reparatur: er muss die beiden im
 * Nachbau gefundenen Ursachen am ECHTEN Ablauf (hochladen -> Felder ->
 * unterschreiben -> fertiges PDF) wiederfinden, er darf ein sauberes
 * Dokument nicht beanstanden, und er darf nichts veraendern.
 *
 * Das Urteil "sichtbar/unsichtbar" entsteht aus einem Bildvergleich mit
 * pdftoppm - poppler ist Teil der Testumgebung (docs/TESTUMGEBUNG.md).
 */
class SignaturDiagnoseTest extends TestCase
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
     * Eine A4-Seite mit etwas Text. $indirekt: /Contents zeigt auf ein
     * Array-OBJEKT statt direkt auf den Strom - eine Bauform, die viele
     * PDF-Programme schreiben.
     */
    private function pdf(bool $indirekt = false): UploadedFile
    {
        $inhalt = "BT /F1 14 Tf 72 760 Td (Arbeitsvertrag) Tj ET\n";
        $objekte = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents '
                .($indirekt ? '6 0 R' : '4 0 R').' >>',
            4 => '<< /Length '.strlen($inhalt)." >>\nstream\n".$inhalt.'endstream',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        if ($indirekt) {
            $objekte[6] = '[4 0 R]';
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

    /** Zeichenflaeche wie im Browser: durchsichtig, dunkler Strich. */
    private function zeichnung(): string
    {
        $b = imagecreatetruecolor(400, 120);
        imagesavealpha($b, true);
        imagealphablending($b, false);
        imagefill($b, 0, 0, imagecolorallocatealpha($b, 0, 0, 0, 127));
        imagealphablending($b, true);
        imagesetthickness($b, 8);
        imageline($b, 20, 100, 380, 20, imagecolorallocate($b, 16, 24, 40));
        imageline($b, 20, 20, 380, 100, imagecolorallocate($b, 16, 24, 40));
        ob_start();
        imagepng($b);
        $png = (string) ob_get_clean();
        imagedestroy($b);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** Ein Firmenlogo, wie es oft hochgeladen wird: JPG, weisser Hintergrund, rotes Zeichen. */
    private function opakesLogo(): UploadedFile
    {
        $b = imagecreatetruecolor(300, 150);
        imagefill($b, 0, 0, imagecolorallocate($b, 255, 255, 255));
        imagefilledrectangle($b, 60, 30, 240, 120, imagecolorallocate($b, 200, 20, 20));
        $pfad = tempnam(sys_get_temp_dir(), 'logo').'.jpg';
        imagejpeg($b, $pfad, 95);
        imagedestroy($b);

        return new UploadedFile($pfad, 'logo.jpg', 'image/jpeg', null, true);
    }

    private function vorgang(bool $indirekt = false, bool $mitLogo = false): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->pdf($indirekt), [
            'title' => 'Arbeitsvertrag Max Mustermann',
            'identity_check' => SignatureRequest::IDENTITY_NONE,
        ], $this->admin);
        $service->syncSigners($request, [['name' => 'Max Mustermann', 'email' => 'max@example.com']]);
        $request = $request->fresh()->load('signers');

        $felder = [[
            'signer_id' => $request->signers->first()->id, 'type' => SignatureFieldType::SIGNATURE, 'page' => 1,
            'x' => 0.1, 'y' => 0.75, 'width' => 0.35, 'height' => 0.08, 'required' => true,
        ]];
        if ($mitLogo) {
            $this->actingAs($this->admin)->post(route('admin.signatures.company.store'), [
                'type' => CompanySignatureAsset::STEMPEL, 'name' => 'Stempel', 'bild' => $this->opakesLogo(), 'is_default' => 1,
            ])->assertSessionHasNoErrors();
            $felder[] = [
                'signer_id' => null, 'company_asset_id' => CompanySignatureAsset::firstOrFail()->id,
                'type' => SignatureFieldType::COMPANY, 'page' => 1, 'x' => 0.55, 'y' => 0.75, 'width' => 0.3, 'height' => 0.12,
            ];
        }
        $service->syncFields($request, $felder);
        $request = $request->fresh();
        $service->send($request);

        return $request->fresh()->load(['signers', 'fields']);
    }

    private function unterschreiben(SignatureRequest $request): SignatureRequest
    {
        $fehler = app(SignatureSigningService::class)->sign(
            $request, $request->signers->first(), [], [SignatureFieldType::SIGNATURE => $this->zeichnung()]
        );
        $this->assertSame([], $fehler);

        return $request->fresh();
    }

    private function diagnose(SignatureRequest $request): array
    {
        return app(SignatureDiagnostics::class)->diagnose($request->fresh(), true, false);
    }

    private function feld(array $bericht, string $art): array
    {
        return collect($bericht['felder'])->firstWhere('art', $art);
    }

    // ---------------------------------------------------------------- Faelle

    public function test_eine_saubere_unterschrift_wird_als_sichtbar_erkannt(): void
    {
        $request = $this->unterschreiben($this->vorgang());
        $this->assertSame(SignatureStatus::COMPLETED, $request->status);

        $bericht = $this->diagnose($request);

        $this->assertSame('sichtbar', $this->feld($bericht, SignatureFieldType::SIGNATURE)['sichtbarkeit']['urteil']);
        $this->assertSame([], $bericht['ursachen'], 'Ein sauberes Dokument darf nicht beanstandet werden.');
        $this->assertSame(0, $bericht['zusammenfassung']['unsichtbar']);
    }

    /**
     * Stellt eine VOR dem Fix erzeugte Datei nach: die Bild-Namen stehen im
     * Inhaltsstrom, sind aber in den Seitenressourcen nicht auffindbar -
     * genau der Befund vom Server ("XObject 'D24Sig1x0' is unknown", KI-062).
     */
    private function alterDefekt(SignatureRequest $request): SignatureRequest
    {
        $storage = app(SignatureStorage::class);
        $pdf = (string) $storage->read($request->signed_path);
        $kaputt = (string) preg_replace('#(/XObject\s*<<\s*)/D24Sig#', '$1/X24Sig', $pdf);
        $this->assertNotSame($pdf, $kaputt, 'Testaufbau: die Ressourcen muessen umbenannt sein.');
        $storage->disk()->put($request->signed_path, $kaputt);
        $request->forceFill(['signed_hash' => hash('sha256', $kaputt), 'signed_size' => strlen($kaputt)])->save();

        return $request->fresh();
    }

    public function test_ein_alter_defekt_wird_als_unsichtbar_mit_ursache_erkannt(): void
    {
        $request = $this->alterDefekt($this->unterschreiben($this->vorgang()));

        $bericht = $this->diagnose($request);
        $feld = $this->feld($bericht, SignatureFieldType::SIGNATURE);

        $this->assertSame('unsichtbar', $feld['sichtbarkeit']['urteil']);
        $this->assertContains(SignatureDiagnostics::RENDER_FEHLER, $feld['ursachen']);
        $this->assertStringContainsString('is unknown', $bericht['poppler'][0]['meldung_signiert']);
        $this->assertNotContains(SignatureDiagnostics::UNBEKANNT, $bericht['ursachen']);
    }

    public function test_ein_deckendes_firmenlogo_ist_jetzt_sichtbar_und_nur_noch_ein_risiko(): void
    {
        // KI-055 behoben: das Logo traegt seine echten Farben. Die
        // Eigenschaft "deckend" bleibt als Risiko vermerkt, ist aber keine
        // Ursache mehr - sonst stuende ein einwandfreies Dokument als
        // betroffen in der Liste.
        $request = $this->unterschreiben($this->vorgang(mitLogo: true));
        $this->assertSame(SignatureStatus::COMPLETED, $request->status);

        $bericht = $this->diagnose($request);
        $logo = $this->feld($bericht, SignatureFieldType::COMPANY);

        $this->assertSame('sichtbar', $logo['sichtbarkeit']['urteil']);
        $this->assertSame([], $logo['ursachen']);
        $this->assertContains(SignatureDiagnostics::FIRMENBILD_OPAK, $logo['risiken']);
        $this->assertSame([], $bericht['ursachen']);
    }

    public function test_ein_indirektes_contents_array_ist_jetzt_sichtbar(): void
    {
        // KI-056 behoben: das Array wird aufgeloest statt als Strom
        // angehaengt.
        $request = $this->unterschreiben($this->vorgang(indirekt: true));

        $bericht = $this->diagnose($request);
        $feld = $this->feld($bericht, SignatureFieldType::SIGNATURE);

        $this->assertSame('array-indirekt', $bericht['seiten'][0]['contents']);
        $this->assertSame('sichtbar', $feld['sichtbarkeit']['urteil']);
        $this->assertContains(SignatureDiagnostics::CONTENTS_INDIREKT, $feld['risiken']);
        $this->assertSame('', $bericht['poppler'][0]['meldung_signiert'], 'poppler darf nichts mehr melden.');
    }

    public function test_fehlt_das_fertige_pdf_wird_es_nur_im_speicher_nachgebaut(): void
    {
        $request = $this->unterschreiben($this->vorgang());
        $storage = app(SignatureStorage::class);
        $storage->disk()->delete($request->signed_path);
        $request->forceFill(['signed_path' => null, 'signed_hash' => null])->save();

        $bericht = $this->diagnose($request);

        $this->assertContains(SignatureDiagnostics::KEIN_SIGNIERTES_PDF, $bericht['ursachen']);
        $this->assertTrue($bericht['ergebnis_simuliert']);
        $this->assertSame('sichtbar', $this->feld($bericht, SignatureFieldType::SIGNATURE)['sichtbarkeit']['urteil']);
        $this->assertNull($request->fresh()->signed_path, 'Der Nachbau wird NIE gespeichert.');
    }

    public function test_der_befehl_veraendert_nichts_und_nennt_keine_personendaten(): void
    {
        $request = $this->alterDefekt($this->unterschreiben($this->vorgang(mitLogo: true)));
        $storage = app(SignatureStorage::class);
        $vorherDateien = $storage->disk()->allFiles();
        $vorherEreignisse = SignatureEvent::count();
        $vorherZeile = $request->fresh()->getAttributes();

        $code = Artisan::call('signaturen:diagnose', ['id' => $request->id]);
        $ausgabe = Artisan::output();

        $this->assertSame(1, $code, 'Betroffen = Exitcode 1.');
        $this->assertStringContainsString(SignatureDiagnostics::RENDER_FEHLER, $ausgabe);
        $this->assertStringNotContainsString('Max Mustermann', $ausgabe);
        $this->assertStringNotContainsString('max@example.com', $ausgabe);
        $this->assertStringNotContainsString('Arbeitsvertrag Max', $ausgabe);

        $this->assertSame($vorherDateien, $storage->disk()->allFiles(), 'Keine Datei angelegt oder geloescht.');
        $this->assertSame($vorherEreignisse, SignatureEvent::count(), 'Kein Protokoll-Ereignis geschrieben.');
        $this->assertSame($vorherZeile, $request->fresh()->getAttributes());
    }

    public function test_der_bestandslauf_listet_nur_betroffene_anfragen_mit_ursache(): void
    {
        $sauber = $this->unterschreiben($this->vorgang());
        $kaputt = $this->alterDefekt($this->unterschreiben($this->vorgang()));

        Artisan::call('signaturen:diagnose', ['--alle' => true, '--json' => true]);
        $json = json_decode(Artisan::output(), true);

        $this->assertSame(2, $json['geprueft']);
        $this->assertSame(1, $json['betroffen']);
        $ids = array_column($json['anfragen'], 'id');
        $this->assertContains($kaputt->id, $ids);
        $this->assertNotContains($sauber->id, $ids);
        $this->assertSame(1, $json['ursachen'][SignatureDiagnostics::RENDER_FEHLER]);
    }

    public function test_ohne_pdftoppm_ist_nichts_pruefbar_aber_nichts_beschuldigt(): void
    {
        // Gefunden in der CI (Job ohne poppler): die "nicht gefunden"-
        // Meldung der Shell zaehlte als Ursache U11 - jede Anfrage stand
        // als betroffen da. Ein fehlendes Programm ist ein Befund ueber den
        // SERVER, keiner ueber das Dokument.
        $sauber = $this->unterschreiben($this->vorgang());
        config(['services.ocr.pdftoppm_binary' => '/gibt/es/nicht/pdftoppm']);

        $bericht = $this->diagnose($sauber);

        $this->assertSame('nicht_pruefbar', $this->feld($bericht, SignatureFieldType::SIGNATURE)['sichtbarkeit']['urteil']);
        $this->assertNotContains(SignatureDiagnostics::RENDER_FEHLER, $bericht['ursachen']);
        $this->assertSame([], $bericht['ursachen']);
        $this->assertStringContainsString('poppler-utils', implode(' ', $bericht['hinweise']));

        Artisan::call('signaturen:diagnose', ['--alle' => true, '--json' => true]);
        $this->assertSame(0, json_decode(Artisan::output(), true)['betroffen']);
    }

    public function test_zeichen_ausserhalb_winansi_werden_ohne_namen_gemeldet(): void
    {
        $this->assertSame([], DiagnoseSignatures::zeichenAusserhalbWinAnsi('Jürgen Größe-Müller'));
        $this->assertSame(['ج', 'ه', 'ا', 'د'], DiagnoseSignatures::zeichenAusserhalbWinAnsi('جهاد Najm'));
        $this->assertSame(['ł', 'ę'], DiagnoseSignatures::zeichenAusserhalbWinAnsi('Wałęsa'));

        User::factory()->create(['name' => 'جهاد نجم']);
        Artisan::call('signaturen:diagnose', ['--zeichen' => true]);
        $ausgabe = Artisan::output();

        $this->assertStringContainsString('U+062C', $ausgabe);
        $this->assertStringContainsString('users.name', $ausgabe);
        $this->assertStringNotContainsString('جهاد نجم', $ausgabe, 'Nur Zeichen, nie ein Name.');
    }
}
