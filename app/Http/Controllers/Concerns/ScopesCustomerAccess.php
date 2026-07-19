<?php

namespace App\Http\Controllers\Concerns;

/**
 * Einheitliches Portfolio-Scoping fuer die Beraterwelt (Audit ARCH-2).
 *
 * Bisher war visibleCustomerIds() in 7 Controllern kopiert - und dabei
 * DRIFTED: einige nutzten visibleCustomerIdsWithSubstitution() (inkl.
 * Vertretung), andere nur assignedCustomers() (ohne Vertretung), sodass ein
 * vertretender Mitarbeiter je nach Modul unterschiedliche Kunden sah. Diese
 * einzige Quelle nutzt konsistent die Variante MIT Vertretung.
 */
trait ScopesCustomerAccess
{
    /** null = alle sichtbar; sonst Array der erlaubten Kunden-IDs (inkl. Vertretung). */
    protected function visibleCustomerIds(): ?array
    {
        $user = auth()->user();
        if (!$user || $user->canSeeAllCustomers()) {
            return null;
        }
        return $user->visibleCustomerIdsWithSubstitution();
    }
}
