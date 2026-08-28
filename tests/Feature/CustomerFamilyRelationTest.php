<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\CustomerRelationship;
use App\Models\Document;
use App\Models\User;
use App\Services\Family\FamilyRelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Familien- und Kundenbeziehungsmanagement (Betreiber-Vorgabe 28.08.2026).
 *
 * Der Kern der Aufgabe ist NICHT "eine Familie abbilden", sondern: bereits
 * einzeln angelegte Kundenakten (je Gesundheitskarte eine) zu einer Familie
 * verbinden, OHNE dass eine Akte, ein Vertrag, ein Dokument oder eine
 * Historie verloren geht. Genau das sichern diese Tests ab.
 */
class CustomerFamilyRelationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kunde(string $name, string $nummer, ?string $geburt = null, ?string $gender = null, array $extra = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => $nummer,
            'preferred_lang' => 'de',
            'birth_date' => $geburt,
            'gender' => $gender,
        ], $extra));
    }

    private function service(): FamilyRelationService
    {
        return app(FamilyRelationService::class);
    }

    /** Fall 1: Die Beziehung wird IMMER in beiden Richtungen gespeichert. */
    public function test_verknuepfung_ist_bidirektional(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Zania Ebraheem', '2600610', now()->subYears(10)->toDateString(), 'female');

        $this->service()->link($vater, $kind, 'tochter');

        $hin = CustomerFamilyRelation::where('customer_id', $vater->id)->where('related_customer_id', $kind->id)->first();
        $rueck = CustomerFamilyRelation::where('customer_id', $kind->id)->where('related_customer_id', $vater->id)->first();

        $this->assertNotNull($hin, 'Hinrichtung fehlt');
        $this->assertNotNull($rueck, 'Rueckrichtung fehlt - vom Kind kaeme man nie zu den Eltern');
        $this->assertSame('tochter', $hin->relationship_type);
        $this->assertSame('vater', $rueck->relationship_type, 'Gegenrolle folgt dem Geschlecht der Bezugsperson');
        $this->assertTrue($hin->is_dependent);
        $this->assertFalse($rueck->is_dependent, 'Ein Elternteil ist nie vom Kind abhaengig');
    }

    /** Fall 2: Es entsteht KEIN zweiter Kundendatensatz - der bestehende wird verbunden. */
    public function test_bestehender_kunde_wird_verknuepft_nie_dupliziert(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Zania Ebraheem', '2600610', now()->subYears(10)->toDateString(), 'female');
        $vorher = Customer::count();

        $this->actingAs($this->admin())
            ->post(route('admin.customer.family.link', $vater->id), [
                'related_customer_id' => (string) $kind->id,
                'relationship_type' => 'tochter',
            ])->assertRedirect();

        $this->assertSame($vorher, Customer::count(), 'Die Verknuepfung darf nie einen Kunden anlegen');
        $this->assertDatabaseHas('customers', ['id' => $kind->id, 'customer_number' => '2600610']);
    }

    /** Fall 3: Verträge, Dokumente und die Kundennummer bleiben unangetastet. */
    public function test_keine_datenverluste_bei_verknuepfung_und_loesen(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Siela Ebraheem', '2600611', now()->subYears(12)->toDateString(), 'female');

        $vertrag = Contract::create([
            'id' => (string) Str::uuid(), 'customer_id' => $kind->id,
            'type' => 'krankenversicherung', 'insurer' => 'BIG direkt gesund', 'status' => 'active',
        ]);
        $dokument = Document::create([
            'id' => (string) Str::uuid(), 'customer_id' => $kind->id,
            'file_name' => 'gesundheitskarte.jpg', 'file_path' => 'documents/test.jpg', 'category' => 'sonstiges',
        ]);

        $relation = $this->service()->link($vater, $kind, 'tochter');
        $this->service()->unlink($relation);

        $this->assertDatabaseHas('customers', ['id' => $kind->id, 'customer_number' => '2600611']);
        $this->assertDatabaseHas('contracts', ['id' => $vertrag->id, 'customer_id' => $kind->id]);
        $this->assertDatabaseHas('documents', ['id' => $dokument->id, 'customer_id' => $kind->id]);
        $this->assertSame(0, CustomerFamilyRelation::count(), 'Beide Richtungen werden gemeinsam entfernt');
    }

    /** Fall 4: Eine Familie ist keine Dublette - das Paar verlaesst die Dubletten-Pruefung. */
    public function test_familie_wird_aus_dubletten_ausgenommen(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $frau = $this->kunde('Hasnaa Qasem', '2600612', '1988-01-01', 'female');

        $this->service()->link($vater, $frau, 'ehepartner');

        [$a, $b] = CustomerRelationship::pairKey((string) $vater->id, (string) $frau->id);
        $this->assertDatabaseHas('customer_relationships', [
            'customer_a_id' => $a, 'customer_b_id' => $b, 'type' => 'spouse',
        ]);
    }

    /** Fall 5: Kind unter 15 gilt als abhaengiges Familienmitglied - mit eigener Akte. */
    public function test_kind_unter_15_ist_abhaengiges_familienmitglied(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Zania Ebraheem', '2600610', now()->subYears(10)->toDateString(), 'female');
        $this->service()->link($vater, $kind, 'tochter');

        $status = $kind->fresh()->familyStatus();
        $this->assertSame('familienmitglied', $status['key']);
        $this->assertStringContainsString('Jehad Ebraheem', $status['label']);
        $this->assertSame('hauptkunde', $vater->fresh()->familyStatus()['key']);
    }

    /** Fall 6: Ohne Geburtsdatum wird KEINE Abhaengigkeit angenommen (nie raten). */
    public function test_ohne_geburtsdatum_keine_abhaengigkeit(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Mayar Ebraheem', '2600613');

        $relation = $this->service()->link($vater, $kind, 'kind');

        $this->assertFalse($relation->is_dependent);
        $this->assertSame('eigenstaendig', $kind->fresh()->familyStatus()['key']);
    }

    /** Fall 7: Stammdaten werden GELESEN, nicht kopiert - eigener Wert schlaegt den geerbten. */
    public function test_stammdaten_werden_vererbt_nicht_dupliziert(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male', [
            'phone' => '0170 1234567',
            'address_street' => 'Musterweg', 'address_house_number' => '5',
            'address_zip' => '71522', 'address_city' => 'Backnang',
        ]);
        $kind = $this->kunde('Zania Ebraheem', '2600610', now()->subYears(10)->toDateString(), 'female');
        $eigenes = $this->kunde('Siela Ebraheem', '2600611', now()->subYears(12)->toDateString(), 'female', [
            'phone' => '0171 9999999',
        ]);

        $this->service()->link($vater, $kind, 'tochter');
        $this->service()->link($vater, $eigenes, 'tochter');

        $telefon = $kind->fresh()->effectiveContact('phone');
        $this->assertSame('0170 1234567', $telefon['value']);
        $this->assertTrue($telefon['inherited']);

        $adresse = $kind->fresh()->effectiveContact('address');
        $this->assertStringContainsString('Backnang', $adresse['value']);
        $this->assertTrue($adresse['inherited']);

        // Physisch kopiert wird NICHTS - die Kindakte bleibt leer.
        $this->assertNull($kind->fresh()->phone);
        $this->assertDatabaseHas('customers', ['id' => $kind->id, 'address_city' => null]);

        // Eigener Wert hat Vorrang.
        $eigen = $eigenes->fresh()->effectiveContact('phone');
        $this->assertSame('0171 9999999', $eigen['value']);
        $this->assertFalse($eigen['inherited']);
    }

    /** Fall 8: Mit 15 wird nur der STATUS getauscht - Beziehung und Vertraege bleiben. */
    public function test_uebergang_mit_15_aendert_nur_den_status(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Siela Ebraheem', '2600611', now()->subYears(14)->subMonths(11)->toDateString(), 'female');
        $vertrag = Contract::create([
            'id' => (string) Str::uuid(), 'customer_id' => $kind->id,
            'type' => 'krankenversicherung', 'insurer' => 'BIG direkt gesund', 'status' => 'active',
        ]);

        $relation = $this->service()->link($vater, $kind, 'tochter');
        $this->assertTrue($relation->is_dependent);

        // Ein Monat spaeter: der 15. Geburtstag ist erreicht.
        Carbon::setTestNow(now()->addMonths(2));
        $this->artisan('familie:uebergaenge-anwenden')->assertExitCode(0);

        $relation->refresh();
        $this->assertFalse($relation->is_dependent, 'Abhaengigkeit endet');
        $this->assertSame('tochter', $relation->relationship_type, 'Die Familienrolle bleibt Tochter');
        $this->assertNotNull($relation->independent_since);

        $this->assertDatabaseHas('customers', ['id' => $kind->id, 'customer_number' => '2600611']);
        $this->assertDatabaseHas('contracts', ['id' => $vertrag->id, 'status' => 'active']);
        $this->assertSame('eigenstaendig', $kind->fresh()->familyStatus()['key']);

        // Und die Verbindung zu den Eltern besteht weiter (Betreiber-Vorgabe 8).
        $this->assertDatabaseHas('customer_family_relations', [
            'customer_id' => $kind->id, 'related_customer_id' => $vater->id, 'relationship_type' => 'vater',
        ]);

        Carbon::setTestNow();
    }

    /** Fall 9: Anzeige haengt nie am Cron - ein 15-Jaehriger gilt sofort als eigenstaendig. */
    public function test_anzeige_folgt_dem_alter_auch_ohne_tageslauf(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Schiyar Ebrahim', '2514545', now()->subYears(10)->toDateString(), 'male');
        $relation = $this->service()->link($vater, $kind, 'sohn');
        $this->assertTrue($relation->dependentNow());

        Carbon::setTestNow(now()->addYears(6));
        $this->assertFalse($relation->fresh()->dependentNow(), 'Ohne Cron-Lauf zaehlt trotzdem das Alter');
        $this->assertSame('eigenstaendig', $kind->fresh()->familyStatus()['key']);
        Carbon::setTestNow();
    }

    /** Fall 10: "Kinder werden 15" listet nur den gewaehlten Vorlauf, sortiert nach Restzeit. */
    public function test_uebergangsliste_zeigt_kinder_vor_dem_15_geburtstag(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        // 15. Geburtstag in ~2 Monaten.
        $bald = $this->kunde('Siela Ebraheem', '2600611', now()->subYears(15)->addMonths(2)->toDateString(), 'female');
        // 15. Geburtstag in ~5 Jahren - weit weg.
        $spaeter = $this->kunde('Zania Ebraheem', '2600610', now()->subYears(10)->toDateString(), 'female');

        $this->service()->link($vater, $bald, 'tochter');
        $this->service()->link($vater, $spaeter, 'tochter');

        $liste = $this->service()->upcomingTransitions(null, 6);
        $this->assertCount(1, $liste);
        $this->assertSame((string) $bald->id, (string) $liste->first()->related_customer_id);

        $this->actingAs($this->admin())->get(route('admin.family.transitions'))
            ->assertOk()->assertSee('Siela Ebraheem')->assertDontSee('Zania Ebraheem');
    }

    /** Fall 11: "Uebergang vorbereiten" aendert KEINEN Vertrag - es entsteht nur eine Wiedervorlage. */
    public function test_uebergang_vorbereiten_aendert_keine_vertraege(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Siela Ebraheem', '2600611', now()->subYears(15)->addMonths(3)->toDateString(), 'female');
        $vertrag = Contract::create([
            'id' => (string) Str::uuid(), 'customer_id' => $kind->id,
            'type' => 'krankenversicherung', 'insurer' => 'BIG direkt gesund', 'status' => 'active',
        ]);
        $relation = $this->service()->link($vater, $kind, 'tochter');

        $this->actingAs($this->admin())
            ->post(route('admin.family.prepare_transition', $relation->id))
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', ['id' => $vertrag->id, 'status' => 'active', 'customer_id' => $kind->id]);
        $this->assertSame(1, Contract::where('customer_id', $kind->id)->count(), 'Es darf kein Vertrag entstehen');
        $this->assertDatabaseHas('tasks', ['customer_id' => $kind->id, 'type' => 'reminder']);
        $this->assertNotNull($relation->fresh()->transition_prepared_at);
    }

    /** Fall 12: Die Suche findet bestehende Kunden auch ueber das Geburtsdatum. */
    public function test_suche_findet_bestehende_kunden(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Zania Ebraheem', '2600610', '2012-03-12', 'female');

        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('admin.customer.family.search', $vater->id) . '?q=2600610')
            ->assertOk()->assertJsonPath('customers.0.number', '2600610');

        $this->actingAs($admin)->getJson(route('admin.customer.family.search', $vater->id) . '?q=12.03.2012')
            ->assertOk()->assertJsonPath('customers.0.id', (string) $kind->id);

        // Bereits verknuepfte Kunden und der Kunde selbst tauchen nicht auf.
        $this->service()->link($vater, $kind, 'tochter');
        $this->actingAs($admin)->getJson(route('admin.customer.family.search', $vater->id) . '?q=Ebraheem')
            ->assertOk()->assertJsonCount(0, 'customers');
    }

    /** Fall 13: Rolle aendern zieht die Gegenrolle mit - kein halber Zustand. */
    public function test_rollenwechsel_aktualisiert_beide_richtungen(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $person = $this->kunde('Hasnaa Qasem', '2600612', '1988-01-01', 'female');
        $relation = $this->service()->link($vater, $person, 'sonstiges');

        $this->actingAs($this->admin())
            ->post(route('admin.customer.family.role', [$vater->id, $relation->id]), ['relationship_type' => 'ehepartner'])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_family_relations', [
            'customer_id' => $vater->id, 'related_customer_id' => $person->id, 'relationship_type' => 'ehepartner',
        ]);
        $this->assertDatabaseHas('customer_family_relations', [
            'customer_id' => $person->id, 'related_customer_id' => $vater->id, 'relationship_type' => 'ehepartner',
        ]);
    }

    /** Fall 14: Ein Kunde kann nicht mit sich selbst verknuepft werden. */
    public function test_selbstverknuepfung_wird_abgelehnt(): void
    {
        $kunde = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');

        $this->actingAs($this->admin())
            ->post(route('admin.customer.family.link', $kunde->id), [
                'related_customer_id' => (string) $kunde->id, 'relationship_type' => 'kind',
            ])->assertSessionHasErrors('related_customer_id');

        $this->assertDatabaseCount('customer_family_relations', 0);
    }

    /** Fall 15: Die Familienkarte im Kundenprofil verlinkt jedes Mitglied. */
    public function test_kundenprofil_zeigt_familienkarte(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Zania Ebraheem', '2600610', now()->subYears(10)->toDateString(), 'female');
        $this->service()->link($vater, $kind, 'tochter');

        $this->actingAs($this->admin())->get(route('admin.customer', $vater->id))
            ->assertOk()
            ->assertSee('Zania Ebraheem')
            ->assertSee('Tochter')
            ->assertSee(route('admin.customer', $kind->id));

        // Und zurueck: das Kind zeigt seine Bezugsperson.
        $this->actingAs($this->admin())->get(route('admin.customer', $kind->id))
            ->assertOk()
            ->assertSee('Jehad Ebraheem')
            ->assertSee('Familienmitglied');
    }

    /**
     * Fall 17: Genau der gemeldete Ausgangsfall - mehrere Gesundheitskarten
     * haben je eine eigene Akte erzeugt und wurden als "verwandt" markiert.
     * Diese Akten werden im Profil als Vorschlag angeboten und mit EINEM Klick
     * (samt Rolle) verknuepft, ohne dass ein Kunde entsteht oder verschwindet.
     */
    public function test_bereits_verwandte_akten_werden_als_vorschlag_angeboten(): void
    {
        $vater = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $kind = $this->kunde('Mayar Ebraheem', '2600611', now()->subYears(9)->toDateString(), 'female');

        // So entsteht der Bestand heute: gleicher Familienname beim Einlesen.
        [$a, $b] = CustomerRelationship::pairKey((string) $vater->id, (string) $kind->id);
        CustomerRelationship::create([
            'customer_a_id' => $a, 'customer_b_id' => $b,
            'type' => 'family', 'note' => 'Gesundheitskarten-Stapel (gleicher Familienname)',
        ]);

        $vorschlaege = $this->service()->linkSuggestions($vater);
        $this->assertCount(1, $vorschlaege);
        $this->assertSame((string) $kind->id, (string) $vorschlaege->first()->id);

        $vorher = Customer::count();
        $this->actingAs($this->admin())
            ->post(route('admin.customer.family.link', $vater->id), [
                'related_customer_id' => (string) $kind->id, 'relationship_type' => 'tochter',
            ])->assertRedirect();

        $this->assertSame($vorher, Customer::count());
        $this->assertTrue($kind->fresh()->isFamilyDependent());
        // Nach der Verknuepfung ist es kein Vorschlag mehr.
        $this->assertCount(0, $this->service()->linkSuggestions($vater->fresh()));
    }

    /** Fall 16: Portfolio-Grenze gilt auch fuer die Familienzuordnung. */
    public function test_fremder_kunde_kann_nicht_verknuepft_werden(): void
    {
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $eigener = $this->kunde('Jehad Ebraheem', '2600608', '1985-04-02', 'male');
        $fremder = $this->kunde('Ahmad Alibrahim', '2513596', '1990-05-05', 'male');
        $eigener->betreuer()->attach($mitarbeiter->id);

        $this->actingAs($mitarbeiter)
            ->post(route('admin.customer.family.link', $eigener->id), [
                'related_customer_id' => (string) $fremder->id, 'relationship_type' => 'sonstiges',
            ])->assertForbidden();

        $this->assertDatabaseCount('customer_family_relations', 0);
    }
}
