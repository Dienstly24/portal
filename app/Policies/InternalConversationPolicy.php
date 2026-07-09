<?php
namespace App\Policies;

use App\Models\InternalConversation;
use App\Models\User;

/**
 * Zugriff auf interne Unterhaltungen: nur Teilnehmer (und nur Staff -
 * Kunden sind über isStaff() ausgeschlossen). Es gibt keinen Weg, über
 * den ein Kunde Teilnehmer werden könnte (Teilnehmerauswahl im
 * Controller filtert hart auf Staff-Rollen).
 */
class InternalConversationPolicy
{
    public function view(User $user, InternalConversation $conversation): bool
    {
        return $user->isStaff() && $conversation->hasParticipant($user->id);
    }

    public function reply(User $user, InternalConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
