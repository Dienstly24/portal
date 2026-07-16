<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Serverseitige Aktivitaetserfassung: Arbeitssitzungen, Aktivitaetslog,
 * Aktivzeit-Gutschrift und Produktivitaetspunkte.
 */
class ActivityTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'employee'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_staff_login_creates_work_session_and_login_log(): void
    {
        $user = $this->staff();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('work_sessions', 1);
        $this->assertDatabaseHas('work_sessions', ['user_id' => $user->id, 'logout_at' => null]);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'login']);
    }

    public function test_customer_login_creates_no_work_session(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertDatabaseCount('work_sessions', 0);
        $this->assertDatabaseMissing('activity_logs', ['user_id' => $user->id, 'action' => 'login']);
    }

    public function test_logout_closes_work_session(): void
    {
        $user = $this->staff();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/logout')->assertRedirect();

        $session = WorkSession::where('user_id', $user->id)->first();
        $this->assertNotNull($session->logout_at);
        $this->assertSame('logout', $session->ended_by);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'logout']);
    }

    public function test_new_login_closes_previous_open_session(): void
    {
        $user = $this->staff();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/logout');
        // Zweite Anmeldung, waehrend (kuenstlich) noch eine Sitzung offen ist
        WorkSession::create([
            'user_id' => $user->id,
            'login_at' => now()->subMinutes(10),
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertSame(1, WorkSession::where('user_id', $user->id)->whereNull('logout_at')->count());
        $this->assertDatabaseHas('work_sessions', ['user_id' => $user->id, 'ended_by' => 'new_login']);
    }

    public function test_page_view_is_logged_but_not_productive(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get(route('admin.tasks'))->assertOk();

        $log = ActivityLog::where('user_id', $admin->id)->where('action', 'seite_geoeffnet')->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->is_productive);
        $this->assertSame(0, $log->points);
        $this->assertSame(0, $log->active_seconds);
        $this->assertSame('admin.tasks', $log->route);
        $this->assertSame('GET', $log->method);
        $this->assertNotNull($log->ip);
        // Seitenaufrufe erzeugen keine Aktivzeit
        $this->assertSame(0, (int) WorkSession::where('user_id', $admin->id)->sum('active_seconds'));
    }

    public function test_productive_action_is_logged_with_points_and_credits_active_time(): void
    {
        $admin = $this->staff('admin');
        WorkSession::create([
            'user_id' => $admin->id,
            'login_at' => now()->subMinutes(2),
            'last_seen_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.tasks.store'), ['title' => 'Testaufgabe', 'assigned_to' => $admin->id])
            ->assertRedirect();

        $log = ActivityLog::where('user_id', $admin->id)->where('action', 'aufgabe_angelegt')->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->is_productive);
        $this->assertSame(2, $log->points); // Default-Gewicht aus config/activity.php

        $session = WorkSession::where('user_id', $admin->id)->first();
        // Luecke seit Login (~120s) wird gutgeschrieben (unter dem Schwellwert)
        $this->assertGreaterThanOrEqual(115, $session->active_seconds);
        $this->assertLessThanOrEqual(125, $session->active_seconds);
        $this->assertNotNull($session->last_productive_at);
    }

    public function test_active_time_credit_is_capped_at_idle_threshold(): void
    {
        $admin = $this->staff('admin');
        WorkSession::create([
            'user_id' => $admin->id,
            'login_at' => now()->subMinutes(25),
            'last_seen_at' => now()->subMinutes(2),
            'last_productive_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.tasks.store'), ['title' => 'Testaufgabe', 'assigned_to' => $admin->id]);

        // 20 Minuten Luecke, aber nur der Schwellwert (5 Min = 300s) zaehlt
        $session = WorkSession::where('user_id', $admin->id)->first();
        $this->assertSame(300, (int) $session->active_seconds);
    }

    public function test_silent_session_is_closed_by_timeout_and_a_new_one_opened(): void
    {
        $admin = $this->staff('admin');
        WorkSession::create([
            'user_id' => $admin->id,
            'login_at' => now()->subMinutes(90),
            'last_seen_at' => now()->subMinutes(45),
        ]);

        $this->actingAs($admin)->get(route('admin.tasks'));

        $sessions = WorkSession::where('user_id', $admin->id)->orderBy('id')->get();
        $this->assertCount(2, $sessions);
        $this->assertSame('timeout', $sessions[0]->ended_by);
        // Als Ende gilt der letzte gesehene Request, nicht "jetzt"
        $this->assertSame(
            $sessions[0]->last_seen_at->toDateTimeString(),
            $sessions[0]->logout_at->toDateTimeString()
        );
        $this->assertNull($sessions[1]->logout_at);
    }

    public function test_failed_validation_is_not_productive_and_earns_no_points(): void
    {
        $admin = $this->staff('admin');

        // title fehlt -> Validierungsfehler -> Redirect mit errors
        $this->actingAs($admin)
            ->post(route('admin.tasks.store'), ['assigned_to' => $admin->id])
            ->assertSessionHasErrors();

        $log = ActivityLog::where('user_id', $admin->id)->where('action', 'aufgabe_angelegt')->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->is_productive);
        $this->assertSame(0, $log->points);
        $this->assertSame(0, $log->active_seconds);
    }

    public function test_point_weights_are_configurable_via_settings(): void
    {
        SystemSetting::set('activity_points', json_encode(['aufgabe_angelegt' => 9]));
        $admin = $this->staff('admin');

        $this->actingAs($admin)
            ->post(route('admin.tasks.store'), ['title' => 'Testaufgabe', 'assigned_to' => $admin->id]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'aufgabe_angelegt',
            'points' => 9,
        ]);
    }

    public function test_notification_polling_is_completely_invisible(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get(route('admin.notifications'))->assertOk();

        // Kein Log-Eintrag UND keine implizite Sitzung durch Polling
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('work_sessions', 0);
    }

    public function test_stale_sessions_are_closed_by_command(): void
    {
        $user = $this->staff();
        WorkSession::create([
            'user_id' => $user->id,
            'login_at' => now()->subHours(3),
            'last_seen_at' => now()->subHours(2),
        ]);

        $this->artisan('activity:close-stale')->assertSuccessful();

        $session = WorkSession::where('user_id', $user->id)->first();
        $this->assertNotNull($session->logout_at);
        $this->assertSame('timeout', $session->ended_by);
    }

    public function test_staff_login_post_is_not_scored_as_generic_action(): void
    {
        $user = $this->staff();

        // Der namenlose POST /login darf NICHT als produktive
        // "aktion_ausgefuehrt" mit Punkten gewertet werden.
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertDatabaseMissing('activity_logs', [
            'user_id' => $user->id,
            'action' => 'aktion_ausgefuehrt',
        ]);
        $this->assertSame(
            0,
            (int) ActivityLog::where('user_id', $user->id)->sum('points')
        );
    }

    public function test_duplicate_open_sessions_are_self_healed(): void
    {
        $admin = $this->staff('admin');
        // Zwei offene Sitzungen (Rennen paralleler Requests simuliert)
        WorkSession::create([
            'user_id' => $admin->id,
            'login_at' => now()->subMinutes(10),
            'last_seen_at' => now()->subMinutes(9),
        ]);
        WorkSession::create([
            'user_id' => $admin->id,
            'login_at' => now()->subMinutes(5),
            'last_seen_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($admin)->get(route('admin.tasks'));

        $open = WorkSession::where('user_id', $admin->id)->whereNull('logout_at')->get();
        $this->assertCount(1, $open);
        $this->assertDatabaseHas('work_sessions', ['user_id' => $admin->id, 'ended_by' => 'duplicate']);
    }

    public function test_prune_command_deletes_only_old_page_views(): void
    {
        $user = $this->staff();
        $old = ActivityLog::create(['user_id' => $user->id, 'action' => 'seite_geoeffnet']);
        $oldProductive = ActivityLog::create(['user_id' => $user->id, 'action' => 'kunde_angelegt', 'is_productive' => true]);
        ActivityLog::where('id', $old->id)->update(['created_at' => now()->subDays(120)]);
        ActivityLog::where('id', $oldProductive->id)->update(['created_at' => now()->subDays(120)]);
        $fresh = ActivityLog::create(['user_id' => $user->id, 'action' => 'seite_geoeffnet']);

        $this->artisan('activity:prune')->assertSuccessful();

        $this->assertDatabaseMissing('activity_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $oldProductive->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $fresh->id]);
    }

    public function test_download_route_is_logged_as_download_not_productive(): void
    {
        $admin = $this->staff('admin');

        // Vorlagen-Download (GET) ist als Download gemappt: geloggt,
        // aber ohne Punkte und ohne Aktivzeit
        $this->actingAs($admin)->get(route('admin.import.template'));

        $log = ActivityLog::where('user_id', $admin->id)->where('action', 'datei_heruntergeladen')->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->is_productive);
        $this->assertSame(0, $log->points);
    }
}
