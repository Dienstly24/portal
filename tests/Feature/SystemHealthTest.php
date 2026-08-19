<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Systemzustand-Seite: sie soll den stillen Ausfall im Hintergrund sichtbar
 * machen - und dabei selbst nichts kaputt machen (nur lesend, keine
 * Geheimnisse, kein Zugriff fuer Nicht-Berechtigte).
 */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ Zugriff

    public function test_admin_und_manager_sehen_die_seite(): void
    {
        foreach (['admin', 'manager'] as $rolle) {
            $this->actingAs(User::factory()->create(['role' => $rolle]))
                ->get('/admin/systemzustand')
                ->assertOk()
                ->assertSee('Systemzustand');
        }
    }

    public function test_mitarbeiter_und_kunden_haben_keinen_zugriff(): void
    {
        // Betriebsdetails (welche Dienste eingerichtet sind, wie viele
        // Anmeldungen fehlschlagen) gehen nur admin/manager etwas an.
        foreach (['employee', 'support'] as $rolle) {
            $this->actingAs(User::factory()->create(['role' => $rolle]))
                ->get('/admin/systemzustand')
                ->assertRedirect(route('admin.dashboard'));
        }

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/systemzustand')
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_ohne_anmeldung_fuehrt_die_seite_zum_login(): void
    {
        $this->get('/admin/systemzustand')->assertRedirect('/login');
    }

    // -------------------------------------------------- Keine Geheimnisse

    public function test_die_seite_gibt_keinen_schluessel_aus(): void
    {
        config([
            'services.anthropic.key' => 'sk-ant-geheim-1234567890',
            'services.lexoffice.key' => 'lex-geheim-abcdefgh',
            'services.meta.access_token' => 'meta-geheim-zzzzzzzz',
        ]);

        $antwort = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/systemzustand')->assertOk();

        $inhalt = $antwort->getContent();
        // Weder ganz noch in Teilen - auch ein Ausschnitt ist ein Leak.
        foreach (['sk-ant-geheim', 'lex-geheim', 'meta-geheim', '1234567890'] as $teil) {
            $this->assertStringNotContainsString($teil, $inhalt);
        }
        // Stattdessen nur die Aussage, DASS etwas gesetzt ist.
        $antwort->assertSee('Schluessel gesetzt');
    }

    // ------------------------------------------------- Geplante Aufgaben

    public function test_nie_gelaufene_aufgabe_wird_als_offen_gemeldet(): void
    {
        $abschnitt = app(SystemHealthService::class)->schedule();

        $this->assertNotEmpty($abschnitt['tasks'], 'Der Planer muss Aufgaben kennen.');
        $this->assertNull($abschnitt['last_any_run']);
        $this->assertSame(SystemHealthService::WARN, $abschnitt['status']);
    }

    public function test_ueberfaellige_aufgabe_wird_rot(): void
    {
        $ereignisse = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $key = ScheduledTaskRun::keyFor($ereignisse[0]->getSummaryForDisplay());

        // Jede Aufgabe hier laeuft mindestens taeglich - drei Tage Stille
        // sind in jedem Fall ueberfaellig.
        ScheduledTaskRun::create([
            'task_key' => $key,
            'last_started_at' => now()->subDays(3),
            'last_finished_at' => now()->subDays(3),
            'last_success_at' => now()->subDays(3),
            'run_count' => 5,
        ]);

        $abschnitt = app(SystemHealthService::class)->schedule();
        $eintrag = collect($abschnitt['tasks'])
            ->firstWhere('label', $ereignisse[0]->getSummaryForDisplay());

        $this->assertSame(SystemHealthService::FAIL, $eintrag['status']);
        $this->assertStringContainsString('Ueberfaellig', $eintrag['note']);
        $this->assertSame(SystemHealthService::FAIL, $abschnitt['status']);
    }

    public function test_fehlgeschlagene_aufgabe_wird_mit_grund_gemeldet(): void
    {
        $ereignisse = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $key = ScheduledTaskRun::keyFor($ereignisse[0]->getSummaryForDisplay());

        ScheduledTaskRun::create([
            'task_key' => $key,
            'last_started_at' => now()->subMinutes(2),
            'last_finished_at' => now()->subMinutes(2),
            'last_success_at' => now()->subDays(2),
            'last_failed_at' => now()->subMinutes(2),
            'last_error' => 'Datenbank nicht erreichbar',
            'run_count' => 9,
            'fail_count' => 1,
        ]);

        $eintrag = collect(app(SystemHealthService::class)->schedule()['tasks'])
            ->firstWhere('label', $ereignisse[0]->getSummaryForDisplay());

        $this->assertSame(SystemHealthService::FAIL, $eintrag['status']);
        $this->assertStringContainsString('Datenbank nicht erreichbar', $eintrag['note']);
    }

    public function test_planer_ereignis_schreibt_den_lauf_mit(): void
    {
        $ereignis = app(\Illuminate\Console\Scheduling\Schedule::class)->events()[0];
        $ereignis->exitCode = 0;

        event(new \Illuminate\Console\Events\ScheduledTaskStarting($ereignis));
        event(new \Illuminate\Console\Events\ScheduledTaskFinished($ereignis, 1.5));

        $lauf = ScheduledTaskRun::where('task_key', ScheduledTaskRun::keyFor($ereignis->getSummaryForDisplay()))->first();

        $this->assertNotNull($lauf, 'Der Lauf muss protokolliert werden.');
        $this->assertNotNull($lauf->last_success_at);
        $this->assertNull($lauf->last_failed_at);
        $this->assertSame(1500, $lauf->runtime_ms);
        $this->assertSame(1, $lauf->run_count);
    }

    public function test_ein_erfolgreicher_lauf_loescht_den_alten_fehler(): void
    {
        $ereignis = app(\Illuminate\Console\Scheduling\Schedule::class)->events()[0];
        $key = ScheduledTaskRun::keyFor($ereignis->getSummaryForDisplay());

        ScheduledTaskRun::create([
            'task_key' => $key,
            'last_failed_at' => now()->subHour(),
            'last_error' => 'alter Fehler',
            'fail_count' => 1,
        ]);

        $ereignis->exitCode = 0;
        event(new \Illuminate\Console\Events\ScheduledTaskFinished($ereignis, 0.2));

        $lauf = ScheduledTaskRun::where('task_key', $key)->first();
        $this->assertNull($lauf->last_error);
        $this->assertNotNull($lauf->last_success_at);
        // Der Zaehler bleibt - die Historie wird nicht geschoenigt.
        $this->assertSame(1, $lauf->fail_count);
    }

    public function test_schluessel_ist_unabhaengig_vom_php_pfad(): void
    {
        $a = ScheduledTaskRun::keyFor("'/usr/bin/php8.3' 'artisan' tasks:remind");
        $b = ScheduledTaskRun::keyFor("'/opt/alt/php' 'artisan' tasks:remind");

        $this->assertSame($a, $b);
        $this->assertSame('command:tasks:remind', $a);
    }

    // --------------------------------------------------- Warteschlange

    public function test_ein_lange_wartender_job_meldet_einen_toten_worker(): void
    {
        config(['queue.default' => 'database']);

        if (! Schema::hasTable('jobs')) {
            $this->markTestSkipped('jobs-Tabelle nicht vorhanden.');
        }

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subHour()->getTimestamp(),
            'created_at' => now()->subHour()->getTimestamp(),
        ]);

        $abschnitt = app(SystemHealthService::class)->queue();
        $worker = collect($abschnitt['items'])->firstWhere('label', 'Queue-Worker');

        $this->assertSame(SystemHealthService::FAIL, $worker['status']);
        $this->assertSame(SystemHealthService::FAIL, $abschnitt['status']);
    }

    public function test_leere_warteschlange_ist_in_ordnung(): void
    {
        config(['queue.default' => 'database']);

        $abschnitt = app(SystemHealthService::class)->queue();

        $this->assertSame(SystemHealthService::OK, $abschnitt['status']);
    }

    // ------------------------------------------------------- Sicherheit

    public function test_fehlgeschlagene_anmeldungen_werden_gezaehlt(): void
    {
        $nutzer = User::factory()->create(['role' => 'admin']);

        for ($i = 0; $i < 3; $i++) {
            ActivityLog::record('login_failed', 'user', $nutzer->id, [], $nutzer->id);
        }
        ActivityLog::record('two_factor_failed', 'user', $nutzer->id, [], $nutzer->id);

        // Alter Eintrag ausserhalb des 24-Stunden-Fensters zaehlt nicht mit.
        $alt = ActivityLog::record('login_failed', 'user', $nutzer->id, [], $nutzer->id);
        $alt->forceFill(['created_at' => now()->subDays(3)])->save();

        $abschnitt = app(SystemHealthService::class)->security();
        $anmeldungen = collect($abschnitt['items'])->firstWhere('label', 'Fehlgeschlagene Anmeldungen (24 h)');
        $zweiterFaktor = collect($abschnitt['items'])->firstWhere('label', 'Fehlgeschlagene 2FA-Eingaben (24 h)');

        $this->assertSame('3', $anmeldungen['value']);
        $this->assertSame('1', $zweiterFaktor['value']);
    }

    public function test_debug_modus_im_betrieb_ist_ein_fehler(): void
    {
        config(['app.debug' => true]);

        $eintrag = collect(app(SystemHealthService::class)->security()['items'])
            ->firstWhere('label', 'Debug-Modus');

        $this->assertSame(SystemHealthService::FAIL, $eintrag['status']);
    }

    // ------------------------------------------------------------- JSON

    public function test_json_liefert_die_ampel_ohne_details(): void
    {
        $antwort = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/systemzustand.json');

        $daten = $antwort->json();

        $this->assertArrayHasKey('status', $daten);
        $this->assertArrayHasKey('sections', $daten);
        // Nur Titel/Zustand/Kurzfassung - keine Einzelwerte nach aussen.
        foreach ($daten['sections'] as $abschnitt) {
            $this->assertSame(['title', 'status', 'summary'], array_keys($abschnitt));
        }
    }
}
