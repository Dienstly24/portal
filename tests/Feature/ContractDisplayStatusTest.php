<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Schlauer Anzeige-Status der Vertraege (Betreiber-Feedback 25./26.07.2026):
 * cancellation_date ist das EINREICHUNGS-Datum der Kuendigung - angezeigt
 * wird das WIRKSAME Ende ("Gekuendigt zum <Ablauf>", KFZ mit Ein-Monats-
 * Frist nach deutschem Recht). Ein abgeschlossener Vertrag mit Beginn in
 * der Zukunft erscheint als "Aktiv ab <Datum>". Der gespeicherte
 * status-Wert bleibt unveraendert (Statistik, Filter, Provisions-Storno).
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

    // Kernfall: Kuendigung HEUTE eingereicht, Ablauf in der Zukunft ->
    // "Gekündigt zum <Ablauf>" (nicht zum Einreichungsdatum!), orange.
    public function test_cancellation_is_effective_at_ablauf_not_submission_date(): void
    {
        $ablauf = now()->addMonths(3);
        $st = $this->contract([
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => $ablauf->toDateString(),
            'cancellation_date' => now()->toDateString(),
        ])->displayStatus();

        $this->assertSame('cancelled_upcoming', $st['key']);
        $this->assertSame('Gekündigt zum ' . $ablauf->format('d.m.Y'), $st['label']);
        $this->assertSame('pending', $st['badge']);
        $this->assertSame($ablauf->format('d.m.Y'), $st['params']['date']);
    }

    // Frist knapp/verpasst? Der SERVER vertraut den ERFASSTEN Daten (der
    // rote Live-Hinweis im Formular beraet beim Eintragen; Sonderkuendigung
    // und Wechsel-Kette waeren sonst falsch): wirksam zum Ablauf.
    public function test_missed_deadline_still_trusts_recorded_ablauf(): void
    {
        $ablauf = now()->addDays(14);
        $st = $this->contract([
            'end_date' => $ablauf->toDateString(),
            'cancellation_date' => now()->toDateString(),
        ])->displayStatus();

        $this->assertSame('cancelled_upcoming', $st['key']);
        $this->assertSame('Gekündigt zum ' . $ablauf->format('d.m.Y'), $st['label']);
    }

    // Andere Sparten identisch: wirksam zum Ablauf.
    public function test_non_kfz_uses_ablauf(): void
    {
        $ablauf = now()->addDays(14);
        $st = $this->contract([
            'type' => 'hausrat',
            'end_date' => $ablauf->toDateString(),
            'cancellation_date' => now()->toDateString(),
        ])->displayStatus();

        $this->assertSame('Gekündigt zum ' . $ablauf->format('d.m.Y'), $st['label']);
        $this->assertSame('pending', $st['badge']);
    }

    // Ohne hinterlegten Ablauf gilt das erfasste Datum selbst als Ende
    // (Altdaten/Sonderkuendigung).
    public function test_cancellation_without_ablauf_uses_submitted_date(): void
    {
        $cancelAt = now()->addDays(40);
        $st = $this->contract([
            'start_date' => now()->subYear()->toDateString(),
            'cancellation_date' => $cancelAt->toDateString(),
        ])->displayStatus();

        $this->assertSame('cancelled_upcoming', $st['key']);
        $this->assertSame('Gekündigt zum ' . $cancelAt->format('d.m.Y'), $st['label']);
        $this->assertSame('pending', $st['badge']);
    }

    // Wirksames Ende erreicht/ueberschritten: weiterhin mit Datum, aber rot.
    public function test_reached_effective_end_is_red(): void
    {
        $st = $this->contract([
            'end_date' => now()->subMonths(2)->toDateString(),
            'cancellation_date' => now()->subMonths(4)->toDateString(),
        ])->displayStatus();

        $this->assertSame('cancelled', $st['key']);
        $this->assertSame('rejected', $st['badge']);
        $this->assertSame('Gekündigt zum ' . now()->subMonths(2)->format('d.m.Y'), $st['label']);
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
    // Altvertrag "Gekündigt zum <Ablauf>" + Folgevertrag "Aktiv ab <Ablauf>".
    public function test_admin_customer_file_shows_smart_labels(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $ablauf = now()->addMonths(2);

        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz',
            'insurer' => 'ADAC Autoversicherung AG', 'status' => 'active',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => $ablauf->toDateString(),
            'cancellation_date' => now()->toDateString(),
        ]);
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz',
            'insurer' => 'Neodigital', 'status' => 'active',
            'start_date' => $ablauf->toDateString(),
        ]);

        $this->actingAs($admin)->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('Gekündigt zum ' . $ablauf->format('d.m.Y'))
            ->assertSee('Aktiv ab ' . $ablauf->format('d.m.Y'));
    }

    // Kundenportal (Vertragsliste) zeigt das wirksame Ende ebenfalls.
    public function test_portal_contract_list_shows_smart_labels(): void
    {
        $customer = $this->makeCustomer();
        $ablauf = now()->addMonths(2);
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz',
            'insurer' => 'ADAC Autoversicherung AG', 'status' => 'active',
            'end_date' => $ablauf->toDateString(),
            'cancellation_date' => now()->toDateString(),
        ]);

        $this->actingAs($customer->user)->get(route('portal.contracts'))
            ->assertOk()
            ->assertSee('Gekündigt zum ' . $ablauf->format('d.m.Y'));
    }
}
