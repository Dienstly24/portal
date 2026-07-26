<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Schlauer Anzeige-Status der Vertraege (Betreiber-Feedback 25.07.2026):
 * eine erfasste Kuendigung (cancellation_date) erscheint als
 * "Gekuendigt zum <Datum>", ein abgeschlossener Vertrag mit Beginn in der
 * Zukunft als "Aktiv ab <Datum>" - in Kundenakte, Vertragsliste und
 * Kundenportal. Der gespeicherte status-Wert bleibt dabei unveraendert
 * (Statistik, Filter und Provisions-Storno haengen daran).
 */
class ContractDisplayStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
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

    // Laufender Vertrag ohne Besonderheiten bleibt schlicht "Aktiv".
    public function test_running_active_contract_shows_plain_aktiv(): void
    {
        $st = $this->contract(['start_date' => now()->subYear()->toDateString()])->displayStatus();
        $this->assertSame('active', $st['key']);
        $this->assertSame('Aktiv', $st['label']);
        $this->assertSame('active', $st['badge']);
    }

    // Kuendigung erfasst, Datum in der Zukunft: "Gekündigt zum <Datum>" (orange),
    // obwohl der Roh-Status weiterhin active ist.
    public function test_future_cancellation_shows_gekuendigt_zum(): void
    {
        $cancelAt = now()->addDays(40);
        $st = $this->contract([
            'start_date' => now()->subYear()->toDateString(),
            'cancellation_date' => $cancelAt->toDateString(),
        ])->displayStatus();

        $this->assertSame('cancelled_upcoming', $st['key']);
        $this->assertSame('Gekündigt zum ' . $cancelAt->format('d.m.Y'), $st['label']);
        $this->assertSame('pending', $st['badge']);
        $this->assertSame('Gekündigt zum :date', $st['label_key']);
        $this->assertSame($cancelAt->format('d.m.Y'), $st['params']['date']);
    }

    // Kuendigungsdatum erreicht/ueberschritten: weiterhin mit Datum, aber rot.
    public function test_reached_cancellation_date_is_red(): void
    {
        $st = $this->contract(['cancellation_date' => now()->subDay()->toDateString()])->displayStatus();
        $this->assertSame('cancelled', $st['key']);
        $this->assertSame('rejected', $st['badge']);
        $this->assertSame('Gekündigt zum ' . now()->subDay()->format('d.m.Y'), $st['label']);
    }

    // Beginn in der Zukunft: "Aktiv ab <Datum>" (blau) statt schlicht "Aktiv".
    public function test_future_start_shows_aktiv_ab(): void
    {
        $start = now()->addMonths(2);
        $st = $this->contract(['start_date' => $start->toDateString()])->displayStatus();
        $this->assertSame('active_upcoming', $st['key']);
        $this->assertSame('Aktiv ab ' . $start->format('d.m.Y'), $st['label']);
        $this->assertSame('open', $st['badge']);
    }

    // Eine erfasste Kuendigung schlaegt "Aktiv ab" (auch beim Zukunfts-Vertrag).
    public function test_cancellation_wins_over_future_start(): void
    {
        $st = $this->contract([
            'start_date' => now()->addMonth()->toDateString(),
            'cancellation_date' => now()->addMonths(2)->toDateString(),
        ])->displayStatus();
        $this->assertSame('cancelled_upcoming', $st['key']);
    }

    // Roh-Status pending/cancelled/expired behalten ihre Labels und Farben.
    public function test_raw_status_fallbacks(): void
    {
        $this->assertSame('Gekündigt', $this->contract(['status' => 'cancelled'])->displayStatus()['label']);
        $this->assertSame('In Bearbeitung', $this->contract(['status' => 'pending'])->displayStatus()['label']);

        $expired = $this->contract(['status' => 'expired'])->displayStatus();
        $this->assertSame('Abgelaufen', $expired['label']);
        $this->assertSame('closed', $expired['badge']);

        // pending mit Beginn in der Zukunft bleibt "In Bearbeitung" (noch
        // kein abgeschlossener Vertrag).
        $st = $this->contract(['status' => 'pending', 'start_date' => now()->addMonth()->toDateString()])->displayStatus();
        $this->assertSame('pending', $st['key']);
    }

    // Kundenakte (Beraterwelt) zeigt beide schlauen Labels wie im Screenshot:
    // gekuendigter Altvertrag + Folgevertrag mit Beginn in der Zukunft.
    public function test_admin_customer_file_shows_smart_labels(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $cancelAt = now()->addDays(30);
        $startAt = now()->addDays(31);

        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz',
            'insurer' => 'ADAC Autoversicherung AG', 'status' => 'active',
            'start_date' => now()->subYear()->toDateString(),
            'cancellation_date' => $cancelAt->toDateString(),
        ]);
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz',
            'insurer' => 'Neodigital', 'status' => 'active',
            'start_date' => $startAt->toDateString(),
        ]);

        $this->actingAs($admin)->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('Gekündigt zum ' . $cancelAt->format('d.m.Y'))
            ->assertSee('Aktiv ab ' . $startAt->format('d.m.Y'));
    }

    // Kundenportal (Vertragsliste) zeigt die schlauen Labels ebenfalls.
    public function test_portal_contract_list_shows_smart_labels(): void
    {
        $customer = $this->makeCustomer();
        $cancelAt = now()->addDays(30);
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz',
            'insurer' => 'ADAC Autoversicherung AG', 'status' => 'active',
            'cancellation_date' => $cancelAt->toDateString(),
        ]);

        $this->actingAs($customer->user)->get(route('portal.contracts'))
            ->assertOk()
            ->assertSee('Gekündigt zum ' . $cancelAt->format('d.m.Y'));
    }
}
