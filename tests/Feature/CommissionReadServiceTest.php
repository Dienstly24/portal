<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractCommission;
use App\Models\Customer;
use App\Models\Provision;
use App\Models\User;
use App\Models\VermittlerSettlement;
use App\Services\Commission\CommissionQuery;
use App\Services\Commission\CommissionReadService;
use App\Support\Commission\CommissionEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ARCH-3: ein Leseweg ueber alle Provisionsquellen - ohne die drei
 * Fachbereiche zu verschmelzen.
 */
class CommissionReadServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommissionReadService $dienst;

    private Contract $vertrag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dienst = app(CommissionReadService::class);

        $user = User::factory()->create(['role' => 'customer', 'name' => 'Lese Test', 'email' => 'lese@example.test']);
        $kunde = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-READ001']);
        $this->vertrag = Contract::create([
            'customer_id' => $kunde->id,
            'type' => 'kfz',
            'insurer' => 'ADAC',
            'status' => 'active',
            'start_date' => now()->subMonth()->toDateString(),
        ]);

        // EINGANG: Abrechnung eines Pools.
        ContractCommission::create([
            'contract_id' => $this->vertrag->id,
            'customer_id' => $kunde->id,
            'amount' => 120.00,
            'currency' => 'EUR',
            'commission_date' => now()->subDays(3)->toDateString(),
            'status' => 'bezahlt',
            'pool' => 'Maklerpool',
            'dedupe_key' => 'read-test-1',
        ]);

        // EINGANG: derselbe Vertrag beim Vermittler.
        VermittlerSettlement::create([
            'contract_id' => $this->vertrag->id,
            'customer_id' => $kunde->id,
            'provision' => 30.00,
            'statement_date' => now()->subDays(2)->toDateString(),
            'vermittler_id' => '9753224',
            'match_result' => 'zugeordnet',
        ]);

        // AUSGANG: Provision an den eigenen Werber.
        Provision::create([
            'contract_id' => $this->vertrag->id,
            'customer_id' => $kunde->id,
            'amount' => 45.00,
            'currency' => 'EUR',
            'status' => 'offen',
            'type' => 'neuvertrag',
        ]);
    }

    public function test_alle_drei_quellen_sind_ueber_einen_weg_lesbar(): void
    {
        $this->assertSame(
            ['contract_commissions', 'vermittler_settlements', 'provisions'],
            array_keys($this->dienst->availableSources())
        );

        $this->assertCount(3, $this->dienst->entries(new CommissionQuery));
    }

    /**
     * Der wichtigste Test hier. Eingang und Ausgang duerfen NIE zu einer
     * Zahl addiert werden: 120 + 30 rein und 45 raus ergeben keinen
     * "Umsatz" von 195 und keinen "Gewinn" von 105 - es sind zwei
     * getrennte Groessen, die Monate auseinanderfallen koennen.
     */
    public function test_eingang_und_ausgang_werden_getrennt_summiert(): void
    {
        $summen = $this->dienst->totals(new CommissionQuery);

        $this->assertSame(150.0, $summen['eingang']);
        $this->assertSame(45.0, $summen['ausgang']);
        $this->assertSame(3, $summen['anzahl']);
        $this->assertArrayNotHasKey('gesamt', $summen, 'Eine Gesamtsumme ueber beide Richtungen waere bedeutungslos.');
    }

    public function test_je_quelle_ist_erkennbar_was_sie_gebracht_hat(): void
    {
        $jeQuelle = $this->dienst->bySource(new CommissionQuery);

        $this->assertSame(120.0, $jeQuelle['contract_commissions']['summe']);
        $this->assertSame(CommissionEntry::EINGANG, $jeQuelle['contract_commissions']['richtung']);
        $this->assertSame(30.0, $jeQuelle['vermittler_settlements']['summe']);
        $this->assertSame(45.0, $jeQuelle['provisions']['summe']);
        $this->assertSame(CommissionEntry::AUSGANG, $jeQuelle['provisions']['richtung']);
    }

    /**
     * Die Frage, fuer die es bisher keinen Weg gab: ein Vertrag kann
     * gleichzeitig in allen drei Quellen stehen, und wer das sehen wollte,
     * musste drei Seiten oeffnen.
     */
    public function test_ein_vertrag_zeigt_seine_buchungen_aus_allen_quellen(): void
    {
        $eintraege = $this->dienst->forContract((string) $this->vertrag->id);

        $this->assertCount(3, $eintraege);
        $this->assertEqualsCanonicalizing(
            ['contract_commissions', 'vermittler_settlements', 'provisions'],
            $eintraege->pluck('source')->all()
        );
    }

    public function test_quellen_lassen_sich_einzeln_abfragen(): void
    {
        $nurPools = $this->dienst->entries(new CommissionQuery(sources: ['contract_commissions']));

        $this->assertCount(1, $nurPools);
        $this->assertSame('contract_commissions', $nurPools->first()->source);
    }

    /**
     * ARCH-3, Teil 2: die beiden Protokolltabellen wurden GEPRUEFT und
     * bewusst NICHT zusammengelegt. Dieser Test haelt die Begruendung an
     * den Daten fest, damit sie nicht als blosse Behauptung im Commit steht.
     */
    public function test_die_beiden_protokolle_haben_unterschiedliche_bedeutung(): void
    {
        // 1) provision_audit_logs haengt IMMER an genau einer Provision
        //    (Spalte nicht nullbar) und verlangt eine Begruendung.
        //    commission_audit_logs protokolliert auch Vorgaenge OHNE Bezug
        //    zu einer einzelnen Buchung - einen Datei-Import etwa.
        $this->assertTrue(Schema::hasColumn('provision_audit_logs', 'provision_id'));
        $this->assertTrue(Schema::hasColumn('provision_audit_logs', 'reason'));
        $this->assertFalse(Schema::hasColumn('commission_audit_logs', 'reason'));

        // 2) Das Provisions-Protokoll kennt weder Datei noch Importlauf.
        $this->assertTrue(Schema::hasColumn('commission_audit_logs', 'source_file'));
        $this->assertTrue(Schema::hasColumn('commission_audit_logs', 'import_id'));
        $this->assertFalse(Schema::hasColumn('provision_audit_logs', 'source_file'));

        // 3) Nur das Provisions-Protokoll der Pools haelt eine
        //    Klartext-Kopie des Handelnden fest - es soll das Loeschen des
        //    Benutzerkontos ueberleben.
        $this->assertTrue(Schema::hasColumn('commission_audit_logs', 'user_label'));
        $this->assertFalse(Schema::hasColumn('provision_audit_logs', 'user_label'));
    }
}
