<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\GeteilterKontaktwert;
use App\Models\User;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PR-4 des Dubletten-Plans: gemeinsam genutzte Kontaktdaten (KI-069 und M2
 * des Berichts vom 03.10.2026).
 *
 * - Eine Familien-E-Mail, ein Festnetz, das Konto der Eltern oder ein
 *   Mehrfamilienhaus lassen sich als "gemeinsam genutzt" markieren und
 *   bilden danach kein Verdachtspaar mehr.
 * - "Nagelshof 20 51": dieselbe Hausnummer in Strasse UND Feld wird einmal
 *   gezeigt und ergibt denselben Haushalts-Schluessel wie die saubere Akte.
 */
class GeteilteKontaktdatenTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name, string $email, array $felder = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5($email.microtime()), 0, 8)),
        ], $felder));
    }

    private function paar(Customer $a, Customer $b): ?array
    {
        foreach (app(DuplicateDetectionService::class)->scan()['pairs'] as $p) {
            $ids = [(string) $p['primary']->id, (string) $p['duplicate']->id];
            if (in_array((string) $a->id, $ids, true) && in_array((string) $b->id, $ids, true)) {
                return $p;
            }
        }

        return null;
    }

    /** Mutter und Tochter teilen nur die Familien-E-Mail. */
    private function mutterUndTochter(): array
    {
        $mutter = $this->kunde('Rana Haddad', 'rana@example.com', ['birth_date' => '1975-01-01']);
        $tochter = $this->kunde('Lina Saleh', 'lina@example.com', ['birth_date' => '2004-06-06', 'email2' => 'rana@example.com']);

        return [$mutter, $tochter];
    }

    public function test_doppelte_hausnummer_wird_einmal_gezeigt_und_ergibt_denselben_haushalt(): void
    {
        $doppelt = $this->kunde('A Eins', 'a1@example.com', ['address_street' => 'Nagelshof 20', 'address_house_number' => '20', 'address_zip' => '51069', 'address_city' => 'Köln']);
        $sauber = $this->kunde('B Zwei', 'b2@example.com', ['address_street' => 'Nagelshof', 'address_house_number' => '20', 'address_zip' => '51069', 'address_city' => 'Köln']);

        $this->assertSame('Nagelshof 20, 51069 Köln', $doppelt->fullAddress());
        $this->assertSame($sauber->householdKey(), $doppelt->householdKey());
    }

    public function test_strasse_und_str_ergeben_denselben_haushalt(): void
    {
        $a = $this->kunde('A Eins', 'a1@example.com', ['address_street' => 'Hauptstraße', 'address_house_number' => '5', 'address_zip' => '24103', 'address_city' => 'Kiel']);
        $b = $this->kunde('B Zwei', 'b2@example.com', ['address_street' => 'Hauptstr.', 'address_house_number' => '5', 'address_zip' => '24103', 'address_city' => 'Kiel']);

        $this->assertSame($a->householdKey(), $b->householdKey());
        $this->assertContains('Gleiche Anschrift', $this->paar($a, $b)['signals'] ?? []);
    }

    public function test_widerspruechliche_hausnummer_wird_nicht_geraten(): void
    {
        $c = $this->kunde('A Eins', 'a1@example.com', ['address_street' => 'Nagelshof 20', 'address_house_number' => '51', 'address_zip' => '51069', 'address_city' => 'Köln']);

        $this->assertSame('Nagelshof 20 51, 51069 Köln', $c->fullAddress());
    }

    public function test_familien_email_markieren_entfernt_das_verdachtspaar(): void
    {
        [$mutter, $tochter] = $this->mutterUndTochter();
        $this->assertNotNull($this->paar($mutter, $tochter), 'Vorher: das Paar steht wegen der E-Mail in der Liste.');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.customers.duplicates.geteilt'), [
                'customer_a' => (string) $mutter->id, 'customer_b' => (string) $tochter->id,
                'art' => 'email', 'notiz' => 'Familien-E-Mail',
            ])->assertSessionHas('success');

        $this->assertNull($this->paar($mutter, $tochter));
        $zeile = GeteilterKontaktwert::sole();
        $this->assertSame('email', $zeile->art);
        $this->assertSame('r***@example.com', $zeile->anzeige);
        $this->assertSame(1, ActivityLog::where('action', 'shared_contact_marked')->count());
    }

    public function test_der_wert_selbst_wird_nie_gespeichert(): void
    {
        [$mutter, $tochter] = $this->mutterUndTochter();
        $mutter->update(['iban' => 'DE89370400440532013000']);
        $tochter->update(['iban' => 'DE89370400440532013000']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.customers.duplicates.geteilt'), ['customer_a' => (string) $mutter->id, 'customer_b' => (string) $tochter->id, 'art' => 'iban']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.customers.duplicates.geteilt'), ['customer_a' => (string) $mutter->id, 'customer_b' => (string) $tochter->id, 'art' => 'email']);

        $roh = json_encode(DB::table('geteilte_kontaktdaten')->get());
        $this->assertStringNotContainsString('rana@example.com', $roh);
        $this->assertStringNotContainsString('370400440532013000', $roh);
        $this->assertSame('…3000', GeteilterKontaktwert::where('art', 'iban')->value('anzeige'));
    }

    public function test_ein_anderes_merkmal_haelt_das_paar_weiter_in_der_liste(): void
    {
        $a = $this->kunde('Omar Nasser', 'familie@example.com', ['birth_date' => '1990-03-03']);
        $b = $this->kunde('Omar Nasser', 'omar2@example.com', ['birth_date' => '1990-03-03', 'email2' => 'familie@example.com']);
        GeteilterKontaktwert::create(['art' => 'email', 'wert_hash' => GeteilterKontaktwert::hashFuer('email', 'familie@example.com'), 'anzeige' => 'f***@example.com']);

        $paar = $this->paar($a, $b);
        $this->assertNotNull($paar, 'Gleicher Name und Geburtsdatum bleiben ein Verdacht.');
        $this->assertNotContains('Gleiche E-Mail-Adresse', $paar['signals']);
        $this->assertSame(DuplicateDetectionService::KLASSE_SICHER, $paar['klasse']);
    }

    public function test_mehrfamilienhaus_als_gemeinsame_anschrift(): void
    {
        $adr = ['address_street' => 'Ringweg', 'address_house_number' => '12', 'address_zip' => '24768', 'address_city' => 'Rendsburg'];
        $a = $this->kunde('Karim Aziz', 'k@example.com', $adr);
        $b = $this->kunde('Petra Lang', 'p@example.com', $adr);
        $this->assertNotNull($this->paar($a, $b));

        $this->actingAs(User::factory()->create(['role' => 'manager']))
            ->post(route('admin.customers.duplicates.geteilt'), ['customer_a' => (string) $a->id, 'customer_b' => (string) $b->id, 'art' => 'anschrift'])
            ->assertSessionHas('success');

        $this->assertNull($this->paar($a, $b));
        $this->assertSame('24768 Rendsburg', GeteilterKontaktwert::sole()->anzeige);
    }

    public function test_ohne_gemeinsamen_wert_wird_nichts_markiert(): void
    {
        [$mutter, $tochter] = $this->mutterUndTochter();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.customers.duplicates.geteilt'), ['customer_a' => (string) $mutter->id, 'customer_b' => (string) $tochter->id, 'art' => 'telefon'])
            ->assertSessionHas('error');

        $this->assertSame(0, GeteilterKontaktwert::count());
    }

    public function test_mitarbeiter_duerfen_nicht_markieren(): void
    {
        [$mutter, $tochter] = $this->mutterUndTochter();

        $this->actingAs(User::factory()->create(['role' => 'employee', 'can_see_all_customers' => true]))
            ->post(route('admin.customers.duplicates.geteilt'), ['customer_a' => (string) $mutter->id, 'customer_b' => (string) $tochter->id, 'art' => 'email'])
            ->assertRedirect(route('admin.dashboard')); // role:admin,manager lehnt ab

        $this->assertSame(0, GeteilterKontaktwert::count());
    }

    public function test_aufheben_bringt_das_paar_zurueck(): void
    {
        [$mutter, $tochter] = $this->mutterUndTochter();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.customers.duplicates.geteilt'), ['customer_a' => (string) $mutter->id, 'customer_b' => (string) $tochter->id, 'art' => 'email']);
        $this->assertNull($this->paar($mutter, $tochter));

        $this->actingAs($admin)->get(route('admin.customers.duplicates'))
            ->assertOk()->assertSee('Gemeinsam genutzte Angaben (1)')->assertSee('r***@example.com')
            ->assertDontSee('rana@example.com</td>', false);

        $this->actingAs($admin)->delete(route('admin.customers.duplicates.geteilt.aufheben', GeteilterKontaktwert::sole()->id))
            ->assertSessionHas('success');

        $this->assertSame(0, GeteilterKontaktwert::count());
        $this->assertNotNull($this->paar($mutter, $tochter));
        $this->assertSame(1, ActivityLog::where('action', 'shared_contact_unmarked')->count());
    }

    public function test_dubletten_seite_bietet_die_markierung_an(): void
    {
        [$mutter, $tochter] = $this->mutterUndTochter();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.customers.duplicates'))
            ->assertOk()
            ->assertSee('Gemeinsam genutzte Angabe markieren')
            ->assertSee('<option value="email">E-Mail-Adresse</option>', false);
    }

    public function test_befehl_listet_widersprueche_und_aendert_nichts(): void
    {
        $w = $this->kunde('A Eins', 'a1@example.com', ['customer_number' => 'C-WIDER01', 'address_street' => 'Nagelshof 20', 'address_house_number' => '51', 'address_zip' => '51069', 'address_city' => 'Köln']);
        $this->kunde('B Zwei', 'b2@example.com', ['customer_number' => 'C-DOPP01', 'address_street' => 'Nagelshof 20', 'address_house_number' => '20']);
        $this->kunde('C Drei', 'c3@example.com', ['customer_number' => 'C-SAUBER1', 'address_street' => 'Nagelshof', 'address_house_number' => '20']);

        $this->artisan('kunden:anschriften-pruefen')
            ->expectsOutputToContain('C-WIDER01')
            ->doesntExpectOutputToContain('C-SAUBER1')
            ->expectsOutputToContain('Widerspruch (zwei verschiedene Nummern, bitte in der Akte klaeren): 1')
            ->expectsOutputToContain('Doppelt (dieselbe Nummer zweimal, Anzeige bereits korrekt): 1')
            ->assertExitCode(0);

        $this->assertSame('Nagelshof 20', $w->fresh()->address_street);
        $this->assertSame('51', $w->fresh()->address_house_number);
    }
}
