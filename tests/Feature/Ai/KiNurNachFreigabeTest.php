<?php

namespace Tests\Feature\Ai;

use App\Jobs\AnalyzeDocumentJob;
use App\Models\Document;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Ai\AiEmailClassifier;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ai\DocumentAnalyzer;
use App\Services\Ai\RelevantPageSelector;
use App\Services\Ai\TemplateParsers\Check24KfzProtocolParser;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use App\Services\Workflow\EmailWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Betreiber-Vorgabe 20.11.2026: "das System soll mich ZUERST fragen, ob ein
 * Dokument mit KI analysiert wird - die Kosten sollen sehr stark sinken".
 * Vorher eskalierte jedes Dokument, das die kostenlose Stufe nicht
 * vollstaendig las, still zum bezahlten KI-Aufruf.
 */
class KiNurNachFreigabeTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): DocumentAiProviderInterface
    {
        return new class implements DocumentAiProviderInterface {
            public int $aufrufe = 0;
            public function isEnabled(): bool { return true; }
            public function model(): string { return 'fake'; }
            public function analyze(string $binary, string $mime, string $ocrText, bool $preferText = false): ?array
            {
                $this->aufrufe++;
                return ['type' => 'beratungsprotokoll', 'confidence' => 90, 'summary' => 'KI', 'title' => null, 'data' => []];
            }
        };
    }

    private function textebene(string $text): PdfTextLayerExtractor
    {
        return new class($text) extends PdfTextLayerExtractor {
            public function __construct(private string $text) {}
            public function isAvailable(): bool { return true; }
            public function extract(string $binary): string { return $this->text; }
        };
    }

    private function keinOcr(): TextExtractorInterface
    {
        return new class implements TextExtractorInterface {
            public function isAvailable(): bool { return false; }
            public function extract(string $binary, string $mime): string { return ''; }
        };
    }

    private function dokument(): Document
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/p.pdf', "%PDF-1.4\n");

        return Document::create([
            'category' => 'other', 'file_name' => 'p.pdf', 'file_path' => 'docs/p.pdf',
            'disk' => 'local', 'visibility' => 'internal', 'ai_status' => 'pending',
        ]);
    }

    /** Zu lang fuer die einfache Heuristik -> frueher: automatische Eskalation. */
    private function langerText(): string
    {
        return "SEPA-LASTSCHRIFTMANDAT\nIBAN DE89370400440532013000\n".str_repeat('Blindtext zur Laenge dieser Seite. ', 120);
    }

    private function analyzer(DocumentAiProviderInterface $provider, string $text): DocumentAnalyzer
    {
        return new DocumentAnalyzer($provider, $this->keinOcr(), $this->textebene($text), new RelevantPageSelector, new Check24KfzProtocolParser);
    }

    public function test_voreinstellung_ruft_die_ki_nicht_selbst_auf(): void
    {
        $provider = $this->provider();

        $result = $this->analyzer($provider, $this->langerText())->analyze($this->dokument());

        $this->assertSame(0, $provider->aufrufe, 'Ohne Freigabe darf kein bezahlter Aufruf entstehen.');
        $this->assertTrue($result['ki_empfohlen']);
        $this->assertSame('ocr', $result['source']);
        $this->assertStringContainsString('wartet auf Freigabe', $result['summary']);
    }

    public function test_auch_ohne_kostenloses_ergebnis_kein_aufruf(): void
    {
        $provider = $this->provider();

        $result = $this->analyzer($provider, '')->analyze($this->dokument());

        $this->assertSame(0, $provider->aufrufe);
        $this->assertSame('sonstiges', $result['type']);
        $this->assertTrue($result['ki_empfohlen']);
    }

    public function test_vorlage_ohne_vertragskern_eskaliert_nicht_ungefragt(): void
    {
        // CHECK24-Protokoll OHNE Versicherer: frueher automatisch zur KI.
        $text = implode("\n", [
            'CHECK24 Vergleichsportal fuer Versicherungen GmbH',
            'Beratungsprotokoll Kfz-Versicherung',
            'Versicherungsnehmer Max Mustermann',
            'Fahrzeug VW Golf',
        ]);
        $provider = $this->provider();

        $result = $this->analyzer($provider, $text)->analyze($this->dokument());

        $this->assertSame(0, $provider->aufrufe);
        if ($result !== null && ($result['source'] ?? null) === 'template') {
            $this->assertTrue($result['ki_empfohlen']);
        }
    }

    public function test_freigabe_per_knopf_ruft_die_ki_und_loescht_den_vermerk(): void
    {
        $provider = $this->provider();
        $this->app->instance(DocumentAnalyzer::class, $this->analyzer($provider, $this->langerText()));
        $doc = $this->dokument();

        AnalyzeDocumentJob::dispatchSync((string) $doc->id);
        $this->assertTrue($doc->fresh()->ki_freigabe_offen, 'Vermerk "wartet auf Freigabe" muss gesetzt sein.');
        $this->assertSame(0, $provider->aufrufe);

        // So setzt auch der Knopf "Mit KI analysieren" den Status zurueck.
        Document::whereKey($doc->id)->update(['ai_status' => 'pending']);
        AnalyzeDocumentJob::dispatchSync((string) $doc->id, forceAi: true, fresh: true);

        $this->assertSame(1, $provider->aufrufe);
        $this->assertFalse($doc->fresh()->ki_freigabe_offen);
        $this->assertSame('ai', $doc->fresh()->ai_source);
    }

    public function test_ausgeschaltet_eskaliert_wie_frueher(): void
    {
        SystemSetting::set(DocumentAnalyzer::SETTING_NUR_NACH_FREIGABE, '0');
        $provider = $this->provider();

        $result = $this->analyzer($provider, $this->langerText())->analyze($this->dokument());

        $this->assertSame(1, $provider->aufrufe);
        $this->assertSame('ai', $result['source']);
    }

    public function test_eingang_zeigt_den_vermerk_und_die_kostenfrage(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $doc = $this->dokument();
        $doc->update(['ai_status' => 'done', 'ai_type' => 'sonstiges', 'ai_source' => 'ocr', 'ki_freigabe_offen' => true, 'uploaded_by' => $admin->id]);

        $html = $this->actingAs($admin)->get(route('admin.documents.inbox'))->assertOk()->getContent();

        $this->assertStringContainsString('KI empfohlen – wartet auf Freigabe', $html);
        $this->assertStringContainsString('Kostenpflichtige KI-Analyse (Claude) fuer dieses Dokument starten?', $html);
    }

    public function test_email_ki_vorschlag_ist_standardmaessig_aus(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        User::factory()->create(['role' => 'admin']);
        Http::fake();
        $account = EmailAccount::create(['email_address' => 'info@example.com', 'name' => 'Test', 'provider' => 'imap', 'folders' => ['INBOX'], 'is_active' => true]);
        $mail = fn () => EmailMessage::create([
            'email_account_id' => $account->id, 'message_uid' => 'INBOX:'.uniqid(),
            'from_address' => 'unbekannt@example.org', 'subject' => 'Hallo', 'body_text' => 'Nur ein Gruss.',
        ]);

        app(EmailWorkflowService::class)->process($mail());
        Http::assertNothingSent();

        SystemSetting::set(AiEmailClassifier::SETTING_AUTOMATISCH, '1');
        app(EmailWorkflowService::class)->process($mail());
        Http::assertSentCount(1);
    }

    public function test_einstellungen_speichern_beide_schalter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue(DocumentAnalyzer::kiNurNachFreigabe());
        $this->assertFalse(AiEmailClassifier::automatischAktiv());

        $this->actingAs($admin)->put(route('admin.settings.update'), ['ki_kosten_form' => '1', AiEmailClassifier::SETTING_AUTOMATISCH => '1']);

        $this->assertFalse(DocumentAnalyzer::kiNurNachFreigabe());
        $this->assertTrue(AiEmailClassifier::automatischAktiv());
    }
}
