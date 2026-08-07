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
        return $this->run($id, fn (Customer $c) => $this->invitationFlash(
            $this->portal->sendInvitation($c, auth()->id()),
            'Einladung wurde an den Kunden gesendet.'
        ));
    }

    public function sendResetLink($id)
    {
        return $this->run($id, function (Customer $c) {
            $this->portal->sendResetLink($c, auth()->id());
            return ['success', 'Passwort-Reset-Link wurde an den Kunden gesendet.'];
        });
    }

    public function reset($id)
    {
        return $this->run($id, fn (Customer $c) => $this->invitationFlash(
            $this->portal->resetPortal($c, auth()->id()),
            'Portal-Zugang wurde zurückgesetzt und die Einladung erneut versendet.'
        ));
    }

    public function toggle($id)
    {
        return $this->run($id, function (Customer $c) {
            $active = !($c->user?->is_active ?? true);
            $this->portal->setActive($c, $active, auth()->id());
            return ['success', 'Portal-Status wurde geändert.'];
        });
    }

    /**
     * Rueckmeldung nach einer Einladung, abhaengig vom Versandmodus.
     * OHNE Geburtsdatum bekommt der Kunde KEIN Startpasswort, sondern nur
     * einen zeitlich begrenzten Passwort-Setzen-Link - das muss der
     * Mitarbeiter deutlich sehen, sonst wirkt die Einladung erfolgreich,
     * waehrend der Kunde den Zugang nie aktivieren kann (Betreiber-Meldung
     * 07.08.2026).
     *
     * @return array{0: string, 1: string} [Flash-Typ, Meldung]
     */
    private function invitationFlash(string $mode, string $successMessage): array
    {
        if ($mode === 'setlink') {
            $stunden = (int) round(((int) config('auth.passwords.users.expire', 60)) / 60);
            return ['warning', $successMessage
                . ' ABER: Es ist KEIN Geburtsdatum hinterlegt - die E-Mail enthaelt statt des Startpassworts'
                . ' nur einen ' . $stunden . ' Stunden gueltigen Link zum Passwort-Setzen.'
                . ' Bitte das Geburtsdatum ergaenzen (Bearbeiten) und die Einladung danach erneut senden.'];
        }
        return ['success', $successMessage . ' Startpasswort ist das Geburtsdatum (TT.MM.JJJJ).'];
    }

    private function run(string $id, \Closure $action)
    {
        $customer = Customer::with('user')->findOrFail($id);
        abort_unless(auth()->user()->canAccessCustomer($customer->id), 403);

        try {
            [$type, $message] = $action($customer);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            \Log::error('Portal-Aktion fehlgeschlagen: ' . $e->getMessage());
            return back()->with('error', 'Die Aktion konnte nicht ausgeführt werden (E-Mail-Versand fehlgeschlagen?). Bitte erneut versuchen.');
        }

        return back()->with($type, $message);
    }
}
