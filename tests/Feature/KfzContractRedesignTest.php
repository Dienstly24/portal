<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleMileageReading;
use App\Models\VehicleSfEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KFZ-Redesign (Betreiber-Auftrag 17.07.2026): strukturierte Fahrzeug- und
 * Vertragsdaten, hierarchische Deckung (Vollkasko nur mit Teilkasko),
 * Zusatzleistungen, Fahrerkreis, Kilometer-Historie inkl. Portal-Meldung,
 * Schaeden als eigene Tabelle und SF-Logik mit Verlauf, Sondereinstufung
 * und Uebertragbarkeit.
 */
class KfzContractRedesignTest extends TestCase
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

    /** Reichhaltiges Beispiel-Fahrzeug (wie ein Mitarbeiter es anlegt). */
    private function fullVehicle(array $overrides = []): array
    {
        return array_merge([
            'vehicle_type' => 'pkw',
            'license_plate' => 'HH-AB 1234',
            'manufacturer' => 'VW',
            'model' => 'Golf VIII',
            'vin' => 'WVWZZZAUZLW000001',
            'hsn' => '0603',
            'tsn' => 'bjm',
            'first_registration' => '2022-03-15',
            'acquisition_date' => '2024-06-01',
            'vehicle_condition' => 'gebrauchtwagen',
            'power_kw' => 110,
            'fuel_type' => 'benzin',
            'transmission' => 'automatik',
            'color' => 'schwarz',
            'has_teilkasko' => '1',
            'teilkasko_deductible' => 150,
            'has_vollkasko' => '1',
            'vollkasko_deductible' => 300,
            'extras' => ['schutzbrief', 'rabattschutz', 'marderbiss', 'gap_deckung'],
            'driver_groups' => ['versicherungsnehmer', 'ehepartner', 'weitere_fahrer'],
            'additional_drivers' => [
                ['name' => 'Lisa Muster', 'birth_date' => '1999-04-12', 'license_date' => '2018-05-20'],
                ['name' => '', 'birth_date' => '', 'license_date' => ''], // leere Zeile wird verworfen
            ],
            'holder_type' => 'abweichender_halter',
            'holder_name' => 'Max Muster sen.',
            'ownership_type' => 'leasing',
            'initial_mileage' => 45000,
            'current_mileage' => 52300,
            'current_mileage_date' => '2026-07-17',
            'annual_mileage' => 12000,
            'sf_liability_class' => '4',
            'sf_liability_valid_from' => '2026-04-02',
            'sf_liability_type' => 'sondereinstufung',
            'sf_liability_special_reason' => 'zweitwagen',
            'sf_liability_real_class' => '1/2',
            'sf_comprehensive_class' => '2',
            'sf_comprehensive_valid_from' => '2026-04-02',
            'sf_comprehensive_type' => 'tatsaechlich',
            'claim_rows' => [
                ['claim_date' => '2025-11-03', 'claim_type' => 'teilkasko', 'damage_amount' => '820.50', 'status' => 'reguliert', 'insurer' => 'HUK-Coburg', 'notes' => 'Steinschlag Frontscheibe'],
            ],
        ], $overrides);
    }

    // 1) Alle neuen Felder werden gespeichert (Fahrzeug, Deckung, Extras,
    //    Fahrer, Halter/Eigentum, km, SF inkl. Sondereinstufung).
    public function test_full_kfz_contract_stores_all_new_fields(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), $this->base(['vehicle' => $this->fullVehicle()]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.customer', $customer->id));

        $veh = Contract::where('customer_id', $customer->id)->firstOrFail()->vehicleDetail;
        $this->assertSame('pkw', $veh->vehicle_type);
        $this->assertSame('0603', $veh->hsn);
        $this->assertSame('BJM', $veh->tsn); // TSN wird normalisiert (Grossbuchstaben)
        $this->assertSame('gebrauchtwagen', $veh->vehicle_condition);
        $this->assertSame(110, (int) $veh->power_kw);
        $this->assertSame('benzin', $veh->fuel_type);
        $this->assertSame('automatik', $veh->transmission);
        $this->assertTrue($veh->has_teilkasko);
        $this->assertSame(150, (int) $veh->teilkasko_deductible);
        $this->assertTrue($veh->has_vollkasko);
        $this->assertSame(300, (int) $veh->vollkasko_deductible);
        $this->assertEqualsCanonicalizing(['schutzbrief', 'rabattschutz', 'gap_deckung', 'marderbiss'], $veh->extras);
        $this->assertTrue($veh->hasExtra('schutzbrief'));
        $this->assertEqualsCanonicalizing(['versicherungsnehmer', 'ehepartner', 'weitere_fahrer'], $veh->driver_groups);
        $this->assertCount(1, $veh->additional_drivers); // leere Zeile verworfen
        $this->assertSame('Lisa Muster', $veh->additional_drivers[0]['name']);
        $this->assertSame('abweichender_halter', $veh->holder_type);
        $this->assertSame('Max Muster sen.', $veh->holder_name);
        $this->assertSame('leasing', $veh->ownership_type);
        $this->assertSame(45000, (int) $veh->initial_mileage);
        $this->assertSame(12000, (int) $veh->annual_mileage);
        // SF Haftpflicht: Sondereinstufung -> nicht uebertragbar, tatsaechliche Klasse getrennt
        $this->assertSame('4', $veh->sf_liability_class);
        $this->assertSame('sondereinstufung', $veh->sf_liability_type);
        $this->assertSame('zweitwagen', $veh->sf_liability_special_reason);
        $this->assertSame('1/2', $veh->sf_liability_real_class);
        $this->assertFalse($veh->sfTransferable('haftpflicht'));
        // SF Vollkasko: tatsaechliche Klasse -> uebertragbar
        $this->assertSame('2', $veh->sf_comprehensive_class);
        $this->assertTrue($veh->sfTransferable('vollkasko'));
        // Deckungs-Kurztext fuer Listen/Cockpit
        $this->assertSame('Haftpflicht · Teilkasko (150 € SB) · Vollkasko (300 € SB)', $veh->coverageLabel());
    }

    // 1b) Vorversicherung (bisheriger Versicherer) wird gespeichert; ein leerer
    //     Kuendigungs-Radio bedeutet "unbekannt" (null).
    public function test_vorversicherung_fields_are_stored(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => $this->fullVehicle([
                'previous_insurer' => 'Generali',
                'previous_insurance_since' => 'länger als 3 Jahre',
                'previous_insurance_terminated_by_insurer' => '0',
            ]),
        ]))->assertSessionHasNoErrors();

        $veh = Contract::where('customer_id', $customer->id)->firstOrFail()->vehicleDetail;
        $this->assertSame('Generali', $veh->previous_insurer);
        $this->assertSame('länger als 3 Jahre', $veh->previous_insurance_since);
        $this->assertFalse($veh->previous_insurance_terminated_by_insurer);
        $this->assertNotNull($veh->previous_insurance_terminated_by_insurer);

        // Leerer Radio ("unbekannt") -> null, kein false.
        $contract = Contract::where('customer_id', $customer->id)->firstOrFail();
        $this->actingAs($this->admin())->put(route('admin.contract.update', $contract->id), $this->base([
            'vehicle' => $this->fullVehicle([
                'previous_insurer' => 'Generali',
                'previous_insurance_terminated_by_insurer' => '',
            ]),
        ]))->assertSessionHasNoErrors();
        $this->assertNull($contract->fresh()->vehicleDetail->previous_insurance_terminated_by_insurer);
    }

    // 2) Vollkasko ohne Teilkasko ist fachlich unmoeglich und wird abgeraeumt.
    public function test_vollkasko_requires_teilkasko(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => [
                'has_teilkasko' => '0',
                'has_vollkasko' => '1',
                'vollkasko_deductible' => 500,
                'sf_comprehensive_class' => '5',
            ],
        ]))->assertSessionHasNoErrors();

        $veh = Contract::where('customer_id', $customer->id)->firstOrFail()->vehicleDetail;
        $this->assertFalse($veh->has_teilkasko);
        $this->assertFalse($veh->has_vollkasko);
        $this->assertNull($veh->vollkasko_deductible);
        $this->assertNull($veh->sf_comprehensive_class); // keine VK-SF ohne Vollkasko
        $this->assertSame(0, $veh->sfHistory()->where('branch', 'vollkasko')->count());
    }

    // 3) Kataloge sind Whitelists: unbekannte Werte werden abgelehnt.
    public function test_invalid_catalog_values_are_rejected(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => ['extras' => ['gibt_es_nicht']],
        ]))->assertSessionHasErrors('vehicle.extras.0');

        $this->actingAs($admin)->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => ['annual_mileage' => 12345],
        ]))->assertSessionHasErrors('vehicle.annual_mileage');

        $this->actingAs($admin)->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => ['teilkasko_deductible' => 999],
        ]))->assertSessionHasErrors('vehicle.teilkasko_deductible');
    }

    // 4) Schaeden liegen in einer eigenen Tabelle und werden beim Bearbeiten ersetzt.
    public function test_claims_are_stored_and_replaced_on_update(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => $this->fullVehicle(),
        ]));
        $contract = Contract::where('customer_id', $customer->id)->firstOrFail();
        $veh = $contract->vehicleDetail;

        $this->assertSame(1, $veh->claims()->count());
        $claim = $veh->claims()->first();
        $this->assertSame('teilkasko', $claim->claim_type);
        $this->assertSame('reguliert', $claim->status);
        $this->assertSame('820.50', (string) $claim->damage_amount);

        // Bearbeiten: zwei Schaeden eingereicht (eine leere Zeile wird ignoriert).
        $this->actingAs($admin)->put(route('admin.contract.update', $contract->id), $this->base([
            'vehicle' => $this->fullVehicle(['claim_rows' => [
                ['claim_date' => '2025-11-03', 'claim_type' => 'teilkasko', 'damage_amount' => '820.50', 'status' => 'reguliert', 'insurer' => '', 'notes' => ''],
                ['claim_date' => '2026-05-20', 'claim_type' => 'haftpflicht', 'damage_amount' => '2400', 'status' => 'in_bearbeitung', 'insurer' => 'Allianz', 'notes' => 'Parkrempler'],
                ['claim_date' => '', 'claim_type' => '', 'damage_amount' => '', 'status' => '', 'insurer' => '', 'notes' => ''],
            ]]),
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, $veh->claims()->count());
        $this->assertDatabaseHas('vehicle_claims', ['claim_type' => 'haftpflicht', 'status' => 'in_bearbeitung', 'insurer' => 'Allianz']);
    }

    // 5) km-Historie: jede neue Meldung wird gespeichert, identische nicht doppelt.
    public function test_mileage_readings_accumulate_without_duplicates(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => $this->fullVehicle(),
        ]));
        $contract = Contract::where('customer_id', $customer->id)->firstOrFail();
        $veh = $contract->vehicleDetail;
        $this->assertSame(1, $veh->mileageReadings()->count());
        $this->assertSame(52300, $veh->latestMileageReading()->mileage);
        $this->assertSame('staff', $veh->latestMileageReading()->source);

        // Unveraenderter Stand -> keine zweite Zeile.
        $this->actingAs($admin)->put(route('admin.contract.update', $contract->id), $this->base([
            'vehicle' => $this->fullVehicle(),
        ]));
        $this->assertSame(1, $veh->mileageReadings()->count());

        // Neuer Stand -> Historie waechst.
        $this->actingAs($admin)->put(route('admin.contract.update', $contract->id), $this->base([
            'vehicle' => $this->fullVehicle(['current_mileage' => 55100, 'current_mileage_date' => '2026-09-01']),
        ]));
        $this->assertSame(2, $veh->mileageReadings()->count());
        $this->assertSame(55100, $veh->fresh()->latestMileageReading()->mileage);
    }

    // 6) SF-Verlauf: Klassenwechsel schliesst den alten Eintrag und legt einen neuen an.
    public function test_sf_history_is_appended_not_overwritten(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => $this->fullVehicle([
                'sf_liability_class' => '3', 'sf_liability_valid_from' => '2025-04-02',
                'sf_liability_type' => 'tatsaechlich', 'sf_liability_special_reason' => null, 'sf_liability_real_class' => null,
            ]),
        ]));
        $contract = Contract::where('customer_id', $customer->id)->firstOrFail();
        $veh = $contract->vehicleDetail;

        $this->assertSame(1, $veh->sfHistory()->where('branch', 'haftpflicht')->count());
        $this->assertSame(1, $veh->sfHistory()->where('branch', 'vollkasko')->count());

        // Hochstufung SF 3 -> SF 4 zum 02.04.2026.
        $this->actingAs($admin)->put(route('admin.contract.update', $contract->id), $this->base([
            'vehicle' => $this->fullVehicle([
                'sf_liability_class' => '4', 'sf_liability_valid_from' => '2026-04-02',
                'sf_liability_type' => 'tatsaechlich', 'sf_liability_special_reason' => null, 'sf_liability_real_class' => null,
            ]),
        ]))->assertSessionHasNoErrors();

        $history = $veh->sfHistory()->where('branch', 'haftpflicht')->orderBy('valid_from')->get();
        $this->assertCount(2, $history);
        $this->assertSame('3', $history[0]->sf_class);
        $this->assertSame('2026-04-01', $history[0]->valid_until->format('Y-m-d')); // Vortag der neuen Einstufung
        $this->assertSame('4', $history[1]->sf_class);
        $this->assertNull($history[1]->valid_until); // aktuell

        // Unveraenderte Klasse erzeugt KEINEN weiteren Eintrag.
        $this->actingAs($admin)->put(route('admin.contract.update', $contract->id), $this->base([
            'vehicle' => $this->fullVehicle([
                'sf_liability_class' => '4', 'sf_liability_valid_from' => '2026-04-02',
                'sf_liability_type' => 'tatsaechlich', 'sf_liability_special_reason' => null, 'sf_liability_real_class' => null,
            ]),
        ]));
        $this->assertSame(2, $veh->sfHistory()->where('branch', 'haftpflicht')->count());
    }

    // 7) Fahrleistungs-Hinweis: Hochrechnung ueber dem vereinbarten Limit warnt den Mitarbeiter.
    public function test_mileage_exceeded_warning_appears_in_backend(): void
    {
        $customer = $this->makeCustomer();
        $contract = Contract::create([
            'customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK',
            'status' => 'active', 'start_date' => now()->subYear()->toDateString(),
        ]);
        $veh = ContractVehicleDetail::create([
            'contract_id' => $contract->id, 'license_plate' => 'HH-XX 77',
            'initial_mileage' => 10000, 'annual_mileage' => 12000,
        ]);
        VehicleMileageReading::create([
            'contract_vehicle_detail_id' => $veh->id, 'mileage' => 40000,
            'reading_date' => now()->toDateString(), 'source' => 'customer',
        ]);

        $status = $veh->fresh()->mileageStatus();
        $this->assertTrue($status['exceeded']);
        $this->assertGreaterThan(12000, $status['projected']);

        $this->actingAs($this->admin())->get(route('admin.customer', $customer->id))
            ->assertOk()->assertSee('Limit überschritten');
        $this->actingAs($this->admin())->get(route('admin.contract.edit', $contract->id))
            ->assertOk()->assertSee('Fahrleistung überschritten');
    }

    // 8) Kunde meldet den Kilometerstand im Portal; Historie bleibt erhalten.
    public function test_customer_can_report_mileage_in_portal(): void
    {
        $customer = $this->makeCustomer();
        $contract = Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        $veh = ContractVehicleDetail::create(['contract_id' => $contract->id, 'initial_mileage' => 45000]);

        $this->actingAs($customer->user)
            ->post(route('portal.contracts.mileage', $contract->id), ['mileage' => 52300])
            ->assertSessionHas('success');

        $reading = $veh->mileageReadings()->first();
        $this->assertSame(52300, $reading->mileage);
        $this->assertSame('customer', $reading->source);

        // Kleinerer Wert als die letzte Meldung wird abgelehnt (Tippfehler-Schutz).
        $this->actingAs($customer->user)
            ->post(route('portal.contracts.mileage', $contract->id), ['mileage' => 50000])
            ->assertSessionHasErrors('mileage');
        $this->assertSame(1, $veh->mileageReadings()->count());

        // Hoeherer Wert ergaenzt die Historie.
        $this->actingAs($customer->user)
            ->post(route('portal.contracts.mileage', $contract->id), ['mileage' => 53400])
            ->assertSessionHas('success');
        $this->assertSame(2, $veh->mileageReadings()->count());
    }

    // 9) Fremde Kunden koennen keine km fuer fremde Vertraege melden.
    public function test_customer_cannot_report_mileage_for_foreign_contract(): void
    {
        $owner = $this->makeCustomer();
        $other = $this->makeCustomer();
        $contract = Contract::create(['customer_id' => $owner->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        ContractVehicleDetail::create(['contract_id' => $contract->id]);

        $this->actingAs($other->user)
            ->post(route('portal.contracts.mileage', $contract->id), ['mileage' => 60000])
            ->assertNotFound();
        $this->assertSame(0, VehicleMileageReading::count());
    }

    // 9b) Eigene Fahrleistung: Freifeld deckt Sonderwerte (18.500 km) ab,
    //     Pflicht bei gewaehltem "custom"-Chip, Buttons funktionieren weiter.
    public function test_custom_annual_mileage_is_supported(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->admin();

        // custom + Freifeld -> krummer Wert wird gespeichert.
        $this->actingAs($admin)->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => $this->fullVehicle(['annual_mileage' => 'custom', 'annual_mileage_custom' => 18500]),
        ]))->assertSessionHasNoErrors();

        $contract = Contract::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(18500, (int) $contract->vehicleDetail->annual_mileage);

        // Bearbeiten-Seite waehlt den Chip vor und fuellt das Freifeld.
        $this->actingAs($admin)->get(route('admin.contract.edit', $contract->id))
            ->assertOk()->assertSee('Eigene Fahrleistung')->assertSee('value="18500"', false);

        // custom ohne Wert -> deutscher Validierungsfehler.
        $this->actingAs($admin)->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => ['annual_mileage' => 'custom'],
        ]))->assertSessionHasErrors(['vehicle.annual_mileage_custom']);

        // Freier Wert ausserhalb der Grenzen wird abgelehnt.
        $this->actingAs($admin)->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => ['annual_mileage' => 'custom', 'annual_mileage_custom' => 500],
        ]))->assertSessionHasErrors(['vehicle.annual_mileage_custom']);
    }

    // 9c) Ablauf-Automatik + Heute-Button sind im Formular verdrahtet
    //     (Berechnung selbst laeuft im Browser, hier: Elemente + Modus-Ableitung).
    public function test_contract_form_has_end_date_automation_and_today_button(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->admin();

        $html = $this->actingAs($admin)->get(route('admin.contract.create', $customer->id))->assertOk()->getContent();
        foreach (['Heute', 'Laufzeit 12 Monate', 'Ende des Kalenderjahres (31.12.)', 'contractEndSync', 'contractSetToday'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        // Neuanlage: "12 Monate" ist vorgewaehlt (haeufigster Fall).
        $this->assertMatchesRegularExpression('/name="end_mode" value="plus12" checked/', $html);

        // Bestand 17.07.2026 - 31.12.2026 -> Modus "Ende des Kalenderjahres" wird erkannt.
        $yearEnd = Contract::create([
            'customer_id' => $customer->id, 'type' => 'haftpflicht', 'insurer' => 'AXA',
            'status' => 'active', 'start_date' => '2026-07-17', 'end_date' => '2026-12-31',
        ]);
        $this->assertMatchesRegularExpression('/name="end_mode" value="year_end" checked/',
            $this->actingAs($admin)->get(route('admin.contract.edit', $yearEnd->id))->assertOk()->getContent());

        // Bestand 17.07.2026 - 17.07.2027 -> Modus "12 Monate" wird erkannt.
        $plus12 = Contract::create([
            'customer_id' => $customer->id, 'type' => 'haftpflicht', 'insurer' => 'AXA',
            'status' => 'active', 'start_date' => '2026-07-17', 'end_date' => '2027-07-17',
        ]);
        $this->assertMatchesRegularExpression('/name="end_mode" value="plus12" checked/',
            $this->actingAs($admin)->get(route('admin.contract.edit', $plus12->id))->assertOk()->getContent());

        // Abweichender Ablauf -> "Manuell".
        $manual = Contract::create([
            'customer_id' => $customer->id, 'type' => 'haftpflicht', 'insurer' => 'AXA',
            'status' => 'active', 'start_date' => '2026-07-17', 'end_date' => '2026-10-01',
        ]);
        $this->assertMatchesRegularExpression('/name="end_mode" value="manual" checked/',
            $this->actingAs($admin)->get(route('admin.contract.edit', $manual->id))->assertOk()->getContent());
    }

    // 10) Seiten rendern mit vollem KFZ-Datensatz (Formular, Cockpit, Kundenakte, Portal).
    public function test_kfz_pages_render_with_full_dataset(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.contract.store', $customer->id), $this->base([
            'vehicle' => $this->fullVehicle(),
        ]));
        $contract = Contract::where('customer_id', $customer->id)->firstOrFail();

        // Bearbeiten-Seite: Cockpit + Button-Formular
        $html = $this->actingAs($admin)->get(route('admin.contract.edit', $contract->id))->assertOk()->getContent();
        foreach (['Schutzbrief', 'Zusatzleistungen', 'Schadenfreiheitsklasse', 'SF-Verlauf', 'Selbstbeteiligung Teilkasko', 'Nicht übertragbar (Sondereinstufung)', 'HH-AB 1234'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        // Neuanlage-Seiten rendern das Button-Formular
        $this->actingAs($admin)->get(route('admin.contract.create', $customer->id))->assertOk()->assertSee('Fahrzeugtyp');
        $this->actingAs($admin)->get(route('admin.contract.new'))->assertOk()->assertSee('Versicherungsschutz');

        // Kundenakte zeigt Deckung + Zusatzleistungen in der Vertragszeile
        $this->actingAs($admin)->get(route('admin.customer', $customer->id))
            ->assertOk()->assertSee('Teilkasko (150 € SB)')->assertSee('Schutzbrief');

        // Kundenportal: Deckung, Zusatzleistungen, km-Meldung
        $html = $this->actingAs($customer->user)->get(route('portal.contracts.show', $contract->id))->assertOk()->getContent();
        foreach (['Versicherungsschutz', 'Schutzbrief', 'Kilometerstand', 'Aktuellen Kilometerstand melden'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }
}
