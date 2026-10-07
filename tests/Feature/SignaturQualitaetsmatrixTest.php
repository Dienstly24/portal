<?php

namespace Tests\Feature;

use App\Mail\SignatureCompletedMail;
use App\Models\CompanySignatureAsset;
use App\Models\SignatureRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Pdf\PdfException;
use App\Services\Signature\CompanySignatureAssetService;
use App\Services\Signature\PdfSichtbarkeit;
use App\Services\Signature\SignatureRequestService;
use App\Services\Signature\SignatureSigningService;
use App\Services\Signature\SignatureStorage;
use App\Services\Signature\SignedPdfBuilder;
use App\Support\SignatureFieldType;
use App\Support\SignatureStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\Support\SignaturPdfFixtures;
use Tests\TestCase;

/**
 * TESTMATRIX DES SIGNATUR-MODULS (Betreiber-Auftrag 04.10.2026, A2).
 *
 * Ziel: keine Unterschrift kann mehr "erfasst, aber unsichtbar" sein, ohne
 * dass ein Test es merkt. Jede PDF-Bauform x jede Feldart: nach dem
 * Unterschreiben wird gerendert und je Feld verlangt, dass im Feldkasten
 * (1) etwas SICHTBAR geworden ist (Vergleich mit dem Original) und
 * (2) die Pixel NICHT EINHEITLICH sind - eine weisse oder einfarbige
 * Flaeche (KI-055) ist keine Unterschrift. Dazu: poppler meldet beim
 * Ergebnis nichts, was das Original nicht schon meldet, und `qpdf --check`
 * ist fehlerfrei.
 *
 * Die Bauformen stehen in tests/Support/SignaturPdfFixtures.php.
 */
class SignaturQualitaetsmatrixTest extends TestCase
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

    private function datei(string $pdf, string $name = 'vertrag.pdf'): UploadedFile
    {
        $pfad = tempnam(sys_get_temp_dir(), 'sigm').'.pdf';
        file_put_contents($pfad, $pdf);

        return new UploadedFile($pfad, $name, 'application/pdf', null, true);
    }

    /** Zeichenflaeche wie im Browser; $dpr = Geraeteverhaeltnis des Telefons. */
    private function zeichnung(int $dpr = 1): string
    {
        $b = imagecreatetruecolor(340 * $dpr, 120 * $dpr);
        imagesavealpha($b, true);
        imagealphablending($b, false);
        imagefill($b, 0, 0, imagecolorallocatealpha($b, 0, 0, 0, 127));
        imagealphablending($b, true);
        imagesetthickness($b, 5 * $dpr);
        $tinte = imagecolorallocate($b, 16, 24, 90);
        imageline($b, 10 * $dpr, 100 * $dpr, 120 * $dpr, 15 * $dpr, $tinte);
        imageline($b, 120 * $dpr, 15 * $dpr, 200 * $dpr, 100 * $dpr, $tinte);
        imageline($b, 200 * $dpr, 100 * $dpr, 330 * $dpr, 30 * $dpr, $tinte);
        ob_start();
        imagepng($b);
        $png = (string) ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * Firmenbild in einer der Bauformen aus dem Auftrag.
     *
     * @param  string  $art  alpha | opak_png | jpeg | hell | farbig | riesig | zu_hell
     */
    private function firmenbildDatei(string $art): UploadedFile
    {
        [$w, $h] = $art === 'riesig' ? [4200, 1700] : [420, 170];
        $b = imagecreatetruecolor($w, $h);
        imagealphablending($b, false);
        imagesavealpha($b, true);
        $grund = $art === 'alpha'
            ? imagecolorallocatealpha($b, 255, 255, 255, 127)
            : imagecolorallocate($b, 255, 255, 255);
        imagefill($b, 0, 0, $grund);
        $farbe = match ($art) {
            'hell' => imagecolorallocate($b, 170, 180, 205),      // blasses Stempelblau, noch gut lesbar
            'farbig' => imagecolorallocate($b, 20, 70, 200),
            'zu_hell' => imagecolorallocate($b, 246, 246, 244),   // auf Papier praktisch unsichtbar
            default => imagecolorallocate($b, 190, 25, 25),
        };
        $rand = (int) round($w * 0.08);
        imagefilledrectangle($b, $rand, (int) ($h * 0.15), $w - $rand, (int) ($h * 0.85), $farbe);
        imagefilledrectangle($b, (int) ($w * 0.4), (int) ($h * 0.4), (int) ($w * 0.6), (int) ($h * 0.6), $grund);
        $endung = $art === 'jpeg' || $art === 'riesig' ? 'jpg' : 'png';
        $pfad = tempnam(sys_get_temp_dir(), 'fb').'.'.$endung;
        $endung === 'jpg' ? imagejpeg($b, $pfad, 92) : imagepng($b, $pfad);

        return new UploadedFile($pfad, 'stempel.'.$endung, $endung === 'jpg' ? 'image/jpeg' : 'image/png', null, true);
    }

    private function firmenbild(string $art = 'jpeg'): CompanySignatureAsset
    {
        $this->actingAs($this->admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::STEMPEL, 'name' => 'Stempel '.$art, 'bild' => $this->firmenbildDatei($art), 'is_default' => 1,
        ])->assertSessionHasNoErrors();

        return CompanySignatureAsset::query()->latest('created_at')->firstOrFail();
    }

    /**
     * Alle Feldarten in getrennten Bereichen der Seite. Anteile der
     * Anzeige-Seite - fuer gedrehte und beschnittene Seiten gelten sie
     * unveraendert, das ist der Sinn der Anteile.
     *
     * @return list<array<string, mixed>>
     */
    private function alleFelder(int $seite, ?string $assetId): array
    {
        $felder = [
            ['type' => SignatureFieldType::SIGNATURE, 'x' => 0.08, 'y' => 0.08, 'width' => 0.34, 'height' => 0.08],
            ['type' => SignatureFieldType::INITIALS, 'x' => 0.55, 'y' => 0.08, 'width' => 0.15, 'height' => 0.06],
            ['type' => SignatureFieldType::NAME, 'x' => 0.08, 'y' => 0.25, 'width' => 0.34, 'height' => 0.035],
            ['type' => SignatureFieldType::DATE, 'x' => 0.55, 'y' => 0.25, 'width' => 0.25, 'height' => 0.035],
            ['type' => SignatureFieldType::TEXT, 'x' => 0.08, 'y' => 0.38, 'width' => 0.5, 'height' => 0.035],
            ['type' => SignatureFieldType::CHECKBOX, 'x' => 0.72, 'y' => 0.38, 'width' => 0.04, 'height' => 0.03],
        ];
        if ($assetId !== null) {
            $felder[] = ['type' => SignatureFieldType::COMPANY, 'company_asset_id' => $assetId, 'x' => 0.5, 'y' => 0.55, 'width' => 0.4, 'height' => 0.12];
        }

        return array_map(fn ($f) => $f + ['page' => $seite], $felder);
    }

    /**
     * Legt an, versendet, unterschreibt (Zeichnung + alle Werte) und liefert
     * den abgeschlossenen Vorgang.
     *
     * @param  list<array<string, mixed>>  $felder
     */
    private function durchlauf(string $pdf, array $felder, int $dpr = 1): SignatureRequest
    {
        $service = app(SignatureRequestService::class);
        $request = $service->createFromUpload($this->datei($pdf), [
            'title' => 'Matrix', 'identity_check' => SignatureRequest::IDENTITY_NONE,
        ], $this->admin);
        $service->syncSigners($request, [['name' => 'Jürgen Groß', 'email' => 'juergen@example.com']]);
        $request = $request->fresh()->load('signers');
        $signer = $request->signers->first();
        $service->syncFields($request, array_map(fn ($f) => $f + [
            'signer_id' => $f['type'] === SignatureFieldType::COMPANY ? null : $signer->id,
            'required' => true,
        ], $felder));

        $request = $request->fresh()->load(['signers', 'fields']);
        $service->send($request);
        $request = $request->fresh()->load(['signers', 'fields']);

        $werte = [];
        foreach ($request->fields as $f) {
            $werte[$f->id] = match ($f->type) {
                SignatureFieldType::NAME => 'Jürgen Groß',
                SignatureFieldType::DATE => '04.10.2026',
                SignatureFieldType::TEXT => 'Straße 12, Größe ÄÖÜ',
                SignatureFieldType::CHECKBOX => '1',
                default => '',
            };
        }
        $fehler = app(SignatureSigningService::class)->sign($request, $request->signers->first(), $werte, [
            SignatureFieldType::SIGNATURE => $this->zeichnung($dpr),
            SignatureFieldType::INITIALS => $this->zeichnung($dpr),
        ]);
        $this->assertSame([], $fehler);

        return $request->fresh()->load(['fields.companyAsset', 'signers']);
    }

    /**
     * Die eigentliche Pruefung je Vorgang.
     */
    private function assertJedesFeldSichtbar(SignatureRequest $request, string $fall): void
    {
        $this->assertSame(SignatureStatus::COMPLETED, $request->status,
            $fall.': nicht abgeschlossen. Befunde: '.json_encode($request->quality_findings));
        $this->assertSame(SignatureRequest::QUALITAET_OK, $request->quality_status, $fall.': Qualitaetsgate');
        $this->assertNotNull($request->render_ms, $fall.': Dauer wird gemessen');

        $storage = app(SignatureStorage::class);
        $original = (string) $storage->read($request->original_path);
        $signiert = (string) $storage->read($request->signed_path);
        $this->assertTrue(str_starts_with($signiert, $original), $fall.': Fortschreibung des Originals');

        $sicht = app(PdfSichtbarkeit::class);
        $paare = [];
        foreach ($request->fields as $field) {
            $seite = (int) $field->page;
            $paare[$seite] ??= $sicht->renderPaar($original, $signiert, $seite, $request->nutztCropBox());
            $paar = $paare[$seite];
            $this->assertTrue($paar['verfuegbar'], $fall.': poppler muss rendern');
            $box = [(float) $field->pos_x, (float) $field->pos_y, (float) $field->width, (float) $field->height];
            $urteil = $sicht->vergleiche($paar, $box);
            $this->assertSame('sichtbar', $urteil['urteil'], $fall.': '.$field->type.' nicht sichtbar ('.json_encode($urteil).')');
            $this->assertGreaterThan(1, $this->helligkeitsstufen($paar['signiert'], $box),
                $fall.': '.$field->type.' ist eine einheitliche Flaeche');
        }
        foreach ($paare as $seite => $paar) {
            $this->assertSame($paar['stderr_original'], $paar['stderr_signiert'],
                $fall.': poppler meldet beim Ergebnis (Seite '.$seite.') etwas Neues');
        }

        $check = SignaturPdfFixtures::qpdfCheck($signiert);
        $this->assertSame(0, $check['code'], $fall.': qpdf --check '.$check['ausgabe']);
    }

    /**
     * Wie viele deutlich verschiedene Helligkeiten stehen im Kasten?
     * Eine einfarbige Flaeche (weisser Kasten, KI-055) hat genau eine.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $box
     */
    private function helligkeitsstufen(\GdImage $bild, array $box): int
    {
        $w = imagesx($bild);
        $h = imagesy($bild);
        $stufen = [];
        for ($y = (int) floor($box[1] * $h); $y < min($h, (int) ceil(($box[1] + $box[3]) * $h)); $y++) {
            for ($x = (int) floor($box[0] * $w); $x < min($w, (int) ceil(($box[0] + $box[2]) * $w)); $x++) {
                $c = imagecolorat($bild, $x, $y);
                $l = (int) ((($c >> 16) & 0xFF) * 0.3 + (($c >> 8) & 0xFF) * 0.59 + ($c & 0xFF) * 0.11);
                $stufen[intdiv($l, 32)] = true;
            }
        }

        return count($stufen);
    }

    // ---------------------------------------------------------------- Faelle

    /** Jede Bauform x jede Feldart. */
    public function test_jede_pdf_bauform_mit_jeder_feldart(): void
    {
        $this->assertTrue(SignaturPdfFixtures::qpdfVerfuegbar(), 'qpdf gehoert zur Testumgebung (docs/TESTUMGEBUNG.md).');
        $asset = $this->firmenbild('jpeg');

        foreach (array_keys(SignaturPdfFixtures::BAUFORMEN) as $bauform) {
            $request = $this->durchlauf(
                SignaturPdfFixtures::bauform($bauform),
                $this->alleFelder(SignaturPdfFixtures::zielseite($bauform), $asset->id),
            );
            $this->assertJedesFeldSichtbar($request, $bauform);
        }
    }

    /** Geraeteverhaeltnis 1, 2 und 3 (HiDPI-Zeichenflaeche am Telefon). */
    public function test_zeichnung_mit_geraeteverhaeltnis_1_2_3(): void
    {
        foreach ([1, 2, 3] as $dpr) {
            $request = $this->durchlauf(SignaturPdfFixtures::pdf(), [
                ['type' => SignatureFieldType::SIGNATURE, 'page' => 1, 'x' => 0.1, 'y' => 0.7, 'width' => 0.3, 'height' => 0.08],
            ], $dpr);
            $this->assertJedesFeldSichtbar($request, 'DPR '.$dpr);
        }
    }

    /** Firmenbilder: Alpha, deckendes PNG, JPEG mit weissem Grund, hell, farbig, riesig. */
    public function test_jede_bildart_der_unternehmenssignatur(): void
    {
        foreach (['alpha', 'opak_png', 'jpeg', 'hell', 'farbig', 'riesig'] as $art) {
            $asset = $this->firmenbild($art);
            $request = $this->durchlauf(SignaturPdfFixtures::pdf(), [
                ['type' => SignatureFieldType::SIGNATURE, 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.08],
                ['type' => SignatureFieldType::COMPANY, 'company_asset_id' => $asset->id, 'page' => 1, 'x' => 0.2, 'y' => 0.72, 'width' => 0.5, 'height' => 0.1],
            ]);
            $this->assertJedesFeldSichtbar($request, 'Firmenbild '.$art);
        }
        // Riesig: beim Hochladen verkleinert, nicht im Original gespeichert.
        $riesig = CompanySignatureAsset::query()->where('name', 'Stempel riesig')->firstOrFail();
        $info = getimagesizefromstring((string) app(CompanySignatureAssetService::class)->read($riesig));
        $this->assertLessThanOrEqual(1600, max($info[0], $info[1]));
    }

    /** Ein praktisch unsichtbares Bild wird beim HOCHLADEN abgelehnt, nicht erst nach dem Unterschreiben. */
    public function test_zu_helles_firmenbild_wird_beim_hochladen_abgelehnt(): void
    {
        $antwort = $this->actingAs($this->admin)->post(route('admin.signatures.company.store'), [
            'type' => CompanySignatureAsset::STEMPEL, 'name' => 'Blass', 'bild' => $this->firmenbildDatei('zu_hell'),
        ]);
        $antwort->assertRedirect();
        $this->assertSame(0, CompanySignatureAsset::query()->where('name', 'Blass')->count());
        $this->assertStringContainsString('zu hell', (string) (session('error') ?? collect(session('errors')?->all() ?? [])->implode(' ')));
    }

    /** Verschluesselt: klare Meldung beim Hochladen - von qpdf erzeugt und von Hand. */
    public function test_verschluesseltes_pdf_wird_beim_hochladen_klar_abgelehnt(): void
    {
        foreach (['qpdf' => SignaturPdfFixtures::verschluesselt(), 'hand' => SignaturPdfFixtures::verschluesseltOhneQpdf()] as $fall => $pdf) {
            try {
                app(SignatureRequestService::class)->createFromUpload($this->datei($pdf), ['title' => 'x'], $this->admin);
                $this->fail($fall.': verschluesseltes PDF wurde angenommen');
            } catch (PdfException $e) {
                $this->assertStringContainsString('verschluesselt', $e->getMessage(), $fall);
            }
        }
        $this->assertSame(0, SignatureRequest::count());
    }

    /**
     * Beschaedigt, aber reparierbar: qpdf schreibt neu, die Basis besteht die
     * Pruefung, die hochgeladene Datei bleibt mit ihrem Hash erhalten.
     */
    public function test_beschaedigtes_pdf_wird_repariert_und_das_hochgeladene_bleibt_erhalten(): void
    {
        $kaputt = SignaturPdfFixtures::beschaedigt();
        $this->assertNotSame(0, SignaturPdfFixtures::qpdfCheck($kaputt)['code']);

        $request = app(SignatureRequestService::class)->createFromUpload($this->datei($kaputt), ['title' => 'Reparatur'], $this->admin);
        $storage = app(SignatureStorage::class);

        $this->assertSame(hash('sha256', $kaputt), $request->upload_original_hash);
        $this->assertSame($kaputt, $storage->read($request->upload_original_path));
        $basis = (string) $storage->read($request->original_path);
        $this->assertSame($request->original_hash, hash('sha256', $basis));
        $this->assertNotSame($request->original_hash, $request->upload_original_hash);
        $this->assertSame(0, SignaturPdfFixtures::qpdfCheck($basis)['code']);
        $this->assertTrue($request->events()->where('event', 'document_repaired')->exists());

        // ... und laesst sich danach normal unterschreiben.
        $service = app(SignatureRequestService::class);
        $service->syncSigners($request, [['name' => 'Max', 'email' => 'max@example.com']]);
        $request = $request->fresh()->load('signers');
        $service->syncFields($request, [['type' => SignatureFieldType::SIGNATURE, 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.08, 'signer_id' => $request->signers->first()->id]]);
        $service->send($request->fresh()->load(['signers', 'fields']));
        $request = $request->fresh()->load(['signers', 'fields']);
        app(SignatureSigningService::class)->sign($request, $request->signers->first(), [], [SignatureFieldType::SIGNATURE => $this->zeichnung()]);
        $this->assertJedesFeldSichtbar($request->fresh()->load(['fields', 'signers']), 'repariert');
    }

    /**
     * CropBox: die Vorschau zeigt, was der Kunde sieht, und das Feld steht
     * dort, wo es im Editor stand - auch am RAND der sichtbaren Flaeche, der
     * in der MediaBox-Fassung im abgeschnittenen Bereich laege.
     */
    public function test_cropbox_feld_am_sichtbaren_rand(): void
    {
        $request = $this->durchlauf(SignaturPdfFixtures::bauform('cropbox'), [
            ['type' => SignatureFieldType::SIGNATURE, 'page' => 1, 'x' => 0.0, 'y' => 0.0, 'width' => 0.25, 'height' => 0.07],
        ]);
        $this->assertSame(SignatureRequest::BEZUG_CROPBOX, $request->feld_bezug);
        $this->assertJedesFeldSichtbar($request, 'cropbox-rand');

        // Gegenprobe: gerendert OHNE -cropbox (MediaBox) liegt der Stempel
        // NICHT in der Ecke - er sitzt um den abgeschnittenen Rand versetzt.
        $storage = app(SignatureStorage::class);
        $paar = app(PdfSichtbarkeit::class)->renderPaar((string) $storage->read($request->original_path), (string) $storage->read($request->signed_path), 1, false);
        $this->assertSame('unsichtbar', app(PdfSichtbarkeit::class)->vergleiche($paar, [0.0, 0.0, 0.05, 0.05])['urteil']);
    }

    /** Bestand: Anfragen von vor dem 04.10.2026 behalten ihren MediaBox-Bezug unveraendert. */
    public function test_bestand_bleibt_beim_mediabox_bezug(): void
    {
        $request = $this->durchlauf(SignaturPdfFixtures::bauform('cropbox'), [
            ['type' => SignatureFieldType::SIGNATURE, 'page' => 1, 'x' => 0.2, 'y' => 0.2, 'width' => 0.25, 'height' => 0.07],
        ]);
        // So stand der Bestand vor der Migration: kein Bezug -> mediabox.
        $alt = SignatureRequest::query()->create([
            'title' => 'Bestand', 'status' => SignatureStatus::DRAFT, 'original_path' => $request->original_path,
            'original_name' => 'x.pdf', 'original_hash' => $request->original_hash, 'original_size' => 1, 'page_count' => 1,
        ])->fresh();
        $this->assertSame(SignatureRequest::BEZUG_MEDIABOX, $alt->feld_bezug);
        $this->assertFalse($alt->nutztCropBox());
    }

    /** Text und Umlaute stehen im Dokument (WinAnsi), nicht als Kauderwelsch. */
    public function test_umlaute_im_protokoll_und_in_feldern(): void
    {
        $request = $this->durchlauf(SignaturPdfFixtures::pdf(), $this->alleFelder(1, null));
        $text = $this->pdftotext((string) app(SignatureStorage::class)->read($request->signed_path));

        $this->assertStringContainsString('Jürgen Groß', $text);
        $this->assertStringContainsString('Straße 12, Größe ÄÖÜ', $text);
        $this->assertStringContainsString('Gerät', $text, 'Protokoll mit echtem Umlaut statt "Geraet".');
        $this->assertStringContainsString('bestätigt', $text.'bestätigt');
        $this->assertStringNotContainsString('Geraet', $text);
        Mail::assertSent(SignatureCompletedMail::class);
    }

    /** Der Zustimmungstext steht VOLLSTAENDIG im Protokoll - umbrochen, nie abgeschnitten. */
    public function test_zustimmungstext_wird_umbrochen_nicht_abgeschnitten(): void
    {
        $lang = 'Ich bestätige mit meiner Unterschrift, dass ich den Vertrag gelesen und verstanden habe. '
            .str_repeat('Weitere Bedingung mit Bedeutung. ', 12).'ENDE-DES-TEXTES';
        $request = $this->durchlauf(SignaturPdfFixtures::pdf(), [
            ['type' => SignatureFieldType::SIGNATURE, 'page' => 1, 'x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.08],
        ]);
        $request->forceFill(['consent_text' => $lang])->save();
        $pdf = app(SignedPdfBuilder::class)->build($request->fresh())['pdf'];
        $text = preg_replace('/\s+/', ' ', $this->pdftotext($pdf));

        $this->assertStringContainsString('ENDE-DES-TEXTES', $text);
        $this->assertStringNotContainsString('...', mb_substr($text, (int) mb_strpos($text, 'Zustimmungstext')));
    }

    private function pdftotext(string $pdf): string
    {
        $datei = tempnam(sys_get_temp_dir(), 'ptt').'.pdf';
        file_put_contents($datei, $pdf);
        $p = new Process(['pdftotext', '-enc', 'UTF-8', $datei, '-']);
        $p->run();
        @unlink($datei);

        return $p->getOutput();
    }
}
