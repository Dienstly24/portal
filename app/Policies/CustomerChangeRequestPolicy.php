<?php
namespace App\Policies;

use App\Models\CustomerChangeRequest;
use App\Models\User;

/**
 * - Kunden sehen/erstellen ausschließlich eigene Anträge (Portal-
 *   Controller scopen zusätzlich hart auf den eigenen Datensatz).
 * - Prüfen/Genehmigen/Ablehnen: nur Staff MIT Zugriff auf den Kunden
 *   (admin: alles, manager: Bereich, support/employee: Zuweisung inkl.
 *   Vertretungen) - dieselbe Logik wie beim internen Chat.
 */
class CustomerChangeRequestPolicy
{
    public function viewOwn(User $user, CustomerChangeRequest $request): bool
    {
        return $user->role === 'customer'
            && $request->customer?->user_id === $user->id;
    }

    public function review(User $user, CustomerChangeRequest $request): bool
    {
        return $user->canAccessCustomer($request->customer_id);
    }
}
