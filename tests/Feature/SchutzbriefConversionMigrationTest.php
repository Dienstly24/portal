<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractRevision;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bestandsdaten-Umzug "Schutzbrief statt Sonstiges" (Betreiber-Vorgabe
 * 28.07.2026): die Daten-Migration verschiebt ADAC-/Mobilclub-Vertraege aus
 * der Sparte "Sonstige" in die neue Sparte 'schutzbrief', liest eine im
 * Freitext genannte Mitgliedschafts-Stufe als Untergruppe und protokolliert
 * alles in der Version History. KFZ-Policen des ADAC und fremde
 * "Sonstige"-Vertraege bleiben unberuehrt.
 */
class SchutzbriefConversionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_07_28_120000_convert_adac_sonstige_to_schutzbrief.php';

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    private function makeContract(Customer $customer, array $attrs): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $customer->id,
            'status' => 'active',
        ], $attrs));
    }

    private function runMigration(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();
    }

    public function test_adac_sonstige_wird_zu_schutzbrief_mit_history(): void
    {
        $customer = $this->makeCustomer();
        $c = $this->makeContract($customer, [
            'type' => 'andere', 'type_other' => 'ADAC Schutzbrief', 'insurer' => 'ADAC',
        ]);

        $this->runMigration();
        $c->refresh();

        $this->assertSame('schutzbrief', $c->type);
        $this->assertNull($c->subtype);
        $this->assertNull($c->type_other);
        $this->assertSame('Schutzbrief / Mobilclub', $c->typeLabel());

        $revs = ContractRevision::where('contract_id', $c->id)->get();
        $sparte = $revs->firstWhere('field', 'type');
        $this->assertNotNull($sparte);
        $this->assertSame('Sonstige', $sparte->old_value);
        $this->assertSame('Schutzbrief / Mobilclub', $sparte->new_value);
        $this->assertSame('system', $sparte->source);
        $this->assertNull($sparte->changed_by);

        $freitext = $revs->firstWhere('field', 'type_other');
        $this->assertNotNull($freitext);
        $this->assertSame('ADAC Schutzbrief', $freitext->old_value);
        $this->assertNull($freitext->new_value);

        // Beide Eintraege gehoeren zu EINEM Vorgang (gemeinsame batch_id).
        $this->assertSame($sparte->batch_id, $freitext->batch_id);
    }

    public function test_stufe_wird_aus_dem_freitext_uebernommen(): void
    {
        $customer = $this->makeCustomer();
        $premium = $this->makeContract($customer, [
            'type' => 'andere', 'type_other' => 'ADAC Mobil-Club Premium', 'insurer' => 'ADAC e.V.',
        ]);
        $plus = $this->makeContract($customer, [
            'type' => 'andere', 'type_other' => 'ADAC Plus Mitgliedschaft', 'insurer' => 'ADAC',
        ]);
        $basis = $this->makeContract($customer, [
            'type' => 'andere', 'type_other' => 'ACE Automobilclub Basis', 'insurer' => 'ACE',
        ]);

        $this->runMigration();

        $this->assertSame('premium', $premium->refresh()->subtype);
        $this->assertSame('Premium-Mitgliedschaft', $premium->subtypeLabel());
        $this->assertSame('plus', $plus->refresh()->subtype);
        $this->assertSame('basis', $basis->refresh()->subtype);

        $stufe = ContractRevision::where('contract_id', $premium->id)->firstWhere('field', 'subtype');
        $this->assertNotNull($stufe);
        $this->assertNull($stufe->old_value);
        $this->assertSame('Premium-Mitgliedschaft', $stufe->new_value);
    }

    public function test_fremde_vertraege_bleiben_unberuehrt(): void
    {
        $customer = $this->makeCustomer();
        // ADAC-KFZ-Police: Sparte kfz ist kein Kandidat.
        $kfz = $this->makeContract($customer, [
            'type' => 'kfz', 'insurer' => 'ADAC Autoversicherung AG',
        ]);
        // Sonstige ohne Club-/Schutzbrief-Bezug.
        $reise = $this->makeContract($customer, [
            'type' => 'andere', 'type_other' => 'Reise-Schutz', 'insurer' => 'Allianz',
        ]);

        $this->runMigration();

        $this->assertSame('kfz', $kfz->refresh()->type);
        $this->assertSame('andere', $reise->refresh()->type);
        $this->assertSame('Reise-Schutz', $reise->type_other);
        $this->assertSame(0, ContractRevision::count());
    }

    public function test_treffer_auch_ueber_den_anbieter_und_idempotent(): void
    {
        $customer = $this->makeCustomer();
        // Kein Freitext, aber der Anbieter nennt den ADAC.
        $c = $this->makeContract($customer, [
            'type' => 'andere', 'insurer' => 'ADAC',
        ]);

        $this->runMigration();
        $this->assertSame('schutzbrief', $c->refresh()->type);
        // Ohne Stufen-Nennung keine erfundene Untergruppe.
        $this->assertNull($c->subtype);

        // Zweiter Lauf (z.B. erneuter Deploy) aendert nichts mehr.
        $count = ContractRevision::count();
        $this->runMigration();
        $this->assertSame($count, ContractRevision::count());
    }
}
