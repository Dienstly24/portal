<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Activity\ActivityReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Berichte & Einstellungen der Aktivitaetserfassung: Zugriffsschutz
 * (nur Verwaltung), Kennzahlen-Berechnung, CSV-Export.
 */
class ActivityReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_are_only_accessible_for_admin_and_manager(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $support = User::factory()->create(['role' => 'support']);
        $manager = User::factory()->create(['role' => 'manager']);
        $admin = User::factory()->create(['role' => 'admin']);

        // Mitarbeiter/Support: kein Zugriff auf Berichte
        $this->actingAs($employee)->get(route('admin.activity.index'))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($support)->get(route('admin.activity.index'))
            ->assertRedirect(route('admin.dashboard'));

        // Verwaltung: Zugriff
        $this->actingAs($manager)->get(route('admin.activity.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.activity.index'))->assertOk();

        // Detailseite ebenso geschuetzt
        $this->actingAs($employee)->get(route('admin.activity.show', $employee->id))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($admin)->get(route('admin.activity.show', $employee->id))->assertOk();
    }

    public function test_activity_settings_are_admin_only(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($manager)->get(route('admin.activity.settings'))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($admin)->get(route('admin.activity.settings'))->assertOk();

        $this->actingAs($manager)->put(route('admin.activity.settings.update'), [
            'idle_threshold' => 10, 'session_timeout' => 60,
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_can_update_thresholds_and_point_weights(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.activity.settings.update'), [
            'idle_threshold' => 10,
            'session_timeout' => 60,
            'points' => ['kunde_angelegt' => 8, 'aufgabe_angelegt' => 4],
        ])->assertRedirect();

        $this->assertSame('10', SystemSetting::get('activity_idle_threshold_minutes'));
        $this->assertSame('60', SystemSetting::get('activity_session_timeout_minutes'));
        $saved = json_decode(SystemSetting::get('activity_points'), true);
        $this->assertSame(8, $saved['kunde_angelegt']);
        $this->assertSame(4, $saved['aufgabe_angelegt']);
    }

    public function test_settings_update_rejects_invalid_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->from(route('admin.activity.settings'))
            ->put(route('admin.activity.settings.update'), [
                'idle_threshold' => 0,
                'session_timeout' => 60,
                'points' => ['kunde_angelegt' => 999],
            ])
            ->assertSessionHasErrors(['idle_threshold', 'points.kunde_angelegt']);
    }

    public function test_overview_computes_login_active_idle_and_points(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'name' => 'Erika Beispiel']);
        $admin = User::factory()->create(['role' => 'admin']);

        // Sitzung heute: 3 Stunden angemeldet
        $session = WorkSession::create([
            'user_id' => $employee->id,
            'login_at' => now()->subHours(3),
            'last_seen_at' => now()->subHour(),
            'logout_at' => now()->subHour(),
            'ended_by' => 'logout',
            'active_seconds' => 3600,
        ]);

        // 3 produktive Aktionen mit insgesamt 3600s Aktivzeit + 15 Punkten
        foreach (range(1, 3) as $i) {
            ActivityLog::create([
                'user_id' => $employee->id,
                'work_session_id' => $session->id,
                'action' => 'kunde_angelegt',
                'is_productive' => true,
                'points' => 5,
                'active_seconds' => 1200,
            ]);
        }
        // Seitenaufruf zaehlt nicht
        ActivityLog::create([
            'user_id' => $employee->id,
            'action' => 'seite_geoeffnet',
            'is_productive' => false,
            'points' => 0,
            'active_seconds' => 0,
        ]);

        $rows = app(ActivityReportService::class)
            ->overview(now()->startOfDay(), now()->endOfDay());
        $row = $rows->firstWhere('user.id', $employee->id);

        $this->assertSame(2 * 3600, $row->login_seconds); // 3h angemeldet, aber logout vor 1h
        $this->assertSame(3600, $row->active_seconds);
        $this->assertSame(3600, $row->idle_seconds);
        $this->assertSame(15, $row->points);
        $this->assertSame(3, $row->productive_ops);
        $this->assertSame(3, $row->creates);
        $this->assertSame(1, $row->rank); // vor dem Admin ohne Aktivitaet

        // Uebersichtsseite rendert die Werte
        $this->actingAs($admin)->get(route('admin.activity.index'))
            ->assertOk()
            ->assertSee('Erika Beispiel');
    }

    public function test_detail_page_shows_employee_and_timeline(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'name' => 'Erika Beispiel']);
        $admin = User::factory()->create(['role' => 'admin']);
        ActivityLog::create([
            'user_id' => $employee->id,
            'action' => 'kunde_angelegt',
            'is_productive' => true,
            'points' => 5,
            'active_seconds' => 60,
        ]);

        $this->actingAs($admin)->get(route('admin.activity.show', $employee->id))
            ->assertOk()
            ->assertSee('Erika Beispiel')
            ->assertSee('Kunde angelegt');
    }

    public function test_detail_page_is_not_available_for_customer_accounts(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.activity.show', $customer->id))
            ->assertNotFound();
    }

    public function test_csv_export_contains_overview_data(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'name' => 'Erika Beispiel']);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.activity.export') . '?zeitraum=monat');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Mitarbeiter', $response->getContent());
        $this->assertStringContainsString('Erika Beispiel', $response->getContent());
    }

    public function test_csv_export_escapes_formula_injection(): void
    {
        User::factory()->create(['role' => 'employee', 'name' => '=SUM(A1:A9)']);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.activity.export') . '?zeitraum=monat');

        $response->assertOk();
        // League\Csv\EscapeFormula neutralisiert Formelzellen mit Tab-Prefix
        $this->assertStringNotContainsString(';=SUM', $response->getContent());
        $this->assertStringContainsString('=SUM(A1:A9)', $response->getContent());
    }

    public function test_employee_csv_export_is_available_for_admin(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'name' => 'Erika Beispiel']);
        $admin = User::factory()->create(['role' => 'admin']);
        ActivityLog::create([
            'user_id' => $employee->id,
            'action' => 'kunde_angelegt',
            'is_productive' => true,
            'points' => 5,
            'active_seconds' => 300,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.activity.user_export', $employee->id) . '?zeitraum=heute');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Erika Beispiel', $response->getContent());
        $this->assertStringContainsString('Datum', $response->getContent());

        // Mitarbeiter duerfen den Export nicht aufrufen
        $this->actingAs($employee)
            ->get(route('admin.activity.user_export', $employee->id))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_legacy_audit_page_hides_page_views(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ActivityLog::create(['user_id' => $admin->id, 'action' => 'seite_geoeffnet', 'route' => 'admin.tasks']);
        ActivityLog::create(['user_id' => $admin->id, 'action' => 'kunde_angelegt']);

        $response = $this->actingAs($admin)->get(route('admin.activity_log'));

        $response->assertOk();
        $response->assertDontSee('Seite geoeffnet');
        $response->assertSee('Kunde angelegt');
    }

    public function test_legacy_activity_log_page_still_renders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        // Alt-Eintrag mit doppelt kodiertem Meta (wie Bestandsdaten)
        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'magic_login_used',
            'meta' => json_encode(['ip' => '127.0.0.1']),
        ]);
        // Neuer Eintrag mit echtem Array-Meta
        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'kunde_angelegt',
            'meta' => ['params' => ['id' => 1]],
        ]);

        $this->actingAs($admin)->get(route('admin.activity_log'))->assertOk();
    }
}
