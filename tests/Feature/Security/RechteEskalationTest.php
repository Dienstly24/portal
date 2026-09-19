<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Rechte-Eskalation in der Mitarbeiterakte (Sicherheitsaudit 19.09.2026).
 *
 * BEFUND: `EmployeeController::update()` verbot einem Manager nur den
 * Zugriff auf ADMINISTRATOR-Konten. Das eigene Konto ist keines - der
 * Manager konnte die Maske also auf sich selbst anwenden und sich
 * `can_manage_commissions` setzen. Das Gate `provisionen-verwalten`
 * bindet dieses Recht bewusst NICHT an die Rolle (Kommentar im
 * AppServiceProvider: "eine Rolle waechst mit der Zeit um Aufgaben, ein
 * Recht wird einzeln vergeben") - genau diese Trennung war damit
 * aufgehoben, und dahinter stehen Provisionsbetraege.
 *
 * Dass die Selbst-Anwendung gefaehrlich ist, war im selben Controller
 * bereits erkannt: `destroy()` und `toggleActive()` tragen den Riegel
 * seit jeher. Nur `update()` - die einzige der drei Stellen, die RECHTE
 * schreibt - hatte ihn nicht.
 *
 * Der zweite Weg fuehrte ueber eine Ecke: `store()` liess denselben
 * Manager ein NEUES Konto mit dem Recht anlegen. Traegt er dort seine
 * eigene Adresse ein, bekommt er die Einladung selbst.
 */
class RechteEskalationTest extends TestCase
{
    use RefreshDatabase;

    /** Ein Manager ohne jedes Einzelrecht - der Ausgangspunkt beider Wege. */
    private function managerOhneRechte(): User
    {
        return User::factory()->create([
            'role' => 'manager',
            'can_manage_commissions' => false,
            'can_see_all_customers' => false,
            'can_import_export' => false,
        ]);
    }

    /** Das vollstaendige Formular, damit die Validierung nicht vorher greift. */
    private function formular(array $zusatz = []): array
    {
        return array_merge([
            'name' => 'Unveraendert',
            'access_level' => 'full',
        ], $zusatz);
    }

    // ------------------------------------------------------------------
    // Weg 1: das eigene Konto
    // ------------------------------------------------------------------

    public function test_manager_kann_sich_das_provisionsrecht_nicht_selbst_setzen(): void
    {
        $manager = $this->managerOhneRechte();

        $this->actingAs($manager)
            ->put(route('admin.employees.update', $manager->id), $this->formular([
                'can_manage_commissions' => '1',
            ]))
            ->assertForbidden();

        $this->assertFalse((bool) $manager->fresh()->can_manage_commissions);
    }

    /**
     * Der eigentliche Schaden steht nicht in der Spalte, sondern im Gate:
     * erst es oeffnet /admin/provisionsmanagement mit den Betraegen.
     */
    public function test_das_provisions_gate_bleibt_nach_dem_versuch_zu(): void
    {
        $manager = $this->managerOhneRechte();

        $this->actingAs($manager)
            ->put(route('admin.employees.update', $manager->id), $this->formular([
                'can_manage_commissions' => '1',
            ]));

        $this->assertFalse(Gate::forUser($manager->fresh())->allows('provisionen-verwalten'));
    }

    /**
     * Derselbe Riegel deckt die uebrigen Rechte mit ab - vor allem die
     * Kombination "alle Kunden sehen" + "exportieren", also den gesamten
     * Kundenbestand als Datei.
     */
    public function test_manager_kann_sich_auch_kundensicht_und_export_nicht_setzen(): void
    {
        $manager = $this->managerOhneRechte();

        $this->actingAs($manager)
            ->put(route('admin.employees.update', $manager->id), $this->formular([
                'can_see_all_customers' => '1',
                'can_import_export' => '1',
            ]))
            ->assertForbidden();

        $frisch = $manager->fresh();
        $this->assertFalse((bool) $frisch->can_see_all_customers);
        $this->assertFalse((bool) $frisch->can_import_export);
    }

    // ------------------------------------------------------------------
    // Weg 2: ein neues Konto, dessen Einladung beim Manager landet
    // ------------------------------------------------------------------

    public function test_manager_kann_ein_recht_nicht_an_ein_neues_konto_weiterreichen(): void
    {
        $manager = $this->managerOhneRechte();

        $this->actingAs($manager)->post(route('admin.employees.store'), [
            'name' => 'Strohmann',
            'email' => 'strohmann@dienstly24.de',
            'access_level' => 'full',
            'can_manage_commissions' => '1',
        ]);

        $neu = User::where('email', 'strohmann@dienstly24.de')->first();
        $this->assertNotNull($neu, 'Das Konto soll entstehen - nur eben ohne das Recht.');
        $this->assertFalse((bool) $neu->can_manage_commissions);
    }

    // ------------------------------------------------------------------
    // Gegenproben: der Riegel darf den Betrieb nicht lahmlegen
    // ------------------------------------------------------------------

    public function test_manager_mit_dem_recht_darf_es_weitergeben(): void
    {
        $manager = User::factory()->create([
            'role' => 'manager',
            'can_manage_commissions' => true,
        ]);
        $kollege = User::factory()->create([
            'role' => 'employee',
            'can_manage_commissions' => false,
        ]);

        $this->actingAs($manager)
            ->put(route('admin.employees.update', $kollege->id), $this->formular([
                'name' => $kollege->name,
                'can_manage_commissions' => '1',
            ]));

        $this->assertTrue((bool) $kollege->fresh()->can_manage_commissions);
    }

    public function test_admin_darf_rechte_weiterhin_vergeben(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'can_manage_commissions' => false,
        ]);
        $mitarbeiter = User::factory()->create(['role' => 'employee']);

        $this->actingAs($admin)
            ->put(route('admin.employees.update', $mitarbeiter->id), $this->formular([
                'name' => $mitarbeiter->name,
                'can_manage_commissions' => '1',
                'can_see_all_customers' => '1',
            ]));

        $frisch = $mitarbeiter->fresh();
        $this->assertTrue((bool) $frisch->can_manage_commissions);
        $this->assertTrue((bool) $frisch->can_see_all_customers);
    }

    /**
     * Die Sperre gilt in BEIDE Richtungen. Haette sie nur das Vergeben im
     * Blick, naehme ein Manager ohne dieses Recht es seinem Kollegen schon
     * dann weg, wenn er nur dessen Namen korrigiert - die nicht
     * angekreuzte Kasten-Reihe liest der Controller als "abwaehlen".
     * Deshalb beide Absenderichtungen als eigener Fall.
     */
    public function test_ein_fremdes_recht_bleibt_beim_umbenennen_erhalten(): void
    {
        $manager = $this->managerOhneRechte();
        $kollege = User::factory()->create([
            'role' => 'employee',
            'can_manage_commissions' => true,
        ]);

        // Formular OHNE die Kasten-Reihe - so sieht ein reines Umbenennen aus.
        $this->actingAs($manager)
            ->put(route('admin.employees.update', $kollege->id), $this->formular([
                'name' => 'Neuer Name',
            ]));

        $frisch = $kollege->fresh();
        $this->assertSame('Neuer Name', $frisch->name, 'Das Umbenennen selbst muss gehen.');
        $this->assertTrue((bool) $frisch->can_manage_commissions);
    }

    public function test_ein_fremdes_recht_wird_auch_beim_mitschicken_nicht_veraendert(): void
    {
        $manager = $this->managerOhneRechte();
        $kollege = User::factory()->create([
            'role' => 'employee',
            'can_manage_commissions' => true,
        ]);

        $this->actingAs($manager)
            ->put(route('admin.employees.update', $kollege->id), $this->formular([
                'name' => $kollege->name,
                'can_manage_commissions' => '1',
            ]));

        $this->assertTrue((bool) $kollege->fresh()->can_manage_commissions);
    }
}
