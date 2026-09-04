<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractSwitchReminder;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Zwei Fehler, die AUSSCHLIESSLICH auf MySQL sichtbar wurden - also genau
 * dort, wo der Betrieb laeuft, und nirgends in der bisherigen Testsuite.
 *
 * Beide Tests laufen bewusst auch auf SQLite: sie pruefen nicht das
 * Datenbankverhalten, sondern das, was auf SQLite still danebenging.
 */
class MysqlCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * FEHLER 1: `Customer::...->orWhere('email', ...)` - die Spalte `email`
     * gibt es auf `customers` gar nicht, die Zweitadresse heisst `email2`.
     *
     * Warum das nie auffiel: Laravel quotet Bezeichner ("email"), und SQLite
     * behandelt einen doppelt gequoteten Namen, der KEINE Spalte ist, als
     * STRING-LITERAL. Aus der Bedingung wurde damit 'email' = 'max@…' - immer
     * falsch. Der halbe Suchzweig war also seit jeher tot, ohne einen
     * einzigen Fehler. MySQL lehnt dieselbe Abfrage hart ab (1054).
     */
    public function test_kunde_wird_ueber_die_zweitadresse_gefunden(): void
    {
        $this->assertFalse(
            Schema::hasColumn('customers', 'email'),
            'customers hat keine Spalte email - Abfragen darauf sind ein Fehler, kein Treffer.'
        );

        $user = User::factory()->create(['role' => 'customer', 'email' => 'login@example.test']);
        $kunde = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-MYSQL01',
            'email2' => 'zweit@example.test',
        ]);

        // Genau die Abfrageform, die in vier Controllern steht.
        $treffer = Customer::whereHas('user', fn ($q) => $q->where('email', 'zweit@example.test'))
            ->orWhere('email2', 'zweit@example.test')
            ->first();

        $this->assertNotNull($treffer, 'Die Zweitadresse muss den Bestandskunden finden.');
        $this->assertSame((string) $kunde->id, (string) $treffer->id);
    }

    /**
     * Die vier Fundstellen duerfen nicht wieder auf `email` zurueckfallen.
     */
    public function test_kein_controller_fragt_customers_nach_email(): void
    {
        foreach ([
            'ServicePageController', 'SupportFormController',
            'WebsiteContactController', 'WebsiteController',
        ] as $controller) {
            $quelle = file_get_contents(app_path("Http/Controllers/{$controller}.php"));

            $this->assertStringNotContainsString(
                "orWhere('email',",
                $quelle,
                "{$controller} fragt customers nach der nicht existierenden Spalte email - auf MySQL ist das ein harter Fehler."
            );
        }
    }

    /**
     * FEHLER 2: `contract_switch_reminders.stage` war varchar(10), die
     * Kennung "schutzbrief_renewal" hat 19 Zeichen.
     *
     * SQLite ignoriert Laengenangaben bei VARCHAR - deshalb fiel es nie auf.
     * Auf MySQL scheiterte der INSERT (SQLSTATE 22001), und weil der Eintrag
     * VOR dem Mailversand geschrieben wird, ist die Schutzbrief-Erinnerung
     * in Produktion nie beim Kunden angekommen. Still, jeden Tag.
     */
    public function test_die_stage_spalte_fasst_die_laengste_kennung(): void
    {
        $laengste = 'schutzbrief_renewal';

        $user = User::factory()->create(['role' => 'customer', 'email' => 'stage@example.test']);
        $kunde = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-STAGE01']);
        $vertrag = Contract::create([
            'customer_id' => $kunde->id,
            'type' => 'kfz',
            'insurer' => 'ADAC',
            'status' => 'active',
            'start_date' => now()->subMonth()->toDateString(),
        ]);

        // Geprueft wird das VERHALTEN, nicht die Typangabe: SQLite ignoriert
        // Laengen bei VARCHAR und meldet sie auch nicht zurueck - genau
        // deshalb ist der Fehler dort nie aufgefallen. Auf MySQL scheitert
        // dieser INSERT, solange die Spalte zu kurz ist.
        $eintrag = ContractSwitchReminder::create([
            'contract_id' => $vertrag->id,
            'stage' => $laengste,
            'anchor' => now()->addYear()->toDateString(),
            'sent_at' => now(),
        ]);

        $this->assertSame(
            $laengste,
            (string) $eintrag->fresh()->stage,
            'Die Kennung wurde gekuerzt gespeichert - dann greift die Idempotenz nicht mehr.'
        );
    }
}
