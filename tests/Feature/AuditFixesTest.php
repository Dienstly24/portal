<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeDocumentJob;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\DocumentAnalyzer;
use App\Services\DocumentIntake\DocumentIntakeService;
use App\Services\Matching\CustomerMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regressionsschutz fuer die im Produktions-Audit bestaetigten Fixes.
 */
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function makeCustomer(string $number): Customer
    {
        return Customer::create([
            'user_id' => $this->user('customer')->id,
            'customer_number' => $number,
        ]);
    }

    public function test_customer_merge_is_admin_only(): void
    {
        $customer = $this->makeCustomer('C-MERGE1');

        // Die Zusammenfuehrung loescht den Duplikat-Datensatz + Login hart -
        // Nicht-Admins (auch Manager/Support/Employee) werden von der
        // role:admin-Middleware weggeleitet (302), kommen also nicht durch.
        foreach (['employee', 'manager', 'support'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('admin.customer.merge', $customer->id))
                ->assertStatus(302);
        }

        // Admin wird NICHT weggeleitet (Middleware laesst durch).
        $adminStatus = $this->actingAs($this->user('admin'))
            ->get(route('admin.customer.merge', $customer->id))->status();
        $this->assertNotSame(302, $adminStatus);
    }

    public function test_family_delete_is_registered_as_delete_not_get(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.customer.family.delete');
        $this->assertNotNull($route);
        $this->assertContains('DELETE', $route->methods());
        $this->assertNotContains('GET', $route->methods());
    }

    /**
     * Robustheits-Fix: schlaegt die Nachverarbeitung NACH der (bezahlten)
     * KI-Analyse fehl, muss das Dokument sauber 'failed' werden - nicht
     * dauerhaft in 'processing' haengen (der atomare Claim liesse einen Retry
     * sonst folgenlos aussteigen).
     */
    public function test_analyze_job_marks_failed_when_postprocessing_throws(): void
    {
        Storage::fake('local');
        config(['services.anthropic.key' => 'test-key', 'services.ocr.enabled' => false]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'type' => 'rechnung', 'confidence' => 90, 'summary' => 'Testrechnung', 'title' => 'Rechnung',
                'data' => ['person' => ['first_name' => 'Max', 'last_name' => 'Muster']],
            ])]],
        ])]);
        Storage::disk('local')->put('documents/eingang/s.pdf', '%PDF-1.4');

        $document = Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => 's.pdf',
            'file_path' => 'documents/eingang/s.pdf', 'disk' => 'local', 'ai_status' => 'pending',
        ]);

        // Intake, dessen findMatch in der Nachverarbeitung wirft.
        $throwingIntake = new class(app(CustomerMatchingService::class)) extends DocumentIntakeService {
            public function findMatch(array $extracted): ?array
            {
                throw new \RuntimeException('post-processing boom');
            }
        };

        (new AnalyzeDocumentJob($document->id))->handle(app(DocumentAnalyzer::class), $throwingIntake);

        $document->refresh();
        $this->assertSame('failed', $document->ai_status, 'Dokument darf nicht in processing haengen bleiben');
        $this->assertStringContainsString('Nachverarbeitung', (string) $document->ai_error);
    }

    /**
     * DoS-Fix: der Portal-Ticket-Endpunkt ist jetzt throttle:20,10 -
     * nach 20 Requests muss der naechste 429 liefern (echter Hammer-Test).
     */
    public function test_portal_ticket_store_is_rate_limited(): void
    {
        $user = $this->user('customer');
        Customer::create(['user_id' => $user->id, 'customer_number' => 'C-THROTTLE']);

        $payload = ['type' => 'other', 'subject' => 'Test', 'description' => 'Test-Anliegen', 'priority' => 'mittel'];
        $statuses = [];
        for ($i = 0; $i < 25; $i++) {
            $statuses[] = $this->actingAs($user)->post(route('portal.tickets.store'), $payload)->status();
        }

        $this->assertContains(429, $statuses, 'Nach 20 Requests muss der Throttle 429 liefern');
        $this->assertLessThanOrEqual(20, count(array_filter($statuses, fn ($s) => $s !== 429)));
    }
}
