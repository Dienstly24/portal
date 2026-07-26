<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractRevision;
use App\Models\Customer;
use App\Models\Provision;
use App\Models\ProvisionRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * contracts:apply-endings (Betreiber-Vorgabe 26.07.2026): der gespeicherte
 * Status folgt der Realitaet. Erreichtes wirksames Kuendigungs-Ende ->
 * cancelled (Model-Hook bucht den Provisions-Storno), E-Scooter nach
 * Saisonende -> expired (kein Storno). Laufende Vertraege ohne Kuendigung
 * bleiben unangetastet (stillschweigende Verlaengerung).
 */
class ContractEndingsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ], $attrs));
    }

    private function contract(array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $this->makeCustomer()->id,
            'type' => 'kfz',
            'insurer' => 'ADAC Autoversicherung AG',
            'status' => 'active',
        ], $overrides));
    }

    // Wirksames Ende erreicht (Kuendigung + Ablauf in der Vergangenheit,
    // Frist war gewahrt) -> cancelled + System-Eintrag in der Version History.
    public function test_reached_cancellation_end_sets_cancelled_with_revision(): void
    {
        $contract = $this->contract([
            'start_date' => now()->subYears(2)->toDateString(),
            'end_date' => now()->subDays(3)->toDateString(),
            'cancellation_date' => now()->subMonths(3)->toDateString(),
        ]);

        $this->artisan('contracts:apply-endings')->assertSuccessful();

        $this->assertSame('cancelled', $contract->fresh()->status);
        $rev = ContractRevision::where('contract_id', $contract->id)->where('field', 'status')->first();
        $this->assertNotNull($rev);
        $this->assertSame('Aktiv', $rev->old_value);
        $this->assertSame('Gekündigt', $rev->new_value);
        $this->assertSame('system', $rev->source);
        $this->assertNull($rev->changed_by);
    }

    // Wirksames Ende liegt in der Zukunft -> Vertrag bleibt aktiv.
    public function test_future_effective_end_stays_active(): void
    {
        $contract = $this->contract([
            'end_date' => now()->addMonths(2)->toDateString(),
            'cancellation_date' => now()->toDateString(),
        ]);

        $this->artisan('contracts:apply-endings')->assertSuccessful();

        $this->assertSame('active', $contract->fresh()->status);
    }

    // Der Tages-Job vertraut den ERFASSTEN Daten: erreichter Ablauf mit
    // Kuendigung -> cancelled, auch wenn die Frist knapp war (der Formular-
    // Hinweis hat beim Erfassen beraten; Sonderkuendigung/Wechsel-Kette
    // sind so korrekt abgebildet).
    public function test_recorded_end_reached_cancels_contract(): void
    {
        $contract = $this->contract([
            'end_date' => now()->subDay()->toDateString(),
            'cancellation_date' => now()->subDays(14)->toDateString(),
        ]);

        $this->artisan('contracts:apply-endings')->assertSuccessful();

        $this->assertSame('cancelled', $contract->fresh()->status);
    }

    // Kuendigung ohne Ablauf: erfasstes Datum erreicht -> cancelled.
    public function test_cancellation_without_ablauf_uses_submitted_date(): void
    {
        $contract = $this->contract([
            'cancellation_date' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('contracts:apply-endings')->assertSuccessful();

        $this->assertSame('cancelled', $contract->fresh()->status);
    }

    // Betreiber-Klarstellung 26.07.2026: die Vermittler-Provision gibt es
    // EINMALIG je Verkauf - beim natuerlichen Vertragsende (Wechsel-Kette,
    // erreichtes Kuendigungs-Ende) bleibt sie verdient. Der Tages-Job bucht
    // also KEIN Storno. (Storno weiterhin bei Loeschung und manueller
    // Stornierung - abgedeckt in ProvisionManagementTest.)
    public function test_natural_end_keeps_werber_provision(): void
    {
        $werber = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        ProvisionRate::create([
            'user_id' => $werber->id, 'contract_type' => 'kfz',
            'amount_fixed' => 50, 'amount_percent' => null,
        ]);
        $customer = $this->makeCustomer(['acquired_by' => $werber->id]);
        $contract = Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK Coburg',
            'status' => 'active',
            'end_date' => now()->subDays(3)->toDateString(),
            'cancellation_date' => now()->subMonths(3)->toDateString(),
        ]);
        $this->assertSame('50.00', (string) Provision::where('contract_id', $contract->id)->where('type', 'neuvertrag')->first()->amount);

        $this->artisan('contracts:apply-endings')->assertSuccessful();

        $this->assertSame('cancelled', $contract->fresh()->status);
        $this->assertSame(0, Provision::where('contract_id', $contract->id)->where('type', 'storno')->count());
        $this->assertSame(1, Provision::where('contract_id', $contract->id)->where('type', 'neuvertrag')->count());
    }

    // E-Scooter nach Saisonende -> expired, OHNE Storno (kein Kuendigungsfall).
    public function test_escooter_past_season_end_becomes_expired_without_storno(): void
    {
        $contract = $this->contract([
            'type' => 'escooter',
            'insurer' => 'DEVK',
            'start_date' => now()->subMonths(14)->toDateString(),
            'end_date' => now()->subMonths(2)->toDateString(),
        ]);

        $this->artisan('contracts:apply-endings')->assertSuccessful();

        $fresh = $contract->fresh();
        $this->assertSame('expired', $fresh->status);
        $this->assertSame(0, Provision::where('contract_id', $contract->id)->where('type', 'storno')->count());
        $rev = ContractRevision::where('contract_id', $contract->id)->where('field', 'status')->first();
        $this->assertSame('Abgelaufen', $rev->new_value);
    }

    // Normale Versicherung mit ueberschrittenem Ablauf, aber OHNE Kuendigung:
    // verlaengert sich stillschweigend -> bleibt aktiv.
    public function test_auto_renewing_contract_without_cancellation_stays_active(): void
    {
        $contract = $this->contract([
            'start_date' => now()->subYears(2)->toDateString(),
            'end_date' => now()->subMonths(1)->toDateString(),
        ]);

        $this->artisan('contracts:apply-endings')->assertSuccessful();

        $this->assertSame('active', $contract->fresh()->status);
    }
}
