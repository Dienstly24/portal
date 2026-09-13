<?php

namespace Tests\Feature;

use App\Models\InternalNotification;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * DER LEBENSLAUF EINES AUFTRAGS - und die Grenzen, die ihn tragen.
 *
 * Gemeldet 13.09.2026: nach dem Anlegen gab es keinen erkennbaren Weg mehr
 * heraus. Wer einen Vorgang doch nicht wollte, fand weder "loeschen" noch
 * "stornieren"; wer Felder gesetzt hatte, musste erst "Entwurf speichern"
 * verstehen, bevor er versenden durfte - und wer das uebersah, verschickte
 * den Stand von vorhin, ohne Fehlermeldung.
 *
 * Die Faelle hier halten die Regeln fest, die daraus folgen:
 * ENTWUERFE darf man loeschen (es hat sie nie jemand gesehen), VERSANDTES
 * wird nur storniert (das Protokoll bleibt), UNTERSCHRIEBENES gar nicht
 * (es ist der Nachweis) - und "Senden" speichert selbst.
 */
class SignaturWorkflowTest extends TestCase
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

    /** Ein Entwurf mit einem Unterzeichner - ohne Felder. */
    private function entwurf(int $seiten = 3): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->pdf($seiten), [
            'title' => 'Arbeitsvertrag',
            'identity_check' => SignatureRequest::IDENTITY_NONE,
        ], $this->admin);
        $service->syncSigners($request, [['name' => 'Max Mustermann', 'email' => 'max@example.com']]);

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

    private function versandfertig(int $seiten = 3): SignatureRequest
    {
        $request = $this->entwurf($seiten);
        $daten = $this->nutzlast($request, [1]);
        app(SignatureRequestService::class)->syncFields($request, $daten['fields']);

        return $request->fresh()->load(['signers', 'fields']);
    }

    // ------------------------------------------------------- 1 / 2: Entwurf

    public function test_ein_entwurf_laesst_sich_weiter_bearbeiten(): void
    {
        $request = $this->entwurf();

        $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->id))
            ->assertOk()
            ->assertSee('Als Entwurf speichern');

        $this->actingAs($this->admin)
            ->postJson(route('admin.signatures.prepare.save', $request->id), $this->nutzlast($request, [1, 2]))
            ->assertOk();

        $this->assertSame(2, $request->fields()->count());
    }

    public function test_ein_entwurf_laesst_sich_loeschen(): void
    {
        $request = $this->entwurf();
        $pfad = $request->original_path;
        $this->assertTrue(app(SignatureStorage::class)->disk()->exists($pfad));

        $this->actingAs($this->admin)
            ->delete(route('admin.signatures.destroy', $request->id))
            ->assertRedirect(route('admin.signatures.index'));

        $this->assertDatabaseMissing('signature_requests', ['id' => $request->id]);
        // Die Datei geht mit - ein Entwurf, den niemand gesehen hat, laesst
        // kein PDF auf der Platte zurueck.
        $this->assertFalse(app(SignatureStorage::class)->disk()->exists($pfad));
        // Die SPUR bleibt, nur eben nicht am geloeschten Vorgang.
        $this->assertDatabaseHas('activity_logs', ['action' => 'signature_draft_deleted']);
    }

    // --------------------------------------------------------- 3: Senden

    public function test_senden_speichert_die_aenderungen_des_editors_mit(): void
    {
        // Der Kern der Meldung: die Felder sind gesetzt, aber noch NICHT
        // gespeichert. Frueher ging der Vorgang so gar nicht raus ("kein
        // Feld gesetzt") - obwohl auf dem Bildschirm sieben Felder standen.
        $request = $this->entwurf();
        $this->assertSame(0, $request->fields()->count());

        $antwort = $this->actingAs($this->admin)
            ->postJson(route('admin.signatures.send', $request->id), $this->nutzlast($request, [1, 2, 3]));

        $antwort->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(route('admin.signatures.show', $request->id), $antwort->json('redirect'));
        $this->assertSame(3, $request->fields()->count());
        $this->assertSame(SignatureStatus::SENT, $request->fresh()->status);
    }

    public function test_ohne_feld_bleibt_der_versand_aus_und_sagt_warum(): void
    {
        $request = $this->entwurf();

        $antwort = $this->actingAs($this->admin)
            ->postJson(route('admin.signatures.send', $request->id), ['signers' => [], 'fields' => []]);

        $antwort->assertStatus(422);
        $this->assertStringContainsString('Unterzeichner', (string) $antwort->json('message'));
        $this->assertSame(SignatureStatus::DRAFT, $request->fresh()->status);
    }

    // ------------------------------------------------ 4 / 5: Stornieren

    public function test_ein_versandter_auftrag_laesst_sich_stornieren(): void
    {
        $request = $this->versandfertig();
        app(SignatureRequestService::class)->send($request);

        $this->actingAs($this->admin)
            ->post(route('admin.signatures.cancel', $request->id), ['reason' => 'Kunde hat abgesagt'])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame(SignatureStatus::CANCELLED, $request->status);
        $this->assertSame('Kunde hat abgesagt', $request->cancel_reason);
        // Storniert heisst NICHT geloescht: der Vorgang und sein Protokoll
        // stehen weiter da.
        $this->assertDatabaseHas('signature_requests', ['id' => $request->id]);
        $this->assertTrue($request->events()->where('event', 'cancelled')->exists());
    }

    public function test_ein_versandter_auftrag_wird_nicht_geloescht_sondern_storniert(): void
    {
        $request = $this->versandfertig();
        app(SignatureRequestService::class)->send($request);

        $this->actingAs($this->admin)->delete(route('admin.signatures.destroy', $request->id));

        $this->assertDatabaseHas('signature_requests', ['id' => $request->id]);
    }

    public function test_nach_dem_stornieren_kann_der_kunde_nicht_mehr_unterschreiben(): void
    {
        $request = $this->versandfertig();
        $service = app(SignatureRequestService::class);
        $service->send($request);

        $signer = $request->signers()->firstOrFail();
        $token = app(SignatureTokenService::class)->issue($signer, $request->expires_at);
        $service->cancel($request->fresh()->load('signers'), null);

        $this->post(route('signature.sign', $token), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);

        $this->assertNull($signer->fresh()->signed_at, 'Ein widerrufener Zugang darf nicht mehr unterschreiben.');
        $this->assertSame(SignatureStatus::CANCELLED, $request->fresh()->status);
    }

    // ------------------------------------------- 6: Abgeschlossenes bleibt

    public function test_ein_unterschriebenes_dokument_wird_nicht_geloescht(): void
    {
        $request = $this->versandfertig();
        $service = app(SignatureRequestService::class);
        $service->send($request);

        $signer = $request->signers()->firstOrFail();
        $token = app(SignatureTokenService::class)->issue($signer, $request->expires_at);
        $this->post(route('signature.sign', $token), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);
        $this->assertSame(SignatureStatus::COMPLETED, $request->fresh()->status);
        $ereignisse = $request->events()->count();

        $this->actingAs($this->admin)->delete(route('admin.signatures.destroy', $request->id));

        // Weder der Vorgang noch das Protokoll duerfen verschwinden - beides
        // zusammen IST der Nachweis der Unterschrift.
        $this->assertDatabaseHas('signature_requests', ['id' => $request->id]);
        $this->assertSame($ereignisse, $request->events()->count());
        // Und auch nicht storniert: abgeschlossen ist abgeschlossen.
        $this->actingAs($this->admin)->post(route('admin.signatures.cancel', $request->id));
        $this->assertSame(SignatureStatus::COMPLETED, $request->fresh()->status);
    }

    // ------------------------------------------- 7 / 8: Glocke je Gruppe

    public function test_sieben_stellen_erzeugen_genau_eine_meldung(): void
    {
        $request = $this->entwurf();
        $daten = $this->nutzlast($request, [1, 1, 2, 2, 3, 3, 3]);
        app(SignatureRequestService::class)->syncFields($request, $daten['fields']);
        $request = $request->fresh()->load(['signers', 'fields']);
        app(SignatureRequestService::class)->send($request);

        $signer = $request->signers()->firstOrFail();
        $token = app(SignatureTokenService::class)->issue($signer, $request->expires_at);
        $this->post(route('signature.sign', $token), [
            'zustimmung' => '1',
            'zeichnung' => [SignatureFieldType::SIGNATURE => $this->bild()],
        ]);

        // EINE Zeichnung fuellt sieben Stellen - und laeutet EINMAL.
        $this->assertSame(7, $signer->fields()->whereNotNull('image_path')->count());
        $this->assertSame(1, InternalNotification::where('user_id', $this->admin->id)
            ->where('title', 'Dokument unterschrieben')->count());
        $this->assertSame(1, InternalNotification::where('user_id', $this->admin->id)
            ->where('title', 'Signatur abgeschlossen')->count());
    }

    // ------------------------------------------------------- 9: Erinnern

    public function test_die_erinnerung_wird_gezaehlt_und_nicht_im_minutentakt_wiederholt(): void
    {
        $request = $this->versandfertig();
        $service = app(SignatureRequestService::class);
        $service->send($request);

        $this->actingAs($this->admin)->post(route('admin.signatures.remind', $request->id));
        $signer = $request->signers()->firstOrFail()->fresh();
        $this->assertSame(1, (int) $signer->reminder_count);
        $this->assertNotNull($signer->reminded_at);

        // WER erinnert hat, steht im Protokoll - dafuer braucht es keine
        // zusaetzliche Spalte am Unterzeichner.
        $ereignis = $request->events()->where('event', 'reminder_sent')->first();
        $this->assertNotNull($ereignis);
        $this->assertSame($this->admin->id, $ereignis->user_id);

        // Sofort noch einmal: abgelehnt. Eine Erinnerung ist eine Bitte,
        // kein Dauerfeuer - und der naechste Schritt waere der Spam-Ordner.
        $this->actingAs($this->admin)->post(route('admin.signatures.remind', $request->id))
            ->assertSessionHas('error');
        $this->assertSame(1, (int) $request->signers()->firstOrFail()->reminder_count);
    }

    public function test_nach_der_frist_ist_eine_weitere_erinnerung_erlaubt(): void
    {
        $request = $this->versandfertig();
        $service = app(SignatureRequestService::class);
        $service->send($request);
        $this->actingAs($this->admin)->post(route('admin.signatures.remind', $request->id));

        $this->travel(SignatureRequestService::REMINDER_MIN_HOURS + 1)->hours();
        $this->actingAs($this->admin)->post(route('admin.signatures.remind', $request->id))
            ->assertSessionHas('success');

        $this->assertSame(2, (int) $request->signers()->firstOrFail()->reminder_count);
    }

    // ----------------------------------------------- 10-12: Die Oberflaeche

    public function test_der_editor_traegt_betrachter_navigation_und_feste_aktionsleiste(): void
    {
        $request = $this->versandfertig(14);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();

        // Der Betrachter hat eine eigene Hoehe - das Dokument bestimmt nicht
        // mehr die Seitenlaenge.
        $this->assertStringContainsString('class="sig-shell"', $html);
        $this->assertMatchesRegularExpression('/\.sig-shell\{[^}]*height:calc\(100dvh/s', $html);
        $this->assertMatchesRegularExpression('/\.sig-viewer\{[^}]*overflow:auto/s', $html);

        // Miniaturen, Seitenzaehler, Zoom, Breite, naechstes Feld.
        $this->assertStringContainsString('id="thumb-list"', $html);
        $this->assertStringContainsString('id="seiten-stand"', $html);
        $this->assertStringContainsString('sigZoomIn', $html);
        $this->assertStringContainsString('sigFitWidth', $html);
        $this->assertStringContainsString('sigNaechstesFeld', $html);

        // Die Aktionen stehen in der festen Leiste, nicht hinter 14 Seiten.
        $this->assertStringContainsString('class="sig-actions"', $html);
        foreach (['Als Entwurf speichern', 'Senden', 'Abbrechen'] as $knopf) {
            $this->assertStringContainsString($knopf, $html);
        }
    }

    public function test_die_schmale_ansicht_erzeugt_keine_zweite_spalte(): void
    {
        $request = $this->versandfertig();
        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();

        // Auf 390 px bliebe von einem Dreispalter nichts uebrig: die beiden
        // Leisten werden dort zu Schubladen ueber dem Dokument.
        $this->assertMatchesRegularExpression(
            '/@media \(max-width:1000px\)\{.*?\.sig-body\{grid-template-columns:1fr;\}/s', $html);
        // Und die Blattbreite haengt am Betrachter, nicht an einer festen
        // Pixelzahl - sonst ragt die Seite auf dem Telefon heraus.
        $this->assertStringContainsString('width:calc((100% - 6px) * var(--zoom))', $html);
    }

    public function test_vor_dem_abbrechen_wird_bei_ungespeicherten_aenderungen_gefragt(): void
    {
        $request = $this->versandfertig();
        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.prepare', $request->id))->assertOk()->getContent();

        // Nichts wird still verworfen - und der zweite Knopf sagt, was er tut.
        $this->assertStringContainsString('Ungespeicherte Änderungen vorhanden.', $html);
        $this->assertStringContainsString('Weiter bearbeiten', $html);
        $this->assertStringContainsString('Änderungen verwerfen', $html);
        // Die Pruefung vor dem Versand gehoert dazu.
        $this->assertStringContainsString('Dokument bereit zum Senden', $html);
    }

    public function test_die_liste_zeigt_nur_die_moeglichen_aktionen(): void
    {
        $entwurf = $this->entwurf();
        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Bearbeiten', $html);
        $this->assertStringNotContainsString('>Erinnern<', $html);

        $request = $this->versandfertig();
        app(SignatureRequestService::class)->send($request);
        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.index', ['reiter' => 'gesendet']))->assertOk()->getContent();
        $this->assertStringContainsString('>Erinnern<', $html);
        $this->assertStringNotContainsString(route('admin.signatures.prepare', $request->id), $html);
        $this->assertNotNull($entwurf->fresh());
    }

    public function test_die_statusseite_nennt_lage_fortschritt_und_die_passenden_wege(): void
    {
        $request = $this->versandfertig();
        app(SignatureRequestService::class)->send($request);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.show', $request->id))->assertOk()->getContent();

        $this->assertStringContainsString('Dokument gesendet', $html);
        $this->assertStringContainsString('Warten auf die Unterschrift', $html);
        $this->assertStringContainsString('0 von 1 unterschrieben', $html);
        $this->assertStringContainsString('Erinnerung senden', $html);
        $this->assertStringContainsString('Auftrag stornieren', $html);
        // Ein versendeter Vorgang hat KEINEN Loeschen-Weg.
        $this->assertStringNotContainsString('Entwurf löschen', $html);
    }

    public function test_der_entwurf_zeigt_loeschen_statt_stornieren(): void
    {
        $request = $this->entwurf();

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.signatures.show', $request->id))->assertOk()->getContent();

        $this->assertStringContainsString('Entwurf löschen', $html);
        $this->assertStringNotContainsString('Auftrag stornieren', $html);
    }

    public function test_ein_mitarbeiter_ohne_leitung_loescht_keinen_fremden_entwurf(): void
    {
        $request = $this->entwurf();
        $mitarbeiter = User::factory()->create(['role' => 'employee']);

        $this->actingAs($mitarbeiter)
            ->delete(route('admin.signatures.destroy', $request->id))
            ->assertForbidden();

        $this->assertDatabaseHas('signature_requests', ['id' => $request->id]);
    }

    public function test_nach_dem_versand_aendert_der_editor_die_aufteilung_nicht_mehr(): void
    {
        $request = $this->versandfertig();
        app(SignatureRequestService::class)->send($request);
        $vorher = $request->fields()->count();

        $this->actingAs($this->admin)
            ->postJson(route('admin.signatures.prepare.save', $request->id), $this->nutzlast($request, [1, 2, 3]))
            ->assertStatus(422);

        $this->assertSame($vorher, $request->fields()->count());
        // Auch ueber den Versandweg nicht: der Vorgang ist kein Entwurf mehr.
        $this->assertSame(SignatureSigner::SENT, $request->signers()->firstOrFail()->status);
    }
}
