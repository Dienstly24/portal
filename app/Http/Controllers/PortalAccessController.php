<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Portal\PortalAccessService;

/**
 * Admin-Controls für den Kundenportal-Zugang (Kundenakte):
 * Einladung (erneut) senden, Reset-Link senden, Portal zurücksetzen,
 * Portal aktivieren/deaktivieren. Nur Rolle admin (Routen-Middleware).
 */
class PortalAccessController extends Controller
{
    public function __construct(private readonly PortalAccessService $portal)
    {
    }

    public function invite($id)
    {
        return $this->run($id, fn (Customer $c) => $this->portal->sendInvitation($c, auth()->id()),
            'Einladung wurde an den Kunden gesendet.');
    }

    public function sendResetLink($id)
    {
        return $this->run($id, fn (Customer $c) => $this->portal->sendResetLink($c, auth()->id()),
            'Passwort-Reset-Link wurde an den Kunden gesendet.');
    }

    public function reset($id)
    {
        return $this->run($id, fn (Customer $c) => $this->portal->resetPortal($c, auth()->id()),
            'Portal-Zugang wurde zurückgesetzt und die Einladung erneut versendet.');
    }

    public function toggle($id)
    {
        return $this->run($id, function (Customer $c) {
            $active = !($c->user?->is_active ?? true);
            $this->portal->setActive($c, $active, auth()->id());
        }, 'Portal-Status wurde geändert.');
    }

    private function run(string $id, \Closure $action, string $successMessage)
    {
        $customer = Customer::with('user')->findOrFail($id);
        abort_unless(auth()->user()->canAccessCustomer($customer->id), 403);

        try {
            $action($customer);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            \Log::error('Portal-Aktion fehlgeschlagen: ' . $e->getMessage());
            return back()->with('error', 'Die Aktion konnte nicht ausgeführt werden (E-Mail-Versand fehlgeschlagen?). Bitte erneut versuchen.');
        }

        return back()->with('success', $successMessage);
    }
}
