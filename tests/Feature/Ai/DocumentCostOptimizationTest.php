<?php

namespace Tests\Feature\Ai;

use App\Models\Document;
use App\Services\Ai\ClaudeDocumentAiProvider;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ai\DocumentAnalyzer;
use App\Services\Ai\RelevantPageSelector;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kostenoptimierung des Smart Document Upload:
 * - Digitale PDFs werden per Textebene (pdftotext) GRATIS gelesen.
 * - Lange, mehrseitige Dokumente werden nicht von der einfachen Heuristik
 *   "akzeptiert" (Falschtreffer), sondern zur KI eskaliert - aber auf dem
 *   billigen Textweg (Text statt Bild-Seiten).
 */
class DocumentCostOptimizationTest extends TestCase
{
    use RefreshDatabase;

    /** Fake der Textebene-Stufe (unabhaengig von poppler auf dem Runner). */
    private function fakePdfText(string $ret, bool $available = true): PdfTextLayerExtractor
    {
        return new class($ret, $available) extends PdfTextLayerExtractor {
            public function __construct(private string $ret, private bool $available) {}
            public function isAvailable(): bool { return $this->available; }
            public function extract(string $binary): string { return $this->ret; }
        };
    }

    private function fakeOcr(bool $available = false, string $text = ''): TextExtractorInterface
    {
        return new class($available, $text) implements TextExtractorInterface {
            public function __construct(private bool $available, private string $text) {}
            public function isAvailable(): bool { return $this->available; }
            public function extract(string $binary, string $mime): string { return $this->available ? $this->text : ''; }
        };
    }

    /** KI-Anbieter, der die Aufruf-Argumente mitschreibt. */
    private function recordingProvider(?array $return): DocumentAiProviderInterface
    {
        return new class($return) implements DocumentAiProviderInterface {
            public bool $called = false;
            public bool $preferText = false;
            public string $ocrText = '';
            public function __construct(private ?array $return) {}
            public function isEnabled(): bool { return true; }
            public function model(): string { return 'fake'; }
            public function analyze(string $binary, string $mime, string $ocrText, bool $preferText = false): ?array
            {
                $this->called = true;
                $this->preferText = $preferText;
                $this->ocrText = $ocrText;
                return $this->return;
            }
        };
    }

    private function pdfDocument(): Document
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/probe.pdf', "%PDF-1.4\n% Testinhalt (Extraktor ist gefaked)\n");

        return Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'probe.pdf',
            'file_path' => 'docs/probe.pdf',
            'disk' => 'local',
            'visibility' => 'staff',
            'ai_status' => 'pending',
        ]);
    }

    private function aiPayload(string $type = 'beratungsprotokoll'): array
    {
        return ['type' => $type, 'confidence' => 92, 'summary' => 'ok', 'title' => null, 'data' => []];
    }

    public function test_long_text_layer_escalates_to_ai_on_cheap_text_path(): void
    {
        // Text WAERE heuristisch verwertbar (SEPA + IBAN), ist aber zu lang
        // fuer die einfache Heuristik -> Eskalation, nicht Akzeptanz.
        $text = "SEPA-LASTSCHRIFTMANDAT\nIBAN DE89370400440532013000\n"
            . str_repeat("Blindtext zur Laenge dieser Seite. ", 120);
        $this->assertGreaterThan(2500, mb_strlen($text));

        $provider = $this->recordingProvider($this->aiPayload());
        $analyzer = new DocumentAnalyzer($provider, $this->fakeOcr(), $this->fakePdfText($text), new RelevantPageSelector());

        $result = $analyzer->analyze($this->pdfDocument());

        $this->assertTrue($provider->called);
        $this->assertTrue($provider->preferText, 'Textebene -> KI soll den billigen Textweg nutzen');
        $this->assertSame($text, $provider->ocrText);
        $this->assertSame('ai', $result['source']);
    }

    public function test_short_recognizable_text_layer_is_accepted_for_free(): void
    {
        $text = "SEPA-LASTSCHRIFTMANDAT\nIBAN DE89370400440532013000\nKontoinhaber Max Mustermann";

        $provider = $this->recordingProvider($this->aiPayload());
        $analyzer = new DocumentAnalyzer($provider, $this->fakeOcr(), $this->fakePdfText($text), new RelevantPageSelector());

        $result = $analyzer->analyze($this->pdfDocument());

        $this->assertFalse($provider->called, 'kurzes, erkanntes Dokument darf keine KI kosten');
        $this->assertSame('ocr', $result['source']);
        $this->assertSame('sepa_mandat', $result['type']);
        $this->assertSame('DE89370400440532013000', $result['data']['bank']['iban']);
    }

    public function test_without_text_layer_falls_back_to_vision(): void
    {
        // Keine Textebene (Scan) und kein OCR -> KI mit Bild/PDF (preferText false).
        $provider = $this->recordingProvider($this->aiPayload('gesundheitskarte'));
        $analyzer = new DocumentAnalyzer($provider, $this->fakeOcr(available: false), $this->fakePdfText(''), new RelevantPageSelector());

        $result = $analyzer->analyze($this->pdfDocument());

        $this->assertTrue($provider->called);
        $this->assertFalse($provider->preferText, 'ohne Textebene bleibt es bei der Bild-Analyse');
        $this->assertSame('ai', $result['source']);
    }

    public function test_claude_provider_sends_text_only_when_prefer_text(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($this->aiPayload('sepa_mandat'))]],
            ]),
        ]);

        (new ClaudeDocumentAiProvider())->analyze('PDF-BYTES', 'application/pdf', 'Sauberer Text mit IBAN DE89370400440532013000', true);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'];
            foreach ($content as $block) {
                if (($block['type'] ?? '') !== 'text') {
                    return false; // KEIN teures document-/image-Block
                }
            }
            return str_contains($content[0]['text'] ?? '', 'DOKUMENTTEXT');
        });
    }

    public function test_claude_provider_sends_document_block_without_prefer_text(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($this->aiPayload('sepa_mandat'))]],
            ]),
        ]);

        (new ClaudeDocumentAiProvider())->analyze('PDF-BYTES', 'application/pdf', 'egal', false);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'document') {
                    return true;
                }
            }
            return false;
        });
    }

    public function test_real_pdftotext_handles_input_gracefully(): void
    {
        // Textebene-Stufe explizit aktivieren (Default folgt OCR_ENABLED, das
        // in Tests aus ist); ohne poppler auf dem Runner wird uebersprungen.
        config(['services.ocr.text_layer' => true]);

        $extractor = new PdfTextLayerExtractor();
        if (!$extractor->isAvailable()) {
            $this->markTestSkipped('pdftotext (poppler-utils) auf diesem Runner nicht verfuegbar.');
        }

        // Realer pdftotext-Aufruf auf nicht-PDF/leerem Input: liefert '',
        // wirft nie (das Kernversprechen des Extraktors). Der Positiv-Pfad
        // auf echten digitalen PDFs ist am echten CHECK24-Protokoll
        // verifiziert; ein handgebautes Mini-PDF ist ueber poppler-Versionen
        // hinweg nicht stabil und taugt nicht als Fixture.
        $this->assertSame('', $extractor->extract('kein pdf'));
        $this->assertSame('', $extractor->extract(''));
    }

    public function test_request_uses_generous_max_tokens_for_full_schema(): void
    {
        // Zu knappe max_tokens schneiden das (mit personen/energie erweiterte)
        // JSON ab -> ungueltig -> "Keine verwertbare Analyse-Antwort".
        config(['services.anthropic.key' => 'test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($this->aiPayload('kfz_vertrag'))]],
            ]),
        ]);

        (new ClaudeDocumentAiProvider())->analyze('BYTES', 'application/pdf', 'Ein langer Dokumenttext', true);

        Http::assertSent(fn ($request) => (int) ($request->data()['max_tokens'] ?? 0) >= 4096);
    }

    public function test_truncated_response_returns_null_without_crash(): void
    {
        // Abgeschnittene Antwort (kein gueltiges JSON) -> null, keine Exception.
        config(['services.anthropic.key' => 'test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"type":"kfz_vertrag","confidence":90,"data":{"person":{"first_name":"Ahmed"']],
                'stop_reason' => 'max_tokens',
            ]),
        ]);

        $this->assertNull((new ClaudeDocumentAiProvider())->analyze('BYTES', 'application/pdf', 'text', true));
    }

    public function test_unknown_type_falls_back_to_sonstiges_without_losing_data(): void
    {
        // Eine kleine Format-Abweichung (Typ nicht in Whitelist) darf nicht
        // das ganze Ergebnis verwerfen - Felder bleiben, Typ wird 'sonstiges'.
        config(['services.anthropic.key' => 'test-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'type' => 'kfz-versicherung-antrag',   // nicht in AI_TYPES
                    // confidence fehlt -> Default 50
                    'summary' => 'Kfz-Antrag',
                    'data' => ['person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar']],
                ])]],
            ]),
        ]);

        $result = (new ClaudeDocumentAiProvider())->analyze('BYTES', 'application/pdf', 'text', true);

        $this->assertNotNull($result);
        $this->assertSame('sonstiges', $result['type']);
        $this->assertSame(50, $result['confidence']);
        $this->assertSame('Ahmed', $result['data']['person']['first_name']);
    }

    public function test_detects_garbled_text_layer(): void
    {
        $extractor = new PdfTextLayerExtractor();

        // Echte deutsche Textebene (Auszug aus einem Energie-Auftrag).
        $ok = str_repeat('Auftrag fuer Gas der EWE VERTRIEB GmbH, Name und Datum, Betrag in Euro pro Monat, Vertragsnummer und Kundennummer. ', 6);
        $this->assertFalse($extractor->isLikelyGarbled($ok));

        // Kaputt kodierte Textebene (Font-Encoding verschoben, wie enviaM).
        $garbled = str_repeat('$XIWUDJ 0(,1 67520 EHVW 6FKRHQ GDVV 6LH VLFK IXHU 6WURP YRQ HQYLD0 ', 10);
        $this->assertTrue($extractor->isLikelyGarbled($garbled));

        // Kurzer Text wird nicht bewertet (zu wenig Signal).
        $this->assertFalse($extractor->isLikelyGarbled('xyz qrst'));
    }
}
