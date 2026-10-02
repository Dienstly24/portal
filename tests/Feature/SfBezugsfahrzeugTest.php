<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractRevision;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\InternalNotification;
use App\Models\Provision;
use App\Models\ProvisionRate;
use App\Models\SystemSetting;
use App\Models\Task;
use App\Models\User;
use App\Models\VehicleSfEntry;
use App\Models\VehicleSfReference;
use App\Services\Family\FamilyRelationService;
use App\Services\Kfz\SfReferenceValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SF-SONDEREINSTUFUNG MIT BEZUGSFAHRZEUG (Betreiber-Auftrag 01.10.2026,
 * KFZ-Modul Phase 1).
 *
 * Ausgangsfehler: der Erstwagen einer Zweitwagenregelung stand
 * zweckentfremdet in der Vorversicherung ("Zweite Wagen ADAC",
 * "AD-5406305005"). Die beiden Vertraege waren nicht verknuepft, nichts
 * liess sich auswerten oder pruefen. Jeder Fall hier scheitert ohne die
 * Aenderung - vorher gab es weder Tabelle noch Formularweg noch Anzeige.
 */
class SfBezugsfahrzeugTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name = 'Erika Muster'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => 'k-'.uniqid().'@kunde.de']);
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'K-'.uniqid()]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kfz(Customer $kunde, array $attr = [], array $veh = []): Contract
    {
        $c = Contract::create(array_merge([
            'customer_id' => $kunde->id, 'type' => 'kfz', 'insurer' => 'ADAC',
            'contract_number' => 'AD-5406305005', 'status' => 'active',
            'start_date' => now()->subYear()->toDateString(),
        ], $attr));
        ContractVehicleDetail::create(array_merge([
            'contract_id' => $c->id, 'license_plate' => 'HH-AB 1',
            'sf_liability_class' => '5', 'sf_liability_type' => 'tatsaechlich',
        ], $veh));
        return $c->fresh('vehicleDetail');
    }

    /** Formularwerte eines Zweitwagens mit Sondereinstufung SF 4. */
    private function zweitwagenPayload(array $sfRef = [], array $extra = [], array $vehicle = []): array
    {
        return array_merge([
            'type' => 'kfz', 'insurer' => 'Neodigital', 'contract_number' => '1467-9911-8390-11',
            'status' => 'active', 'origin' => 'brokered', 'start_date' => '2026-10-01',
            'vehicle' => array_merge([
                'license_plate' => 'HH-ZW 2',
                'sf_liability_class' => '4', 'sf_liability_valid_from' => '2026-10-01',
                'sf_liability_type' => 'sondereinstufung', 'sf_liability_special_reason' => 'zweitwagen',
                'sf_liability_real_class' => '1/2',
                'sf_ref' => ['haftpflicht' => $sfRef],
            ], $vehicle),
        ], $extra);
    }

    private function neuerVertrag(Customer $kunde): Contract
    {
        return Contract::where('customer_id', $kunde->id)->where('insurer', 'Neodigital')->firstOrFail();
    }

    // ---------------------------------------------------------------
    // 1. Formular: Bezug im Bestand waehlen
    // ---------------------------------------------------------------

    public function test_erstwagen_aus_dem_bestand_wird_verknuepft_mit_snapshot_verlauf_und_protokoll(): void
    {
        $kunde = $this->kunde();
        $erst = $this->kfz($kunde);

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload([
                'reference_type' => 'internal', 'reference_contract_id' => $erst->id, 'holder_relation' => 'kunde',
            ]))->assertSessionHasNoErrors();

        $zweit = $this->neuerVertrag($kunde);
        $ref = $zweit->vehicleDetail->sfReference('haftpflicht');
        $this->assertNotNull($ref);
        $this->assertSame('internal', $ref->reference_type);
        $this->assertSame($erst->id, $ref->reference_contract_id);
        $this->assertSame('ADAC AD-5406305005', $ref->reference_label);
        $this->assertSame('5', $ref->snapshot_sf_class);
        $this->assertSame('2026-10-01', $ref->snapshot_date->toDateString());

        $entry = VehicleSfEntry::where('contract_vehicle_detail_id', $zweit->vehicleDetail->id)->where('branch', 'haftpflicht')->firstOrFail();
        $this->assertSame('zweitwagen', $entry->special_reason);
        $this->assertSame('ADAC AD-5406305005', $entry->reference_label);
        $this->assertSame($erst->id, $entry->reference_contract_id);

        $this->assertTrue(ActivityLog::where('action', 'sf_reference_changed')->where('entity_id', $zweit->id)->exists());
        $this->assertTrue(ContractRevision::where('contract_id', $zweit->id)->where('field', 'sf_reference_haftpflicht')->exists());
    }

    public function test_anzeige_in_beide_richtungen(): void
    {
        $kunde = $this->kunde();
        $erst = $this->kfz($kunde);
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload([
            'reference_type' => 'internal', 'reference_contract_id' => $erst->id,
        ]))->assertSessionHasNoErrors();
        $zweit = $this->neuerVertrag($kunde);

        // Vertragszeile des Kunden: Grund + Bezug, verlinkt auf den Erstwagen
        $html = $this->actingAs($admin)->get(route('admin.customer', $kunde->id))->assertOk()->getContent();
        $this->assertStringContainsString('Zweitwagen zu ADAC AD-5406305005 (SF 5)', $html);
        $this->assertStringContainsString(route('admin.contract.edit', $erst->id), $html);
        $this->assertStringContainsString('Erstwagen für:', $html);

        // Erstwagen: "Erstwagen fuer Neodigital ..."
        $this->actingAs($admin)->get(route('admin.contract.edit', $erst->id))->assertOk()
            ->assertSee('Erstwagen für:')->assertSee('Neodigital 1467-9911-8390-11')
            ->assertSee(route('admin.contract.edit', $zweit->id), false);

        // Zweitwagen: SF-Verlauf mit Grund / Bezug
        $this->actingAs($admin)->get(route('admin.contract.edit', $zweit->id))->assertOk()
            ->assertSee('Grund / Bezug')->assertSee('Zweitwagenregelung · zu ADAC AD-5406305005');
    }

    // ---------------------------------------------------------------
    // 2. Selbst-, Kreis- und Fremdbezug
    // ---------------------------------------------------------------

    public function test_selbstbezug_wird_abgelehnt(): void
    {
        $kunde = $this->kunde();
        $c = $this->kfz($kunde, ['insurer' => 'Neodigital', 'contract_number' => '1467']);

        $this->actingAs($this->admin())
            ->put(route('admin.contract.update', $c->id), $this->zweitwagenPayload(['reference_type' => 'internal', 'reference_contract_id' => $c->id]))
            ->assertSessionHasErrors('vehicle.sf_ref');
        $this->assertSame(0, VehicleSfReference::count());
    }

    public function test_kreisbezug_wird_abgelehnt(): void
    {
        $kunde = $this->kunde();
        $a = $this->kfz($kunde, ['insurer' => 'ADAC', 'contract_number' => 'A-1']);
        $b = $this->kfz($kunde, ['insurer' => 'HUK', 'contract_number' => 'B-2']);
        $admin = $this->admin();

        // B stuetzt sich auf A
        $this->actingAs($admin)->put(route('admin.contract.update', $b->id), $this->zweitwagenPayload(
            ['reference_type' => 'internal', 'reference_contract_id' => $a->id], ['insurer' => 'HUK', 'contract_number' => 'B-2']
        ))->assertSessionHasNoErrors();

        // A darf sich nun nicht auf B stuetzen (A -> B -> A)
        $this->actingAs($admin)->put(route('admin.contract.update', $a->id), $this->zweitwagenPayload(
            ['reference_type' => 'internal', 'reference_contract_id' => $b->id], ['insurer' => 'ADAC', 'contract_number' => 'A-1']
        ))->assertSessionHasErrors('vehicle.sf_ref');
        $this->assertNull($a->fresh()->vehicleDetail->sfReference('haftpflicht'));
    }

    public function test_fremder_kunde_nein_familienmitglied_ja(): void
    {
        $kunde = $this->kunde();
        $fremd = $this->kfz($this->kunde('Fremde Person'), ['contract_number' => 'X-1']);
        $partner = $this->kunde('Max Muster');
        $partnerVertrag = $this->kfz($partner, ['contract_number' => 'P-1']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload(
            ['reference_type' => 'internal', 'reference_contract_id' => $fremd->id]
        ))->assertSessionHasErrors('vehicle.sf_ref');
        $this->assertSame(0, Contract::where('insurer', 'Neodigital')->count(), 'nichts halb gespeichert');

        app(FamilyRelationService::class)->link($kunde, $partner, 'ehepartner', $admin->id);
        $this->actingAs($admin)->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload(
            ['reference_type' => 'internal', 'reference_contract_id' => $partnerVertrag->id, 'holder_relation' => 'partner', 'holder_name' => 'Max Muster']
        ))->assertSessionHasNoErrors();
        $ref = $this->neuerVertrag($kunde)->vehicleDetail->sfReference('haftpflicht');
        $this->assertSame($partnerVertrag->id, $ref->reference_contract_id);
        $this->assertSame('partner', $ref->holder_relation);
        $this->assertSame('Max Muster', $ref->holder_name);
    }

    // ---------------------------------------------------------------
    // 3. Erstwagen bei einem anderen Versicherer
    // ---------------------------------------------------------------

    public function test_externer_erstwagen_wird_als_fremdvertrag_angelegt_ohne_courtage_und_eigenbestand(): void
    {
        $werber = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        ProvisionRate::create(['user_id' => $werber->id, 'contract_type' => 'kfz', 'amount_fixed' => 50, 'amount_percent' => 10]);
        $kunde = $this->kunde();
        $kunde->update(['acquired_by' => $werber->id]);
        $admin = $this->admin();

        $payload = $this->zweitwagenPayload([
            'reference_type' => 'external', 'ext_insurer' => 'ADAC', 'ext_contract_number' => 'AD-5406305005',
            'ext_license_plate' => 'hh-ad 5', 'ext_sf_class' => '5', 'create_external' => '1',
        ]);
        $this->actingAs($admin)->post(route('admin.contract.store', $kunde->id), $payload)
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($msg) => str_contains($msg, 'als Fremdvertrag im Kundenbestand angelegt'));

        $erst = Contract::where('customer_id', $kunde->id)->where('insurer', 'ADAC')->firstOrFail();
        $this->assertSame(Contract::ORIGIN_EXTERNAL, $erst->origin);
        $this->assertSame('active', $erst->status);
        $this->assertFalse($erst->origin_verified);
        $this->assertFalse($erst->isOwnPortfolio());
        $this->assertSame(0, Contract::where('id', $erst->id)->ownPortfolio()->count(), 'zaehlt nie zum Eigenbestand');
        $this->assertSame('5', $erst->vehicleDetail->sf_liability_class);
        $this->assertSame('HH-AD 5', $erst->vehicleDetail->license_plate);

        // Nur der vermittelte Zweitwagen bucht eine Provision, der Fremdvertrag nie.
        $this->assertSame(1, Provision::count());
        $this->assertSame($this->neuerVertrag($kunde)->id, Provision::first()->contract_id);

        $ref = $this->neuerVertrag($kunde)->vehicleDetail->sfReference('haftpflicht');
        $this->assertSame('internal', $ref->reference_type);
        $this->assertSame($erst->id, $ref->reference_contract_id);
        $this->assertSame('5', $ref->snapshot_sf_class);

        // Erneutes Speichern mit denselben Angaben legt keinen zweiten an.
        $zweit = $this->neuerVertrag($kunde);
        $this->actingAs($admin)->put(route('admin.contract.update', $zweit->id), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Contract::where('customer_id', $kunde->id)->where('insurer', 'ADAC')->count());
    }

    public function test_externer_erstwagen_ohne_anlage_bleibt_externer_bezug(): void
    {
        $kunde = $this->kunde();
        $this->actingAs($this->admin())->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload([
            'reference_type' => 'external', 'ext_insurer' => 'ADAC', 'ext_contract_number' => 'AD-5406305005', 'ext_sf_class' => '5',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Contract::where('customer_id', $kunde->id)->count(), 'kein Fremdvertrag ohne Haken');
        $v = $this->neuerVertrag($kunde)->vehicleDetail;
        $ref = $v->sfReference('haftpflicht');
        $this->assertSame('external', $ref->reference_type);
        $this->assertSame('Zweitwagen zu ADAC AD-5406305005 (SF 5)', $ref->summary('zweitwagen'));
        $this->assertSame('5', $ref->snapshot_sf_class);
    }

    public function test_vollkasko_uebernimmt_bezug_der_haftpflicht(): void
    {
        $kunde = $this->kunde();
        $erst = $this->kfz($kunde, [], ['has_teilkasko' => true, 'has_vollkasko' => true, 'sf_comprehensive_class' => '3', 'sf_comprehensive_type' => 'tatsaechlich']);

        $payload = $this->zweitwagenPayload(
            ['reference_type' => 'internal', 'reference_contract_id' => $erst->id],
            [],
            [
                'has_teilkasko' => '1', 'has_vollkasko' => '1',
                'sf_comprehensive_class' => '2', 'sf_comprehensive_type' => 'sondereinstufung', 'sf_comprehensive_special_reason' => 'zweitwagen',
            ]
        );
        $payload['vehicle']['sf_ref']['vollkasko'] = ['copy_from_liability' => '1'];
        $this->actingAs($this->admin())->post(route('admin.contract.store', $kunde->id), $payload)->assertSessionHasNoErrors();

        $vk = $this->neuerVertrag($kunde)->vehicleDetail->sfReference('vollkasko');
        $this->assertSame($erst->id, $vk->reference_contract_id);
        $this->assertSame('3', $vk->snapshot_sf_class, 'Snapshot aus der VOLLKASKO-SF des Erstwagens');
    }

    // ---------------------------------------------------------------
    // 4. Gruende ohne Bezug, Wegfall, Pruefvermerk
    // ---------------------------------------------------------------

    public function test_uebrige_gruende_speichern_nur_ihre_angaben_und_wegfall_raeumt_auf(): void
    {
        $kunde = $this->kunde();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload(
            ['campaign_name' => 'Herbstaktion', 'license_date' => '2020-01-01', 'reference_type' => 'external', 'ext_insurer' => 'ADAC'],
            [], ['sf_liability_special_reason' => 'sonderaktion']
        ))->assertSessionHasNoErrors();
        $zweit = $this->neuerVertrag($kunde);
        $ref = $zweit->vehicleDetail->sfReference('haftpflicht');
        $this->assertSame('Herbstaktion', $ref->campaign_name);
        $this->assertNull($ref->license_date, 'Fuehrerschein gehoert nicht zur Sonderaktion');
        $this->assertNull($ref->reference_type, 'Sonderaktion hat kein Bezugsfahrzeug');

        // Zurueck auf tatsaechliche Klasse: die Begruendung faellt weg - protokolliert.
        $payload = $this->zweitwagenPayload([], [], ['sf_liability_type' => 'tatsaechlich']);
        $this->actingAs($admin)->put(route('admin.contract.update', $zweit->id), $payload)->assertSessionHasNoErrors();
        $this->assertSame(0, VehicleSfReference::count());
        $this->assertTrue(ContractRevision::where('contract_id', $zweit->id)->where('new_value', 'entfernt')->exists());
    }

    public function test_pruefvermerk_nur_durch_admin_oder_manager(): void
    {
        $kunde = $this->kunde();
        $erst = $this->kfz($kunde);
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => true]);
        $refInput = ['reference_type' => 'internal', 'reference_contract_id' => $erst->id, 'verified' => '1'];

        $this->actingAs($mitarbeiter)->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload($refInput))->assertSessionHasNoErrors();
        $zweit = $this->neuerVertrag($kunde);
        $this->assertFalse($zweit->vehicleDetail->sfReference('haftpflicht')->verified);

        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->put(route('admin.contract.update', $zweit->id), $this->zweitwagenPayload($refInput))->assertSessionHasNoErrors();
        $ref = $zweit->fresh()->vehicleDetail->sfReference('haftpflicht');
        $this->assertTrue($ref->verified);
        $this->assertSame($manager->id, $ref->verified_by);

        // Speichert danach ein Mitarbeiter (ohne Haken), bleibt der Vermerk stehen.
        $this->actingAs($mitarbeiter)->put(route('admin.contract.update', $zweit->id), $this->zweitwagenPayload(['reference_type' => 'internal', 'reference_contract_id' => $erst->id]))->assertSessionHasNoErrors();
        $this->assertTrue($zweit->fresh()->vehicleDetail->sfReference('haftpflicht')->verified);
    }

    // ---------------------------------------------------------------
    // 5. Warnungen und Pflicht
    // ---------------------------------------------------------------

    public function test_warnungen_fehlender_und_inaktiver_bezug_auf_beiden_vertraegen(): void
    {
        $kunde = $this->kunde();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload([]))->assertSessionHasNoErrors();
        $zweit = $this->neuerVertrag($kunde);
        $this->actingAs($admin)->get(route('admin.contract.edit', $zweit->id))->assertSee('ohne Bezugsfahrzeug');

        $erst = $this->kfz($kunde);
        $this->actingAs($admin)->put(route('admin.contract.update', $zweit->id), $this->zweitwagenPayload(['reference_type' => 'internal', 'reference_contract_id' => $erst->id]))->assertSessionHasNoErrors();
        $erst->update(['status' => 'cancelled', 'cancellation_date' => now()->subDay()->toDateString()]);

        $w = app(SfReferenceValidator::class)->warnings($zweit->fresh());
        $this->assertTrue(collect($w)->contains(fn ($t) => str_contains($t, 'ist nicht mehr aktiv')));
        $this->assertTrue(collect(app(SfReferenceValidator::class)->warnings($erst->fresh()))->contains(fn ($t) => str_contains($t, 'Erstwagen für Neodigital')));
    }

    public function test_halter_eines_anderen_kunden_ohne_beziehung_warnt(): void
    {
        $kunde = $this->kunde();
        $partner = $this->kunde('Max Muster');
        app(FamilyRelationService::class)->link($kunde, $partner, 'ehepartner');
        $erst = $this->kfz($partner);
        $this->actingAs($this->admin())->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload(['reference_type' => 'internal', 'reference_contract_id' => $erst->id]))->assertSessionHasNoErrors();

        $w = app(SfReferenceValidator::class)->warnings($this->neuerVertrag($kunde));
        $this->assertTrue(collect($w)->contains(fn ($t) => str_contains($t, 'gehört einem anderen Kunden')));
    }

    public function test_bezugspflicht_blockiert_nur_mit_einstellung_und_nur_antrag_oder_vertrag(): void
    {
        $kunde = $this->kunde();
        $admin = $this->admin();

        // Standard AUS: speichern geht, nur Warnung.
        $this->actingAs($admin)->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload([], ['stage' => 'antrag']))->assertSessionHasNoErrors();

        SystemSetting::set(SfReferenceValidator::SETTING_REQUIRED, '1');
        $this->actingAs($admin)->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload([], ['stage' => 'antrag', 'insurer' => 'HUK', 'contract_number' => 'HUK-1']))
            ->assertSessionHasErrors('vehicle.sf_ref.haftpflicht');
        $this->assertSame(0, Contract::where('insurer', 'HUK')->count());

        // Ohne Stufe (noch in Arbeit) bleibt es eine Warnung.
        $this->actingAs($admin)->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload([], ['insurer' => 'HUK', 'contract_number' => 'HUK-1']))->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------------
    // 6. Benachrichtigungen am Erstwagen
    // ---------------------------------------------------------------

    private function verknuepftesPaar(): array
    {
        $kunde = $this->kunde();
        $betreuer = User::factory()->create(['role' => 'employee']);
        $kunde->betreuer()->attach($betreuer->id, ['is_primary' => true]);
        $erst = $this->kfz($kunde);
        $this->actingAs($this->admin())->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload(['reference_type' => 'internal', 'reference_contract_id' => $erst->id]))->assertSessionHasNoErrors();
        return [$erst->fresh('vehicleDetail'), $this->neuerVertrag($kunde), $betreuer];
    }

    public function test_hoeherstufung_meldet_nichts_rueckstufung_meldet_einmal(): void
    {
        [$erst, $zweit, $betreuer] = $this->verknuepftesPaar();

        $erst->vehicleDetail->update(['sf_liability_class' => '6']); // jaehrliche Hoeherstufung
        $this->assertSame(0, InternalNotification::where('user_id', $betreuer->id)->count());
        $this->assertSame(0, Task::where('contract_id', $zweit->id)->count());

        $erst->vehicleDetail->fresh()->update(['sf_liability_class' => '2']); // Rueckstufung
        $this->assertSame(1, InternalNotification::where('user_id', $betreuer->id)->count());
        $task = Task::where('contract_id', $zweit->id)->sole();
        $this->assertSame($betreuer->id, $task->assigned_to);
        $this->assertStringContainsString('SF 6 → SF 2', $task->description);

        // Erneute Rueckstufung: Glocke wird aufgefrischt/ergaenzt, aber KEINE zweite offene Aufgabe.
        $erst->vehicleDetail->fresh()->update(['sf_liability_class' => '1']);
        $this->assertSame(1, Task::where('contract_id', $zweit->id)->count());
    }

    public function test_kuendigung_und_loeschung_des_erstwagens_werden_gemeldet(): void
    {
        [$erst, $zweit, $betreuer] = $this->verknuepftesPaar();

        $erst->update(['cancellation_date' => now()->toDateString()]);
        $this->assertTrue(InternalNotification::where('user_id', $betreuer->id)->where('dedup_key', 'like', 'sf-erstwagen-nicht_mehr_aktiv-%')->exists());

        $erst->fresh()->delete();
        $this->assertTrue(InternalNotification::where('user_id', $betreuer->id)->where('dedup_key', 'like', 'sf-erstwagen-geloescht-%')->exists());

        // Der Bezug ueberlebt das Loeschen als Text.
        $ref = $zweit->fresh()->vehicleDetail->sfReference('haftpflicht');
        $this->assertNull($ref->reference_contract_id);
        $this->assertSame('ADAC AD-5406305005', $ref->reference_label);
        $this->assertTrue(collect(app(SfReferenceValidator::class)->warnings($zweit->fresh()))->contains(fn ($t) => str_contains($t, 'wurde gelöscht')));
    }

    // ---------------------------------------------------------------
    // 7. Suche
    // ---------------------------------------------------------------

    public function test_suche_liefert_kunde_und_familie_nicht_fremde_und_nicht_sich_selbst(): void
    {
        $kunde = $this->kunde();
        $eigen = $this->kfz($kunde, ['contract_number' => 'E-1']);
        $selbst = $this->kfz($kunde, ['insurer' => 'Neodigital', 'contract_number' => 'N-1']);
        $partner = $this->kunde('Max Muster');
        app(FamilyRelationService::class)->link($kunde, $partner, 'ehepartner');
        $fam = $this->kfz($partner, ['insurer' => 'HUK', 'contract_number' => 'F-1']);
        $this->kfz($this->kunde('Fremd'), ['insurer' => 'Allianz', 'contract_number' => 'X-1']);
        Contract::create(['customer_id' => $kunde->id, 'type' => 'hausrat', 'insurer' => 'Hausrat AG', 'status' => 'active']);

        $json = $this->actingAs($this->admin())
            ->getJson(route('admin.contract.sf_reference_search', $kunde->id).'?exclude='.$selbst->id)
            ->assertOk()->json('results');
        $ids = collect($json)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$eigen->id, $fam->id], $ids);
        $this->assertSame('Max Muster', collect($json)->firstWhere('id', $fam->id)['owner']);
        $this->assertSame('SF 5', collect($json)->firstWhere('id', $eigen->id)['sf_hp']);
    }

    public function test_suche_ohne_zugriff_auf_den_kunden_ist_verboten(): void
    {
        $kunde = $this->kunde();
        $fremderMitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $this->actingAs($fremderMitarbeiter)->getJson(route('admin.contract.sf_reference_search', $kunde->id))->assertForbidden();
    }

    // ---------------------------------------------------------------
    // 8. Vorversicherung
    // ---------------------------------------------------------------

    public function test_keine_vorversicherung_leert_die_felder_und_zweitwagen_text_erzeugt_hinweis(): void
    {
        $kunde = $this->kunde();
        $admin = $this->admin();
        $this->kfz($kunde); // ADAC AD-5406305005

        $this->actingAs($admin)->post(route('admin.contract.store', $kunde->id), $this->zweitwagenPayload([], [], [
            'previous_insurer' => 'Zweite Wagen ADAC', 'previous_contract_number' => 'AD-5406305005',
        ]))->assertSessionHasNoErrors();
        $zweit = $this->neuerVertrag($kunde);
        $this->assertNotNull(app(SfReferenceValidator::class)->previousInsuranceHint($zweit));
        $this->actingAs($admin)->get(route('admin.contract.edit', $zweit->id))->assertSee('sieht nach dem Erstwagen aus');

        // Nur die Nummer eines eigenen Vertrags reicht ebenfalls fuer den Hinweis.
        $this->assertTrue(app(SfReferenceValidator::class)->looksLikeZweitwagenEntry('ADAC', 'AD 5406305005', $zweit));
        $this->assertFalse(app(SfReferenceValidator::class)->looksLikeZweitwagenEntry('Generali', 'G-777', $zweit));

        $this->actingAs($admin)->put(route('admin.contract.update', $zweit->id), $this->zweitwagenPayload([], [], [
            'no_previous_insurance' => '1', 'previous_insurer' => 'Zweite Wagen ADAC', 'previous_contract_number' => 'AD-5406305005',
        ]))->assertSessionHasNoErrors();
        $v = $zweit->fresh()->vehicleDetail;
        $this->assertTrue($v->no_previous_insurance);
        $this->assertNull($v->previous_insurer);
        $this->assertNull($v->previous_contract_number);
    }

    // ---------------------------------------------------------------
    // 9. Bestandspruefung (schreibt nichts)
    // ---------------------------------------------------------------

    public function test_bestandspruefung_findet_zweckentfremdete_vorversicherung_und_schreibt_nichts(): void
    {
        $kunde = $this->kunde();
        $erst = $this->kfz($kunde);
        $zweit = $this->kfz($kunde, ['insurer' => 'Neodigital', 'contract_number' => '1467-9911-8390-11'], [
            'previous_insurer' => 'Zweite Wagen ADAC', 'previous_contract_number' => 'AD-5406305005',
        ]);
        $this->kfz($kunde, ['insurer' => 'HUK', 'contract_number' => 'H-1'], ['previous_insurer' => 'Generali', 'previous_contract_number' => 'G-777']);
        $vorher = [VehicleSfReference::count(), ContractVehicleDetail::whereNotNull('previous_insurer')->count()];
        $csv = tempnam(sys_get_temp_dir(), 'zw');

        $this->artisan('kfz:zweitwagen-pruefen', ['--csv' => $csv])
            ->expectsOutputToContain('1 Vertrag/Vertraege')
            ->assertSuccessful();

        $inhalt = (string) file_get_contents($csv);
        $this->assertStringContainsString($zweit->id, $inhalt);
        $this->assertStringContainsString('Nummer = eigener Vertrag', $inhalt);
        $this->assertStringContainsString($erst->id, $inhalt, 'Vorschlag nennt den Erstwagen');
        $this->assertStringNotContainsString('Generali', $inhalt);
        $this->assertSame($vorher, [VehicleSfReference::count(), ContractVehicleDetail::whereNotNull('previous_insurer')->count()]);
        @unlink($csv);
    }
}
