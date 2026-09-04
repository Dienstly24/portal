<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AKTUELLE VERTRAGSSTRUKTUR = AUSSCHLIESSLICH AKTUELL AKTIVE VERTRAEGE
 * (Betreiber-Vorgabe 17.08.2026).
 *
 * Ausgangsfehler: die Struktur-Symbole in der Kundenakte zaehlten ALLE
 * Vertraege einer Sparte (countBy('type')). Ein Kunde mit einem laufenden
 * und einem abgelaufenen Stromvertrag sah dort "2" - als besitze er zwei
 * aktive Vertraege. Zugleich war "aktiv" an mehreren Stellen als roher
 * Statusvergleich (status === 'active') implementiert; ein zum Ablauf
 * gekuendigter oder nach dem Saisonende noch nicht nachgezogener Vertrag
 * zaehlte dort als aktiv.
 *
 * Diese Tests halten die EINE Definition fest (Contract::isCurrentlyActive
 * bzw. der deckungsgleiche Scope currentlyActive) und pruefen die
 * Abnahmefaelle 1-7 der Anforderung.
 */
class ContractStatusLogicTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $suffix = 'A'): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5($suffix.$user->id), 0, 8)),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function contract(Customer $customer, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $customer->id,
            'type' => 'strom',
            'insurer' => 'RheinEnergie AG',
            'status' => 'active',
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // Definition von "aktiv": Modell und Query muessen deckungsgleich sein
    // ---------------------------------------------------------------

    /**
     * Kern-Regel: welche Zustaende gelten als aktiv, welche als Historie?
     * Der PHP-Helfer und der Query-Scope muessen fuer JEDE Konstellation
     * dasselbe sagen - sonst zeigt die Liste etwas anderes als die Kennzahl.
     */
    public function test_active_definition_matches_between_model_and_query(): void
    {
        $customer = $this->makeCustomer();

        $faelle = [
            // [Beschreibung, Attribute, aktiv?]
            'laufend ohne Ablauf' => [['start_date' => now()->subYear()->toDateString()], true],
            'laufend mit Ablauf in der Zukunft' => [['start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addMonths(3)->toDateString()], true],
            // Stillschweigende Verlaengerung: blosses Ablaufdatum ist KEIN Ende.
            'Ablauf ueberschritten, keine Kuendigung' => [['start_date' => now()->subYears(2)->toDateString(), 'end_date' => now()->subMonth()->toDateString()], true],
            'Beginn in der Zukunft (Aktiv ab)' => [['start_date' => now()->addMonth()->toDateString()], true],
            'gekuendigt zum Ablauf in der Zukunft' => [['end_date' => now()->addMonths(2)->toDateString(), 'cancellation_date' => now()->toDateString()], true],
            'gekuendigt, wirksames Ende erreicht' => [['end_date' => now()->subMonth()->toDateString(), 'cancellation_date' => now()->subMonths(3)->toDateString()], false],
            'gekuendigt ohne Ablauf, Datum erreicht' => [['cancellation_date' => now()->subDay()->toDateString()], false],
            'Status gekuendigt' => [['status' => 'cancelled'], false],
            'Status abgelaufen' => [['status' => 'expired'], false],
            'Status in Bearbeitung' => [['status' => 'pending'], false],
        ];

        $erwartetAktiv = [];
        foreach ($faelle as $name => [$attrs, $aktiv]) {
            $c = $this->contract($customer, $attrs);
            $this->assertSame($aktiv, $c->isCurrentlyActive(), 'isCurrentlyActive falsch bei: '.$name);
            if ($aktiv) {
                $erwartetAktiv[] = $c->id;
            }
        }

        // Query-Fassung liefert exakt dieselbe Menge.
        $ausQuery = Contract::currentlyActive()->pluck('id')->sort()->values()->all();
        sort($erwartetAktiv);
        $this->assertSame($erwartetAktiv, $ausQuery, 'Scope currentlyActive weicht von isCurrentlyActive ab.');
    }

    /** Historie-Scope ist deckungsgleich mit isHistoric() (inkl. Nachlauf-Faelle). */
    public function test_historic_scope_matches_model(): void
    {
        $customer = $this->makeCustomer();
        $this->contract($customer, ['status' => 'cancelled']);
        $this->contract($customer, ['status' => 'expired']);
        // Status noch "active", wirksames Ende aber erreicht (Tages-Job laeuft
        // erst nachts) -> gehoert bereits in die Historie.
        $this->contract($customer, ['end_date' => now()->subWeek()->toDateString(), 'cancellation_date' => now()->subMonths(2)->toDateString()]);
        // E-Scooter nach Saisonende, Status noch "active".
        $this->contract($customer, ['type' => 'escooter', 'start_date' => now()->subYears(2)->toDateString()]);
        // Nicht Historie:
        $laufend = $this->contract($customer, ['start_date' => now()->subYear()->toDateString()]);
        $pending = $this->contract($customer, ['status' => 'pending']);

        $erwartet = Contract::all()->filter(fn ($c) => $c->isHistoric())->pluck('id')->sort()->values()->all();
        $ausQuery = Contract::historic()->pluck('id')->sort()->values()->all();

        $this->assertSame($erwartet, $ausQuery, 'Scope historic weicht von isHistoric ab.');
        $this->assertCount(4, $ausQuery);
        $this->assertNotContains($laufend->id, $ausQuery);
        $this->assertNotContains($pending->id, $ausQuery, '"In Bearbeitung" ist keine Historie.');
    }

    /** Jeder Vertrag liegt in GENAU EINER Gruppe (keine Doppelzaehlung, keine Luecke). */
    public function test_groups_are_exclusive_and_complete(): void
    {
        $customer = $this->makeCustomer();
        $this->contract($customer, ['start_date' => now()->subYear()->toDateString()]);
        $this->contract($customer, ['status' => 'pending']);
        $this->contract($customer, ['status' => 'cancelled']);
        $this->contract($customer, ['status' => 'expired']);

        $alle = Contract::count();
        $summe = Contract::currentlyActive()->count()
            + Contract::inProgress()->count()
            + Contract::historic()->count();
        $this->assertSame($alle, $summe, 'Gruppen ueberschneiden sich oder lassen Vertraege aus.');

        foreach (Contract::all() as $c) {
            $treffer = array_filter([
                Contract::GROUP_ACTIVE => $c->isCurrentlyActive(),
                Contract::GROUP_PENDING => $c->isPendingStatus(),
                Contract::GROUP_HISTORY => $c->isHistoric(),
            ]);
            $this->assertCount(1, $treffer, 'Vertrag liegt in mehreren/keiner Gruppe.');
            $this->assertSame(array_key_first($treffer), $c->statusGroup());
        }
    }

    /**
     * Anzeige und Datenlogik duerfen nie widersprechen: der Badge-Status
     * traegt die Gruppe aus derselben Quelle. Ein E-Scooter nach Saisonende
     * (Status noch "active") wird deshalb NICHT mehr als "Aktiv" angezeigt.
     */
    public function test_display_status_never_claims_active_for_ended_coverage(): void
    {
        $customer = $this->makeCustomer();
        $escooter = $this->contract($customer, [
            'type' => 'escooter',
            'start_date' => now()->subYears(2)->toDateString(),
        ]);

        $st = $escooter->displayStatus();
        $this->assertFalse($escooter->isCurrentlyActive());
        $this->assertTrue($st['historic']);
        $this->assertSame(Contract::GROUP_HISTORY, $st['group']);
        $this->assertNotSame('Aktiv', $st['label']);
        $this->assertStringStartsWith('Abgelaufen', $st['label']);

        // Gegenprobe: laufender Vertrag ist und bleibt "Aktiv".
        $laufend = $this->contract($customer, ['start_date' => now()->subYear()->toDateString()]);
        $stL = $laufend->displayStatus();
        $this->assertSame('Aktiv', $stL['label']);
        $this->assertSame(Contract::GROUP_ACTIVE, $stL['group']);
        $this->assertFalse($stL['historic']);
    }

    // ---------------------------------------------------------------
    // Abnahmefaelle 1-7 der Anforderung
    // ---------------------------------------------------------------

    /** Fall 1: Kunde hat 1 aktiven Vertrag -> Struktur zeigt 1 aktiven Vertrag. */
    public function test_fall1_single_active_contract(): void
    {
        $customer = $this->makeCustomer();
        $this->contract($customer, ['start_date' => now()->subMonths(6)->toDateString()]);

        $this->assertSame(1, Contract::currentlyActive()->count());

        $this->actingAs($this->admin())->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('Aktuelle Vertragsstruktur')
            ->assertSee('1 aktiver Vertrag');
    }

    /**
     * Fall 2 (der gemeldete Fehler): 1 aktiver + 1 gekuendigter Vertrag
     * derselben Sparte -> Struktur zaehlt genau 1. Der gekuendigte Vertrag
     * bleibt sichtbar, ist aber eindeutig als Historie gekennzeichnet.
     */
    public function test_fall2_active_plus_cancelled_counts_one(): void
    {
        $customer = $this->makeCustomer();
        $aktiv = $this->contract($customer, ['start_date' => now()->addWeeks(3)->toDateString(), 'stage' => Contract::STAGE_ANTRAG]);
        $alt = $this->contract($customer, [
            'insurer' => 'Grünwelt Energie',
            'contract_number' => '1515804',
            'status' => 'expired',
            'start_date' => now()->subYear()->toDateString(),
        ]);

        $this->assertTrue($aktiv->isCurrentlyActive());
        $this->assertTrue($alt->isHistoric());

        // Vertragsstruktur/Zaehler: genau EIN aktiver Stromvertrag.
        $this->assertSame(1, $customer->contracts()->currentlyActive()->where('type', 'strom')->count());
        $this->assertSame(1, $customer->contracts()->historic()->count());

        $html = $this->actingAs($this->admin())->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('1 aktiver Vertrag')
            ->assertSee('Historie – nicht aktiv', false)
            ->getContent();

        // Die Seite behauptet nirgends zwei aktive Vertraege; die Historie wird
        // als solche benannt (Tooltip am Struktur-Symbol).
        $this->assertStringNotContainsString('2 aktive Verträge', $html);
        $this->assertStringContainsString('beendet (Historie)', $html);
    }

    /** Fall 3: 1 aktiver + mehrere historische Vertraege -> Struktur zeigt nur den aktiven. */
    public function test_fall3_active_plus_multiple_historic(): void
    {
        $customer = $this->makeCustomer();
        $this->contract($customer, ['start_date' => now()->subMonth()->toDateString()]);
        $this->contract($customer, ['status' => 'cancelled', 'insurer' => 'Alt 1']);
        $this->contract($customer, ['status' => 'expired', 'insurer' => 'Alt 2']);
        $this->contract($customer, [
            'insurer' => 'Alt 3',
            'end_date' => now()->subWeeks(2)->toDateString(),
            'cancellation_date' => now()->subMonths(3)->toDateString(),
        ]);

        $this->assertSame(1, $customer->contracts()->currentlyActive()->count());
        $this->assertSame(3, $customer->contracts()->historic()->count());

        $this->actingAs($this->admin())->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('1 aktiver Vertrag')
            ->assertSee('3 beendete Verträge');
    }

    /** Fall 4: nur inaktive Vertraege -> KEIN aktiver Vertrag in der Struktur. */
    public function test_fall4_only_inactive_contracts(): void
    {
        $customer = $this->makeCustomer();
        $this->contract($customer, ['status' => 'cancelled']);
        $this->contract($customer, ['status' => 'expired', 'insurer' => 'Alt']);

        $this->assertSame(0, $customer->contracts()->currentlyActive()->count());

        $this->actingAs($this->admin())->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('0 aktive Verträge');

        // Kennzahl der Beraterwelt zaehlt sie ebenfalls nicht.
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk();
        $this->assertSame(0, Contract::currentlyActive()->count());
    }

    /**
     * Fall 5: Filter "Beendet / Historie" zeigt die inaktiven Vertraege, aber
     * eindeutig gekennzeichnet - und die Gruppen-Zaehler der Vertragsliste
     * trennen aktiven Bestand und Historie.
     */
    public function test_fall5_history_filter_marks_contracts_clearly(): void
    {
        $customer = $this->makeCustomer();
        $this->contract($customer, ['start_date' => now()->subMonth()->toDateString()]);
        $this->contract($customer, ['status' => 'cancelled', 'insurer' => 'Alt Strom']);

        // Die Liste filtert seit dem Umbau in der DATENBANK und oeffnet auf
        // dem aktiven Bestand: die Zaehler trennen die Gruppen, gezeigt wird
        // nur die gewaehlte.
        $this->actingAs($this->admin())->get(route('admin.contracts'))
            ->assertOk()
            // Eindeutige Bezeichnungen statt "Inaktive Verträge".
            ->assertSee('Aktiver Bestand (1)')
            ->assertSee('Beendet / Historie (1)')
            ->assertSee('data-group="aktiv"', false)
            ->assertDontSee('data-group="historie"', false);

        // Auf dem Historie-Reiter steht der beendete Vertrag - eindeutig
        // gekennzeichnet.
        $this->actingAs($this->admin())
            ->get(route('admin.contracts', ['gruppe' => 'historie']))
            ->assertOk()
            ->assertSee('Historie – nicht aktiv', false)
            ->assertSee('data-group="historie"', false)
            ->assertDontSee('data-group="aktiv"', false);
    }

    /**
     * Fall 6: ein aktiver Vertrag wird gekuendigt -> verschwindet aus der
     * aktiven Struktur und erscheint als Historie. Geprueft ueber den echten
     * Bearbeiten-Weg (Formular), damit auch der Controller-Pfad zaehlt.
     */
    public function test_fall6_cancelling_removes_contract_from_active_structure(): void
    {
        $customer = $this->makeCustomer();
        $contract = $this->contract($customer, [
            'insurer' => 'RheinEnergie AG',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addMonths(2)->toDateString(),
        ]);
        $this->assertSame(1, $customer->contracts()->currentlyActive()->count());

        // Kuendigung mit bereits erreichtem Ende (Vertrag ist damit beendet).
        $this->actingAs($this->admin())
            ->put(route('admin.contract.update', $contract->id), [
                'type' => 'strom',
                'insurer' => 'RheinEnergie AG',
                'status' => 'cancelled',
                'start_date' => now()->subYear()->toDateString(),
                'end_date' => now()->subDay()->toDateString(),
                'cancellation_date' => now()->subMonths(2)->toDateString(),
                'premium_interval' => 'monthly',
            ])->assertRedirect();

        $contract->refresh();
        $this->assertFalse($contract->isCurrentlyActive());
        $this->assertTrue($contract->isHistoric());
        $this->assertSame(0, $customer->contracts()->currentlyActive()->count());
        $this->assertSame(1, $customer->contracts()->historic()->count());

        $this->actingAs($this->admin())->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('0 aktive Verträge');
    }

    /** Fall 7: ein neuer Vertrag wird aktiv -> zaehlt sofort zum aktiven Bestand. */
    public function test_fall7_new_active_contract_is_counted(): void
    {
        $customer = $this->makeCustomer();
        $this->contract($customer, ['status' => 'expired', 'insurer' => 'Alt']);
        $this->assertSame(0, $customer->contracts()->currentlyActive()->count());

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $customer->id), [
                'type' => 'strom',
                'insurer' => 'RheinEnergie AG',
                'status' => 'active',
                'start_date' => now()->toDateString(),
                'premium_interval' => 'monthly',
            ])->assertRedirect();

        $this->assertSame(1, $customer->contracts()->currentlyActive()->count());
        $this->assertSame(1, Contract::currentlyActive()->count());

        $this->actingAs($this->admin())->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('1 aktiver Vertrag');
    }

    // ---------------------------------------------------------------
    // Einheitliche Datenlogik quer durchs System
    // ---------------------------------------------------------------

    /**
     * Sparten-Filter/Kennzahl der Kundenliste zaehlen nur Kunden mit einem
     * AKTIVEN Vertrag der Sparte - ein Kunde mit ausschliesslich beendeten
     * Stromvertraegen darf nicht als Strom-Kunde gelten.
     */
    public function test_customer_list_sparte_filter_ignores_history(): void
    {
        $mitAktiv = $this->makeCustomer('aktiv');
        $mitAktiv->user->update(['name' => 'Laufend Stromkunde']);
        $this->contract($mitAktiv, ['start_date' => now()->subMonth()->toDateString()]);
        $nurHistorie = $this->makeCustomer('historie');
        $nurHistorie->user->update(['name' => 'Beendet Stromkunde']);
        $this->contract($nurHistorie, ['status' => 'cancelled']);

        // Die Liste zeigt die Kundennamen; gefiltert wird auf "mind. ein
        // AKTIVER Vertrag der Sparte".
        $antwort = $this->actingAs($this->admin())
            ->get(route('admin.customers', ['sparte' => 'strom']))->assertOk();

        $antwort->assertSee('Laufend Stromkunde');
        $antwort->assertDontSee('Beendet Stromkunde');

        // Die Sparten-Kennzahl oben in der Liste zaehlt genauso.
        $this->assertSame(1, Customer::query()
            ->whereHas('contracts', fn ($q) => $q->currentlyActive()->where('type', 'strom'))->count());
    }

    /** Kundenportal: Kostenuebersicht und Zaehler nutzen dieselbe Definition. */
    public function test_portal_separates_active_from_history(): void
    {
        $customer = $this->makeCustomer();
        $this->contract($customer, [
            'start_date' => now()->subMonth()->toDateString(),
            'premium_amount' => 50, 'premium_interval' => 'monthly',
        ]);
        $this->contract($customer, [
            'insurer' => 'Alt Strom', 'status' => 'cancelled',
            'premium_amount' => 999, 'premium_interval' => 'monthly',
        ]);

        $this->actingAs($customer->user)->get(route('portal.contracts'))
            ->assertOk()
            ->assertSee('Laufende Verträge')
            ->assertSee('Beendete Verträge')
            // Kosten nur aus dem aktiven Vertrag (50,00 €), nie 1.049,00 €.
            ->assertSee('50,00 €')
            ->assertDontSee('1.049,00 €');

        $this->actingAs($customer->user)->get(route('portal.dashboard'))
            ->assertOk()->assertSee('Aktive Verträge');
        $this->assertSame(1, Contract::where('customer_id', $customer->id)->currentlyActive()->count());
    }

    /**
     * Wechsel-Kette (Betreiber-Regel 26.07.2026): Altvertrag "Gekündigt zum X"
     * laeuft bis X noch, Folgevertrag ist "Aktiv ab X". Beide gehoeren heute
     * zum Bestand - die Kundenakte weist den auslaufenden Vertrag deshalb
     * ausdruecklich aus, damit die Zahl 2 erklaerbar bleibt.
     */
    public function test_switch_chain_marks_expiring_contract(): void
    {
        $customer = $this->makeCustomer();
        $wechsel = now()->addMonths(2);
        $this->contract($customer, [
            'insurer' => 'Grünwelt Energie',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => $wechsel->toDateString(),
            'cancellation_date' => now()->toDateString(),
        ]);
        $this->contract($customer, [
            'insurer' => 'RheinEnergie AG',
            'start_date' => $wechsel->toDateString(),
        ]);

        $this->assertSame(2, $customer->contracts()->currentlyActive()->count());

        $this->actingAs($this->admin())->get(route('admin.customer', $customer->id))
            ->assertOk()
            ->assertSee('2 aktive Verträge')
            ->assertSee('gekündigt und läuft noch bis zum Ablauf')
            ->assertSee('Gekündigt zum '.$wechsel->format('d.m.Y'))
            ->assertSee('Aktiv ab '.$wechsel->format('d.m.Y'));
    }

    /**
     * Tages-Job und Anzeige sind sich einig: was der Job auf "cancelled"
     * stellt, war vorher schon nicht mehr aktiv (kein Sprung in der Zaehlung).
     */
    public function test_daily_job_only_confirms_what_logic_already_says(): void
    {
        $customer = $this->makeCustomer();
        $faellig = $this->contract($customer, [
            'end_date' => now()->subDay()->toDateString(),
            'cancellation_date' => now()->subMonths(2)->toDateString(),
        ]);
        $laufend = $this->contract($customer, [
            'insurer' => 'RheinEnergie AG',
            'start_date' => now()->subYear()->toDateString(),
        ]);

        $vorher = Contract::currentlyActive()->count();
        $this->artisan('contracts:apply-endings')->assertExitCode(0);
        $nachher = Contract::currentlyActive()->count();

        $this->assertSame($vorher, $nachher, 'Der Tages-Job darf die Zahl aktiver Vertraege nicht veraendern.');
        $this->assertSame('cancelled', $faellig->fresh()->status);
        $this->assertSame('active', $laufend->fresh()->status);
        $this->assertSame(1, $nachher);
    }

    /** Status-Auswahl im Formular: eindeutige Bezeichnungen + gruppierte Wirkung. */
    public function test_contract_form_status_selection_is_unambiguous(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin())->get(route('admin.contract.create', $customer->id))
            ->assertOk()
            ->assertSee('Aktiv – laufender Vertrag', false)
            ->assertSee('In Bearbeitung – noch nicht aktiv', false)
            ->assertSee('Inaktiv / Gekündigt')
            ->assertSee('Beendet / Abgelaufen')
            // Gruppierung nach Wirkung auf den Bestand.
            ->assertSee('Beendet / Historie');

        // Whitelist der Validierung stammt aus derselben Quelle.
        $this->assertSame(['active', 'pending', 'cancelled', 'expired'], Contract::statusKeys());
    }
}
