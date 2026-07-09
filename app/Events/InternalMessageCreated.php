<?php
namespace App\Events;

use App\Models\InternalMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Wird bei jeder neuen internen Nachricht ausgelöst. Aktuell ohne
 * Listener - vorbereitet für Laravel Echo/WebSockets: später einfach
 * ShouldBroadcast implementieren und einen PrivateChannel
 * ("internal-chat.customer.{id}", Autorisierung über
 * InternalMessagePolicy::viewForCustomer) zurückgeben.
 */
class InternalMessageCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public InternalMessage $message) {}
}
