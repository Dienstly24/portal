<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Klickbare Kennzahlen-Karten (Filter-Links) + Ticket-Statistik-Seite.
 */
class TicketStatsTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeCustomer(): Customer
    {
        $n = ++self::$seq;
        $user = User::factory()->create(['role' => 'customer', 'email' => "kunde{$n}@test.de", 'name' => "Kunde {$n}"]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26001' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'first_name' => 'Kunde',
            'last_name' => (string) $n,
        ]);
    }

    private function makeTicket(Customer $customer, array $attrs = []): Ticket
    {
        return Ticket::create(array_merge([
            'customer_id' => $customer->id,
            'type' => 'other',
            'status' => 'open',
            'priority' => 'mittel',
            'subject' => 'Testanfrage',
            'description' => 'Beschreibung',
        ], $attrs));
    }

    // ---------------- Klickbare Kennzahlen-Karten ----------------

    public function test_ticket_list_metric_cards_link_to_filtered_views(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeTicket($this->makeCustomer());

        $html = $this->actingAs($admin)->get(route('admin.tickets'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.tickets', ['status' => 'open']), $html);
        $this->assertStringContainsString(route('admin.tickets', ['status' => 'in_progress']), $html);
        $this->assertStringContainsString(route('admin.tickets', ['overdue' => 1]), $html);
        $this->assertStringContainsString('metric-card-link', $html);
        // Statistik-Button fuer Admins sichtbar
        $this->assertStringContainsString(route('admin.tickets.stats'), $html);
    }

    public function test_overdue_filter_shows_only_overdue_tickets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $overdue = $this->makeTicket($customer, ['subject' => 'Ueberfaelliges Ticket']);
        $overdue->forceFill(['due_at' => now()->subHours(2)])->save();
        $this->makeTicket($customer, ['subject' => 'Puenktliches Ticket']);

        $this->actingAs($admin)->get(route('admin.tickets', ['overdue' => 1]))
            ->assertOk()
            ->assertSee('Ueberfaelliges Ticket')
            ->assertDontSee('Puenktliches Ticket');
    }

    public function test_aktiv_filter_hides_finished_tickets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $this->makeTicket($customer, ['subject' => 'Aktives Ticket']);
        $done = $this->makeTicket($customer, ['subject' => 'Fertiges Ticket']);
        $done->transitionTo('closed');

        $this->actingAs($admin)->get(route('admin.tickets', ['status' => 'aktiv']))
            ->assertOk()
            ->assertSee('Aktives Ticket')
            ->assertDontSee('Fertiges Ticket');
    }

    // ---------------- Statistik-Seite ----------------

    public function test_stats_page_shows_correct_kpis(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = $this->makeCustomer();
        $b = $this->makeCustomer();

        $this->makeTicket($a);                                   // offen
        $this->makeTicket($a, ['status' => 'in_progress']);      // in Arbeit
        $resolved = $this->makeTicket($b);
        $resolved->transitionTo('resolved');                     // erledigt
        $alt = $this->makeTicket($b, ['subject' => 'Altes Ticket']);
        $alt->forceFill(['created_at' => now()->subDays(60)])->save(); // ausserhalb 30 Tage

        $response = $this->actingAs($admin)->get(route('admin.tickets.stats'))
            ->assertOk()
            ->assertSee('Ticket-Statistik')
            ->assertSee('Team-Leistung');

        $response->assertViewHas('kpis', function ($kpis) {
            return $kpis['neu'] === 3
                && $kpis['kunden'] === 2
                && $kpis['erledigt'] === 1
                && $kpis['in_arbeit'] === 1
                && $kpis['offen'] === 1;
        });
    }

    public function test_stats_zeitraum_filter_limits_cohort(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $this->makeTicket($customer); // heute
        $alt = $this->makeTicket($customer);
        $alt->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->actingAs($admin)->get(route('admin.tickets.stats', ['zeitraum' => 'heute']))
            ->assertOk()
            ->assertViewHas('kpis', fn($kpis) => $kpis['neu'] === 1);

        $this->actingAs($admin)->get(route('admin.tickets.stats', ['zeitraum' => '7']))
            ->assertOk()
            ->assertViewHas('kpis', fn($kpis) => $kpis['neu'] === 2);
    }

    public function test_stats_lists_top_customers_and_employees(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Chef Admin']);
        $worker = User::factory()->create(['role' => 'employee', 'name' => 'Willi Worker']);
        $customer = $this->makeCustomer();
        $this->makeTicket($customer, ['assigned_to' => $worker->id]);
        $t = $this->makeTicket($customer, ['assigned_to' => $worker->id]);
        $t->transitionTo('resolved');
        $t->update(['rating' => 4]);

        $response = $this->actingAs($admin)->get(route('admin.tickets.stats'))->assertOk();
        $response->assertSee('Willi Worker');
        $response->assertSee($customer->customer_number);
        $response->assertViewHas('byEmployee', fn($rows) => $rows->count() === 1
            && $rows[0]['total'] === 2 && $rows[0]['erledigt'] === 1 && $rows[0]['rating'] === 4.0);
    }

    public function test_stats_page_is_not_accessible_for_employees(): void
    {
        // Rollen-Middleware leitet falsche Rollen um (kein 403; siehe EnsureUserRole)
        $employee = User::factory()->create(['role' => 'employee', 'can_manage_tickets' => true]);

        $this->actingAs($employee)->get(route('admin.tickets.stats'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_stats_route_does_not_collide_with_ticket_show(): void
    {
        // /tickets/statistik darf nicht als Ticket-ID interpretiert werden
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/tickets/statistik')->assertOk();
    }
}
