<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractRevision;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Doppelversicherungs-Schutz + Wechsel-Automatik (Betreiber-Vorgabe
 * 26.07.2026): dasselbe Fahrzeug darf nie zwei Vertraege mit
 * ueberschneidendem Zeitraum haben. Gleicher Versicherer = Duplikat ->
 * blockiert. ANDERER Versicherer = Wechsel -> der Altvertrag bekommt
 * automatisch die Kuendigung erfasst (eingereicht heute, Ablauf =
 * Beginn des neuen) und die Akte zeigt die Kette "Gekuendigt zum X" ->
 * "Aktiv ab X". Mehrere Fahrzeuge je Kunde bleiben uneingeschraenkt.
 */
class VehicleOverlapGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    /** Bestandsvertrag mit Fahrzeug anlegen. */
    private function existingContract(Customer $customer, array $contract = [], array $vehicle = []): Contract
    {
        $c = Contract::create(array_merge([
            'customer_id' => $customer->id,
            'type' => 'kfz',
            'insurer' => 'ADAC Autoversicherung AG',
            'status' => 'active',
            'start_date' => now()->subYear()->toDateString(),
        ], $contract));
        ContractVehicleDetail::create(array_merge(['contract_id' => $c->id], $vehicle));
        return $c;
    }

    /** Formular-Payload fuer einen neuen KFZ-Vertrag. */
    private function payload(array $contract = [], array $vehicle = []): array
    {
        return array_merge([
            'type' => 'kfz',
            'insurer' => 'Neodigital',
            'status' => 'active',
        ], $contract, ['vehicle' => $vehicle]);
    }

    // Gleiches Fahrzeug (Kennzeichen mit/ohne Umlaut!) beim SELBEN
    // Versicherer -> Duplikat, kein Wechsel -> Anlegen wird abgelehnt.
    public function test_same_insurer_overlap_is_blocked_as_duplicate(): void
    {
        $customer = $this->makeCustomer();
        $this->existingContract($customer, [], ['license_plate' => 'LUN-G 1110']);

        $this->actingAs($this->admin())->post(
            route('admin.contract.store', $customer->id),
            $this->payload([
                'insurer' => 'ADAC Autoversicherung AG',
                'start_date' => now()->addMonths(2)->toDateString(),
            ], ['license_plate' => 'LÜN-G1110'])
        )->assertSessionHasErrors('vehicle_overlap');

        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());
    }

    // ANDERER Versicherer = Wechsel: der Altvertrag bekommt automatisch die
    // Kuendigung erfasst (eingereicht heute, Ablauf = Beginn des neuen) -
    // die Akte zeigt die Kette "Gekündigt zum X" -> "Aktiv ab X".
    public function test_insurer_switch_records_cancellation_on_old_contract(): void
    {
        $customer = $this->makeCustomer();
        $alt = $this->existingContract($customer, [], ['license_plate' => 'LUN-G 1110']);
        $wechseltag = now()->addMonths(2)->startOfDay();

        $this->actingAs($this->admin())->post(
            route('admin.contract.store', $customer->id),
            $this->payload(['start_date' => $wechseltag->toDateString()], ['license_plate' => 'LÜN-G1110'])
        )->assertRedirect(route('admin.customer', $customer->id))->assertSessionHas('success');

        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
        $alt->refresh();
        $this->assertSame(now()->toDateString(), (string) $alt->cancellation_date);
        $this->assertSame($wechseltag->toDateString(), (string) $alt->end_date);
        $this->assertSame('Gekündigt zum '.$wechseltag->format('d.m.Y'), $alt->displayStatus()['label']);
        $this->assertTrue(
            ContractRevision::where('contract_id', $alt->id)
                ->where('field', 'end_date')->where('source', 'system')->exists()
        );
    }

    // Wechsel ohne Beginn laesst sich nicht verketten -> klare Aufforderung.
    public function test_switch_without_start_date_asks_for_beginn(): void
    {
        $customer = $this->makeCustomer();
        $alt = $this->existingContract($customer, [], ['license_plate' => 'D-XY 88']);

        $this->actingAs($this->admin())->post(
            route('admin.contract.store', $customer->id),
            $this->payload([], ['license_plate' => 'D-XY 88'])
        )->assertSessionHasErrors('vehicle_overlap');

        $this->assertSame(1, Contract::where('customer_id', $customer->id)->count());
        $this->assertNull($alt->fresh()->cancellation_date);
    }

    // Beginnt der neue Vertrag VOR dem bereits erfassten Ablauf des alten,
    // zieht die Wechsel-Automatik den Ablauf auf den Wechseltag vor
    // (Doppelversicherung ist verboten - das faktische Ende zaehlt).
    public function test_switch_tightens_later_recorded_ablauf(): void
    {
        $customer = $this->makeCustomer();
        $alt = $this->existingContract($customer, [
            'end_date' => now()->addMonths(3)->toDateString(),
            'cancellation_date' => now()->subDays(5)->toDateString(),
        ], ['license_plate' => 'HH-AB 1234']);
        $wechseltag = now()->addMonths(2)->startOfDay();

        $this->actingAs($this->admin())->post(
            route('admin.contract.store', $customer->id),
            $this->payload(['start_date' => $wechseltag->toDateString()], ['license_plate' => 'HH-AB1234'])
        )->assertSessionHas('success');

        $alt->refresh();
        $this->assertSame($wechseltag->toDateString(), (string) $alt->end_date);
        $this->assertSame(now()->subDays(5)->toDateString(), (string) $alt->cancellation_date);
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }

    // Wechsel-Kette: Altvertrag gekuendigt zum Ablauf X, neuer beginnt genau
    // am X -> nahtlos, erlaubt (genau der Fall aus der Kundenakte).
    public function test_seamless_insurer_switch_is_allowed(): void
    {
        $customer = $this->makeCustomer();
        $ablauf = now()->addMonths(3)->toDateString();
        $this->existingContract($customer, [
            'end_date' => $ablauf,
            'cancellation_date' => now()->toDateString(), // Frist gewahrt (3 Monate > 1 Monat)
        ], ['license_plate' => 'LUN-G 1110']);

        $this->actingAs($this->admin())->post(
            route('admin.contract.store', $customer->id),
            $this->payload(['start_date' => $ablauf], ['license_plate' => 'LÜN-G 1110'])
        )->assertRedirect(route('admin.customer', $customer->id))->assertSessionHas('success');

        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }

    // Gleicher Versicherer, neuer Beginn einen Tag VOR dem wirksamen Ende
    // -> Duplikat-Ueberschneidung, abgelehnt.
    public function test_same_insurer_one_day_overlap_is_blocked(): void
    {
        $customer = $this->makeCustomer();
        $ablauf = now()->addMonths(3);
        $this->existingContract($customer, [
            'end_date' => $ablauf->toDateString(),
            'cancellation_date' => now()->toDateString(),
        ], ['license_plate' => 'HH-AB 1234']);

        $this->actingAs($this->admin())->post(
            route('admin.contract.store', $customer->id),
            $this->payload([
                'insurer' => 'ADAC Autoversicherung AG',
                'start_date' => $ablauf->copy()->subDay()->toDateString(),
            ], ['license_plate' => 'HH-AB1234'])
        )->assertSessionHasErrors('vehicle_overlap');
    }

    // Zweites Fahrzeug desselben Kunden -> parallel voellig okay, und die
    // Wechsel-Automatik fasst fremde Fahrzeuge natuerlich nicht an.
    public function test_second_car_is_allowed(): void
    {
        $customer = $this->makeCustomer();
        $alt = $this->existingContract($customer, [], ['license_plate' => 'B-AA 1']);

        $this->actingAs($this->admin())->post(
            route('admin.contract.store', $customer->id),
            $this->payload(['start_date' => now()->toDateString()], ['license_plate' => 'B-BB 2'])
        )->assertSessionHas('success');

        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
        $this->assertNull($alt->fresh()->cancellation_date);
    }

    // Verschiedene FIN = sicher verschiedene Fahrzeuge, auch wenn das
    // Kennzeichen (uebernommen/vertippt) gleich aussieht - kein Konflikt,
    // kein automatischer Eingriff in den Altvertrag.
    public function test_different_vin_beats_equal_plate(): void
    {
        $customer = $this->makeCustomer();
        $alt = $this->existingContract($customer, [], ['license_plate' => 'K-XY 77', 'vin' => 'WVWZZZAAA111']);

        $this->actingAs($this->admin())->post(
            route('admin.contract.store', $customer->id),
            $this->payload(['start_date' => now()->toDateString()], ['license_plate' => 'K-XY 77', 'vin' => 'WVWZZZBBB222'])
        )->assertSessionHas('success');

        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
        $this->assertNull($alt->fresh()->cancellation_date);
    }

    // HSN/TSN greift als letzte Stufe, wenn keine Seite FIN/Kennzeichen hat
    // (gleicher Versicherer -> Duplikat, abgelehnt).
    public function test_hsn_tsn_fallback_blocks_overlap(): void
    {
        $customer = $this->makeCustomer();
        $this->existingContract($customer, [], ['hsn' => '0603', 'tsn' => 'BJM']);

        $this->actingAs($this->admin())->post(
            route('admin.contract.store', $customer->id),
            $this->payload([
                'insurer' => 'ADAC Autoversicherung AG',
                'start_date' => now()->toDateString(),
            ], ['hsn' => '0603', 'tsn' => 'bjm'])
        )->assertSessionHasErrors('vehicle_overlap');
    }

    // Bearbeiten desselben Vertrags kollidiert nicht mit sich selbst.
    public function test_update_does_not_conflict_with_itself(): void
    {
        $customer = $this->makeCustomer();
        $contract = $this->existingContract($customer, [], ['license_plate' => 'M-QQ 5']);

        $this->actingAs($this->admin())->put(route('admin.contract.update', $contract->id), [
            'type' => 'kfz', 'insurer' => 'ADAC Autoversicherung AG', 'status' => 'active',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addMonths(2)->toDateString(),
            'cancellation_date' => now()->toDateString(),
            'vehicle' => ['license_plate' => 'M-QQ 5'],
        ])->assertRedirect(route('admin.customer', $customer->id))->assertSessionHas('success');
    }

    // Beendeter Altvertrag (wirksames Ende in der Vergangenheit) blockiert
    // einen anschliessenden Neuvertrag nicht.
    public function test_past_contract_does_not_block(): void
    {
        $customer = $this->makeCustomer();
        $this->existingContract($customer, [
            'status' => 'cancelled',
            'start_date' => now()->subYears(2)->toDateString(),
            'end_date' => now()->subMonths(6)->toDateString(),
            'cancellation_date' => now()->subMonths(8)->toDateString(),
        ], ['license_plate' => 'F-GG 9']);

        $this->actingAs($this->admin())->post(
            route('admin.contract.store', $customer->id),
            $this->payload(['start_date' => now()->subMonths(6)->toDateString()], ['license_plate' => 'F-GG 9'])
        )->assertSessionHas('success');

        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }
}
