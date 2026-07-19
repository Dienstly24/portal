<?php

namespace Tests\Feature;

use App\Mail\CustomerWelcomeMail;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Filter, Sortierung und Kennzahlen der Kundenliste (admin.customers) sowie
 * die automatische Portal-Einladung, sobald eine echte E-Mail nachgetragen wird.
 */
class CustomerListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function customer(array $userAttrs = [], array $customerAttrs = []): Customer
    {
        $user = User::factory()->create(array_merge([
            'role' => 'customer', 'name' => 'Max Mustermann', 'email' => 'kunde-' . uniqid() . '@kunde.de',
        ], $userAttrs));

        return Customer::create(array_merge([
            'user_id' => $user->id, 'customer_number' => 'K-' . uniqid(), 'birth_date' => '1985-03-15',
        ], $customerAttrs));
    }

    private function contract(Customer $customer, array $attrs = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK',
            'status' => 'active', 'contract_number' => 'V-' . uniqid(),
        ], $attrs));
    }

    // ---------- Filter ----------

    public function test_email_filter_splits_customers_with_and_without_real_email(): void
    {
        $this->customer(['email' => 'mit@kunde.de', 'name' => 'Mit Mail']);
        $this->customer(['email' => 'platzhalter@dienstly24.internal', 'name' => 'Ohne Mail']);

        $withEmail = $this->actingAs($this->admin)->get(route('admin.customers', ['email' => 'mit']));
        $withEmail->assertOk()->assertSee('Mit Mail')->assertDontSee('Ohne Mail');

        $withoutEmail = $this->actingAs($this->admin)->get(route('admin.customers', ['email' => 'ohne']));
        $withoutEmail->assertOk()->assertSee('Ohne Mail')->assertDontSee('Mit Mail');
    }

    public function test_sparte_filter_shows_only_customers_with_active_contract_of_type(): void
    {
        $strom = $this->customer(['name' => 'Strom Kunde']);
        $this->contract($strom, ['type' => 'strom', 'end_date' => now()->addYear()]);

        $kfz = $this->customer(['name' => 'Kfz Kunde']);
        $this->contract($kfz, ['type' => 'kfz']);

        $res = $this->actingAs($this->admin)->get(route('admin.customers', ['sparte' => 'strom']));
        $res->assertSee('Strom Kunde')->assertDontSee('Kfz Kunde');
    }

    public function test_inactive_contracts_do_not_count_for_sparte_filter(): void
    {
        $c = $this->customer(['name' => 'Gekuendigt Gas']);
        $this->contract($c, ['type' => 'gas', 'status' => 'cancelled']);

        $res = $this->actingAs($this->admin)->get(route('admin.customers', ['sparte' => 'gas']));
        $res->assertDontSee('Gekuendigt Gas');
    }

    public function test_ablauf_filter_finds_contracts_ending_within_window(): void
    {
        $bald = $this->customer(['name' => 'Laeuft Bald']);
        $this->contract($bald, ['type' => 'strom', 'end_date' => now()->addDays(20)]);

        $spaeter = $this->customer(['name' => 'Laeuft Spaeter']);
        $this->contract($spaeter, ['type' => 'strom', 'end_date' => now()->addDays(200)]);

        $res = $this->actingAs($this->admin)->get(route('admin.customers', ['ablauf' => '30']));
        $res->assertSee('Laeuft Bald')->assertDontSee('Laeuft Spaeter');
    }

    public function test_kontakt_filter_finds_long_uncontacted_and_never_contacted(): void
    {
        $nie   = $this->customer(['name' => 'Nie Kontakt'], ['last_contact' => null]);
        $alt   = $this->customer(['name' => 'Alt Kontakt'], ['last_contact' => now()->subDays(200)->toDateString()]);
        $neu   = $this->customer(['name' => 'Neu Kontakt'], ['last_contact' => now()->subDays(5)->toDateString()]);

        $res = $this->actingAs($this->admin)->get(route('admin.customers', ['kontakt' => '180']));
        $res->assertSee('Nie Kontakt')->assertSee('Alt Kontakt')->assertDontSee('Neu Kontakt');

        $onlyNever = $this->actingAs($this->admin)->get(route('admin.customers', ['kontakt' => 'nie']));
        $onlyNever->assertSee('Nie Kontakt')->assertDontSee('Alt Kontakt');
    }

    public function test_portal_status_filter_matches_badge_derivation(): void
    {
        // Kein Portal-Account (Platzhalter-Adresse)
        $keinAccount = $this->customer(['email' => 'x@dienstly24.internal', 'name' => 'Kein Account']);
        // Aktiv - Login erfolgt
        $login = $this->customer(['email' => 'login@kunde.de', 'name' => 'Hat Login', 'first_login_at' => now()]);

        $res = $this->actingAs($this->admin)->get(route('admin.customers', ['portal' => 'kein_account']));
        $res->assertSee('Kein Account')->assertDontSee('Hat Login');

        $res2 = $this->actingAs($this->admin)->get(route('admin.customers', ['portal' => 'erster_login']));
        $res2->assertSee('Hat Login')->assertDontSee('Kein Account');
    }

    // ---------- Sortierung ----------

    public function test_sort_by_name_orders_alphabetically(): void
    {
        $this->customer(['name' => 'Zacharias Zed']);
        $this->customer(['name' => 'Anton Anfang']);

        $html = $this->actingAs($this->admin)->get(route('admin.customers', ['sort' => 'name']))->getContent();
        $this->assertLessThan(strpos($html, 'Zacharias Zed'), strpos($html, 'Anton Anfang'));
    }

    // ---------- Kennzahlen ----------

    public function test_counts_reflect_portfolio(): void
    {
        $strom = $this->customer(['name' => 'S1']);
        $this->contract($strom, ['type' => 'strom']);
        $this->customer(['email' => 'y@dienstly24.internal', 'name' => 'Ohne']);

        $res = $this->actingAs($this->admin)->get(route('admin.customers'));
        // Chip-Zaehler werden gerendert (Strom 1, Ohne E-Mail 1).
        $res->assertOk()->assertSee('Strom')->assertSee('Ohne E-Mail');
    }

    // ---------- Auto-Einladung bei E-Mail-Nachtrag ----------

    public function test_adding_email_on_update_auto_sends_invitation(): void
    {
        $customer = $this->customer(['email' => 'platzhalter@dienstly24.internal', 'name' => 'Neu Eingeladen']);

        $this->actingAs($this->admin)->put(route('admin.customer.update', $customer->id), [
            'first_name' => 'Neu', 'last_name' => 'Eingeladen', 'preferred_lang' => 'de', 'customer_type' => 'privat',
            'email' => 'neu@kunde.de',
        ])->assertRedirect();

        $this->assertEquals('neu@kunde.de', $customer->user->fresh()->email);
        $this->assertNotNull($customer->user->fresh()->invitation_sent_at);
        Mail::assertQueued(CustomerWelcomeMail::class, fn ($m) => $m->hasTo('neu@kunde.de'));
    }

    public function test_update_without_email_change_does_not_send_invitation(): void
    {
        $customer = $this->customer(['email' => 'bestehend@kunde.de', 'first_login_at' => now()]);

        $this->actingAs($this->admin)->put(route('admin.customer.update', $customer->id), [
            'first_name' => 'Max', 'last_name' => 'Mustermann', 'preferred_lang' => 'de', 'customer_type' => 'privat',
            'email' => 'bestehend@kunde.de',
        ])->assertRedirect();

        Mail::assertNothingQueued();
    }

    public function test_email_change_for_existing_portal_account_does_not_reinvite(): void
    {
        // Kunde hatte bereits eine echte E-Mail (Portal-Zugang) - reine Adressaenderung.
        $customer = $this->customer(['email' => 'alt@kunde.de', 'invitation_sent_at' => now()->subMonth()]);

        $this->actingAs($this->admin)->put(route('admin.customer.update', $customer->id), [
            'first_name' => 'Max', 'last_name' => 'Mustermann', 'preferred_lang' => 'de', 'customer_type' => 'privat',
            'email' => 'geaendert@kunde.de',
        ])->assertRedirect();

        Mail::assertNothingQueued();
    }
}
