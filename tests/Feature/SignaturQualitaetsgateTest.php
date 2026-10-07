<?php

namespace Tests\Feature;

use App\Mail\SignatureInvitationMail;
use App\Mail\SignaturQualitaetMail;
use App\Models\CompanySignatureAsset;
use App\Models\InternalNotification;
use App\Models\SignatureRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Signature\SignatureDiagnostics;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureSigningService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignatureTokenService;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Support\SignaturPdfFixtures;
use Tests\TestCase;

/**
 * DAS QUALITAETSGATE (Betreiber-Auftrag 04.10.2026, A1/A5): keine
 * Unterschrift kann mehr "erfasst, aber unsichtbar" sein, ohne dass jemand
 * davon weiss.
 *
 *  - beim Versand: ein unsichtbarer Firmenstempel stoppt den Versand
 *  - jede Nacht: ein fertiges Dokument, das (nicht mehr) zeigt, was
 *    unterschrieben wurde, landet in der Liste, die Leitung wird EINMAL
 *    benachrichtigt und bekommt EINE Zusammenfassung per Mail
 *  - die Liste sieht nur der Admin, "Neu erzeugen" behebt
 *  - die Diagnose wertet offene Vorgaenge nicht mehr nach der Bildanalyse
 *    des alten Stemplers (Befund vom Server 04.10.2026)
 */
class SignaturQualitaetsgateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        SystemSetting::set('company_name', 'Dienstly24 GmbH');
    }

    private function datei(string $pdf): UploadedFile
    {
        $pfad = tempnam(sys_get_temp_dir(), 'sigq').'.pdf';
        file_put_contents($pfad, $pdf);

        return new UploadedFile($pfad, 'vertrag.pdf', 'application/pdf', null, true);
    }

    private function stempel(): CompanySignatureAsset
    {
        $b = imagecreatetruecolor(400, 160);
        imagefill($b, 0, 0, imagecolorallocate($b, 255, 255, 255));
        imagefilledrectangle($b, 40, 20, 360, 140, imagecolorallocate($b, 200, 20, 20));
        $pfad = tempnam(sys_get_temp_dir(), 'st').'.jpg';
        imagejpeg($b, $pfad, 95);
        $this->actingAs($this->admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::STEMPEL, 'name' => 'Stempel',
            'bild' => new UploadedFile($pfad, 'st.jpg', 'image/jpeg', null, true), 'is_default' => 1,
        ])->assertSessionHasNoErrors();

        return CompanySignatureAsset::firstOrFail();
    }

    /** Ein Stempel, dessen Datei nachtraeglich unsichtbar geworden ist (vollstaendig durchsichtig). */
    private function stempelUnsichtbarMachen(CompanySignatureAsset $asset): void
    {
        $b = imagecreatetruecolor(400, 160);
        imagealphablending($b, false);
        imagesavealpha($b, true);
        imagefill($b, 0, 0, imagecolorallocatealpha($b, 255, 255, 255, 127));
        ob_start();
        imagepng($b);
        app(SignatureStorage::class)->disk()->put($asset->path, (string) ob_get_clean());
    }

    private function vorgang(?CompanySignatureAsset $asset): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->datei(SignaturPdfFixtures::pdf()), ['title' => 'Gate'], $this->admin);
        $service->syncSigners($request, [['name' => 'Max', 'email' => 'max@example.com']]);
        $request = $request->fresh()->load('signers');
        $felder = [['type' => SignatureFieldType::SIGNATURE, 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.08, 'signer_id' => $request->signers->first()->id]];
        if ($asset !== null) {
            $felder[] = ['type' => SignatureFieldType::COMPANY, 'company_asset_id' => $asset->id, 'page' => 1, 'x' => 0.2, 'y' => 0.7, 'width' => 0.5, 'height' => 0.1];
        }
        $service->syncFields($request, $felder);

        return $request->fresh()->load(['signers', 'fields']);
    }

    private function zeichnung(): string
    {
        $b = imagecreatetruecolor(340, 120);
        imagesavealpha($b, true);
        imagealphablending($b, false);
        imagefill($b, 0, 0, imagecolorallocatealpha($b, 0, 0, 0, 127));
        imagealphablending($b, true);
        imagesetthickness($b, 6);
        imageline($b, 10, 100, 330, 15, imagecolorallocate($b, 10, 10, 60));
        ob_start();
        imagepng($b);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function abgeschlossen(?CompanySignatureAsset $asset = null): SignatureRequest
    {
        $request = $this->vorgang($asset);
        app(SignatureRequestService::class)->send($request);
        $request = $request->fresh()->load(['signers', 'fields']);
        app(SignatureSigningService::class)->sign($request, $request->signers->first(), [], [SignatureFieldType::SIGNATURE => $this->zeichnung()]);

        return $request->fresh();
    }

    // ---------------------------------------------------------------- Faelle

    public function test_unsichtbarer_firmenstempel_stoppt_den_versand(): void
    {
        $asset = $this->stempel();
        $this->stempelUnsichtbarMachen($asset);
        $request = $this->vorgang($asset);

        $antwort = $this->actingAs($this->admin)->postJson(route('admin.signatures.send', $request->id));

        $antwort->assertStatus(422);
        $this->assertStringContainsString('Unternehmenssignatur', (string) $antwort->json('message'));
        $request->refresh();
        $this->assertSame(SignatureStatus::DRAFT, $request->status, 'Nicht versendet.');
        $this->assertSame(SignatureRequest::QUALITAET_FEHLER, $request->quality_status);
        Mail::assertNotSent(SignatureInvitationMail::class);
    }

    public function test_sichtbarer_firmenstempel_wird_versendet_und_vermerkt(): void
    {
        $request = $this->vorgang($this->stempel());
        app(SignatureRequestService::class)->send($request);

        $request->refresh();
        $this->assertSame(SignatureStatus::SENT, $request->status);
        $this->assertSame(SignatureRequest::QUALITAET_OK, $request->quality_status);
        $this->assertNotNull($request->render_ms);
    }

    public function test_nachtlauf_findet_ein_dokument_das_die_unterschrift_nicht_zeigt(): void
    {
        $request = $this->abgeschlossen();
        $this->assertSame(SignatureRequest::QUALITAET_OK, $request->quality_status);

        // Das gespeicherte Dokument wird durch eines OHNE Stempel ersetzt
        // (Hash nachgezogen) - genau der Zustand der 11 Vorgaenge vom
        // 03.10.2026, die als "Abgeschlossen" galten.
        $storage = app(SignatureStorage::class);
        $ohne = (string) $storage->read($request->original_path)."\n% leerer Anhang\n";
        $storage->disk()->put($request->signed_path, $ohne);
        $request->forceFill(['signed_hash' => hash('sha256', $ohne)])->save();

        $this->artisan('signaturen:qualitaet-pruefen')->assertExitCode(0);

        $request->refresh();
        $this->assertSame(SignatureRequest::QUALITAET_FEHLER, $request->quality_status);
        $this->assertStringContainsString('nicht sichtbar', implode(' ', $request->quality_findings));
        $this->assertSame(SignatureStatus::COMPLETED, $request->status, 'Der Nachtlauf aendert nie den Status.');
        $this->assertTrue($request->events()->where('event', 'quality_failed')->exists());
        $this->assertSame(1, InternalNotification::where('user_id', $this->admin->id)->where('dedup_key', 'signatur-qualitaet:'.$request->id)->count());
        Mail::assertSent(SignaturQualitaetMail::class, fn ($m) => $m->gesamt === 1 && $m->hasTo($this->admin->email));

        // Zweiter Lauf: derselbe Befund laeutet NICHT erneut.
        InternalNotification::query()->update(['read_at' => now()]);
        $this->artisan('signaturen:qualitaet-pruefen', ['--ohne-mail' => true])->assertExitCode(0);
        $this->assertSame(1, InternalNotification::where('user_id', $this->admin->id)->where('dedup_key', 'signatur-qualitaet:'.$request->id)->count());
        $this->assertSame(1, $request->events()->where('event', 'quality_failed')->count());
    }

    public function test_ohne_befund_keine_mail(): void
    {
        $this->abgeschlossen($this->stempel());
        $this->artisan('signaturen:qualitaet-pruefen')->assertExitCode(0);

        Mail::assertNotSent(SignaturQualitaetMail::class);
        $this->assertSame(SignatureRequest::QUALITAET_OK, SignatureRequest::firstOrFail()->quality_status);
    }

    public function test_liste_nur_fuer_admin_und_neu_erzeugen_behebt(): void
    {
        $request = $this->abgeschlossen();
        $storage = app(SignatureStorage::class);
        $ohne = (string) $storage->read($request->original_path)."\n% leer\n";
        $storage->disk()->put($request->signed_path, $ohne);
        $request->forceFill(['signed_hash' => hash('sha256', $ohne)])->save();
        $this->artisan('signaturen:qualitaet-pruefen', ['--ohne-mail' => true]);

        // Falsche Rolle: die Rollen-Middleware des Projekts leitet in den
        // eigenen Bereich um - und es passiert NICHTS.
        $pfadVorher = $request->fresh()->signed_path;
        foreach (['manager', 'employee', 'support'] as $rolle) {
            $nutzer = User::factory()->create(['role' => $rolle]);
            $this->actingAs($nutzer)->get(route('admin.signatures.quality'))->assertRedirect(route('admin.dashboard'));
            $this->actingAs($nutzer)->post(route('admin.signatures.quality.regenerate', $request->id))->assertRedirect(route('admin.dashboard'));
            $this->actingAs($nutzer)->post(route('admin.signatures.quality.check', $request->id))->assertRedirect(route('admin.dashboard'));
        }
        $this->assertSame($pfadVorher, $request->fresh()->signed_path, 'Ohne Admin-Rolle wurde nichts neu erzeugt.');

        $this->actingAs($this->admin)->get(route('admin.signatures.quality'))
            ->assertOk()->assertSee('Gate')->assertSee('Neu erzeugen');

        $alterPfad = $request->fresh()->signed_path;
        $this->actingAs($this->admin)->post(route('admin.signatures.quality.regenerate', $request->id))->assertRedirect();

        $request->refresh();
        $this->assertSame(SignatureRequest::QUALITAET_OK, $request->quality_status);
        $this->assertNotSame($alterPfad, $request->signed_path);
        $this->assertTrue($storage->disk()->exists($alterPfad), 'Das alte PDF bleibt erhalten.');
        $this->assertTrue($request->events()->where('event', 'quality_passed')->exists());
        $this->actingAs($this->admin)->get(route('admin.signatures.quality'))->assertSee('Kein Vorgang mit Befund');
    }

    public function test_jetzt_pruefen_von_hand(): void
    {
        $request = $this->abgeschlossen();
        $this->actingAs($this->admin)->post(route('admin.signatures.quality.check', $request->id))
            ->assertRedirect()->assertSessionHas('success');
    }

    /**
     * Befund vom Server (04.10.2026): offene Vorgaenge mit deckendem
     * Firmenbild standen als "betroffen" da - die Diagnose wertete die
     * Bildanalyse des ALTEN Stemplers. Jetzt wird im Speicher gestempelt
     * und am Bild geprueft: sichtbar -> nur ein Risiko, keine Ursache.
     */
    public function test_diagnose_offener_vorgang_mit_deckendem_logo_ist_nicht_betroffen(): void
    {
        $request = $this->vorgang($this->stempel());
        app(SignatureRequestService::class)->send($request);

        $bericht = app(SignatureDiagnostics::class)->diagnose($request->fresh(), true, false);

        $this->assertTrue($bericht['ergebnis_simuliert']);
        $this->assertSame(0, $bericht['zusammenfassung']['unsichtbar']);
        $this->assertSame([], $bericht['ursachen']);
        $firma = collect($bericht['felder'])->firstWhere('art', SignatureFieldType::COMPANY);
        $this->assertSame('sichtbar', $firma['sichtbarkeit']['urteil']);
        $this->assertContains(SignatureDiagnostics::FIRMENBILD_OPAK, $firma['risiken']);
    }

    /**
     * A4: nach dem Abschluss ist der Link kein 30-Tage-Zugang zu einem
     * unterschriebenen Vertrag mehr - er liest das fertige Dokument noch
     * 7 Tage, danach ist er tot.
     */
    public function test_link_nach_dem_abschluss_nur_noch_kurz_lesend(): void
    {
        $request = $this->vorgang(null);
        app(SignatureRequestService::class)->send($request);
        $request = $request->fresh()->load(['signers', 'fields']);
        $signer = $request->signers->first();
        $token = app(SignatureTokenService::class)->issue($signer, now()->addDays(30));
        app(SignatureSigningService::class)->sign($request->fresh()->load(['signers', 'fields']), $signer->fresh(), [], [SignatureFieldType::SIGNATURE => $this->zeichnung()]);

        $signer->refresh();
        $this->assertTrue($signer->token_expires_at->lte(now()->addDays(SignatureSigningService::NACHLAUF_TAGE)->addMinute()));
        $this->get(route('signature.document', $token))->assertOk();

        $this->travel(SignatureSigningService::NACHLAUF_TAGE + 1)->days();
        $this->get(route('signature.document', $token))->assertStatus(410);
    }
}
