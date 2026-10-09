<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sammelaktionen der Aufgabenliste (Betreiber-Auftrag 09.10.2026: 929
 * Aufgaben, davon 896 ueberfaellig, liessen sich nur einzeln bearbeiten).
 *
 * Ohne die neue Route scheitert jeder Fall mit 404.
 */
class AufgabenSammelaktionTest extends TestCase
{
    use RefreshDatabase;

    private function aufgabe(User $an, array $extra = []): Task
    {
        return Task::create(array_merge([
            'assigned_to' => $an->id, 'created_by' => $an->id,
            'title' => 'Aufgabe '.Str::random(5), 'type' => 'email',
            'status' => 'open', 'priority' => 'medium',
            'due_date' => today()->subDays(10)->toDateString(),
        ], $extra));
    }

    public function test_auswahl_als_erledigt_markieren_bildet_den_modell_hook_nach(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = $this->aufgabe($admin, ['auto_email_status' => 'pending', 'auto_email_subject' => 'X', 'auto_email_body' => 'Y', 'auto_email_send_on' => today()->addDay()->toDateString()]);
        $b = $this->aufgabe($admin);
        $c = $this->aufgabe($admin);

        $this->actingAs($admin)->post(route('admin.tasks.bulk'), [
            'aktion' => 'erledigt', 'auswahl' => 'ids', 'ids' => [$a->id, $b->id],
        ])->assertRedirect()->assertSessionHas('success', '2 Aufgabe(n) als erledigt markiert.');

        $a->refresh();
        $b->refresh();
        $c->refresh();
        $this->assertSame('done', $a->status);
        $this->assertNotNull($a->completed_at);
        $this->assertSame('skipped', $a->auto_email_status, 'eine geplante Mail einer erledigten Aufgabe darf nie rausgehen');
        $this->assertSame('done', $b->status);
        $this->assertSame('open', $c->status, 'nicht ausgewaehlt bleibt unberuehrt');
        $this->assertTrue(ActivityLog::where('action', 'tasks_bulk')->exists());
    }

    public function test_alle_treffer_des_filters_folgen_derselben_abfrage_wie_die_liste(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ueberfaellig = collect(range(1, 35))->map(fn () => $this->aufgabe($admin));
        $kuenftig = $this->aufgabe($admin, ['due_date' => today()->addDays(5)->toDateString()]);

        $this->actingAs($admin)->post(route('admin.tasks.bulk'), [
            'aktion' => 'verschieben', 'tage' => 7, 'auswahl' => 'filter',
            'tab' => 'mine', 'due' => 'overdue',
        ])->assertRedirect()->assertSessionHas('success');

        $neu = today()->addDays(7)->toDateString();
        foreach ($ueberfaellig as $t) {
            $this->assertSame($neu, $t->refresh()->due_date->toDateString());
        }
        $this->assertSame(today()->addDays(5)->toDateString(), $kuenftig->refresh()->due_date->toDateString(),
            'nicht im Filter "ueberfaellig" - darf nicht mitverschoben werden');
    }

    public function test_verschieben_einer_kuenftigen_faelligkeit_rechnet_ab_ihrem_datum(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $t = $this->aufgabe($admin, ['due_date' => today()->addDays(5)->toDateString()]);

        $this->actingAs($admin)->post(route('admin.tasks.bulk'), [
            'aktion' => 'verschieben', 'tage' => 3, 'auswahl' => 'ids', 'ids' => [$t->id],
        ]);
        $this->assertSame(today()->addDays(8)->toDateString(), $t->refresh()->due_date->toDateString());
    }

    public function test_loeschen_verlangt_bestaetigung_und_laesst_sich_zuruecknehmen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = $this->aufgabe($admin, ['description' => 'Wichtige Notiz']);
        $b = $this->aufgabe($admin);

        $this->actingAs($admin)->post(route('admin.tasks.bulk'), [
            'aktion' => 'loeschen', 'auswahl' => 'ids', 'ids' => [$a->id, $b->id],
        ])->assertSessionHas('error');
        $this->assertSame(2, Task::count(), 'ohne Bestaetigung wird nichts geloescht');

        $antwort = $this->actingAs($admin)->post(route('admin.tasks.bulk'), [
            'aktion' => 'loeschen', 'auswahl' => 'ids', 'ids' => [$a->id, $b->id], 'bestaetigt' => 1,
        ])->assertSessionHas('aufgaben_rueckgaengig');
        $this->assertSame(0, Task::count());

        $token = $antwort->baseResponse->getSession()->get('aufgaben_rueckgaengig')['token'];
        $this->actingAs($admin)->post(route('admin.tasks.bulk_undo'), ['token' => $token])
            ->assertSessionHas('success', '2 Aufgabe(n) wiederhergestellt.');
        $this->assertSame(2, Task::count());
        $this->assertSame('Wichtige Notiz', Task::find($a->id)->description, 'mit alter ID und vollem Inhalt zurueck');

        // Der Schluessel ist verbraucht
        $this->actingAs($admin)->post(route('admin.tasks.bulk_undo'), ['token' => $token])->assertSessionHas('error');
    }

    public function test_rueckgaengig_einer_statusaenderung_stellt_die_alten_werte_her(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kollege = User::factory()->create(['role' => 'employee']);
        $t = $this->aufgabe($admin, ['status' => 'in_progress']);

        $antwort = $this->actingAs($admin)->post(route('admin.tasks.bulk'), [
            'aktion' => 'zuweisen', 'assigned_to' => $kollege->id, 'auswahl' => 'ids', 'ids' => [$t->id],
        ]);
        $this->assertSame($kollege->id, $t->refresh()->assigned_to);

        $token = $antwort->baseResponse->getSession()->get('aufgaben_rueckgaengig')['token'];
        $this->actingAs($admin)->post(route('admin.tasks.bulk_undo'), ['token' => $token]);
        $this->assertSame($admin->id, $t->refresh()->assigned_to);
        $this->assertSame('in_progress', $t->status);
    }

    public function test_fremder_kann_eine_rueckgaengig_marke_nicht_benutzen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $anderer = User::factory()->create(['role' => 'admin']);
        $t = $this->aufgabe($admin);
        $antwort = $this->actingAs($admin)->post(route('admin.tasks.bulk'), [
            'aktion' => 'loeschen', 'auswahl' => 'ids', 'ids' => [$t->id], 'bestaetigt' => 1,
        ]);
        $token = $antwort->baseResponse->getSession()->get('aufgaben_rueckgaengig')['token'];

        $this->actingAs($anderer)->post(route('admin.tasks.bulk_undo'), ['token' => $token])->assertSessionHas('error');
        $this->assertSame(0, Task::count());
    }

    public function test_mitarbeiter_trifft_nur_eigene_und_portfolio_aufgaben(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $andererMa = User::factory()->create(['role' => 'employee']);
        $eigene = $this->aufgabe($employee);
        $fremde = $this->aufgabe($andererMa);
        $kundeUser = User::factory()->create(['role' => 'customer']);
        $fremderKunde = Customer::create(['user_id' => $kundeUser->id, 'customer_number' => '2690001']);
        $fremdeKundenaufgabe = $this->aufgabe($andererMa, ['customer_id' => $fremderKunde->id]);

        $this->actingAs($employee)->post(route('admin.tasks.bulk'), [
            'aktion' => 'erledigt', 'auswahl' => 'ids',
            'ids' => [$eigene->id, $fremde->id, $fremdeKundenaufgabe->id],
        ])->assertSessionHas('success', '1 Aufgabe(n) als erledigt markiert.');

        $this->assertSame('done', $eigene->refresh()->status);
        $this->assertSame('open', $fremde->refresh()->status, 'eine fremde ID im Formular wird nicht getroffen');
        $this->assertSame('open', $fremdeKundenaufgabe->refresh()->status);
    }

    public function test_unbekannter_reiter_zeigt_keine_fremden_aufgaben(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $andererMa = User::factory()->create(['role' => 'employee']);
        $this->aufgabe($andererMa, ['title' => 'Fremde geheime Aufgabe']);

        $this->actingAs($employee)->get(route('admin.tasks', ['tab' => 'alle']))
            ->assertOk()->assertDontSee('Fremde geheime Aufgabe');

        // ... und die Sammelaktion ueber "alle Treffer" eines solchen Reiters trifft sie ebenfalls nicht
        $this->actingAs($employee)->post(route('admin.tasks.bulk'), [
            'aktion' => 'erledigt', 'auswahl' => 'filter', 'tab' => 'alle',
        ])->assertSessionHas('error');
        $this->assertSame('open', Task::first()->status);
    }

    public function test_viele_aufgaben_brauchen_bestaetigung_und_laufen_in_einem_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        collect(range(1, 60))->each(fn () => $this->aufgabe($admin));

        $this->actingAs($admin)->post(route('admin.tasks.bulk'), [
            'aktion' => 'erledigt', 'auswahl' => 'filter', 'tab' => 'mine',
        ])->assertSessionHas('error');
        $this->assertSame(0, Task::where('status', 'done')->count());

        \DB::enableQueryLog();
        $this->actingAs($admin)->post(route('admin.tasks.bulk'), [
            'aktion' => 'erledigt', 'auswahl' => 'filter', 'tab' => 'mine', 'bestaetigt' => 1,
        ])->assertSessionHas('success', '60 Aufgabe(n) als erledigt markiert.');
        $updates = collect(\DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'update "tasks"') || str_starts_with(strtolower($q['query']), 'update `tasks`'));
        $this->assertLessThanOrEqual(3, $updates->count(), 'keine Einzel-Updates je Aufgabe');
        $this->assertSame(60, Task::where('status', 'done')->count());
    }

    public function test_fehlende_angaben_werden_verstaendlich_gemeldet(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $t = $this->aufgabe($admin);

        $this->actingAs($admin)->post(route('admin.tasks.bulk'), ['aktion' => 'zuweisen', 'auswahl' => 'ids', 'ids' => [$t->id]])
            ->assertSessionHasErrors(['assigned_to' => 'Bitte einen Mitarbeiter für die Zuweisung wählen.']);
        $this->actingAs($admin)->post(route('admin.tasks.bulk'), ['aktion' => 'verschieben', 'auswahl' => 'ids', 'ids' => [$t->id]])
            ->assertSessionHasErrors('tage');
        $this->actingAs($admin)->post(route('admin.tasks.bulk'), ['aktion' => 'status', 'auswahl' => 'ids', 'ids' => [$t->id]])
            ->assertSessionHasErrors(['neuer_status' => 'Bitte den neuen Status wählen.']);
    }

    public function test_liste_zeigt_auswahl_und_aktionsleiste(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        collect(range(1, 31))->each(fn () => $this->aufgabe($admin));

        $this->actingAs($admin)->get(route('admin.tasks'))->assertOk()
            ->assertSee('data-sammel-item', false)
            ->assertSee('Alle auf dieser Seite auswählen (30)')
            ->assertSee('Alle 31 Treffer des aktuellen Filters auswählen');
    }
}
