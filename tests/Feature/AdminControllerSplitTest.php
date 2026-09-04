<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ContractController;
use App\Http\Controllers\Admin\CustomerDocumentController;
use App\Http\Controllers\Admin\DuplicateController;
use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ARCH-5: die Aufteilung des AdminControllers war eine reine VERSCHIEBUNG.
 *
 * Der Wert dieser Tests liegt darin, dass sie das Versprechen pruefen, unter
 * dem die Aufteilung gemacht wurde - Routen, Namen und Middleware bleiben
 * gleich. Genau daran scheitert so ein Umbau sonst still: eine Route zeigt
 * ins Leere oder verliert ihre Rollenpruefung, und auffallen wuerde es erst
 * im Betrieb.
 */
class AdminControllerSplitTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: class-string, 2: string}>
     */
    public static function verschobeneRouten(): array
    {
        return [
            'Vertragsliste' => ['admin.contracts', ContractController::class, 'contracts'],
            'Vertrag anlegen' => ['admin.contract.store', ContractController::class, 'contractStore'],
            'Vertrag bearbeiten' => ['admin.contract.edit', ContractController::class, 'contractEdit'],
            'Vertrag aktualisieren' => ['admin.contract.update', ContractController::class, 'contractUpdate'],
            'Vertrag loeschen' => ['admin.contract.destroy', ContractController::class, 'contractDestroy'],
            'Dokument hochladen' => ['admin.customer.document.store', CustomerDocumentController::class, 'storeDocument'],
            'Dokument anzeigen' => ['admin.documents.show', CustomerDocumentController::class, 'documentShow'],
            'Dokument loeschen' => ['admin.documents.destroy', CustomerDocumentController::class, 'documentDestroy'],
            'Dubletten' => ['admin.customers.duplicates', DuplicateController::class, 'duplicates'],
            'Zusammenfuehren' => ['admin.customer.merge', DuplicateController::class, 'mergeForm'],
        ];
    }

    #[DataProvider('verschobeneRouten')]
    public function test_route_zeigt_auf_den_neuen_controller(string $name, string $controller, string $method): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Die Route {$name} existiert nicht mehr.");
        $this->assertSame($controller.'@'.$method, $route->getActionName());
    }

    /**
     * Die Zugriffspruefungen liegen im gemeinsamen Trait. Haette ein
     * Controller sie beim Verschieben verloren, waere die Rollenpruefung
     * still weg - der haerteste denkbare Regressionsfall hier.
     */
    #[DataProvider('aufgeteilteController')]
    public function test_controller_bringt_die_zugriffspruefungen_mit(string $controller): void
    {
        foreach (['visibleCustomerIds', 'scopeCustomers', 'authorizeCustomerAccess', 'authorizeDocumentAccess'] as $methode) {
            $this->assertTrue(
                method_exists($controller, $methode),
                "{$controller} fehlt {$methode} - ohne sie greift die Portfolio-Pruefung nicht."
            );
        }
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function aufgeteilteController(): array
    {
        return [
            'AdminController' => [AdminController::class],
            'ContractController' => [ContractController::class],
            'CustomerDocumentController' => [CustomerDocumentController::class],
            'DuplicateController' => [DuplicateController::class],
        ];
    }

    /**
     * Der Zweck der Uebung: der AdminController traegt nicht mehr alles.
     * Die Grenze ist bewusst grosszuegig - sie soll den Rueckfall melden,
     * nicht jede neue Methode verhindern.
     */
    public function test_der_admin_controller_ist_deutlich_kleiner_geworden(): void
    {
        $zeilen = count(file(app_path('Http/Controllers/AdminController.php')));

        $this->assertLessThan(
            1400,
            $zeilen,
            'Der AdminController waechst wieder zu einem Sammelbecken - neue Verantwortung gehoert in einen eigenen Controller.'
        );
    }

    /**
     * Die verschobenen Methoden duerfen nicht doppelt existieren: eine
     * zurueckgebliebene Kopie waere toter Code, der wie ein Feature aussieht.
     */
    public function test_verschobene_methoden_sind_im_admin_controller_verschwunden(): void
    {
        $quelle = file_get_contents(app_path('Http/Controllers/AdminController.php'));

        foreach (['function contractStore', 'function validateContract', 'function documentShow', 'function duplicatesMerge'] as $weg) {
            $this->assertStringNotContainsString($weg, $quelle);
        }
    }
}
