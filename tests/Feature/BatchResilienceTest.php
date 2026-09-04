<?php

namespace Tests\Feature;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\DocumentRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Facades\Notify;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * "Ein kaputter Datensatz darf nie den ganzen Lauf stoppen."
 *
 * Die geplanten Aufgaben arbeiten Listen ab. Ohne Absicherung beendet die
 * ERSTE Ausnahme den gesamten Lauf: ein Kunde mit kaputter Adresse
 * verhinderte, dass alle anderen ihre Erinnerung bekamen - im Hintergrund,
 * also unbemerkt. Diese Tests halten fest, dass der Lauf weitergeht UND
 * am Ende ehrlich einen Fehlschlag meldet (Exitcode 1 = rot auf
 * /admin/systemzustand).
 */
class BatchResilienceTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id.uniqid()), 0, 8)),
        ], $attrs));
    }

    // ------------------------------------------------------ Der Baustein

    public function test_der_lauf_geht_nach_einem_fehler_weiter_und_meldet_ihn(): void
    {
        Log::spy();

        $befehl = new TestBatchBefehl;
        $code = $befehl->lauf([1, 2, 3, 4], fehlerBei: 2);

        // Alles ausser dem kaputten Datensatz wurde erledigt ...
        $this->assertSame([1, 3, 4], $befehl->verarbeitet);
        // ... und der Fehlschlag verschwindet nicht still.
        $this->assertSame(1, $code);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_ohne_fehler_bleibt_der_erfolgscode_erhalten(): void
    {
        $befehl = new TestBatchBefehl;

        $this->assertSame(0, $befehl->lauf([1, 2, 3]));
        $this->assertSame([1, 2, 3], $befehl->verarbeitet);
    }

    public function test_jeder_fehler_wird_einzeln_gemeldet(): void
    {
        Log::spy();

        $befehl = new TestBatchBefehl;
        // Zwei kaputte Datensaetze - beide werden uebersprungen, keiner
        // beendet den Lauf.
        $code = $befehl->lauf([1, 2, 3, 4], fehlerBei: 2, auchFehlerBei: 4);

        $this->assertSame([1, 3], $befehl->verarbeitet);
        $this->assertSame(1, $code);
        Log::shouldHaveReceived('error')->twice();
    }

    // ------------------------------------- Krankenkassenwechsel am Stichtag

    public function test_ein_kaputter_vertrag_blockiert_die_anderen_kassenwechsel_nicht(): void
    {
        $vertraege = collect(range(1, 3))->map(fn () => Contract::create([
            'customer_id' => $this->kunde()->id,
            'type' => 'krankenversicherung',
            'insurer' => 'AOK',
            'status' => 'pending',
            'start_date' => now()->subDay()->toDateString(),
        ]));

        // Der mittlere Vertrag scheitert beim Speichern - so wie es im Betrieb
        // durch einen kaputten Bezug oder eine Constraint passieren kann.
        $kaputt = $vertraege[1]->id;
        Event::listen('eloquent.updating: '.Contract::class, function (Contract $c) use ($kaputt) {
            if ($c->id === $kaputt) {
                throw new \RuntimeException('Vertrag kaputt');
            }
        });

        // Der Lauf meldet den Fehlschlag - aber erst, nachdem er alles
        // Uebrige erledigt hat.
        $this->artisan('health:apply-due-switches')->assertExitCode(1);

        $this->assertSame('active', $vertraege[0]->fresh()->status);
        $this->assertSame('active', $vertraege[2]->fresh()->status);
        // Der kaputte bleibt unveraendert und faellt im naechsten Lauf erneut an.
        $this->assertSame('pending', $vertraege[1]->fresh()->status);
    }

    public function test_kassenwechsel_ohne_fehler_meldet_erfolg(): void
    {
        $vertrag = Contract::create([
            'customer_id' => $this->kunde()->id,
            'type' => 'krankenversicherung',
            'insurer' => 'Techniker Krankenkasse',
            'status' => 'pending',
            'start_date' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('health:apply-due-switches')->assertSuccessful();

        $this->assertSame('active', $vertrag->fresh()->status);
    }

    public function test_kassenwechsel_schreibt_das_protokoll_nicht_doppelt_kodiert(): void
    {
        $kunde = $this->kunde();
        Contract::create([
            'customer_id' => $kunde->id,
            'type' => 'krankenversicherung',
            'insurer' => 'Barmer',
            'status' => 'pending',
            'start_date' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('health:apply-due-switches')->assertSuccessful();

        $eintrag = ActivityLog::where('action', 'health_switch_applied')->firstOrFail();
        // metaArray() faengt zwar doppelte Kodierung ab - gespeichert werden
        // soll sie trotzdem nie.
        $this->assertIsArray($eintrag->meta);
        $this->assertSame('Barmer', $eintrag->metaArray()['insurer']);
    }

    // --------------------------------------------- Vorgaenge automatisch schliessen

    public function test_eine_fehlgeschlagene_glocke_blockiert_das_schliessen_der_anderen_nicht(): void
    {
        $tickets = collect(range(1, 3))->map(fn ($i) => Ticket::create([
            'customer_id' => $this->kunde()->id,
            'ticket_number' => 'T-2600'.$i,
            'type' => 'other',
            'subject' => 'Testvorgang '.$i,
            'description' => 'Testbeschreibung',
            'status' => 'resolved',
            'resolved_at' => now()->subDays(10),
        ]));

        // Realistischer Ausfall: die Portal-Glocke fuer EINEN Vorgang
        // scheitert. Frueher endete damit der ganze Lauf - alle folgenden
        // geloesten Vorgaenge blieben offen.
        $kaputt = $tickets[1]->id;
        Notify::shouldReceive('push')->andReturnUsing(function (int $userId, array $attrs) use ($kaputt) {
            if (str_contains($attrs['dedup_key'] ?? '', $kaputt)) {
                throw new \RuntimeException('Glocke kaputt');
            }

            return null;
        });
        Notify::shouldReceive('pushMany')->andReturn(0);

        // Der Lauf laeuft durch und meldet den Fehler ERST danach.
        $this->artisan('tickets:auto-close')->assertExitCode(1);

        // Entscheidend: auch die Vorgaenge NACH dem kaputten sind geschlossen.
        foreach ($tickets as $ticket) {
            $this->assertSame('closed', $ticket->fresh()->status);
        }
    }

    // ------------------------------------------- Fristen-Erinnerung an Kunden

    public function test_eine_kaputte_anfrage_blockiert_die_anderen_erinnerungen_nicht(): void
    {
        $anfragen = collect(range(1, 3))->map(fn ($i) => DocumentRequest::create([
            'customer_id' => $this->kunde()->id,
            'title' => 'Nachweis '.$i,
            'status' => 'open',
            'deadline' => today()->addDay()->toDateString(),
        ]));

        $kaputt = $anfragen[1]->id;
        Event::listen('eloquent.updating: '.DocumentRequest::class, function (DocumentRequest $r) use ($kaputt) {
            if ($r->id === $kaputt) {
                throw new \RuntimeException('Anfrage kaputt');
            }
        });

        $this->artisan('document-requests:remind')->assertExitCode(1);

        // Die uebrigen Kunden wurden erinnert ...
        $this->assertNotNull($anfragen[0]->fresh()->reminder_sent_at);
        $this->assertNotNull($anfragen[2]->fresh()->reminder_sent_at);
        // ... die kaputte bleibt offen und wird morgen erneut versucht.
        $this->assertNull($anfragen[1]->fresh()->reminder_sent_at);
    }
}

/**
 * Ein minimaler Befehl, um den Baustein selbst zu pruefen - unabhaengig von
 * einer konkreten Fachaufgabe.
 */
class TestBatchBefehl extends Command
{
    use ProcessesRecordsSafely;

    public array $verarbeitet = [];

    public function lauf(array $werte, ?int $fehlerBei = null, ?int $auchFehlerBei = null): int
    {
        $this->verarbeiteEinzeln($werte, function ($wert) use ($fehlerBei, $auchFehlerBei) {
            if ($wert === $fehlerBei || $wert === $auchFehlerBei) {
                throw new \RuntimeException('kaputt');
            }
            $this->verarbeitet[] = $wert;
        }, 'Datensatz');

        return $this->ergebnisMitUebersprungenen(0);
    }
}
