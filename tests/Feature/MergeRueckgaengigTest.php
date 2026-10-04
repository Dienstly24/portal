<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\CustomerView;
use App\Models\User;
use App\Services\Matching\CustomerMergeService;
use App\Services\Matching\CustomerMergeUndoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zusammenfuehrung rueckgaengig machen + Abschluss nach Fristablauf
 * (KI-064, PR-3b des Dubletten-Plans).
 */
class MergeRueckgaengigTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name, string $email, string $nummer, array $felder = [], array $konto = []): Customer
    {
        $user = User::factory()->create(array_merge(['role' => 'customer', 'name' => $name, 'email' => $email], $konto));

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => $nummer,
            'birth_date' => '1982-07-07',
        ], $felder));
    }

    private function merge(Customer $haupt, Customer $dup): CustomerMerge
    {
        app(CustomerMergeService::class)->merge($haupt->fresh('user'), $dup->fresh('user'), null);

        return CustomerMerge::where('duplicate_customer_id', $dup->id)->sole();
    }

    private function undo(CustomerMerge $merge): array
    {
        return app(CustomerMergeUndoService::class)->rueckgaengig($merge->fresh(), null);
    }

    public function test_rueckgaengig_macht_die_akte_wieder_eigenstaendig(): void
    {
        $haupt = $this->kunde('Ali Hamdan', 'ali@example.com', 'C-ALI00001');
        $dup = $this->kunde('Ali Hamdan', 'ali2@example.com', 'C-ALI00002', ['phone' => '0401234567']);
        $vertrag = Contract::create(['customer_id' => $dup->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        $merge = $this->merge($haupt, $dup);
        $this->assertSame('0401234567', $haupt->fresh()->phone, 'Vorbedingung: Feld ergaenzt.');

        $bilanz = $this->undo($merge);

        $this->assertNotNull(Customer::find($dup->id), 'Die Akte ist wieder sichtbar.');
        $this->assertNull(Customer::find($dup->id)->merged_into_id);
        $this->assertSame((string) $dup->id, (string) $vertrag->fresh()->customer_id);
        $this->assertNull($haupt->fresh()->phone, 'Das ergaenzte Feld wird geleert.');
        $this->assertTrue((bool) User::find($dup->user_id)->is_active, 'Der stillgelegte Zugang ist wieder offen.');
        $this->assertNotNull($merge->fresh()->undone_at);
        $this->assertSame(0, $bilanz['nicht_zurueck']);
    }

    public function test_verworfene_zeilen_kommen_zurueck(): void
    {
        $haupt = $this->kunde('Sara Ibrahim', 'sara@example.com', 'C-SARA0001');
        $dup = $this->kunde('Sara Ibrahim', 'sara2@example.com', 'C-SARA0002');
        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        CustomerView::create(['user_id' => $mitarbeiter->id, 'customer_id' => $haupt->id, 'viewed_at' => now()]);
        $ansicht = CustomerView::create(['user_id' => $mitarbeiter->id, 'customer_id' => $dup->id, 'viewed_at' => now()->subDay()]);
        $merge = $this->merge($haupt, $dup);
        $this->assertNull(CustomerView::find($ansicht->id));

        $bilanz = $this->undo($merge);

        $this->assertSame(1, $bilanz['wiederhergestellt']);
        $this->assertSame((string) $dup->id, (string) CustomerView::find($ansicht->id)->customer_id);
    }

    public function test_was_seither_woanders_haengt_bleibt_dort(): void
    {
        $haupt = $this->kunde('Mona Saeed', 'mona@example.com', 'C-MONA0001', ['phone' => null]);
        $dup = $this->kunde('Mona Saeed', 'mona2@example.com', 'C-MONA0002', ['phone' => '0301111111']);
        $dritter = $this->kunde('Dritte Person', 'dritte@example.com', 'C-DRIT0001');
        $vertrag = Contract::create(['customer_id' => $dup->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        $merge = $this->merge($haupt, $dup);

        $vertrag->update(['customer_id' => $dritter->id]);
        $haupt->fresh()->update(['phone' => '0309999999']);

        $bilanz = $this->undo($merge);

        $this->assertSame(1, $bilanz['nicht_zurueck']);
        $this->assertSame((string) $dritter->id, (string) $vertrag->fresh()->customer_id, 'Nie raten: die spaetere Zuordnung bleibt.');
        $this->assertSame('0309999999', $haupt->fresh()->phone, 'Ein seither gepflegter Wert bleibt.');
    }

    public function test_konto_tausch_wird_zurueckgenommen(): void
    {
        $haupt = $this->kunde('Karim Aziz', 'import-'.uniqid().'@dienstly24.internal', 'C-KAR00001');
        $altesKonto = $haupt->user_id;
        $dup = $this->kunde('Karim Aziz', 'karim@example.com', 'C-KAR00002', [], ['first_login_at' => now()->subMonth()]);
        $merge = $this->merge($haupt, $dup);
        $this->assertSame($dup->user_id, $haupt->fresh()->user_id, 'Vorbedingung: Konto uebernommen.');

        $this->undo($merge);

        $this->assertSame($altesKonto, $haupt->fresh()->user_id);
        $this->assertSame($dup->user_id, Customer::find($dup->id)->user_id);
    }

    public function test_vorher_gesperrter_zugang_bleibt_gesperrt(): void
    {
        $haupt = $this->kunde('Nour Hadi', 'nour@example.com', 'C-NOUR0001');
        $dup = $this->kunde('Nour Hadi', 'nour2@example.com', 'C-NOUR0002', [], ['is_active' => false]);
        $merge = $this->merge($haupt, $dup);

        $this->undo($merge);

        $this->assertFalse((bool) User::find($dup->user_id)->is_active);
    }

    public function test_nach_fristablauf_nicht_mehr_moeglich(): void
    {
        $haupt = $this->kunde('Omar Farah', 'omar@example.com', 'C-OMAR0001');
        $dup = $this->kunde('Omar Farah', 'omar2@example.com', 'C-OMAR0002');
        $merge = $this->merge($haupt, $dup);
        $merge->forceFill(['created_at' => now()->subDays(CustomerMerge::RUECKGAENGIG_TAGE + 1)])->save();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.customer.merge.undo', $merge->id))
            ->assertRedirect(route('admin.customer', $haupt->id))
            ->assertSessionHas('error');

        $this->assertNull(Customer::find($dup->id));
        $this->assertNull($merge->fresh()->undone_at);
    }

    public function test_spaeter_selbst_zusammengefuehrte_hauptakte_sperrt(): void
    {
        $a = $this->kunde('Rami Taha', 'rami1@example.com', 'C-RAMI0001');
        $b = $this->kunde('Rami Taha', 'rami2@example.com', 'C-RAMI0002');
        $c = $this->kunde('Rami Taha', 'rami3@example.com', 'C-RAMI0003');
        $erster = $this->merge($b, $a);
        $this->merge($c, $b);

        $this->assertNotSame([], app(CustomerMergeUndoService::class)->hindernisse($erster->fresh()));
    }

    public function test_admin_macht_es_ueber_die_oberflaeche_rueckgaengig_manager_nicht(): void
    {
        $haupt = $this->kunde('Lina Yousef', 'lina@example.com', 'C-LINA0001');
        $dup = $this->kunde('Lina Yousef', 'lina2@example.com', 'C-LINA0002');
        $merge = $this->merge($haupt, $dup);

        $this->actingAs(User::factory()->create(['role' => 'manager']))
            ->post(route('admin.customer.merge.undo', $merge->id));
        $this->assertNull(Customer::find($dup->id), 'Rueckgaengig ist admin-Sache.');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.customer', $haupt->id))
            ->assertOk()->assertSee('In diese Akte zusammengeführt')->assertSee('C-LINA0002');

        $this->actingAs($admin)->post(route('admin.customer.merge.undo', $merge->id))
            ->assertRedirect(route('admin.customer', $haupt->id))
            ->assertSessionHas('success');
        $this->assertNotNull(Customer::find($dup->id));
    }

    public function test_abschluss_nach_fristablauf_leert_stammdaten_behaelt_alias(): void
    {
        $haupt = $this->kunde('Hiba Nasser', 'hiba@example.com', 'C-HIBA0001');
        $dup = $this->kunde('Hiba Nasser', 'hiba2@example.com', 'C-HIBA0002', ['phone' => '0405555555', 'address_city' => 'Kiel']);
        $merge = $this->merge($haupt, $dup);

        $this->artisan('kunden:zusammenfuehrungen-abschliessen')->assertSuccessful();
        $this->assertSame('0405555555', Customer::mitArchiv()->find($dup->id)->phone, 'Innerhalb der Frist bleibt alles stehen.');

        $merge->forceFill(['created_at' => now()->subDays(CustomerMerge::RUECKGAENGIG_TAGE + 1)])->save();
        $this->artisan('kunden:zusammenfuehrungen-abschliessen')->assertSuccessful();

        $huelle = Customer::mitArchiv()->find($dup->id);
        $this->assertNull($huelle->phone);
        $this->assertNull($huelle->address_city);
        $this->assertSame('C-HIBA0002', $huelle->customer_number);
        $this->assertSame([(string) $haupt->id], Customer::query()->search('C-HIBA0002')->pluck('id')->map(fn ($id) => (string) $id)->all());
        $this->assertTrue($merge->fresh()->protokoll['abgeschlossen']);
        $this->assertStringStartsWith('archiv-', User::find($dup->user_id)->email);
        $this->assertNotSame([], app(CustomerMergeUndoService::class)->hindernisse($merge->fresh()));
    }
}
