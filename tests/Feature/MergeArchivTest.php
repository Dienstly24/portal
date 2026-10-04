<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\CustomerView;
use App\Models\User;
use App\Services\CustomerDeletionService;
use App\Services\CustomerNumberGenerator;
use App\Services\Matching\CustomerMergeService;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zusammenfuehren ARCHIVIERT statt zu loeschen (KI-064, PR-3a des
 * Dubletten-Plans): die Duplikat-Akte bleibt als unsichtbare Huelle stehen,
 * ihre Kundennummer findet den Hauptkunden, ein Protokoll haelt fest, was
 * umgehaengt und was verworfen wurde - die Grundlage fuers Rueckgaengigmachen.
 */
class MergeArchivTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name, string $email, string $nummer, array $felder = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => $nummer,
            'birth_date' => '1980-05-05',
        ], $felder));
    }

    private function merge(Customer $haupt, Customer $dup): void
    {
        app(CustomerMergeService::class)->merge($haupt->fresh('user'), $dup->fresh('user'), null);
    }

    public function test_duplikat_wird_archiviert_statt_geloescht(): void
    {
        $haupt = $this->kunde('Samir Haddad', 'samir@example.com', 'C-HAUPT01');
        $dup = $this->kunde('Samir Haddad', 'samir.alt@example.com', 'C-DUPL001');
        $vertrag = Contract::create(['customer_id' => $dup->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);

        $this->merge($haupt, $dup);

        $this->assertNull(Customer::find($dup->id), 'Fuer die Anwendung ist die Huelle nicht vorhanden.');
        $huelle = Customer::mitArchiv()->find($dup->id);
        $this->assertNotNull($huelle, 'Die Akte darf nicht mehr geloescht werden.');
        $this->assertNotNull($huelle->archived_at);
        $this->assertSame((string) $haupt->id, (string) $huelle->merged_into_id);
        $this->assertSame((string) $haupt->id, (string) $vertrag->fresh()->customer_id);

        $protokoll = CustomerMerge::where('duplicate_customer_id', $dup->id)->sole();
        $this->assertSame((string) $haupt->id, (string) $protokoll->primary_customer_id);
        $this->assertSame('C-DUPL001', $protokoll->duplicate_number);
        $this->assertContains($vertrag->id, $protokoll->protokoll['umgehaengt']['contracts']);
        $this->assertFalse((bool) User::find($dup->user_id)->is_active, 'Der unterlegene Zugang wird stillgelegt.');
    }

    public function test_verworfene_kollisionszeilen_stehen_vollstaendig_im_protokoll(): void
    {
        $haupt = $this->kunde('Rami Saleh', 'rami@example.com', 'C-RAMI0001');
        $dup = $this->kunde('Rami Saleh', 'rami2@example.com', 'C-RAMI0002');
        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        CustomerView::create(['user_id' => $mitarbeiter->id, 'customer_id' => $haupt->id, 'viewed_at' => now()]);
        CustomerView::create(['user_id' => $mitarbeiter->id, 'customer_id' => $dup->id, 'viewed_at' => now()->subDay()]);

        $this->merge($haupt, $dup);

        $verworfen = CustomerMerge::sole()->protokoll['verworfen']['customer_views'] ?? [];
        $this->assertCount(1, $verworfen);
        $this->assertSame((string) $dup->id, (string) $verworfen[0]['customer_id']);
    }

    public function test_huelle_erscheint_nicht_in_der_dublettenpruefung(): void
    {
        $haupt = $this->kunde('Lina Omar', 'lina@example.com', 'C-LINA0001');
        $dup = $this->kunde('Lina Omar', 'lina2@example.com', 'C-LINA0002');

        $this->merge($haupt, $dup);

        $this->assertSame([], app(DuplicateDetectionService::class)->scan()['pairs']);
    }

    public function test_alte_kundennummer_findet_den_hauptkunden(): void
    {
        $haupt = $this->kunde('Nadia Karim', 'nadia@example.com', 'C-NADIA001');
        $dup = $this->kunde('Nadia Karim', 'nadia2@example.com', 'C-NADIA777');

        $this->merge($haupt, $dup);

        $treffer = Customer::query()->search('C-NADIA777')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertSame([(string) $haupt->id], $treffer);
    }

    public function test_alter_link_fuehrt_zur_akte_in_der_sie_aufging(): void
    {
        $haupt = $this->kunde('Omar Aziz', 'omar@example.com', 'C-OMAR0001');
        $dup = $this->kunde('Omar Aziz', 'omar2@example.com', 'C-OMAR0002');
        $this->merge($haupt, $dup);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.customer', $dup->id))
            ->assertRedirect(route('admin.customer', $haupt->id));
    }

    public function test_ketten_zeigen_immer_auf_die_lebende_akte(): void
    {
        $a = $this->kunde('Hadi Nasser', 'hadi1@example.com', 'C-HADI0001');
        $b = $this->kunde('Hadi Nasser', 'hadi2@example.com', 'C-HADI0002');
        $c = $this->kunde('Hadi Nasser', 'hadi3@example.com', 'C-HADI0003');

        $this->merge($b, $a);
        $this->merge($c, $b);

        $this->assertSame((string) $c->id, (string) Customer::mitArchiv()->find($a->id)->merged_into_id);
        $this->assertSame((string) $c->id, Customer::aufgegangenIn((string) $a->id));
        $this->assertSame([(string) $c->id], Customer::query()->search('C-HADI0001')->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    public function test_nummernvergabe_ueberspringt_archivierte_nummern(): void
    {
        $jahr = now()->format('y');
        $haupt = $this->kunde('Rana Fares', 'rana@example.com', $jahr.'00001');
        $dup = $this->kunde('Rana Fares', 'rana2@example.com', $jahr.'00002');
        $this->merge($haupt, $dup);

        $this->assertSame($jahr.'00003', app(CustomerNumberGenerator::class)->generate());
    }

    public function test_loeschen_des_hauptkunden_nimmt_huellen_und_konten_mit(): void
    {
        $haupt = $this->kunde('Yara Salim', 'yara@example.com', 'C-YARA0001');
        $dup = $this->kunde('Yara Salim', 'yara2@example.com', 'C-YARA0002');
        $dupUserId = $dup->user_id;
        $this->merge($haupt, $dup);

        app(CustomerDeletionService::class)->delete($haupt->fresh());

        $this->assertNull(Customer::mitArchiv()->find($dup->id), 'Die Huelle traegt Daten DIESES Kunden (Art. 17 DSGVO).');
        $this->assertNull(User::find($dupUserId));
        $this->assertSame(0, CustomerMerge::count());
    }
}
