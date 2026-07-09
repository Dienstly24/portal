<?php
namespace App\Policies;

use App\Models\InternalMessage;
use App\Models\User;

/**
 * Rechte für interne Nachrichten/Notizen:
 * - sehen/erstellen: nur Staff mit Zugriff auf den jeweiligen Kunden
 *   (admin: alles; manager: sein Bereich via canSeeAllCustomers;
 *   support/employee: nur zugewiesene Kunden inkl. Vertretungen)
 * - löschen: Autor selbst oder admin/manager (mit Kundenzugriff)
 * Kunden (role=customer) werden von isStaff() immer ausgeschlossen.
 */
class InternalMessagePolicy
{
    public function viewForCustomer(User $user, string $customerId): bool
    {
        return $user->canAccessCustomer($customerId);
    }

    public function view(User $user, InternalMessage $message): bool
    {
        return $user->canAccessCustomer($message->customer_id);
    }

    public function createForCustomer(User $user, string $customerId): bool
    {
        return $user->canAccessCustomer($customerId);
    }

    public function delete(User $user, InternalMessage $message): bool
    {
        if (!$user->canAccessCustomer($message->customer_id)) {
            return false;
        }
        return $user->id === $message->sender_id
            || in_array($user->role, ['admin', 'manager'], true);
    }
}
