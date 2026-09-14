<?php

namespace Tests\Feature;

use App\Models\CompanySignatureAsset;
use App\Models\SignatureRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignatureTokenService;
use App\Services\Signature\SignedPdfBuilder;
use App\Support\Firmensignatur;
use App\Support\SignatureFieldType;
use App\Support\SignatureGroup;
use App\Support\SignatureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * EINE UNTERNEHMENSSIGNATUR IST MEHR ALS EIN LOGO.
 *
 * Gemeldet 14.09.2026: "Company Signature fuegt nur ein Logo hinzu". Der
 * Befund stimmte - das Firmenfeld trug ausschliesslich ein BILD. Im
 * fertigen Vertrag stand damit eine Grafik ohne jeden Bezug: kein Name,
 * keine Zuordnung. Wer das Dokument spaeter las, sah ein Zeichen und
 * konnte nicht sagen, WELCHE Firma unterschrieben hat. Ein Logo ist ein
 * Wiedererkennungszeichen; eine Signatur braucht den NAMEN.
 *
 * Die Faelle hier halten beides fest: dass der Name im Dokument steht -
 * und dass die Einordnung unveraendert streng bleibt (ein Firmenbild ist
 * KEIN Unterzeichner und keine Willenserklaerung eines Menschen).
 */
class UnternehmenssignaturTest extends TestCase
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

    private function pdf(int $seiten = 2): UploadedFile
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

    private function pngDatei(): UploadedFile
    {
        $bild = imagecreatetruecolor(300, 120);
        imagesavealpha($bild, true);
        imagealphablending($bild, false);
        imagefill($bild, 0, 0, imagecolorallocatealpha($bild, 0, 0, 0, 127));
        imagealphablending($bild, true);
        imagefilledrectangle($bild, 20, 40, 280, 80, imagecolorallocate($bild, 30, 60, 40));
        $pfad = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng($bild, $pfad);
        imagedestroy($bild);

        return new UploadedFile($pfad, 'logo.png', 'image/png', null, true);
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

    private function firmenbild(string $typ = CompanySignatureAsset::UNTERSCHRIFT): CompanySignatureAsset
    {
        $this->actingAs($this->admin)->post(route('admin.signatures.company.store'), [
            'type' => $typ, 'name' => 'Geschäftsführung', 'bild' => $this->pngDatei(), 'is_default' => 1,
        ])->assertSessionHasNoErrors();

        return CompanySignatureAsset::firstOrFail();
    }

    /** Vorgang mit EINER Person und EINER Unternehmenssignatur. */
    private function vorgang(?CompanySignatureAsset $asset = null): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->pdf(), [
            'title' => 'Arbeitsvertrag',
            'identity_check' => SignatureRequest::IDENTITY_NONE,
        ], $this->admin);
        $service->syncSigners($request, [['name' => 'Max Mustermann', 'email' => 'max@example.com']]);
        $request = $request->fresh()->load('signers');
        $signer = $request->signers->first();

        $felder = [[
            'signer_id' => $signer->id, 'type' => SignatureFieldType::SIGNATURE, 'page' => 1,
            'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.06, 'required' => true,
        ]];
        if ($asset !== null) {
            $felder[] = [
                'signer_id' => null, 'company_asset_id' => $asset->id, 'type' => SignatureFieldType::COMPANY,
                'page' => 1, 'x' => 0.6, 'y' => 0.7, 'width' => 0.28, 'height' => 0.07,
            ];
        }
        $service->syncFields($request, $felder);

        return $request->fresh()->load(['signers', 'fields']);
    }

    // ------------------------------------------------- 1-4: echte Signatur

    public function test_die_firmenidentitaet_kommt_aus_der_einen_einstellung(): void
    {
        $this->assertSame('Dienstly24 GmbH', Firmensignatur::name());
        $this->assertTrue(Firmensignatur::namePflegt());

        // Ohne gepflegten Namen faellt es auf den Anwendungsnamen zurueck -
        // leer wird der Block nie, sonst waere er wieder nur ein Logo.
        SystemSetting::set('company_name', '');
        $this->assertNotSame('', Firmensignatur::name());
        $this->assertFalse(Firmensignatur::namePflegt());
    }

    public function test_die_unternehmenssignatur_laesst_sich_setzen_und_kennt_ihr_bild(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);

        $feld = $request->fields->firstWhere('type', SignatureFieldType::COMPANY);
        $this->assertNotNull($feld);
        $this->assertSame($asset->id, $feld->company_asset_id);
        $this->assertNull($feld->signature_signer_id, 'Sie gehoert keinem Menschen.');
        $this->assertTrue($feld->isFilled(), 'Sie wartet auf niemanden.');
    }

    public function test_im_fertigen_pdf_steht_der_firmenname_nicht_nur_das_bild(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);

        $pdf = app(SignedPdfBuilder::class)->build($request)['pdf'];

        // DER KERN DER MELDUNG: der Name steht im Dokument.
        $this->assertStringContainsString('Dienstly24 GmbH', $pdf);
        // Und das Bild ebenfalls - ein Name allein waere keine Signatur.
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        // Die Einordnung bleibt streng.
        $this->assertStringContainsString('eingesetzt von', $pdf);
        $this->assertStringContainsString('KEINE Unterschrift einer Person', $pdf);
    }

    public function test_ein_sehr_flaches_feld_bekommt_nur_das_bild(): void
    {
        // EHRLICH BLEIBEN: unter rund 26 Punkten Hoehe ist fuer eine
        // lesbare Namenszeile kein Platz. Dann steht dort nur das Bild -
        // lieber kein Name als ein Name, der im Bild klebt.
        $asset = $this->firmenbild();
        $service = app(SignatureRequestService::class);
        $request = $this->vorgang($asset);
        $feld = $request->fields->firstWhere('type', SignatureFieldType::COMPANY);
        $feld->forceFill(['height' => 0.01])->save();

        $pdf = app(SignedPdfBuilder::class)->build($request->fresh()->load('fields'))['pdf'];

        // Im SEITENINHALT steht kein Name (im Protokoll schon - es hat Platz).
        $seiteninhalt = substr($pdf, 0, (int) strpos($pdf, 'Signaturprotokoll'));
        $this->assertStringNotContainsString('(Dienstly24 GmbH) Tj', $seiteninhalt);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->assertNotNull($service);
    }

    // -------------------------------------- 5: der Kunde sieht sie auch

    public function test_der_unterzeichner_sieht_die_unternehmenssignatur(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);
        app(SignatureRequestService::class)->send($request);

        $signer = $request->signers()->firstOrFail();
        $token = app(SignatureTokenService::class)->issue($signer, $request->expires_at);

        $html = (string) $this->get(route('signature.show', $token))->assertOk()->getContent();

        // Er soll wissen, dass der Betrieb mitzeichnet - und WER das ist.
        $this->assertStringContainsString('Dienstly24 GmbH', $html);
        $this->assertStringContainsString(route('signature.company_image', [$token, $asset->id]), $html);
        // Aber es ist nichts zum Ausfuellen: kein Eingabefeld dafuer.
        $this->assertStringNotContainsString('felder['.$asset->id.']', $html);

        // Das Bild kommt ueber das TOKEN, nicht ueber eine Rolle.
        $this->get(route('signature.company_image', [$token, $asset->id]))->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_ein_fremdes_firmenbild_kommt_ueber_das_token_nicht_heraus(): void
    {
        $asset = $this->firmenbild();
        // Ein zweites Bild, das in DIESEM Vorgang nicht gesetzt ist.
        $this->actingAs($this->admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::STEMPEL, 'name' => 'Fremd', 'bild' => $this->pngDatei(),
        ]);
        $fremd = CompanySignatureAsset::where('name', 'Fremd')->firstOrFail();

        $request = $this->vorgang($asset);
        app(SignatureRequestService::class)->send($request);
        $signer = $request->signers()->firstOrFail();
        $token = app(SignatureTokenService::class)->issue($signer, $request->expires_at);

        // Sonst waere ein gueltiges Token ein Weg durch den gesamten
        // Firmenbild-Bestand.
        $this->get(route('signature.company_image', [$token, $fremd->id]))->assertNotFound();
    }

    // ------------------------------------- 6-10: beide Arten nebeneinander

    public function test_unternehmen_und_person_stehen_nebeneinander(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);

        $this->assertSame(1, $request->fields->filter(fn ($f) => $f->isCompany())->count());
        $this->assertSame(1, $request->fields->filter(fn ($f) => $f->isDrawn())->count());
        $this->assertSame(1, $request->signers->count());
    }

    public function test_die_unternehmenssignatur_blockiert_den_versand_nicht(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);

        // Sie wartet auf niemanden und zaehlt deshalb nicht als offener Punkt.
        $this->assertSame([], app(SignatureRequestService::class)->blockersForSending($request));

        app(SignatureRequestService::class)->send($request);
        $this->assertSame(SignatureStatus::SENT, $request->fresh()->status);
    }

    public function test_ein_vorgang_NUR_mit_unternehmenssignatur_geht_nicht_raus(): void
    {
        // Ein Dokument, auf dem nur der Betrieb zeichnet, hat niemanden zum
        // Einladen - es waere eine E-Mail ohne Zweck.
        $asset = $this->firmenbild();
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->pdf(), [
            'title' => 'Nur Firma', 'identity_check' => SignatureRequest::IDENTITY_NONE,
        ], $this->admin);
        $service->syncSigners($request, [['name' => 'Max', 'email' => 'max@example.com']]);
        $request = $request->fresh()->load('signers');
        $service->syncFields($request, [[
            'signer_id' => null, 'company_asset_id' => $asset->id, 'type' => SignatureFieldType::COMPANY,
            'page' => 1, 'x' => 0.6, 'y' => 0.7, 'width' => 0.28, 'height' => 0.07,
        ]]);

        $blocker = $service->blockersForSending($request->fresh()->load(['signers', 'fields']));
        $this->assertNotEmpty($blocker);
        // Die Unternehmenssignatur zaehlt fuer den Menschen NICHT mit -
        // sonst waere der Vorgang versandfertig, ohne dass irgendwer etwas
        // zu unterschreiben haette.
        $this->assertStringContainsString('kein Feld gesetzt', implode(' ', $blocker));
    }

    public function test_beide_ueberstehen_das_unterschreiben_und_landen_im_pdf(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);
        app(SignatureRequestService::class)->send($request);

        $signer = $request->signers()->firstOrFail();
        $token = app(SignatureTokenService::class)->issue($signer, $request->expires_at);
        $this->post(route('signature.sign', $token), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);

        $request->refresh();
        $this->assertSame(SignatureStatus::COMPLETED, $request->status);

        // Die Unternehmenssignatur ueberlebt das Unterschreiben unveraendert -
        // die Zeichnung der Person ersetzt sie nicht und loescht sie nicht.
        $firmenfeld = $request->fields()->where('type', SignatureFieldType::COMPANY)->firstOrFail();
        $this->assertSame($asset->id, $firmenfeld->company_asset_id);
        $this->assertNull($firmenfeld->signature_signer_id);

        $pdf = app(SignatureStorage::class)->read($request->signed_path);
        $this->assertNotNull($pdf);
        $this->assertStringContainsString('Dienstly24 GmbH', $pdf);
        $this->assertStringContainsString('Max Mustermann', $pdf);
    }

    public function test_die_signaturgruppe_bleibt_die_eine_quelle(): void
    {
        // Die Unternehmenssignatur darf die Gruppe der Person NICHT
        // beeinflussen: sie ist keine Handschrift und gehoert keinem
        // Unterzeichner. Sonst wuerde aus "eine Unterschrift an drei
        // Stellen" plotzlich "vier".
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);
        $signer = $request->signers->first();

        $gruppen = SignatureGroup::forSigner($signer, $request->fields);

        $this->assertCount(1, $gruppen);
        $this->assertSame(1, $gruppen->first()->count());
        $this->assertSame(SignatureFieldType::SIGNATURE, $gruppen->first()->type);
    }

    // ------------------------------------------------- 11-12: der Editor

    public function test_der_editor_bietet_zwei_karten_und_eine_vorschau(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();

        // Zwei gleichrangige Wege statt sieben gleich aussehender Knoepfe.
        $this->assertStringContainsString('Signatur hinzufügen', $html);
        $this->assertStringContainsString('id="karte-person"', $html);
        $this->assertStringContainsString('id="karte-firma"', $html);
        // Die Vorschau zeigt, was ins Dokument kommt: Bild UND Name.
        $this->assertStringContainsString('id="firma-vorschau"', $html);
        $this->assertStringContainsString('Dienstly24 GmbH', $html);
        $this->assertStringContainsString('✓ Unternehmenssignatur', $html);
    }

    public function test_fehlende_unternehmensdaten_werden_im_klartext_gemeldet(): void
    {
        SystemSetting::set('company_name', '');
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();

        // Kein technischer Fehler, sondern der naechste Schritt.
        $this->assertStringContainsString('Unternehmensdaten unvollständig', $html);
        $this->assertStringContainsString('Firmenname', $html);
    }

    public function test_ohne_hinterlegtes_bild_gibt_es_die_unternehmenskarte_nicht(): void
    {
        // Eine Karte, die zu nichts fuehrt, ist eine Einladung in eine
        // Sackgasse. Ohne Bild im Bestand erscheint sie deshalb gar nicht -
        // der Weg dorthin steht in den Einstellungen.
        $request = $this->vorgang(null);
        $this->assertSame(0, CompanySignatureAsset::count());

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="karte-firma"', $html);
        // Die Personen-Karte steht trotzdem da.
        $this->assertStringContainsString('id="karte-person"', $html);
    }

    public function test_alle_personal_rollen_duerfen_die_unternehmenssignatur_benutzen(): void
    {
        // Benutzen darf sie jede Personal-Rolle (VERWALTEN nur admin/manager -
        // das prueft CompanySignatureAssetTest). Ein Mitarbeiter, der ein
        // Dokument vorbereitet, kommt sonst nicht an den Firmenstempel.
        $asset = $this->firmenbild();
        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        $request = $this->vorgang($asset);
        $request->forceFill(['created_by' => $mitarbeiter->id])->save();

        $html = (string) $this->actingAs($mitarbeiter)
            ->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();

        $this->assertStringContainsString('id="karte-firma"', $html);
        $this->assertNotNull($asset);
    }

    public function test_das_feldmenue_bietet_keine_unterzeichnerwahl_fuer_die_firma(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();

        // Der Server setzt den Unterzeichner eines Firmenfeldes IMMER auf
        // leer - ein Auswahlfeld dafuer waere ein Bedienelement, das nichts
        // tut. Es wird deshalb ausgeblendet, statt ins Leere zu laufen.
        $this->assertStringContainsString('id="menu-kopf"', $html);
        $this->assertStringContainsString('wahl.hidden = istFirma', $html);
    }

    public function test_die_miniaturen_unterscheiden_person_und_unternehmen(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();

        // Ein ✍ neben einem Firmenfeld hiesse, dort muesse jemand mit der
        // Hand zeichnen - dort steht aber die Unternehmenssignatur.
        $this->assertStringContainsString("' 🏢'", $html);
        $this->assertStringContainsString("' ✍'", $html);
    }

    public function test_der_leerzustand_nennt_beide_wege(): void
    {
        $request = $this->vorgang(null);
        $request->fields()->delete();

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->fresh()->id))->assertOk()->getContent();

        $this->assertStringContainsString('Noch keine Signaturen', $html);
        $this->assertStringContainsString('Personen- oder Unternehmenssignatur', $html);
    }

    public function test_die_statusseite_zaehlt_person_und_unternehmen_getrennt(): void
    {
        $asset = $this->firmenbild();
        $request = $this->vorgang($asset);
        app(SignatureRequestService::class)->send($request);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.show', $request->id))->assertOk()->getContent();

        $this->assertStringContainsString('0 / 1 Unterschriftsstellen', $html);
        $this->assertStringContainsString('1 / 1 Unternehmen', $html);
        $this->assertStringContainsString('Dienstly24 GmbH', $html);
    }
}
