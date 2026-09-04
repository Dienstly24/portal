<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Document;
use App\Models\Ticket;

/**
 * Einheitliches Portfolio-Scoping fuer die Beraterwelt (Audit ARCH-2).
 *
 * Bisher war visibleCustomerIds() in 7 Controllern kopiert - und dabei
 * DRIFTED: einige nutzten visibleCustomerIdsWithSubstitution() (inkl.
 * Vertretung), andere nur assignedCustomers() (ohne Vertretung), sodass ein
 * vertretender Mitarbeiter je nach Modul unterschiedliche Kunden sah. Diese
 * einzige Quelle nutzt konsistent die Variante MIT Vertretung.
 *
 * ARCH-5: die darauf aufbauenden Zugriffspruefungen liegen ebenfalls hier.
 * Sie standen als private Methoden im AdminController und wurden beim
 * Aufteilen von genau denselben Regeln in mehreren Controllern gebraucht -
 * kopiert waeren sie wieder auseinandergelaufen, so wie es visibleCustomerIds()
 * schon einmal getan hat.
 */
trait ScopesCustomerAccess
{
    /** null = alle sichtbar; sonst Array der erlaubten Kunden-IDs (inkl. Vertretung). */
    protected function visibleCustomerIds(): ?array
    {
        $user = auth()->user();
        if (! $user || $user->canSeeAllCustomers()) {
            return null;
        }
        return $user->visibleCustomerIdsWithSubstitution();
    }

    /** Query auf das sichtbare Portfolio einschraenken. */
    protected function scopeCustomers($query)
    {
        $ids = $this->visibleCustomerIds();
        if ($ids !== null) {
            $query->whereIn('customers.id', $ids);
        }

        return $query;
    }

    /** 403, wenn der eingeloggte Mitarbeiter diesen Kunden nicht sehen darf. (Audit M1) */
    protected function authorizeCustomerAccess($customerId): void
    {
        $ids = $this->visibleCustomerIds();
        if ($ids !== null && ! in_array((string) $customerId, array_map('strval', $ids), true)) {
            abort(403, 'Kein Zugriff auf diesen Kunden.');
        }
    }

    /**
     * Dokumente im Dokumenten-Eingang (Smart Upload, noch ohne Kunde)
     * duerfen von Mitarbeitern bearbeitet werden; sobald ein Kunde
     * zugeordnet ist, gilt der normale Portfolio-Check.
     */
    protected function authorizeDocumentAccess(Document $doc): void
    {
        if ($doc->customer_id !== null) {
            $this->authorizeCustomerAccess($doc->customer_id);

            return;
        }
        // Nicht zugeordnete Inbox-Dokumente: portfolio-begrenzte Mitarbeiter
        // duerfen nur die selbst hochgeladenen sehen - spiegelt
        // SmartDocumentUploadController::authorizeDocument. (Audit SEC-2/IDOR)
        $user = auth()->user();
        if (! $user?->canSeeAllCustomers() && (int) $doc->uploaded_by !== (int) $user?->id) {
            abort(403, 'Kein Zugriff auf dieses Dokument.');
        }
    }

    /** 403, wenn das Ticket zu einem nicht sichtbaren Kunden gehoert. (Audit M1) */
    protected function authorizeTicketAccess(Ticket $ticket): void
    {
        if ($ticket->customer_id !== null) {
            $this->authorizeCustomerAccess($ticket->customer_id);

            return;
        }
        // Gast-Anfragen (Leads): nur admin/manager/support - gleiche Regel
        // wie die Anfragen-Liste (betrifft z. B. Anhang-Downloads).
        if (! in_array(auth()->user()?->role, ['admin', 'manager', 'support'], true)) {
            abort(403, 'Kein Zugriff auf Gast-Anfragen.');
        }
    }
}
