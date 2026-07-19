<?php

namespace App\Support\Facades;

use App\Models\InternalNotification;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Facade;

/**
 * Facade fuer den zentralen NotificationService (Notification-System-Audit).
 *
 * @method static InternalNotification|null push(int $userId, array $attrs)
 * @method static int pushMany(iterable $userIds, array|callable $attrs)
 *
 * @see NotificationService
 */
class Notify extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NotificationService::class;
    }
}
