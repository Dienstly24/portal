<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deckt die Vertrags-Verbesserungen ab (Betreiber-Feedback 13.07.2026):
 * alle Sparten anlegbar, Sonstige-Freitext, keine Auto-Nummer, Bearbeiten/
 * Löschen, Dokument-Zuordnung sowie die IBAN-Erfassung bei der Neuanlage.
 */
class ContractManagementTest extends TestCase
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
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    private function base(array $overrides = []): array
    {
        return array_merge([
            'type' => 'kfz',
            'insurer' => 'HUK-Coburg',
            'status' => 'active',
        ], $overrides);
    }

    // 1) Sparten, die früher am DB-Enum scheiterten, lassen sich jetzt anlegen.
    public function test_admin_can_create_contract_of_previously_blocked_type(): void
    {
        $customer = $this->makeCustomer();

        foreach (['haftpflicht', 'leben', 'unfall', 'hausrat', 'rechtsschutz'] as $type) {
            $this->actingAs($this->admin())
                ->post(route('admin.contract.store', $customer->id), $this->base(['type' => $type, 'insurer' => 'Allianz']))
                ->assertRedirect(route('admin.customer', $customer->id))
                ->assertSessionHas('success');

            $this->assertDatabaseHas('contracts', ['customer_id' => (string) $customer->id, 'type' => $type]);
        }
    }

    // 2) Sonstige speichert den Freitext; ohne Freitext schlägt die Validierung fehl.
    public function test_sonstige_requires_and_stores_free_text(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->base(['type' => 'andere']))
            ->assertSessionHasErrors('type_other');

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->base(['type' => 'andere', 'type_other' => 'ADAC Schutzbrief']))
            ->assertSessionHas('success');

        $contract = Contract::where('customer_id', $customer->id)->first();
        $this->assertSame('andere', $contract->type);
        $this->assertSame('ADAC Schutzbrief', $contract->type_other);
        $this->assertSame('ADAC Schutzbrief', $contract->typeLabel());
    }

    // 2b) Krankenzusatz: neue Sparte inkl. "Art der Krankenzusatz" (subtype).
    public function test_krankenzusatz_stores_art_subtype(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->base([
                'type' => 'krankenzusatz', 'insurer' => 'DKV', 'subtype' => 'zahnzusatz',
            ]))
            ->assertSessionHas('success');

        $contract = Contract::where('customer_id', $customer->id)->first();
        $this->assertSame('krankenzusatz', $contract->type);
        $this->assertSame('zahnzusatz', $contract->subtype);
        $this->assertSame('Zahnzusatzversicherung', $contract->subtypeLabel());
    }

    // 2c) Eine zur Sparte fremde Untergruppe wird verworfen (kein "gkv" am Zusatz).
    public function test_mismatched_subtype_is_dropped(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->base([
                'type' => 'krankenzusatz', 'insurer' => 'DKV', 'subtype' => 'gkv',
            ]))
            ->assertSessionHas('success');

        $this->assertNull(Contract::where('customer_id', $customer->id)->first()->subtype);
    }

    // 2d) Beitrag + Zahlweise werden gespeichert und korrekt normiert.
    public function test_premium_amount_and_interval_are_stored(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->base([
                'premium_amount' => '60', 'premium_interval' => 'semiannual',
            ]))
            ->assertSessionHas('success');

        $contract = Contract::where('customer_id', $customer->id)->first();
        $this->assertSame('60.00', (string) $contract->premium_amount);
        $this->assertSame('semiannual', $contract->premium_interval);
        // 60 EUR halbjaehrlich -> 10 EUR/Monat, 120 EUR/Jahr
        $this->assertSame(10.0, $contract->monthlyPremium());
        $this->assertSame(120.0, $contract->yearlyPremium());
    }

    // 2e) Ungueltige Zahlweise faellt auf "monthly" zurueck; leerer Betrag bleibt null.
    public function test_premium_defaults_are_safe(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->base([
                'premium_amount' => '', 'premium_interval' => 'unsinn',
            ]))
            ->assertSessionHasErrors('premium_interval');

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->base(['premium_amount' => '']))
            ->assertSessionHas('success');

        $contract = Contract::where('customer_id', $customer->id)->first();
        $this->assertNull($contract->premium_amount);
        $this->assertFalse($contract->hasPremium());
    }

    // 2f) Der Kunde sieht Beitrag + Kostenuebersicht im Portal.
    public function test_customer_sees_premium_and_cost_overview(): void
    {
        $customer = $this->makeCustomer();
        Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK',
            'status' => 'active', 'premium_amount' => 49.90, 'premium_interval' => 'monthly',
        ]);

        $html = $this->actingAs($customer->user)->get(route('portal.contracts'))->assertOk()->getContent();
        $this->assertStringContainsString('Kostenübersicht', $html);
        $this->assertStringContainsString('49,90', $html);
    }

    // 3) Keine automatische Nummer mehr - leer bleibt leer, Eingabe wird gespeichert.
    public function test_contract_number_is_not_autogenerated(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->base());
        $this->assertNull(Contract::where('customer_id', $customer->id)->first()->contract_number);

        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->base(['contract_number' => 'VS-4711']));
        $this->assertDatabaseHas('contracts', ['customer_id' => (string) $customer->id, 'contract_number' => 'VS-4711']);
    }

    // 4) KFZ-Detaildaten werden mitgespeichert.
    public function test_kfz_contract_stores_vehicle_details(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->base([
            'type' => 'kfz',
            'vehicle' => ['license_plate' => 'HH-AB 1234', 'manufacturer' => 'VW', 'model' => 'Golf'],
        ]))->assertSessionHas('success');

        $this->assertDatabaseHas('contract_vehicle_details', ['license_plate' => 'HH-AB 1234', 'manufacturer' => 'VW']);
    }

    // 5) Bearbeiten aktualisiert Felder; Typwechsel entfernt verwaiste Detaildaten.
    public function test_admin_can_edit_contract_and_type_switch_clears_details(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->base([
            'type' => 'kfz', 'vehicle' => ['license_plate' => 'HH-XY 9'],
        ]));
        $contract = Contract::where('customer_id', $customer->id)->first();
        $this->assertNotNull($contract->vehicleDetail);

        $this->actingAs($this->admin())->put(route('admin.contract.update', $contract->id), $this->base([
            'type' => 'leben', 'insurer' => 'Gothaer', 'status' => 'pending',
        ]))->assertRedirect(route('admin.customer', $customer->id));

        $contract->refresh();
        $this->assertSame('leben', $contract->type);
        $this->assertSame('Gothaer', $contract->insurer);
        $this->assertSame('pending', $contract->status);
        $this->assertNull($contract->fresh()->vehicleDetail); // KFZ-Details entfernt
    }

    // 6) Löschen entfernt den Vertrag, Dokumente bleiben (Zuordnung wird gelöst).
    public function test_admin_can_delete_contract_and_document_survives(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        $contract = Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        $doc = Document::create([
            'customer_id' => $customer->id, 'contract_id' => $contract->id,
            'category' => 'contract', 'file_name' => 'police.pdf', 'file_path' => 'x/police.pdf', 'disk' => 'local',
        ]);

        $this->actingAs($this->admin())->delete(route('admin.contract.destroy', $contract->id))
            ->assertRedirect(route('admin.customer', $customer->id));

        $this->assertDatabaseMissing('contracts', ['id' => (string) $contract->id]);
        $this->assertDatabaseHas('documents', ['id' => (string) $doc->id, 'contract_id' => null]);
    }

    // 7) Gemeldeter Haftpflicht-Vertrag (früher Enum-Fehler) lässt sich genehmigen.
    public function test_reported_haftpflicht_contract_can_be_approved(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.contracts.report'), [
            'type' => 'haftpflicht', 'insurer' => 'AXA',
        ])->assertSessionHas('success');

        $request = CustomerChangeRequest::where('type', 'contract')->firstOrFail();
        $this->actingAs($this->admin())->post(route('admin.change_requests.action', $request->id), ['action' => 'approve'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('contracts', [
            'customer_id' => (string) $customer->id, 'type' => 'haftpflicht', 'status' => 'pending',
        ]);
    }

    // 8) Zwei Meldungen ohne Nummer blockieren sich nicht mehr am Unique-Index.
    public function test_two_reported_contracts_without_number_can_both_be_approved(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->admin();

        foreach (['AXA', 'Allianz'] as $insurer) {
            $this->actingAs($customer->user)->post(route('portal.contracts.report'), ['type' => 'haftpflicht', 'insurer' => $insurer]);
        }
        foreach (CustomerChangeRequest::where('type', 'contract')->get() as $req) {
            $this->actingAs($admin)->post(route('admin.change_requests.action', $req->id), ['action' => 'approve'])
                ->assertSessionHas('success');
        }

        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
    }

    // 9) Dokument hochladen + einem Vertrag zuordnen, bearbeiten, löschen.
    public function test_document_assign_update_delete(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        $contract = Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.customer.document.store', $customer->id), [
            'documents' => [UploadedFile::fake()->create('police.pdf', 50, 'application/pdf')],
            'category' => 'police', 'visibility' => 'customer', 'color' => 'red', 'contract_id' => $contract->id,
        ])->assertSessionHas('success');

        $doc = Document::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame((string) $contract->id, (string) $doc->contract_id);
        $this->assertSame('red', $doc->color); // color ist jetzt fillable

        $this->actingAs($admin)->put(route('admin.documents.update', $doc->id), [
            'category' => 'invoice', 'visibility' => 'internal', 'color' => 'yellow', 'contract_id' => '',
        ])->assertSessionHas('success');
        $doc->refresh();
        $this->assertSame('internal', $doc->visibility);
        $this->assertSame('invoice', $doc->category);
        $this->assertNull($doc->contract_id);

        $this->actingAs($admin)->delete(route('admin.documents.destroy', $doc->id))->assertSessionHas('success');
        $this->assertDatabaseMissing('documents', ['id' => (string) $doc->id]);
    }

    // 10) Fremdzuordnung eines Dokuments zu einem fremden Vertrag wird verhindert.
    public function test_document_cannot_be_assigned_to_foreign_contract(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        $foreign = Contract::create(['customer_id' => $other->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);

        $this->actingAs($this->admin())->post(route('admin.customer.document.store', $customer->id), [
            'documents' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
            'contract_id' => $foreign->id,
        ])->assertSessionHas('success');

        $doc = Document::where('customer_id', $customer->id)->firstOrFail();
        $this->assertNull($doc->contract_id); // fremder Vertrag wird ignoriert
    }

    // 11) IBAN kann bereits bei der Neuanlage erfasst werden (verschlüsselt).
    public function test_customer_can_be_created_with_iban(): void
    {
        $this->actingAs($this->admin())->post(route('admin.customers.store'), [
            'first_name' => 'Max', 'last_name' => 'Mustermann',
            'email' => 'max.neu@example.test',
            'iban' => 'DE89370400440532013000', 'account_holder' => 'Max Mustermann',
        ])->assertSessionHas('success');

        $customer = Customer::whereHas('user', fn($q) => $q->where('email', 'max.neu@example.test'))->firstOrFail();
        $this->assertSame('DE89370400440532013000', $customer->iban);
        $this->assertSame('Max Mustermann', $customer->account_holder);
        // In der DB liegt der Wert verschlüsselt, nicht im Klartext.
        $this->assertNotSame('DE89370400440532013000', $customer->getRawOriginal('iban'));
    }

    // 12) SafeEncrypted: Alt-Klartext in der Bankspalte crasht die Anzeige nicht.
    public function test_safe_encrypted_tolerates_legacy_plaintext(): void
    {
        $customer = $this->makeCustomer();
        // Klartext direkt in die Spalte schreiben (wie Alt-Bestand vor der Verschlüsselung).
        \Illuminate\Support\Facades\DB::table('customers')->where('id', $customer->id)->update(['iban' => 'DE00PLAINTEXT']);

        $reloaded = Customer::find($customer->id);
        $this->assertSame('DE00PLAINTEXT', $reloaded->iban); // kein DecryptException
    }

    // 13) Neue/überarbeitete Seiten rendern fehlerfrei (Blade-Runtime-Check).
    public function test_contract_pages_render(): void
    {
        $customer = $this->makeCustomer();
        $contract = Contract::create(['customer_id' => $customer->id, 'type' => 'andere', 'type_other' => 'ADAC Schutzbrief', 'insurer' => 'ADAC', 'status' => 'active']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.contract.new'))->assertOk()->assertSee('Vertrag anlegen');
        $this->actingAs($admin)->get(route('admin.contract.create', $customer->id))->assertOk()->assertSee('Sparte');
        $this->actingAs($admin)->get(route('admin.contract.edit', $contract->id))->assertOk()->assertSee('ADAC Schutzbrief');
    }

    // 14) Kundenakte zeigt den neuen Dokumente-Tab und Vertrags-Aktionen.
    public function test_customer_show_has_documents_tab_and_actions(): void
    {
        $customer = $this->makeCustomer();
        Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);

        $this->actingAs($this->admin())->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('tab-dokumente')
            ->assertSee('Bearbeiten');
    }

    // 15) Strom und Gas sind getrennte Sparten; Energievertraege speichern
    //     Vertragsnummer (am Vertrag) UND Kundennummer (am Energie-Detail).
    public function test_strom_and_gas_are_separate_sparten_with_energy_numbers(): void
    {
        $customer = $this->makeCustomer();

        // Strom-Vertrag mit Vertrags- und Kundennummer + Zaehler.
        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->base([
            'type' => 'strom', 'insurer' => 'LichtBlick', 'contract_number' => 'V-STROM-1',
            'energy' => ['tariff' => 'ÖkoStrom 24', 'customer_number' => 'KD-777', 'meter_number' => 'Z-123', 'consumption_kwh' => 3200],
        ]))->assertSessionHas('success');

        $strom = Contract::where('customer_id', $customer->id)->where('type', 'strom')->firstOrFail();
        $this->assertSame('V-STROM-1', $strom->contract_number);
        $this->assertSame('KD-777', $strom->energyDetail->customer_number);
        $this->assertSame('Z-123', $strom->energyDetail->meter_number);
        $this->assertSame(3200, $strom->energyDetail->consumption_kwh);

        // Gas-Vertrag als eigene Sparte.
        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->base([
            'type' => 'gas', 'insurer' => 'EWE',
            'energy' => ['tariff' => 'EWE Gas basis', 'customer_number' => 'KD-888'],
        ]))->assertSessionHas('success');

        $gas = Contract::where('customer_id', $customer->id)->where('type', 'gas')->firstOrFail();
        $this->assertSame('gas', $gas->type);
        $this->assertSame('KD-888', $gas->energyDetail->customer_number);
        $this->assertTrue($gas->isEnergy());

        // Beide getrennt, kein "strom_gas" mehr.
        $this->assertSame(0, Contract::where('type', 'strom_gas')->count());
    }

    // 15b) Vorversorger (bisheriger Lieferant) wird am Energie-Detail gespeichert.
    public function test_energy_contract_stores_previous_provider(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->base([
            'type' => 'strom', 'insurer' => 'EWE VERTRIEB GmbH',
            'energy' => [
                'tariff' => 'EWE business Gruenstrom', 'meter_number' => '364-8646796',
                'previous_provider' => 'Stadtwerke Neuss Energie und Wasser GmbH',
                'previous_customer_number' => '20478172',
            ],
        ]))->assertSessionHasNoErrors();

        $strom = Contract::where('customer_id', $customer->id)->where('type', 'strom')->firstOrFail();
        $this->assertSame('Stadtwerke Neuss Energie und Wasser GmbH', $strom->energyDetail->previous_provider);
        $this->assertSame('20478172', $strom->energyDetail->previous_customer_number);
    }

    // 16) Typwechsel weg von Energie entfernt die Energie-Detaildaten.
    public function test_switching_away_from_energy_clears_energy_details(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->base([
            'type' => 'gas', 'insurer' => 'EWE', 'energy' => ['tariff' => 'EWE Gas basis'],
        ]));
        $contract = Contract::where('customer_id', $customer->id)->firstOrFail();
        $this->assertNotNull($contract->energyDetail);

        $this->actingAs($this->admin())->put(route('admin.contract.update', $contract->id), $this->base([
            'type' => 'kfz', 'insurer' => 'HUK',
        ]))->assertRedirect(route('admin.customer', $customer->id));

        $this->assertNull($contract->fresh()->energyDetail);
    }
}
