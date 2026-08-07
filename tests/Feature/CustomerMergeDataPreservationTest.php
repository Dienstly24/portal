<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\Document;
use App\Models\User;
use App\Services\Matching\CustomerMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Absicherung gegen die am 06.08.2026 gemeldeten Datenverluste beim
 * Zusammenfuehren: Nach dem Merge eines Duplikats verlor der Kunde seinen
 * Portal-Zugang (echte E-Mail, Passwort, Logins), weil der User des
 * Duplikats IMMER geloescht wurde - auch wenn der Hauptkunde nur ein
 * Import-Rumpf mit Platzhalter-Adresse war. Zusaetzlich fielen
 * Verwandte-Kunden-Verknuepfungen (customer_a_id/customer_b_id) durch den
 * generischen customer_id-Abgleich und wurden per FK-Kaskade mitgeloescht.
 */
class CustomerMergeDataPreservationTest extends TestCase
{
    use RefreshDatabase;

    /** Import-Rumpf: Platzhalter-E-Mail, kein Passwort-Status, keine Logins. */
    private function makeImportStub(string $name, array $attrs = []): Customer
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'name' => $name,
            'email' => 'import-' . fake()->uuid() . '@dienstly24.internal',
            'email_verified_at' => null,
        ]);
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($user->email . microtime()), 0, 8)),
        ], $attrs));
    }

    /** Echter Portal-Account: echte E-Mail, Passwort gesetzt, Logins erfolgt. */
    private function makePortalCustomer(string $name, string $email, array $attrs = []): Customer
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'name' => $name,
            'email' => $email,
            'invitation_sent_at' => now()->subDays(30),
            'portal_password_set_at' => now()->subDays(29),
            'first_login_at' => now()->subDays(29),
            'last_login_at' => now()->subDay(),
        ]);
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($email . microtime()), 0, 8)),
        ], $attrs));
    }

    private function makeCustomer(string $name, string $email, array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($email . microtime()), 0, 8)),
        ], $attrs));
    }

    public function test_merge_adopts_real_portal_account_when_primary_is_import_stub(): void
    {
        // Genau der gemeldete Fall: aelterer Import-Rumpf ist Hauptkunde,
        // das Duplikat traegt den echten, aktivierten Portal-Zugang.
        $primary = $this->makeImportStub('Abdullah Alahmed');
        $duplicate = $this->makePortalCustomer('Abdullah Alahmed', 'abdullah@example.com', ['preferred_lang' => 'ar']);

        $contract = Contract::create(['customer_id' => $duplicate->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        $document = Document::create(['customer_id' => $duplicate->id, 'category' => 'other', 'file_name' => 'd.pdf', 'file_path' => 'x/d.pdf', 'disk' => 'local', 'visibility' => 'customer']);

        $stubUserId = $primary->user_id;
        $portalUserId = $duplicate->user_id;

        app(CustomerMergeService::class)->merge($primary, $duplicate);
        $primary = $primary->fresh();

        // Der echte Portal-Account traegt jetzt die vereinte Akte.
        $this->assertEquals($portalUserId, $primary->user_id, 'Der echte Portal-Account muss uebernommen werden');
        $this->assertNotNull(User::find($portalUserId), 'Der echte Portal-Account darf nicht geloescht werden');
        $this->assertEquals('abdullah@example.com', $primary->user->email);
        $this->assertNotNull($primary->user->portal_password_set_at, 'Passwort-Status darf nicht verloren gehen');
        $this->assertNotNull($primary->user->first_login_at, 'Login-Historie darf nicht verloren gehen');
        $this->assertNotEquals('kein_account', $primary->portalStatus()['key']);

        // Der Import-Rumpf-User ist weg, die Platzhalter-Adresse wird NICHT als email2 gesichert.
        $this->assertNull(User::find($stubUserId));
        $this->assertEmpty($primary->email2, 'Platzhalter-Adressen duerfen nicht als alternative E-Mail gesichert werden');

        // Portal-Sprache des uebernommenen Accounts gilt weiter.
        $this->assertEquals('ar', $primary->preferred_lang);

        // Vertraege/Dokumente haengen am Hauptkunden, nichts verloren.
        $this->assertEquals($primary->id, $contract->fresh()->customer_id);
        $this->assertEquals($primary->id, $document->fresh()->customer_id);
        $this->assertNull(Customer::find($duplicate->id));
    }

    public function test_merge_keeps_primary_account_and_salvages_duplicate_login_email(): void
    {
        // Gegenrichtung: Hauptkunde hat den echten Portal-Zugang, das
        // Duplikat nur eine echte E-Mail ohne Portal-Aktivitaet. Der
        // Hauptkunden-Account bleibt, die zweite Adresse wandert nach email2.
        $primary = $this->makePortalCustomer('Sara Klein', 'sara@example.com');
        $duplicate = $this->makeCustomer('Sara Klein', 'sara.alt@example.com');

        $primaryUserId = $primary->user_id;
        $dupUserId = $duplicate->user_id;

        app(CustomerMergeService::class)->merge($primary, $duplicate);
        $primary = $primary->fresh();

        $this->assertEquals($primaryUserId, $primary->user_id);
        $this->assertEquals('sara@example.com', $primary->user->email);
        $this->assertNull(User::find($dupUserId), 'Der unterlegene Duplikat-User wird aufgeraeumt');
        $this->assertEquals('sara.alt@example.com', $primary->email2, 'Die zweite echte E-Mail-Adresse darf nicht verloren gehen');
    }

    public function test_merge_moves_family_relationships_and_drops_self_pair(): void
    {
        $primary = $this->makeCustomer('Vater Najm', 'vater@example.com');
        $duplicate = $this->makeCustomer('Vater Najm', 'vater2@example.com');
        $child = $this->makeCustomer('Kind Najm', 'kind@example.com');

        // Familien-Verknuepfung haengt am Duplikat, dazu ein
        // "kein Duplikat"-Marker zwischen den beiden Merge-Partnern.
        [$a, $b] = CustomerRelationship::pairKey((string) $duplicate->id, (string) $child->id);
        CustomerRelationship::create(['customer_a_id' => $a, 'customer_b_id' => $b, 'type' => 'family']);
        [$a, $b] = CustomerRelationship::pairKey((string) $primary->id, (string) $duplicate->id);
        CustomerRelationship::create(['customer_a_id' => $a, 'customer_b_id' => $b, 'type' => 'not_duplicate']);

        $moved = app(CustomerMergeService::class)->merge($primary, $duplicate);

        // Die Familien-Verknuepfung zeigt jetzt (normalisiert) auf den Hauptkunden.
        [$a, $b] = CustomerRelationship::pairKey((string) $primary->id, (string) $child->id);
        $this->assertTrue(
            DB::table('customer_relationships')
                ->where('customer_a_id', $a)->where('customer_b_id', $b)
                ->where('type', 'family')->exists(),
            'Familien-Verknuepfung muss auf den Hauptkunden umgehaengt werden'
        );

        // Kein Selbst-Paar, keine Zeile zeigt mehr auf das geloeschte Duplikat.
        $this->assertEquals(0, DB::table('customer_relationships')
            ->where('customer_a_id', $duplicate->id)->orWhere('customer_b_id', $duplicate->id)->count());
        $this->assertEquals(0, DB::table('customer_relationships')
            ->where('customer_a_id', $primary->id)->where('customer_b_id', $primary->id)->count());

        $this->assertEquals(1, $moved['customer_relationships'] ?? 0);
    }

    public function test_merge_deduplicates_colliding_relationship_pairs(): void
    {
        $primary = $this->makeCustomer('Mutter Amal', 'amal@example.com');
        $duplicate = $this->makeCustomer('Mutter Amal', 'amal2@example.com');
        $child = $this->makeCustomer('Tochter Amal', 'tochter@example.com');

        // BEIDE Akten sind bereits mit demselben Kind verknuepft - nach dem
        // Merge darf das Paar nur einmal existieren (UNIQUE a+b).
        [$a, $b] = CustomerRelationship::pairKey((string) $primary->id, (string) $child->id);
        CustomerRelationship::create(['customer_a_id' => $a, 'customer_b_id' => $b, 'type' => 'family']);
        [$a, $b] = CustomerRelationship::pairKey((string) $duplicate->id, (string) $child->id);
        CustomerRelationship::create(['customer_a_id' => $a, 'customer_b_id' => $b, 'type' => 'family']);

        app(CustomerMergeService::class)->merge($primary, $duplicate);

        [$a, $b] = CustomerRelationship::pairKey((string) $primary->id, (string) $child->id);
        $this->assertEquals(1, DB::table('customer_relationships')
            ->where('customer_a_id', $a)->where('customer_b_id', $b)->count());
        $this->assertEquals(0, DB::table('customer_relationships')
            ->where('customer_a_id', $duplicate->id)->orWhere('customer_b_id', $duplicate->id)->count());
    }

    public function test_merge_propagates_marketing_unsubscribe(): void
    {
        // DSGVO: Das Duplikat hat sich vom Marketing abgemeldet - die
        // vereinte Akte darf nicht wieder anschreibbar werden.
        $primary = $this->makeCustomer('Omar Salim', 'omar@example.com', ['marketing_consent' => true]);
        $duplicate = $this->makeCustomer('Omar Salim', 'omar2@example.com', [
            'marketing_consent' => false,
            'unsubscribed_at' => now()->subDays(10),
        ]);

        app(CustomerMergeService::class)->merge($primary, $duplicate);
        $primary = $primary->fresh();

        $this->assertNotNull($primary->unsubscribed_at, 'Abmeldung muss fortwirken');
        $this->assertFalse((bool) $primary->marketing_consent);
        $this->assertFalse($primary->isMarketingReachable());
    }

    public function test_merge_takes_newer_last_contact(): void
    {
        $primary = $this->makeCustomer('Lisa Braun', 'lisa@example.com', ['last_contact' => '2026-01-05']);
        $duplicate = $this->makeCustomer('Lisa Braun', 'lisa2@example.com', ['last_contact' => '2026-07-20']);

        app(CustomerMergeService::class)->merge($primary, $duplicate);

        $this->assertEquals('2026-07-20', $primary->fresh()->last_contact);
    }

    public function test_merge_never_deletes_user_still_linked_to_another_customer(): void
    {
        // Anomalie-Schutz: zeigt eine DRITTE Kundenakte auf denselben User wie
        // das Duplikat, wuerde dessen Loeschung diese Akte per FK-Kaskade
        // mitreissen. Der User muss dann stehen bleiben.
        $primary = $this->makePortalCustomer('Tim Gross', 'tim@example.com');
        $duplicate = $this->makeCustomer('Tim Gross', 'tim2@example.com');
        $third = Customer::create([
            'user_id' => $duplicate->user_id,
            'customer_number' => 'C-DRITTAKTE1',
        ]);

        app(CustomerMergeService::class)->merge($primary, $duplicate);

        $this->assertNotNull(User::find($third->user_id), 'User mit weiterer Kundenakte darf nicht geloescht werden');
        $this->assertNotNull(Customer::find($third->id), 'Die dritte Akte darf nicht per Kaskade verschwinden');
    }

    public function test_preview_counts_relationships(): void
    {
        $duplicate = $this->makeCustomer('Nour Haddad', 'nour@example.com');
        $child = $this->makeCustomer('Kind Haddad', 'kindhaddad@example.com');
        [$a, $b] = CustomerRelationship::pairKey((string) $duplicate->id, (string) $child->id);
        CustomerRelationship::create(['customer_a_id' => $a, 'customer_b_id' => $b, 'type' => 'family']);

        $preview = app(CustomerMergeService::class)->preview($duplicate);

        $this->assertEquals(1, $preview['customer_relationships'] ?? 0);
    }

    public function test_bulk_cluster_merge_keeps_real_portal_account(): void
    {
        // Sammel-Zusammenfuehrung: der AELTESTE Datensatz bleibt Hauptkunde,
        // aber der echte Portal-Account (aus dem mittleren Datensatz) muss
        // die Kette ueberleben.
        $admin = User::factory()->create(['role' => 'admin']);

        $oldest = $this->makeImportStub('Karim Aziz');
        $oldest->created_at = now()->subDays(30);
        $oldest->save();

        $portal = $this->makePortalCustomer('Karim Aziz', 'karim@example.com');
        $portal->created_at = now()->subDays(20);
        $portal->save();

        $newest = $this->makeImportStub('Karim Aziz');
        $newest->created_at = now()->subDays(10);
        $newest->save();

        $portalUserId = $portal->user_id;
        Contract::create(['customer_id' => $portal->id, 'type' => 'kfz', 'insurer' => 'WGV', 'status' => 'active']);

        $this->actingAs($admin)->post(route('admin.customers.duplicates.merge'), [
            'pairs' => ["{$oldest->id}|{$portal->id}", "{$portal->id}|{$newest->id}"],
        ])->assertRedirect(route('admin.customers.duplicates'));

        // Genau einer bleibt: der aelteste - aber mit dem echten Portal-Account.
        $remaining = Customer::whereIn('id', [$oldest->id, $portal->id, $newest->id])->get();
        $this->assertCount(1, $remaining);
        $this->assertEquals((string) $oldest->id, (string) $remaining->first()->id);
        $this->assertEquals($portalUserId, $remaining->first()->user_id, 'Der echte Portal-Account muss den Cluster-Merge ueberleben');
        $this->assertEquals('karim@example.com', $remaining->first()->user->email);
        $this->assertEquals(1, Contract::where('customer_id', $oldest->id)->count());
    }
}
