<?php

namespace Tests\Feature;

use App\Exceptions\AbhaengigerKundeException;
use App\Models\ArchivierteKundennummer;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\CustomerTimeline;
use App\Models\InternalNotification;
use App\Models\SystemSetting;
use App\Models\Task;
use App\Models\User;
use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerNumberGenerator;
use App\Services\Family\AbhaengigesKindService;
use App\Services\Family\FamilyRelationService;
use App\Services\Portal\PortalAccessService;
use App\Support\FamilienAlter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kinder unter dem Selbststaendigkeitsalter (Betreiber-Auftrag 07.10.2026,
 * KI-095). Gemeldet: die Tochter "Tala" (Kind) trug eine eigene
 * Kundennummer. Regel: unter 16 keine Kundennummer, kein Vertrag, kein
 * Portal - das Kind steht als abhaengiges Familienmitglied unter dem Vater
 * (sonst der Mutter); mit 15 erinnert das System das Team; ab 16 vergibt
 * das Team die Nummer. Beide Alter sind Einstellungen.
 *
 * Die mit [ohne Fix rot] markierten Faelle scheitern auf dem alten Stand.
 */
class AbhaengigeKinderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Neuanlage ueber den normalen Weg - Nummer vorab gezogen wie alle Aufrufer. */
    private function anlegen(string $name, ?string $geburt, ?string $gender = null): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => app(CustomerNumberGenerator::class)->generate(),
            'birth_date' => $geburt,
            'gender' => $gender,
        ]);
    }

    /** ALTBESTAND: eine Akte, wie sie vor dem Fix entstanden ist (am Hook vorbei). */
    private function altbestand(string $name, string $nummer, ?string $geburt, ?string $gender = null): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::withoutEvents(fn () => Customer::forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'customer_number' => $nummer,
            'birth_date' => $geburt,
            'gender' => $gender,
        ]));
    }

    private function jahre(int $alter, int $tageNachGeburtstag = 10): string
    {
        return now()->subYears($alter)->subDays($tageNachGeburtstag)->toDateString();
    }

    // ---------------------------------------------------------------
    // Regel 1: unter 16 keine Kundennummer
    // ---------------------------------------------------------------

    /** [ohne Fix rot] Kind unter 16 bekommt keine Kundennummer, egal welcher Weg sie mitgibt. */
    public function test_kind_unter_16_bekommt_keine_kundennummer(): void
    {
        $kind = $this->anlegen('Tala Alhamoud', $this->jahre(9), 'female');

        $this->assertNull($kind->fresh()->customer_number);
        $this->assertTrue($kind->unterSelbststaendigkeitsalter());
    }

    public function test_ab_16_gibt_es_eine_kundennummer(): void
    {
        $jugend = $this->anlegen('Omar Alhamoud', $this->jahre(16), 'male');
        $erwachsen = $this->anlegen('Ahmad Alhamoud', '1980-01-01', 'male');

        $this->assertNotNull($jugend->fresh()->customer_number);
        $this->assertNotNull($erwachsen->fresh()->customer_number);
    }

    /** [ohne Fix rot] Automatische Anlage: "als Kind" gekennzeichnet -> keine Nummer, auch ohne Geburtsdatum. */
    public function test_automatische_anlage_als_kind_ohne_geburtsdatum(): void
    {
        $service = app(CustomerAutoCreationService::class);

        $kind = $service->createFromUnmatched(['first_name' => 'Lina', 'last_name' => 'Musterkind', 'als_kind' => true], 'manual');
        $kind2 = $service->createFromUnmatched(['first_name' => 'Rami', 'last_name' => 'Musterkind', 'birth_date' => $this->jahre(5)], 'manual');
        $vater = $service->createFromUnmatched(['first_name' => 'Karim', 'last_name' => 'Mustervater', 'birth_date' => '1979-05-05'], 'manual');

        $this->assertNull($kind->customer_number);
        $this->assertNull($kind2->fresh()->customer_number);
        $this->assertNotNull($vater->customer_number);
        $this->assertNotNull($kind->eigenstaendigkeitsSperre(), 'Ohne Nummer ist die Akte kein eigenstaendiger Kunde');
    }

    /** [ohne Fix rot] Admin-Neuanlage eines Kindes: keine Nummer, keine Einladung, Hinweis auf das Verknuepfen. */
    public function test_admin_neuanlage_kind(): void
    {
        Mail::fake();
        $this->actingAs($this->admin())->post(route('admin.customers.store'), [
            'first_name' => 'Tala', 'last_name' => 'Alhamoud',
            'email' => 'tala@example.com',
            'birth_date' => $this->jahre(9),
        ])->assertRedirect()->assertSessionHas('warning');

        $kind = Customer::whereHas('user', fn ($q) => $q->where('email', 'tala@example.com'))->firstOrFail();
        $this->assertNull($kind->customer_number);
        Mail::assertNothingSent();
    }

    /** [ohne Fix rot] Die Selbstregistrierung lehnt Kinder ab. */
    public function test_registrierung_unter_16_abgelehnt(): void
    {
        Mail::fake();
        $this->post('/register', [
            'first_name' => 'Tala', 'last_name' => 'Alhamoud',
            'email' => 'kind@example.com',
            'birth_date' => $this->jahre(13),
            'password' => 'test-passwort-2026', 'password_confirmation' => 'test-passwort-2026',
            'agb' => '1',
        ])->assertSessionHasErrors('birth_date');

        $this->assertDatabaseMissing('pending_registrations', ['email' => 'kind@example.com']);
    }

    // ---------------------------------------------------------------
    // Regel 2: keine Vertraege und kein Portal fuer ein Kind
    // ---------------------------------------------------------------

    /** [ohne Fix rot] Kein Vertrag an der Akte eines Kindes - auf JEDEM Weg (Modell-Netz). */
    public function test_kein_vertrag_fuer_kind_am_modell(): void
    {
        $kind = $this->anlegen('Tala Alhamoud', $this->jahre(9), 'female');

        $this->expectException(AbhaengigerKundeException::class);
        Contract::create(['customer_id' => $kind->id, 'type' => 'haftpflicht', 'insurer' => 'Test AG', 'status' => 'active']);
    }

    /** [ohne Fix rot] Auch ein Altbestand-Kind MIT Nummer bekommt keinen neuen Vertrag. */
    public function test_kein_vertrag_fuer_altbestand_kind_mit_nummer(): void
    {
        $kind = $this->altbestand('Tala Alhamoud', '2600810', $this->jahre(9), 'female');

        $this->expectException(AbhaengigerKundeException::class);
        Contract::create(['customer_id' => $kind->id, 'type' => 'haftpflicht', 'insurer' => 'Test AG', 'status' => 'active']);
    }

    /** [ohne Fix rot] Formular: kein Vertragsformular, kein Speichern - mit verstaendlicher Meldung. */
    public function test_vertragsformular_fuer_kind_gesperrt(): void
    {
        $kind = $this->anlegen('Tala Alhamoud', $this->jahre(9), 'female');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.contract.create', $kind->id))
            ->assertRedirect(route('admin.customer', $kind->id))->assertSessionHas('error');

        $this->actingAs($admin)->post(route('admin.contract.store', $kind->id), [
            'type' => 'haftpflicht', 'insurer' => 'Test AG', 'status' => 'active', 'origin' => 'brokered',
        ])->assertSessionHasErrors('customer');

        $this->assertSame(0, Contract::where('customer_id', $kind->id)->count());
    }

    public function test_vertrag_fuer_16_jaehrigen_erlaubt(): void
    {
        $jugend = $this->anlegen('Omar Alhamoud', $this->jahre(16), 'male');

        $vertrag = Contract::create(['customer_id' => $jugend->id, 'type' => 'haftpflicht', 'insurer' => 'Test AG', 'status' => 'active']);
        $this->assertTrue($vertrag->exists);
    }

    /** [ohne Fix rot] Kein Portal fuer ein Kind. */
    public function test_kein_portal_fuer_kind(): void
    {
        Mail::fake();
        $kind = $this->altbestand('Tala Alhamoud', '2600810', $this->jahre(9), 'female');
        $kind->user->forceFill(['email' => 'tala@example.com'])->save();

        $this->assertFalse(app(PortalAccessService::class)->autoInvite($kind->fresh()));
        $this->expectException(\RuntimeException::class);
        app(PortalAccessService::class)->sendInvitation($kind->fresh());
    }

    // ---------------------------------------------------------------
    // Regel 3: Erinnerung mit 15
    // ---------------------------------------------------------------

    private function familie(int $alterKind, int $tage = 10): array
    {
        $vater = $this->anlegen('Ahmad Alhamoud', '1980-01-01', 'male');
        $mutter = $this->anlegen('Hiba Alhamoud', '1983-01-01', 'female');
        $kind = $this->anlegen('Tala Alhamoud', $this->jahre($alterKind, $tage), 'female');
        app(FamilyRelationService::class)->link($mutter, $kind, 'tochter');
        app(FamilyRelationService::class)->link($vater, $kind, 'tochter');
        $betreuer = User::factory()->create(['role' => 'employee']);
        $vater->betreuer()->attach($betreuer->id, ['is_primary' => true]);

        return [$vater, $mutter, $kind->fresh(), $betreuer];
    }

    /** [ohne Fix rot] Mit 15: Aufgabe an der Akte des VATERS, Glocke, Vermerk - genau einmal. */
    public function test_erinnerung_mit_15(): void
    {
        [$vater, , $kind, $betreuer] = $this->familie(15);

        $this->artisan('familie:portal-vorbereitung-erinnern')->assertExitCode(0);

        $task = Task::where('customer_id', $vater->id)->first();
        $this->assertNotNull($task, 'Die Erinnerung steht in der Akte des Vaters');
        $this->assertStringContainsString('Das abhängige Familienmitglied Tala Alhamoud ist 15', $task->title);
        $this->assertStringContainsString('Portal', $task->title);
        $this->assertSame($betreuer->id, $task->assigned_to, 'In der Aufgabenliste des Betreuers');
        $this->assertTrue(InternalNotification::where('user_id', $betreuer->id)->where('type', 'family_transition')->exists());
        $this->assertTrue(CustomerTimeline::where('customer_id', $vater->id)->where('title', 'like', 'Erinnerung%')->exists());
        $this->assertNotNull($kind->fresh()->portal_vorbereitung_erinnert_at);
        $this->assertNull($kind->fresh()->customer_number, 'Die Erinnerung vergibt keine Nummer');

        // Zweiter Lauf: nichts doppelt.
        $this->artisan('familie:portal-vorbereitung-erinnern')->assertExitCode(0);
        $this->assertSame(1, Task::count());
    }

    public function test_keine_erinnerung_mit_14_und_nicht_mehr_mit_16(): void
    {
        $this->familie(14);
        $this->artisan('familie:portal-vorbereitung-erinnern')->assertExitCode(0);
        $this->assertSame(0, Task::count());
    }

    /** Die Alter sind Einstellungen: Erinnerung mit 14, Selbststaendigkeit mit 17. */
    public function test_altersgrenzen_kommen_aus_den_einstellungen(): void
    {
        $this->assertNull(FamilienAlter::setze(14, 17));

        [$vater, , $kind] = $this->familie(14);
        $this->artisan('familie:portal-vorbereitung-erinnern')->assertExitCode(0);
        $this->assertSame(1, Task::where('customer_id', $vater->id)->count(), 'Erinnerung schon mit 14');

        $sechzehn = $this->anlegen('Omar Alhamoud', $this->jahre(16), 'male');
        $this->assertNull($sechzehn->fresh()->customer_number, 'Mit Grenze 17 ist ein 16-Jaehriger noch Kind');
    }

    public function test_einstellungen_speichern_und_widerspruch_ablehnen(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'familie_form' => '1',
            FamilienAlter::SETTING_ERINNERUNG => 16,
            FamilienAlter::SETTING_SELBSTSTAENDIG => 16,
        ])->assertSessionHasErrors(FamilienAlter::SETTING_ERINNERUNG);
        $this->assertSame(16, FamilienAlter::selbststaendig(), 'Standard unveraendert');

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'familie_form' => '1',
            FamilienAlter::SETTING_ERINNERUNG => 14,
            FamilienAlter::SETTING_SELBSTSTAENDIG => 17,
        ])->assertSessionHasNoErrors();
        $this->assertSame(14, FamilienAlter::erinnerung());
        $this->assertSame(17, FamilienAlter::selbststaendig());
    }

    public function test_unsinnige_einstellung_faellt_auf_standard(): void
    {
        SystemSetting::set(FamilienAlter::SETTING_SELBSTSTAENDIG, '3');
        SystemSetting::set(FamilienAlter::SETTING_ERINNERUNG, '99');

        $this->assertSame(16, FamilienAlter::selbststaendig());
        $this->assertSame(15, FamilienAlter::erinnerung());
    }

    // ---------------------------------------------------------------
    // Regel 4: ab 16 Kundennummer durch das Team, Beziehung bleibt
    // ---------------------------------------------------------------

    public function test_kundennummer_erst_ab_16_und_beziehung_bleibt(): void
    {
        [$vater, , $kind] = $this->familie(15);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.customer.kundennummer_vergeben', $kind->id))
            ->assertSessionHas('error');
        $this->assertNull($kind->fresh()->customer_number);

        // Ein Jahr spaeter.
        $kind->forceFill(['birth_date' => $this->jahre(16)])->save();
        $this->actingAs($admin)->post(route('admin.customer.kundennummer_vergeben', $kind->id))
            ->assertSessionHas('success');

        $kind->refresh();
        $this->assertNotNull($kind->customer_number);
        $rel = CustomerFamilyRelation::where('customer_id', $vater->id)->where('related_customer_id', $kind->id)->first();
        $this->assertNotNull($rel, 'Die Verbindung zum Vater bleibt');
        $this->assertFalse($rel->is_dependent);
    }

    /** Am 16. Geburtstag legt der Tageslauf eine Aufgabe "Kundennummer vergeben" an - vergeben wird nichts automatisch. */
    public function test_uebergang_mit_16_legt_aufgabe_an(): void
    {
        [, , $kind] = $this->familie(15);
        $kind->forceFill(['birth_date' => $this->jahre(16, 0)])->save();

        $this->artisan('familie:uebergaenge-anwenden')->assertExitCode(0);

        $this->assertNull($kind->fresh()->customer_number);
        $this->assertTrue(Task::where('customer_id', $kind->id)->where('title', 'like', '%Kundennummer vergeben%')->exists());
    }

    /** Vater vor Mutter - auch wenn die Mutter zuerst verknuepft wurde. */
    public function test_vater_ist_haupt_bezugsperson(): void
    {
        [$vater, , $kind] = $this->familie(9);

        $this->assertSame((string) $vater->id, (string) $kind->familyGuardian()->id);
        $this->assertSame((string) $vater->id, (string) app(AbhaengigesKindService::class)->vorgeschlageneBezugsperson($kind)->id);
    }

    // ---------------------------------------------------------------
    // Bestand: pruefen (lesend) und umstellen (Probelauf zuerst)
    // ---------------------------------------------------------------

    public function test_pruefbericht_ist_lesend(): void
    {
        $vater = $this->anlegen('Ahmad Alhamoud', '1980-01-01', 'male');
        $kind = $this->altbestand('Tala Alhamoud', '2600810', $this->jahre(9), 'female');
        app(FamilyRelationService::class)->link($vater, $kind, 'tochter');
        $vorher = Customer::where('id', $kind->id)->value('customer_number');

        $this->artisan('kunden:kinder-pruefen')
            ->expectsOutputToContain('2600810 - Tala Alhamoud')
            ->expectsOutputToContain('Es wurde NICHTS geaendert')
            ->assertExitCode(0);

        $this->assertSame($vorher, $kind->fresh()->customer_number);
        $this->assertSame(0, ArchivierteKundennummer::count());
    }

    /** [ohne Fix rot] Tala: Probelauf aendert nichts, Ausfuehren archiviert die Nummer und stellt um. */
    public function test_umstellen_der_bestehenden_kinderakte(): void
    {
        $vater = $this->anlegen('Ahmad Alhamoud', '1980-01-01', 'male');
        $kind = $this->altbestand('Tala Alhamoud', '2600810', $this->jahre(9), 'female');
        $kind->user->forceFill(['email' => 'tala@example.com'])->save();
        app(FamilyRelationService::class)->link($vater, $kind, 'tochter');

        $this->artisan('kunden:kind-umstellen', ['nummer' => '2600810'])
            ->expectsOutputToContain('PROBELAUF')->assertExitCode(0);
        $this->assertSame('2600810', $kind->fresh()->customer_number);

        $this->artisan('kunden:kind-umstellen', ['nummer' => '2600810', '--ausfuehren' => true])->assertExitCode(0);

        $kind->refresh();
        $this->assertNull($kind->customer_number);
        $this->assertDatabaseHas('customers', ['id' => $kind->id]); // Die Akte bleibt - nichts geloescht.
        $this->assertDatabaseHas('archivierte_kundennummern', [
            'customer_number' => '2600810', 'customer_id' => $kind->id, 'bezugsperson_customer_id' => $vater->id,
        ]);
        $this->assertTrue(CustomerFamilyRelation::where('customer_id', $vater->id)->where('related_customer_id', $kind->id)
            ->where('is_dependent', true)->exists());
        $this->assertFalse((bool) $kind->user->fresh()->is_active, 'Portal stillgelegt, nicht geloescht');
        $this->assertTrue(CustomerTimeline::where('customer_id', $vater->id)->where('description', 'like', '%2600810%')->exists());

        // Die alte Nummer findet weiterhin das Kind ...
        $this->assertTrue(Customer::search('2600810')->where('customers.id', $kind->id)->exists());
        // ... und wird nie neu vergeben.
        $neu = app(CustomerNumberGenerator::class)->generate();
        $this->assertNotSame('2600810', $neu);
        $this->assertGreaterThan(810, (int) substr($neu, 2));
    }

    /** [ohne Fix rot] Haengt ein Vertrag an der Kinderakte, wird NICHTS umgestellt - der Betreiber entscheidet. */
    public function test_umstellen_mit_vertrag_ist_blockiert(): void
    {
        $vater = $this->anlegen('Ahmad Alhamoud', '1980-01-01', 'male');
        $kind = $this->altbestand('Tala Alhamoud', '2600810', $this->jahre(9), 'female');
        app(FamilyRelationService::class)->link($vater, $kind, 'tochter');
        Contract::withoutEvents(fn () => Contract::forceCreate([
            'id' => (string) Str::uuid(), 'customer_id' => $kind->id,
            'type' => 'krankenversicherung', 'insurer' => 'KKH', 'status' => 'active',
        ]));

        $this->artisan('kunden:kinder-pruefen')->expectsOutputToContain('BLOCKIERT')->assertExitCode(0);
        $this->artisan('kunden:kind-umstellen', ['nummer' => '2600810', '--ausfuehren' => true])
            ->expectsOutputToContain('BLOCKIERT')->assertExitCode(1);

        $this->assertSame('2600810', $kind->fresh()->customer_number);
        $this->assertSame(0, ArchivierteKundennummer::count());
    }

    public function test_umstellen_ohne_eindeutigen_elternteil_verweigert(): void
    {
        $this->altbestand('Tala Alhamoud', '2600810', $this->jahre(9), 'female');

        $this->artisan('kunden:kind-umstellen', ['nummer' => '2600810', '--ausfuehren' => true])
            ->expectsOutputToContain('Kein eindeutiger Elternteil')->assertExitCode(1);
        $this->assertSame(0, ArchivierteKundennummer::count());
    }

    /** Anzeige: Kinderakte ohne Nummer und ohne Vertragsknopf; Vaterakte zeigt die Erinnerung. */
    public function test_anzeige_in_kinder_und_vaterakte(): void
    {
        [$vater, , $kind] = $this->familie(15);
        $this->artisan('familie:portal-vorbereitung-erinnern')->assertExitCode(0);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.customer', $kind->id))->assertOk()
            ->assertSee('Keine eigene Kundennummer (abhängiges Familienmitglied)')
            ->assertDontSee('+ Vertrag hinzufügen');

        $this->actingAs($admin)->get(route('admin.customer', $vater->id))->assertOk()
            ->assertSee('Tala Alhamoud')
            ->assertSee('ohne eigene Kundennummer')
            ->assertSee('Erinnerung „Portal vorbereiten"', false)
            ->assertSee('+ Vertrag hinzufügen');
    }
}
