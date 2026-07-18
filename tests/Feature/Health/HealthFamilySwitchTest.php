<?php

namespace Tests\Feature\Health;

use App\Models\Contract;
use App\Models\ContractHistory;
use App\Models\Customer;
use App\Models\CustomerFamily;
use App\Models\Document;
use App\Models\User;
use App\Services\Health\FamilyBundleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Krankenkassen-Fall aus dem Dokumenten-Eingang: Familie erkennen, Haupt-
 * versicherten waehlen, Wechseldatum berechnen, Familienmitglieder + Vertrag
 * + Verlauf anlegen; Stichtag-Automatik (health:apply-due-switches).
 */
class HealthFamilySwitchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function inboxDoc(string $type, array $extracted, string $name = 'x.pdf'): Document
    {
        Storage::fake('local');
        $path = 'documents/eingang/' . uniqid() . '.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');
        return Document::create([
            'customer_id' => null, 'category' => 'other', 'file_name' => $name,
            'file_path' => $path, 'disk' => 'local', 'ai_status' => 'done',
            'ai_type' => $type, 'ai_extracted' => $extracted,
        ]);
    }

    /** Buendel: 1 Karten-Scan mit 4 Personen (Vater, Mutter, 2 Kinder) + Ausweis. */
    private function familyBundle(): array
    {
        $cards = $this->inboxDoc('gesundheitskarte', [
            'person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar', 'birth_date' => '1990-01-01'],
            'gesundheit' => ['health_insurance_company' => 'AOK', 'health_insurance_number' => 'A111'],
            'personen' => [
                ['first_name' => 'Ahmed', 'last_name' => 'Nassar', 'birth_date' => '1990-01-01', 'gender' => 'male', 'health_insurance_number' => 'A111'],
                ['first_name' => 'Fatima', 'last_name' => 'Nassar', 'birth_date' => '1992-05-16', 'gender' => 'female', 'health_insurance_number' => 'A222'],
                ['first_name' => 'Sara', 'last_name' => 'Nassar', 'birth_date' => '2019-03-01', 'gender' => 'female', 'health_insurance_number' => 'A333'],
                ['first_name' => 'Omar', 'last_name' => 'Nassar', 'birth_date' => '2023-12-04', 'gender' => 'male', 'health_insurance_number' => 'A444'],
            ],
        ], 'karten.pdf');
        $ausweis = $this->inboxDoc('personalausweis', [
            'person' => ['first_name' => 'Ahmed', 'last_name' => 'Nassar', 'birth_date' => '1990-01-01', 'zip' => '70806', 'city' => 'Kornwestheim'],
        ], 'ausweis.pdf');
        return [$cards, $ausweis];
    }

    public function test_detects_family_persons_and_suggests_haupt(): void
    {
        [$cards, $ausweis] = $this->familyBundle();
        $service = new FamilyBundleService();

        $persons = $service->detectPersons([$cards, $ausweis]);

        $this->assertCount(4, $persons); // dedupliziert (Ahmed nur einmal)
        $haupt = $persons[$service->suggestHauptIndex($persons)];
        $this->assertSame('Ahmed', $haupt['first_name']); // aeltester Mann
    }

    public function test_full_family_switch_flow_regular_wechsel(): void
    {
        [$cards, $ausweis] = $this->familyBundle();
        $service = new FamilyBundleService();
        $persons = $service->detectPersons([$cards, $ausweis]);
        $hauptIndex = $service->suggestHauptIndex($persons);
        $members = [];
        foreach ($persons as $i => $p) {
            if ($i === $hauptIndex) continue;
            $members[] = ['index' => $i, 'status' => 'familienversichert',
                'relation' => ($p['first_name'] === 'Fatima') ? 'Ehepartner' : 'Kind'];
        }

        $response = $this->actingAs($this->admin())->postJson(route('admin.documents.create_customer_batch'), [
            'document_ids' => [$cards->id, $ausweis->id],
            'apply_fields' => ['birth_date', 'address'],
            'family' => [
                'haupt_index' => $hauptIndex,
                'members' => $members,
                'switch_reason' => 'wechsel',
                'old_insurer' => 'AOK',
                'new_insurer' => 'TK',
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $expectedEffective = now()->addMonthsNoOverflow(3)->startOfMonth()->toDateString();
        $this->assertSame($expectedEffective, $response->json('health.effective_date'));

        $customer = Customer::findOrFail($response->json('customer_id'));
        // Haupt = Ahmed; Kasse bleibt bis Stichtag die ALTE.
        $this->assertSame('Ahmed Nassar', $customer->user->name);
        $this->assertSame('AOK', $customer->health_insurance_company);
        $this->assertSame('A111', $customer->health_insurance_number);

        // 3 Familienmitglieder mit neuem Versicherer ab Stichtag.
        $family = CustomerFamily::where('customer_id', $customer->id)->get();
        $this->assertCount(3, $family);
        $this->assertSame(['familienversichert'], $family->pluck('health_insurance_status')->unique()->all());
        $this->assertSame(['TK'], $family->pluck('health_insurance_company')->unique()->all());
        $ehe = $family->firstWhere('relation', 'Ehepartner');
        $this->assertSame('Fatima Nassar', $ehe->name);

        // Kranken-Vertrag pending bis Stichtag.
        $contract = Contract::where('customer_id', $customer->id)->where('type', 'krankenversicherung')->first();
        $this->assertNotNull($contract);
        $this->assertSame('pending', $contract->status);
        $this->assertSame('TK', $contract->insurer);
        $this->assertSame($expectedEffective, $contract->start_date);

        // Verlauf: AOK endet am Vortag, TK beginnt am Stichtag.
        $entries = ContractHistory::where('customer_id', $customer->id)->orderBy('created_at')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('AOK', $entries[0]->provider);
        $this->assertSame(
            \Carbon\CarbonImmutable::parse($expectedEffective)->subDay()->toDateString(),
            $entries[0]->effective_until?->toDateString(),
        );
        $this->assertSame('TK', $entries[1]->provider);
        $this->assertSame('wechsel', $entries[1]->reason);
        $this->assertSame('hauptversichert', $entries[1]->role);
    }

    public function test_new_job_switches_immediately(): void
    {
        [$cards, $ausweis] = $this->familyBundle();

        $response = $this->actingAs($this->admin())->postJson(route('admin.documents.create_customer_batch'), [
            'document_ids' => [$cards->id, $ausweis->id],
            'family' => [
                'haupt_index' => 0,
                'members' => [],
                'switch_reason' => 'new_job',
                'job_start' => now()->toDateString(),
                'old_insurer' => 'AOK',
                'new_insurer' => 'Barmer',
            ],
        ]);

        $response->assertOk();
        $customer = Customer::findOrFail($response->json('customer_id'));
        // Sofortiger Wechsel: Kasse direkt umgestellt, Vertrag aktiv.
        $this->assertSame('Barmer', $customer->health_insurance_company);
        $contract = Contract::where('customer_id', $customer->id)->where('type', 'krankenversicherung')->first();
        $this->assertSame('active', $contract->status);
    }

    public function test_due_switch_command_activates_pending_contract(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-TEST01',
            'health_insurance_company' => 'AOK',
        ]);
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'krankenversicherung',
            'insurer' => 'TK', 'status' => 'pending', 'start_date' => now()->toDateString(),
        ]);
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'krankenversicherung',
            'insurer' => 'Zukunft', 'status' => 'pending', 'start_date' => now()->addMonths(2)->toDateString(),
        ]);

        $this->artisan('health:apply-due-switches')->assertSuccessful();

        $this->assertSame('TK', $customer->fresh()->health_insurance_company);
        $this->assertSame('active', Contract::where('insurer', 'TK')->first()->status);
        // Der zukuenftige bleibt pending.
        $this->assertSame('pending', Contract::where('insurer', 'Zukunft')->first()->status);
    }

    public function test_sonder_is_flagged_in_result(): void
    {
        [$cards, $ausweis] = $this->familyBundle();

        $response = $this->actingAs($this->admin())->postJson(route('admin.documents.create_customer_batch'), [
            'document_ids' => [$cards->id, $ausweis->id],
            'family' => [
                'haupt_index' => 0, 'members' => [],
                'switch_reason' => 'sonder', 'old_insurer' => 'AOK', 'new_insurer' => 'TK',
            ],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('health.is_sonder'));
        $this->assertSame('sonder', ContractHistory::where('provider', 'TK')->first()->reason);
    }
}
