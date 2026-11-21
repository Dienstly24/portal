<?php

namespace Tests\Feature\DocumentIntake;

use App\Jobs\AnalyzeDocumentJob;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ai\DocumentAnalyzer;
use App\Services\Ai\HeuristicDocumentClassifier;
use App\Services\Ai\RelevantPageSelector;
use App\Services\Ai\TemplateParsers\Check24KfzProtocolParser;
use App\Services\DocumentIntake\DocumentIntakeService;
use App\Services\Matching\KundenImTextFinder;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Betreiber-Meldung 21.11.2026 (KI-122): eine Familie schickte ihre
 * Unterlagen (Karten, Ausweise, Arbeitsvertrag, Heiratsurkunde). Alles lief
 * ueber die kostenlose OCR. Ein Scan trug IBAN UND vollen Namen eines
 * Bestandskunden - trotzdem "Kein Kunde gefunden", und auch die Dokumente
 * desselben Vorgangs boten den Kunden nicht an, obwohl ein Geschwister-
 * Dokument ihm schon zugeordnet war.
 */
class KundeImDokumenttextTest extends TestCase
{
    use RefreshDatabase;

    /** Aufbau wie der gemessene OCR-Text des Bankkarten-Scans (Daten ersetzt). */
    private const OCR_BANKKARTE = "DE89370400440532013000 1:69 Karim Benali Haddad ati";

    private function kunde(string $name, array $extra = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => Str::slug($name).'@example.com']);

        return Customer::create(['user_id' => $user->id, 'customer_number' => (string) random_int(2600000, 2699999)] + $extra);
    }

    private function analyzerMitOcr(string $text): DocumentAnalyzer
    {
        $ocr = new class($text) implements TextExtractorInterface {
            public function __construct(private string $text) {}
            public function isAvailable(): bool { return true; }
            public function extract(string $binary, string $mime): string { return $this->text; }
        };
        $keineTextebene = new class extends PdfTextLayerExtractor {
            public function __construct() {}
            public function isAvailable(): bool { return false; }
            public function extract(string $binary): string { return ''; }
        };
        $keineKi = new class implements DocumentAiProviderInterface {
            public function isEnabled(): bool { return false; }
            public function model(): string { return 'keins'; }
            public function analyze(string $binary, string $mime, string $ocrText, bool $preferText = false): ?array { return null; }
        };

        return new DocumentAnalyzer($keineKi, $ocr, $keineTextebene, new RelevantPageSelector, new Check24KfzProtocolParser);
    }

    private function eingangsDokument(?string $batch = null, string $status = 'pending', ?string $kundeId = null): Document
    {
        $path = 'documents/eingang/'.uniqid().'.png';
        Storage::disk('local')->put($path, 'bild');

        return Document::create([
            'customer_id' => $kundeId,
            'intake_batch' => $batch,
            'category' => 'other',
            'file_name' => 'Scan.png',
            'file_path' => $path,
            'disk' => 'local',
            'visibility' => 'internal',
            'ai_status' => $status,
        ]);
    }

    public function test_bestandskunde_wird_im_ocr_text_der_bankkarte_gefunden(): void
    {
        Storage::fake('local');
        $kunde = $this->kunde('Karim Benali Haddad');
        $this->kunde('Samir Tarek Mansour');
        $doc = $this->eingangsDokument();

        (new AnalyzeDocumentJob($doc->id))->handle($this->analyzerMitOcr(self::OCR_BANKKARTE), app(DocumentIntakeService::class));

        $doc->refresh();
        $match = $doc->ai_extracted['match'] ?? null;
        $this->assertNotNull($match, 'Vorher: "Kein Kunde gefunden", obwohl Name und IBAN im Text stehen.');
        $this->assertSame((string) $kunde->id, $match['customer_id']);
        $this->assertSame('confirm', $match['tier'], 'Ein Name im Text ist ein Vorschlag, nie eine automatische Zuordnung.');
        $this->assertNull($doc->customer_id);
        // Rohtext wird nie gespeichert - nur Kunden-ID und Grund (ohne Namen).
        $this->assertStringNotContainsString('Benali', json_encode($doc->ai_extracted['kunden_im_text']));
    }

    public function test_iban_mitten_in_der_zeile_wird_gelesen_und_fuehrt_zum_kunden(): void
    {
        $ergebnis = (new HeuristicDocumentClassifier)->classify(self::OCR_BANKKARTE);
        $this->assertSame('DE89370400440532013000', $ergebnis['data']['bank']['iban'] ?? null);

        $kunde = $this->kunde('Anderer Name Hier', ['iban' => 'DE89 3704 0044 0532 0130 00']);
        $treffer = app(KundenImTextFinder::class)->finde('nur rauschen', 'DE89370400440532013000');
        $this->assertSame([(string) $kunde->id], array_column($treffer, 'customer_id'));
        $this->assertSame('iban', $treffer[0]['art']);
    }

    public function test_karte_mit_nachname_vorn_wird_erkannt(): void
    {
        $kunde = $this->kunde('Karim Benali Haddad');
        $treffer = app(KundenImTextFinder::class)->finde("AUFENTHALTSTITEL\nHADDAD, KARIM BENALI\n12.03.1990");

        $this->assertSame([(string) $kunde->id], array_column($treffer, 'customer_id'));
    }

    public function test_verstreute_namensteile_und_kurze_namen_loesen_nicht_aus(): void
    {
        $this->kunde('Mohamad Ali');
        $this->kunde('Ali Omar');
        $finder = app(KundenImTextFinder::class);

        // "Mohamad" und "Ali" stehen im Text, aber nicht hintereinander.
        $this->assertSame([], $finder->finde("Mohamad Schmidt wohnt in Berlin.\nAli ist sein Nachbar."));
        // "Ali Omar" hat zusammen nur 7 Buchstaben - zu wenig fuer einen Beleg.
        $this->assertSame([], $finder->finde('Herr Ali Omar'));
    }

    public function test_zwei_kunden_im_text_ergeben_keinen_match_aber_beide_vorschlaege(): void
    {
        $a = $this->kunde('Karim Benali Haddad');
        $b = $this->kunde('Samir Tarek Mansour');
        $hinweise = app(KundenImTextFinder::class)->finde('Karim Benali Haddad und Samir Tarek Mansour');

        $intake = app(DocumentIntakeService::class);
        $this->assertNull($intake->matchAusTextHinweisen($hinweise), 'Mehrdeutig -> nicht raten.');

        $vorschlaege = $intake->findSuggestions(['kunden_im_text' => $hinweise]);
        $this->assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], array_column($vorschlaege, 'customer_id'));
    }

    public function test_vorgang_schlaegt_den_kunden_eines_bereits_zugeordneten_geschwisters_vor(): void
    {
        Storage::fake('local');
        $kunde = $this->kunde('Karim Benali Haddad');
        $batch = (string) Str::uuid();
        $this->eingangsDokument($batch, 'done', (string) $kunde->id);
        $offen = $this->eingangsDokument($batch, 'done');
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.documents.customer_suggestions', $offen->id))
            ->assertOk();

        $ids = array_column($response->json('suggestions'), 'customer_id');
        $this->assertContains((string) $kunde->id, $ids, 'Vorher: der Vorgang kannte seinen Kunden nicht.');
    }
}
