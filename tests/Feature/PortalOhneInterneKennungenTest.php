<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * KI-010 (Wissensbasis, 23.09.2026): die Trennung "der Kunde sieht nichts
 * von unseren Provisionen" ist fuer `Customer` STRUKTURELL - es gibt keine
 * Beziehung dorthin. Fuer `Contract` nicht: die Tabelle `contracts` traegt
 * selbst `vermittler_id`, `internal_contract_number`, `pool` und
 * `commission_status`, und das Modell hat Relationen zu den Provisionen.
 * Heute gibt das Portal die Felder einzeln aus; eine kuenftige Seite oder
 * ein JSON-Endpunkt, der ein Vertragsmodell als Ganzes ausgibt, wuerde sie
 * mitliefern.
 *
 * Dieser Test geht deshalb nicht EINE Seite durch, sondern ALLE
 * GET-Adressen des Kunden- und des Partnerportals - eine neue Seite ist
 * automatisch mitgeprueft.
 */
class PortalOhneInterneKennungenTest extends TestCase
{
    use RefreshDatabase;

    private const GEHEIM = ['VERM-9753224', 'INTERN-V19613073', 'POOL-GEHEIM'];

    private function kundeMitVertrag(?Partner $partner = null): array
    {
        $user = User::factory()->create(['role' => 'customer']);
        $kunde = Customer::create([
            'user_id' => $user->id,
            'partner_id' => $partner?->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
        $vertrag = Contract::create([
            'customer_id' => $kunde->id,
            'type' => 'kfz',
            'insurer' => 'Allianz',
            'status' => 'active',
            'contract_number' => 'POL-SICHTBAR-1',
            'vermittler_id' => self::GEHEIM[0],
            'internal_contract_number' => self::GEHEIM[1],
            'pool' => self::GEHEIM[2],
        ]);

        return [$user, $kunde, $vertrag];
    }

    public function test_keine_seite_des_kundenportals_zeigt_interne_kennungen(): void
    {
        [$user, , $vertrag] = $this->kundeMitVertrag();

        $geprueft = 0;
        foreach ($this->getRouten('portal.') as $name => $route) {
            $parameter = $route->parameterNames();
            if ($parameter === []) {
                $url = route($name);
            } elseif ($name === 'portal.contracts.show') {
                $url = route($name, $vertrag->id);
            } else {
                continue;
            }

            $antwort = $this->actingAs($user)->get($url);
            if ($antwort->getStatusCode() >= 300) {
                continue;
            }
            $geprueft++;
            foreach (self::GEHEIM as $wert) {
                $this->assertStringNotContainsString($wert, (string) $antwort->getContent(), "{$name} zeigt {$wert}");
            }
        }

        // Der Vertrag selbst ist sichtbar - sonst prueft der Test ins Leere.
        $this->actingAs($user)->get(route('portal.contracts.show', $vertrag->id))
            ->assertOk()->assertSee('POL-SICHTBAR-1');
        $this->assertGreaterThan(10, $geprueft, 'Zu wenige Portalseiten geprueft - Routen geaendert?');
    }

    public function test_das_partnerportal_zeigt_keine_internen_kennungen(): void
    {
        $partnerUser = User::factory()->create(['role' => 'partner']);
        $partner = Partner::create(['name' => 'Partner GmbH', 'user_id' => $partnerUser->id, 'is_active' => true]);
        [, $kunde] = $this->kundeMitVertrag($partner);

        $seiten = [route('partner.dashboard'), route('partner.customers'), route('partner.customer', $kunde->id), route('partner.commissions')];
        foreach ($seiten as $url) {
            $antwort = $this->actingAs($partnerUser)->get($url);
            $antwort->assertOk();
            foreach (self::GEHEIM as $wert) {
                $this->assertStringNotContainsString($wert, (string) $antwort->getContent(), "{$url} zeigt {$wert}");
            }
        }
    }

    /** @return array<string, \Illuminate\Routing\Route> */
    private function getRouten(string $praefix): array
    {
        $liste = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name !== null && str_starts_with($name, $praefix) && in_array('GET', $route->methods(), true)) {
                $liste[$name] = $route;
            }
        }

        return $liste;
    }
}
