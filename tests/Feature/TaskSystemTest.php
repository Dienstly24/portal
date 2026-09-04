<?php

namespace Tests\Feature;

use App\Mail\DirectEmailMail;
use App\Models\Customer;
use App\Models\InternalNotification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Aufgaben-System-Ausbau (26.07.2026): Sofort-Kundensuche im Formular,
 * Wiedervorlage (+X Tage, Verschieben, Glocken-Erinnerung) und geplante
 * automatische Kunden-E-Mails inkl. Platzhalter-Rendering beim Versand.
 */
class TaskSystemTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $name, string $email, string $number, array $extra = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);
        return Customer::create(array_merge([
            'user_id' => $user->id, 'customer_number' => $number,
            'gender' => 'male', 'preferred_lang' => 'de',
        ], $extra));
    }

    /* ---------------- Anlegen + Kundensuche ---------------- */

    public function test_aufgabe_mit_kunde_und_wiedervorlage_anlegen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer('Max Meyer', 'max@example.de', '2600001');

        $this->actingAs($admin)->post(route('admin.tasks.store'), [
            'title' => 'Kunde nachfassen: Angebot KFZ',
            'type' => 'follow_up',
            'priority' => 'high',
            'due_date' => today()->addDays(10)->toDateString(),
            'assigned_to' => $admin->id,
            'customer_id' => (string) $customer->id,
            'description' => 'In 10 Tagen anrufen und Stand erfragen.',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('tasks', [
            'title' => 'Kunde nachfassen: Angebot KFZ',
            'type' => 'follow_up',
            'customer_id' => (string) $customer->id,
            'status' => 'open',
            'auto_email_status' => null,
        ]);
    }

    public function test_kundensuche_liefert_treffer_und_respektiert_portfolio(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $mine = $this->makeCustomer('Zugewiesener Kunde', 'mine@example.de', '2600010');
        $this->makeCustomer('Fremder Kunde', 'foreign@example.de', '2600011');
        $mine->betreuer()->attach($employee->id);

        $response = $this->actingAs($employee)
            ->get(route('admin.tasks.customer_search', ['q' => 'Kunde']))
            ->assertOk();
        $response->assertJsonFragment(['name' => 'Zugewiesener Kunde']);
        $response->assertJsonMissing(['name' => 'Fremder Kunde']);

        // Platzhalter-Adressen werden nie als E-Mail angeboten
        $intern = $this->makeCustomer('Import Kunde', 'import-7@dienstly24.internal', '2600012');
        $intern->betreuer()->attach($employee->id);
        $this->actingAs($employee)->get(route('admin.tasks.customer_search', ['q' => 'Import']))
            ->assertOk()->assertJsonFragment(['email' => null]);
    }

    public function test_mitarbeiter_kann_keine_aufgabe_fuer_fremden_kunden_anlegen(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $foreign = $this->makeCustomer('Fremder Kunde', 'foreign2@example.de', '2600020');

        $this->actingAs($employee)->post(route('admin.tasks.store'), [
            'title' => 'Unerlaubte Aufgabe',
            'assigned_to' => $employee->id,
            'customer_id' => (string) $foreign->id,
        ])->assertForbidden();
    }

    /* ---------------- Verschieben + Bearbeiten ---------------- */

    public function test_aufgabe_schnell_verschieben(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $task = Task::create([
            'title' => 'Wiedervorlage', 'assigned_to' => $admin->id, 'created_by' => $admin->id,
            'type' => 'follow_up', 'status' => 'open', 'priority' => 'medium',
            'due_date' => today()->subDays(5)->toDateString(),
        ]);

        // Ueberfaellige Aufgabe: Basis ist HEUTE, nicht das alte Datum
        $this->actingAs($admin)->put(route('admin.tasks.update', $task->id), [
            'postpone_days' => 7,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(today()->addDays(7)->toDateString(), $task->fresh()->due_date->toDateString());
    }

    public function test_aufgabe_voll_bearbeiten(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'employee']);
        $customer = $this->makeCustomer('Edit Kunde', 'edit@example.de', '2600030');
        $task = Task::create([
            'title' => 'Alter Titel', 'assigned_to' => $admin->id, 'created_by' => $admin->id,
            'type' => 'other', 'status' => 'open', 'priority' => 'low',
        ]);

        $this->actingAs($admin)->put(route('admin.tasks.update', $task->id), [
            'edit' => 1,
            'title' => 'Neuer Titel',
            'type' => 'call',
            'priority' => 'high',
            'due_date' => today()->addDays(3)->toDateString(),
            'assigned_to' => $employee->id,
            'customer_id' => (string) $customer->id,
            'description' => 'Aktualisiert.',
        ])->assertRedirect()->assertSessionHas('success');

        $fresh = $task->fresh();
        $this->assertSame('Neuer Titel', $fresh->title);
        $this->assertSame('call', $fresh->type);
        $this->assertSame('high', $fresh->priority);
        $this->assertSame($employee->id, $fresh->assigned_to);
        $this->assertSame((string) $customer->id, (string) $fresh->customer_id);
    }

    public function test_erledigt_setzt_completed_at_und_wiedereroeffnen_loescht_es(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $task = Task::create([
            'title' => 'Abschliessen', 'assigned_to' => $admin->id, 'created_by' => $admin->id,
            'type' => 'other', 'status' => 'open', 'priority' => 'medium',
        ]);

        $this->actingAs($admin)->put(route('admin.tasks.update', $task->id), ['status' => 'done']);
        $this->assertNotNull($task->fresh()->completed_at);

        $this->actingAs($admin)->put(route('admin.tasks.update', $task->id), ['status' => 'open']);
        $this->assertNull($task->fresh()->completed_at);
    }

    /* ---------------- Automatische Kunden-E-Mail ---------------- */

    public function test_auto_email_planen_erfordert_kunden_mit_echter_adresse(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $intern = $this->makeCustomer('Intern Kunde', 'import-9@dienstly24.internal', '2600040');

        $this->actingAs($admin)->from(route('admin.tasks'))->post(route('admin.tasks.store'), [
            'title' => 'Mit Auto-Mail',
            'assigned_to' => $admin->id,
            'customer_id' => (string) $intern->id,
            'auto_email' => 1,
            'auto_email_subject' => 'Erinnerung',
            'auto_email_body' => 'Hallo',
            'auto_email_send_on' => today()->addDays(5)->toDateString(),
        ])->assertRedirect(route('admin.tasks'))->assertSessionHasErrors('auto_email');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_auto_email_sendetermin_nicht_in_der_vergangenheit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer('Termin Kunde', 'termin@example.de', '2600041');

        $this->actingAs($admin)->from(route('admin.tasks'))->post(route('admin.tasks.store'), [
            'title' => 'Mit Auto-Mail',
            'assigned_to' => $admin->id,
            'customer_id' => (string) $customer->id,
            'auto_email' => 1,
            'auto_email_subject' => 'Erinnerung',
            'auto_email_body' => 'Hallo',
            'auto_email_send_on' => today()->subDay()->toDateString(),
        ])->assertSessionHasErrors('auto_email_send_on');
    }

    public function test_mitarbeiter_ohne_mailrecht_darf_keine_auto_email_planen(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_send_emails' => false]);
        $customer = $this->makeCustomer('Mail Kunde', 'mailkunde@example.de', '2600042');
        $customer->betreuer()->attach($employee->id);

        $this->actingAs($employee)->post(route('admin.tasks.store'), [
            'title' => 'Mit Auto-Mail',
            'assigned_to' => $employee->id,
            'customer_id' => (string) $customer->id,
            'auto_email' => 1,
            'auto_email_subject' => 'Erinnerung',
            'auto_email_body' => 'Hallo',
            'auto_email_send_on' => today()->addDay()->toDateString(),
        ])->assertForbidden();
    }

    public function test_auto_email_wird_am_stichtag_mit_platzhaltern_versendet(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Anna Berater']);
        $customer = $this->makeCustomer('Max Meyer', 'max.meyer@example.de', '2600050');

        $this->actingAs($admin)->post(route('admin.tasks.store'), [
            'title' => 'Unterlagen nachfassen',
            'type' => 'follow_up',
            'assigned_to' => $admin->id,
            'customer_id' => (string) $customer->id,
            'due_date' => today()->toDateString(),
            'auto_email' => 1,
            'auto_email_subject' => 'Erinnerung für {{vorname}}',
            'auto_email_body' => "{{anrede}},\n\nbitte senden Sie uns die Unterlagen.\n\n{{berater}}",
            'auto_email_send_on' => today()->toDateString(),
        ])->assertSessionHas('success');

        $task = Task::firstOrFail();
        $this->assertSame('pending', $task->auto_email_status);

        $this->artisan('tasks:send-auto-emails')->assertSuccessful();

        Mail::assertQueued(DirectEmailMail::class, function (DirectEmailMail $mail) {
            return $mail->hasTo('max.meyer@example.de')
                && $mail->mailSubject === 'Erinnerung für Max'
                && str_contains($mail->mailBody, 'Sehr geehrter Herr Meyer,')
                && str_contains($mail->mailBody, 'Anna Berater');
        });

        $fresh = $task->fresh();
        $this->assertSame('sent', $fresh->auto_email_status);
        $this->assertNotNull($fresh->auto_email_sent_at);

        // Nachvollziehbarkeit: Kundenakte + Glocke
        $this->assertDatabaseHas('customer_timeline', [
            'customer_id' => (string) $customer->id,
            'type' => 'email',
        ]);
        $this->assertDatabaseHas('internal_notifications', [
            'user_id' => $admin->id,
            'dedup_key' => 'task-auto-email-'.$task->id,
        ]);

        // Idempotenz: zweiter Lauf sendet nicht erneut
        $this->artisan('tasks:send-auto-emails')->assertSuccessful();
        Mail::assertQueuedCount(1);
    }

    public function test_auto_email_vor_stichtag_wird_nicht_versendet(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer('Warte Kunde', 'warte@example.de', '2600051');

        Task::create([
            'title' => 'Spaeter senden', 'assigned_to' => $admin->id, 'created_by' => $admin->id,
            'type' => 'follow_up', 'status' => 'open', 'priority' => 'medium',
            'customer_id' => $customer->id,
            'auto_email_status' => 'pending',
            'auto_email_subject' => 'Hallo', 'auto_email_body' => 'Text',
            'auto_email_send_on' => today()->addDays(14)->toDateString(),
        ]);

        $this->artisan('tasks:send-auto-emails')->assertSuccessful();
        Mail::assertNothingQueued();
    }

    public function test_erledigte_aufgabe_ueberspringt_geplanten_versand(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer('Fertig Kunde', 'fertig@example.de', '2600052');

        $task = Task::create([
            'title' => 'Kunde hat schon reagiert', 'assigned_to' => $admin->id, 'created_by' => $admin->id,
            'type' => 'follow_up', 'status' => 'open', 'priority' => 'medium',
            'customer_id' => $customer->id,
            'auto_email_status' => 'pending',
            'auto_email_subject' => 'Nachfassen', 'auto_email_body' => 'Text',
            'auto_email_send_on' => today()->toDateString(),
        ]);

        // Erledigen stoppt den Versand sofort (Model-Hook)
        $this->actingAs($admin)->put(route('admin.tasks.update', $task->id), ['status' => 'done']);
        $this->assertSame('skipped', $task->fresh()->auto_email_status);

        $this->artisan('tasks:send-auto-emails')->assertSuccessful();
        Mail::assertNothingQueued();
    }

    public function test_gesendete_auto_email_bleibt_beim_bearbeiten_unveraendert(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer('Sent Kunde', 'sent@example.de', '2600053');
        $task = Task::create([
            'title' => 'Schon gesendet', 'assigned_to' => $admin->id, 'created_by' => $admin->id,
            'type' => 'follow_up', 'status' => 'open', 'priority' => 'medium',
            'customer_id' => $customer->id,
            'auto_email_status' => 'sent', 'auto_email_subject' => 'Original',
            'auto_email_body' => 'Original-Text', 'auto_email_sent_at' => now(),
        ]);

        $this->actingAs($admin)->put(route('admin.tasks.update', $task->id), [
            'edit' => 1, 'title' => 'Schon gesendet', 'assigned_to' => $admin->id,
            'customer_id' => (string) $customer->id,
        ])->assertRedirect();

        $fresh = $task->fresh();
        $this->assertSame('sent', $fresh->auto_email_status);
        $this->assertSame('Original', $fresh->auto_email_subject);
    }

    /* ---------------- Erinnerungen (Glocke) ---------------- */

    public function test_taegliche_erinnerung_buendelt_faellige_und_ueberfaellige_aufgaben(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        Task::create([
            'title' => 'Heute anrufen', 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'type' => 'call', 'status' => 'open', 'priority' => 'medium',
            'due_date' => today()->toDateString(),
        ]);
        Task::create([
            'title' => 'Laengst faellig', 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'type' => 'other', 'status' => 'open', 'priority' => 'high',
            'due_date' => today()->subDays(3)->toDateString(),
        ]);
        // Erledigte zaehlen nicht mit
        Task::create([
            'title' => 'Schon erledigt', 'assigned_to' => $employee->id, 'created_by' => $employee->id,
            'type' => 'other', 'status' => 'done', 'priority' => 'low',
            'due_date' => today()->toDateString(),
        ]);

        $this->artisan('tasks:remind')->assertSuccessful();

        $note = InternalNotification::where('user_id', $employee->id)
            ->where('dedup_key', 'tasks-due-'.$employee->id)->first();
        $this->assertNotNull($note);
        $this->assertSame('Aufgaben: 1 heute fällig · 1 überfällig', $note->title);

        // Zweiter Lauf: ungelesener Hinweis wird aufgefrischt statt dupliziert
        $this->artisan('tasks:remind')->assertSuccessful();
        $this->assertSame(1, InternalNotification::where('user_id', $employee->id)->count());
    }

    /* ---------------- Liste / Tabs ---------------- */

    public function test_meine_aufgaben_zeigen_nur_offene_und_ueberfaellig_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Task::create([
            'title' => 'Offen und ueberfaellig', 'assigned_to' => $admin->id, 'created_by' => $admin->id,
            'type' => 'call', 'status' => 'open', 'priority' => 'medium',
            'due_date' => today()->subDays(2)->toDateString(),
        ]);
        Task::create([
            'title' => 'Bereits erledigt', 'assigned_to' => $admin->id, 'created_by' => $admin->id,
            'type' => 'call', 'status' => 'done', 'priority' => 'medium',
        ]);

        $this->actingAs($admin)->get(route('admin.tasks', ['tab' => 'mine']))
            ->assertOk()
            ->assertSee('Offen und ueberfaellig')
            ->assertDontSee('Bereits erledigt');

        $this->actingAs($admin)->get(route('admin.tasks', ['tab' => 'mine', 'due' => 'overdue']))
            ->assertOk()->assertSee('Offen und ueberfaellig');

        $this->actingAs($admin)->get(route('admin.tasks', ['tab' => 'done']))
            ->assertOk()->assertSee('Bereits erledigt');
    }

    public function test_kunden_tab_respektiert_portfolio_scope(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $mine = $this->makeCustomer('Mein Kunde', 'mein@example.de', '2600060');
        $foreign = $this->makeCustomer('Anderer Kunde', 'anderer@example.de', '2600061');
        $mine->betreuer()->attach($employee->id);
        $other = User::factory()->create(['role' => 'admin']);

        Task::create([
            'title' => 'Aufgabe eigener Kunde', 'assigned_to' => $other->id, 'created_by' => $other->id,
            'type' => 'other', 'status' => 'open', 'priority' => 'medium', 'customer_id' => $mine->id,
        ]);
        Task::create([
            'title' => 'Aufgabe fremder Kunde', 'assigned_to' => $other->id, 'created_by' => $other->id,
            'type' => 'other', 'status' => 'open', 'priority' => 'medium', 'customer_id' => $foreign->id,
        ]);

        $this->actingAs($employee)->get(route('admin.tasks', ['tab' => 'customer']))
            ->assertOk()
            ->assertSee('Aufgabe eigener Kunde')
            ->assertDontSee('Aufgabe fremder Kunde');
    }

    public function test_suche_filtert_nach_titel_und_kundenname(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer('Sabine Sucher', 'sabine@example.de', '2600070');
        Task::create([
            'title' => 'Vertrag verlaengern', 'assigned_to' => $admin->id, 'created_by' => $admin->id,
            'type' => 'other', 'status' => 'open', 'priority' => 'medium', 'customer_id' => $customer->id,
        ]);
        Task::create([
            'title' => 'Ganz andere Aufgabe', 'assigned_to' => $admin->id, 'created_by' => $admin->id,
            'type' => 'other', 'status' => 'open', 'priority' => 'medium',
        ]);

        $this->actingAs($admin)->get(route('admin.tasks', ['tab' => 'mine', 'q' => 'Sabine']))
            ->assertOk()
            ->assertSee('Vertrag verlaengern')
            ->assertDontSee('Ganz andere Aufgabe');
    }
}
