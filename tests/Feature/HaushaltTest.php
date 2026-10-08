<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\Haushalt;
use App\Models\HaushaltMitglied;
use App\Models\User;
use App\Services\Haushalt\HaushaltService;
use App\Services\Matching\CustomerMergeService;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Haushalt (PR-5b, 08.10.2026): gruenden, aufnehmen, austragen ohne
 * Loeschen, genau ein Hauptansprechpartner, Vertraege des Haushalts,
 * Portfolio-Scope, Dubletten-Ausschluss, Zusammenfuehrung und die
 * Uebernahme aus "Gleicher Haushalt".
 */
class HaushaltTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kunde(string $name, array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => Str::uuid().'@example.com']);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(Str::random(8)),
        ], $attrs));
    }

    private function service(): HaushaltService
    {
        return app(HaushaltService::class);
    }

    public function test_gruenden_aufnehmen_und_hauptansprechpartner(): void
    {
        $vater = $this->kunde('Maher Abboud');
        $sohn = $this->kunde('Ahmad Abboud');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.customer.haushalt.gruenden', $vater->id), ['name' => 'Familie Abboud'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $haushalt = Haushalt::sole();
        $this->assertSame('Familie Abboud', $haushalt->name);
        $this->assertTrue(HaushaltMitglied::where('customer_id', $vater->id)->sole()->hauptansprechpartner);

        $this->actingAs($admin)->post(route('admin.haushalt.aufnehmen', $haushalt->id), [
            'customer_id' => (string) $sohn->id, 'von_kunde' => (string) $vater->id,
        ])->assertSessionHas('success');
        $this->assertSame(2, $haushalt->aktuelleMitglieder()->count());

        $sohnZeile = HaushaltMitglied::where('customer_id', $sohn->id)->sole();
        $this->actingAs($admin)->post(route('admin.haushalt.hauptansprechpartner', $sohnZeile->id))->assertSessionHas('success');
        $this->assertTrue($sohnZeile->fresh()->hauptansprechpartner);
        $this->assertFalse(HaushaltMitglied::where('customer_id', $vater->id)->sole()->hauptansprechpartner, 'Es gibt genau einen');

        $this->actingAs($admin)->get(route('admin.customer', $vater->id))
            ->assertOk()->assertSee('Familie Abboud')->assertSee('Ahmad Abboud')->assertSee('Hauptansprechpartner');
    }

    public function test_ein_kunde_gehoert_nur_zu_einem_aktuellen_haushalt(): void
    {
        $a = $this->kunde('Erste Person');
        $b = $this->kunde('Zweite Person');
        $h1 = $this->service()->gruenden($a);
        $this->service()->gruenden($b);

        $this->actingAs($this->admin())->post(route('admin.haushalt.aufnehmen', $h1->id), [
            'customer_id' => (string) $b->id, 'von_kunde' => (string) $a->id,
        ])->assertSessionHas('error');
        $this->assertSame(1, $h1->aktuelleMitglieder()->count());
    }

    public function test_austragen_loescht_nichts_und_der_hauptansprechpartner_bleibt_leer(): void
    {
        $a = $this->kunde('Haupt Person');
        $b = $this->kunde('Neben Person');
        $haushalt = $this->service()->gruenden($a);
        $this->service()->aufnehmen($haushalt, $b);
        $zeileA = HaushaltMitglied::where('customer_id', $a->id)->sole();

        $this->actingAs($this->admin())->post(route('admin.haushalt.austragen', $zeileA->id))->assertSessionHas('success');

        $zeileA->refresh();
        $this->assertNotNull($zeileA->valid_until, 'Die Zeile bleibt als Historie');
        $this->assertFalse($zeileA->istAktuell());
        $this->assertFalse($zeileA->hauptansprechpartner);
        $this->assertSame(2, HaushaltMitglied::count());
        $this->assertNotNull(Customer::find($a->id));
        // Niemand rueckt automatisch nach - die Karte sagt es.
        $this->assertFalse(HaushaltMitglied::where('customer_id', $b->id)->sole()->hauptansprechpartner);
        $this->assertContains('Kein Hauptansprechpartner festgelegt.', $this->service()->uebersicht($b)['warnungen']);

        // Ausgetragen ist frei: neuer Haushalt moeglich, Wiedereinzug oeffnet die alte Zeile.
        $this->service()->aufnehmen($haushalt, $a);
        $this->assertSame(2, HaushaltMitglied::count());
        $this->assertTrue($zeileA->fresh()->istAktuell());
    }

    public function test_vertraege_des_haushalts_ohne_fremdvertraege_in_der_summe(): void
    {
        $a = $this->kunde('Vertrag Eins');
        $b = $this->kunde('Vertrag Zwei');
        $haushalt = $this->service()->gruenden($a);
        $this->service()->aufnehmen($haushalt, $b);
        Contract::create(['customer_id' => $a->id, 'type' => 'kfz', 'insurer' => 'DA Direkt', 'status' => 'active',
            'start_date' => now()->subMonth()->toDateString(), 'premium_amount' => 40, 'premium_interval' => 'monthly']);
        Contract::create(['customer_id' => $b->id, 'type' => 'haftpflicht', 'insurer' => 'HUK', 'status' => 'active',
            'start_date' => now()->subMonth()->toDateString(), 'premium_amount' => 120, 'premium_interval' => 'yearly']);
        Contract::create(['customer_id' => $b->id, 'type' => 'kfz', 'insurer' => 'ADAC', 'status' => 'active',
            'start_date' => now()->subMonth()->toDateString(), 'premium_amount' => 80, 'premium_interval' => 'monthly',
            'origin' => Contract::ORIGIN_EXTERNAL, 'origin_verified' => true]);
        Contract::create(['customer_id' => $b->id, 'type' => 'hausrat', 'insurer' => 'Alt', 'status' => 'cancelled',
            'start_date' => now()->subYears(2)->toDateString(), 'premium_amount' => 10, 'premium_interval' => 'monthly']);

        $u = $this->service()->uebersicht($a);
        $this->assertCount(2, $u['vertraege']);
        $this->assertSame(50.0, (float) $u['monatsbeitrag']);
        $this->assertSame(1, $u['fremdvertraege']);
    }

    public function test_fremde_mitglieder_nur_als_anzahl_und_kein_zugriff(): void
    {
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $eigener = $this->kunde('Eigener Kunde');
        $fremder = $this->kunde('Geheimer Fremdkunde');
        $eigener->betreuer()->attach($mitarbeiter->id);
        $haushalt = $this->service()->gruenden($eigener);
        $this->service()->aufnehmen($haushalt, $fremder);

        $this->actingAs($mitarbeiter)->get(route('admin.customer', $eigener->id))
            ->assertOk()->assertDontSee('Geheimer Fremdkunde')->assertSee('weitere(s) Mitglied(er)');

        $fremdZeile = HaushaltMitglied::where('customer_id', $fremder->id)->sole();
        $this->actingAs($mitarbeiter)->post(route('admin.haushalt.austragen', $fremdZeile->id))->assertForbidden();
        $this->assertTrue($fremdZeile->fresh()->istAktuell());

        // Aufnehmen eines fremden Kunden: verboten.
        $dritter = $this->kunde('Noch Fremder');
        $this->actingAs($mitarbeiter)->post(route('admin.haushalt.aufnehmen', $haushalt->id), [
            'customer_id' => (string) $dritter->id, 'von_kunde' => (string) $eigener->id,
        ])->assertForbidden();
    }

    public function test_aufnehmen_nur_aus_einer_akte_des_haushalts(): void
    {
        $a = $this->kunde('Haushalt A');
        $b = $this->kunde('Ausserhalb B');
        $c = $this->kunde('Ausserhalb C');
        $haushalt = $this->service()->gruenden($a);

        $this->actingAs($this->admin())->post(route('admin.haushalt.aufnehmen', $haushalt->id), [
            'customer_id' => (string) $c->id, 'von_kunde' => (string) $b->id,
        ])->assertForbidden();
    }

    public function test_haushalt_ist_keine_dublette(): void
    {
        $phone = '040'.random_int(100000, 999999);
        $a = $this->kunde('Anna Haus', ['phone' => $phone]);
        $b = $this->kunde('Bernd Haus', ['phone' => $phone]);
        $hatPaar = function () use ($a, $b) {
            foreach (app(DuplicateDetectionService::class)->scan()['pairs'] as $p) {
                $ids = [(string) $p['primary']->id, (string) $p['duplicate']->id];
                if (in_array((string) $a->id, $ids, true) && in_array((string) $b->id, $ids, true)) {
                    return true;
                }
            }

            return false;
        };
        $this->assertTrue($hatPaar());

        $haushalt = $this->service()->gruenden($a);
        $this->service()->aufnehmen($haushalt, $b);
        $this->assertFalse($hatPaar(), 'Mitbewohner sind keine Dublette');

        $this->service()->austragen(HaushaltMitglied::where('customer_id', $b->id)->sole());
        $this->assertTrue($hatPaar(), 'Ausgezogen: das Paar darf wieder erscheinen');
    }

    public function test_zusammenfuehren_haengt_die_mitgliedschaft_um_ohne_doppelte_zeile(): void
    {
        $haupt = $this->kunde('Max Muster', ['birth_date' => '1980-01-01']);
        $dublette = $this->kunde('Max Muster', ['birth_date' => '1980-01-01']);
        $partnerin = $this->kunde('Erika Muster');
        $haushalt = $this->service()->gruenden($haupt);
        $this->service()->aufnehmen($haushalt, $dublette);
        $this->service()->aufnehmen($haushalt, $partnerin);

        app(CustomerMergeService::class)->merge($haupt, $dublette);

        $this->assertSame(1, HaushaltMitglied::where('customer_id', $haupt->id)->count());
        $this->assertSame(2, $haushalt->aktuelleMitglieder()->count());
    }

    public function test_uebernahme_aus_gleicher_haushalt_probelauf_ausfuehren_zuruecknehmen(): void
    {
        $a = $this->kunde('Ueber A');
        $b = $this->kunde('Ueber B');
        $c = $this->kunde('Ueber C');
        $d = $this->kunde('Schon Drin D');
        $e = $this->kunde('Partner E');
        foreach ([[$a, $b], [$b, $c], [$d, $e]] as [$x, $y]) {
            [$k1, $k2] = CustomerRelationship::pairKey((string) $x->id, (string) $y->id);
            CustomerRelationship::create(['customer_a_id' => $k1, 'customer_b_id' => $k2, 'type' => 'gleicher_haushalt']);
        }
        $this->service()->gruenden($d);

        $this->artisan('haushalte:aus-beziehungen-bilden')->expectsOutputToContain('1 Haushalt(e) mit 3 Person(en), 1 übersprungen')->assertSuccessful();
        $this->assertSame(1, Haushalt::count(), 'Probelauf schreibt nichts');

        $this->artisan('haushalte:aus-beziehungen-bilden', ['--ausfuehren' => true])->assertSuccessful();
        $neu = Haushalt::where('herkunft', Haushalt::HERKUNFT_UEBERNAHME)->sole();
        $this->assertSame(3, $neu->aktuelleMitglieder()->count());
        $this->assertSame(0, $neu->mitglieder()->where('hauptansprechpartner', true)->count(), 'Niemand wird bestimmt');
        $this->assertSame(0, $neu->mitglieder()->whereNotNull('valid_from')->count(), 'Kein erfundenes Einzugsdatum');
        $this->assertSame(3, CustomerRelationship::where('type', 'gleicher_haushalt')->count(), 'Beziehungen bleiben');

        // Zweiter Lauf: alles schon vergeben, nichts doppelt.
        $this->artisan('haushalte:aus-beziehungen-bilden', ['--ausfuehren' => true])->assertSuccessful();
        $this->assertSame(2, Haushalt::count());

        $this->artisan('haushalte:aus-beziehungen-bilden', ['--zuruecknehmen' => true, '--ausfuehren' => true])->assertSuccessful();
        $this->assertSame(0, Haushalt::where('herkunft', Haushalt::HERKUNFT_UEBERNAHME)->count());
        $this->assertSame(1, Haushalt::count(), 'Manuelle Haushalte bleiben');
    }

    public function test_zuruecknehmen_laesst_bearbeitete_uebernahmen_stehen(): void
    {
        $a = $this->kunde('Bearbeitet A');
        $b = $this->kunde('Bearbeitet B');
        [$k1, $k2] = CustomerRelationship::pairKey((string) $a->id, (string) $b->id);
        CustomerRelationship::create(['customer_a_id' => $k1, 'customer_b_id' => $k2, 'type' => 'gleicher_haushalt']);
        $this->artisan('haushalte:aus-beziehungen-bilden', ['--ausfuehren' => true])->assertSuccessful();
        $this->service()->hauptansprechpartnerSetzen(HaushaltMitglied::where('customer_id', $a->id)->sole());

        $this->artisan('haushalte:aus-beziehungen-bilden', ['--zuruecknehmen' => true, '--ausfuehren' => true])
            ->expectsOutputToContain('behalten (seither bearbeitet): 1')->assertSuccessful();
        $this->assertSame(1, Haushalt::count());
    }
}
