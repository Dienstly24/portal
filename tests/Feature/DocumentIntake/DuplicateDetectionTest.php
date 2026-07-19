<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ai\DocumentAnalyzer;
use App\Services\Ai\RelevantPageSelector;
use App\Services\Ai\TemplateParsers\Check24KfzProtocolParser;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Duplikat-Erkennung: dieselbe Datei erneut hochgeladen (z.B. eine Woche
 * spaeter) wird am identischen Inhalts-Hash erkannt, als Duplikat markiert und
 * spart die (kostenpflichtige) KI-Analyse.
 */
class DuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Legt ein Eingangs-Dokument mit konkretem Dateiinhalt an. */
    private function docWithContent(string $content, array $overrides = []): Document
    {
        Storage::fake('local');
        $path = 'documents/eingang/' . Str::uuid() . '.pdf';
        Storage::disk('local')->put($path, $content);

        return Document::create(array_merge([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'datei.pdf',
            'file_path' => $path,
            'disk' => 'local',
            'ai_status' => 'done',
        ], $overrides));
    }

    public function test_content_hash_is_set_from_stored_file(): void
    {
        $content = "%PDF-1.4\nInhalt A\n%%EOF";
        $doc = $this->docWithContent($content);

        $this->assertNotNull($doc->content_hash);
        $this->assertSame(hash('sha256', $content), $doc->content_hash);
    }

    public function test_reupload_of_identical_content_is_marked_as_duplicate(): void
    {
        $content = "%PDF-1.4\nExakt gleicher Inhalt\n%%EOF";
        $first = $this->docWithContent($content);
        // Zweites Dokument mit demselben Inhalt (anderer Pfad).
        $second = $this->docWithContent($content);

        $this->assertNull($first->duplicate_of, 'das erste Dokument ist kein Duplikat');
        $this->assertSame((string) $first->id, (string) $second->duplicate_of);
        $this->assertSame($first->content_hash, $second->content_hash);
    }

    public function test_different_content_is_not_a_duplicate(): void
    {
        $a = $this->docWithContent("%PDF-1.4\nInhalt A\n");
        $b = $this->docWithContent("%PDF-1.4\nInhalt B (anders)\n");

        $this->assertNull($b->duplicate_of);
        $this->assertNotSame($a->content_hash, $b->content_hash);
    }

    public function test_smart_upload_reports_duplicate_in_response(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => '']);
        $admin = $this->admin();
        $content = "%PDF-1.4\nDieselbe hochgeladene Datei\n%%EOF";

        $first = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->createWithContent('police.pdf', $content)],
        ]);
        $first->assertOk()->assertJsonCount(0, 'duplicates');

        $second = $this->actingAs($admin)->postJson(route('admin.documents.smart_upload'), [
            'files' => [UploadedFile::fake()->createWithContent('police.pdf', $content)],
        ]);
        $second->assertOk()
            ->assertJsonCount(1, 'duplicates')
            ->assertJsonPath('duplicates.0.file_name', 'police.pdf');

        // Markierung ist persistiert.
        $dup = Document::find($second->json('ids.0'));
        $this->assertNotNull($dup->duplicate_of);
    }

    public function test_analyzer_reuses_result_for_duplicate_without_calling_ai(): void
    {
        $content = "%PDF-1.4\nBereits analysiert\n%%EOF";
        $original = $this->docWithContent($content, [
            'ai_status' => 'done',
            'ai_type' => 'sepa_mandat',
            'ai_confidence' => 88,
            'ai_source' => 'ocr',
            'ai_summary' => 'SEPA-Mandat',
            'ai_extracted' => ['bank' => ['iban' => 'DE89370400440532013000'], 'match' => ['customer_id' => 'x', 'score' => 99]],
        ]);
        $duplicate = $this->docWithContent($content, ['ai_status' => 'pending']);
        $this->assertSame((string) $original->id, (string) $duplicate->duplicate_of);

        // KI-Anbieter, der bei jedem Aufruf durchfaellt - darf NICHT aufgerufen werden.
        $provider = new class implements DocumentAiProviderInterface {
            public bool $called = false;
            public function isEnabled(): bool { return true; }
            public function model(): string { return 'fake'; }
            public function analyze(string $binary, string $mime, string $ocrText, bool $preferText = false): ?array
            {
                $this->called = true;
                throw new \RuntimeException('KI darf fuer ein Duplikat nicht aufgerufen werden.');
            }
        };
        $analyzer = new DocumentAnalyzer(
            $provider,
            $this->fakeOcr(),
            $this->fakePdfText(''),
            new RelevantPageSelector(),
            new Check24KfzProtocolParser(),
        );

        $result = $analyzer->analyze($duplicate->fresh());

        $this->assertFalse($provider->called, 'Duplikat darf keine KI kosten');
        $this->assertSame('sepa_mandat', $result['type']);
        $this->assertSame('DE89370400440532013000', $result['data']['bank']['iban']);
        // Der personenbezogene Match wird bewusst NICHT uebernommen.
        $this->assertArrayNotHasKey('match', $result['data']);
    }

    public function test_inbox_shows_duplicate_warning(): void
    {
        $content = "%PDF-1.4\nDoppelt im Eingang\n%%EOF";
        // Original zuerst (bleibt im Eingang), dann das Duplikat.
        $this->docWithContent($content);
        $this->docWithContent($content);

        $response = $this->actingAs($this->admin())->get(route('admin.documents.inbox'));

        $response->assertOk()
            ->assertSee('Bereits vorhanden', false)
            ->assertSee('Original anzeigen', false)
            ->assertSee('Duplikat', false)
            // Blade-Direktiven muessen geparst sein, nicht als Text durchsickern.
            ->assertDontSee('@if', false)
            ->assertDontSee('$dupOrig', false)
            ->assertDontSee('$dupCustomerName', false);
    }

    public function test_inbox_duplicate_warning_names_assigned_customer(): void
    {
        $content = "%PDF-1.4\nDuplikat eines zugeordneten Dokuments\n%%EOF";
        // Original einem sichtbaren Kunden zuordnen, dann Duplikat im Eingang.
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Sara Test']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-DUP1']);
        $this->docWithContent($content, ['customer_id' => $customer->id]);
        $this->docWithContent($content);

        $response = $this->actingAs($this->admin())->get(route('admin.documents.inbox'));

        $response->assertOk()
            ->assertSee('Bereits vorhanden', false)
            ->assertSee('Sara Test', false)
            ->assertDontSee('@if', false);
    }

    public function test_backfill_command_sets_missing_hashes(): void
    {
        $doc = $this->docWithContent("%PDF-1.4\nAltbestand\n%%EOF");
        // Hash entfernen, als waere das Dokument vor dem Feature angelegt worden.
        Document::whereKey($doc->id)->update(['content_hash' => null]);
        $this->assertNull($doc->fresh()->content_hash);

        $this->artisan('documents:backfill-hashes')->assertSuccessful();

        $this->assertNotNull($doc->fresh()->content_hash);
    }

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
}
