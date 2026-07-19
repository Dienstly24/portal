<?php

namespace App\Services;

use App\Models\CustomerMessage;
use App\Models\User;
use App\Support\Facades\Notify;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Benachrichtigungen rund um Direktnachrichten (Portal-Chat).
 *
 * Staff -> Kunde: Portal-Glocke + optional E-Mail (hint = nur Hinweis,
 * full = kompletter Text; none = keine E-Mail).
 * Kunde -> Staff: Glocke fuer Betreuer, zusaetzlich Admins/Manager
 * (gleicher Empfaengerkreis wie beim Ticket-System).
 */
class CustomerMessageNotifier
{
    public static function notifyCustomer(CustomerMessage $message, string $emailMode): void
    {
        $message->loadMissing('customer.user');
        $customer = $message->customer;
        if (!$customer) {
            return;
        }

        if ($customer->user_id) {
            Notify::push($customer->user_id, [
                'type' => NotificationService::TYPE_MESSAGE,
                'title' => '💬 Neue Nachricht',
                'body' => 'Ihr Berater hat Ihnen eine Nachricht geschickt: „'
                    . Str::limit(trim($message->body), 60) . '"',
                'link' => route('portal.messages'),
                'dedup_key' => 'cust-msg-' . $customer->id,
            ]);
        }

        $email = $customer->user?->email;
        if ($emailMode !== 'none' && $email && !str_contains($email, '@dienstly24.internal')) {
            try {
                Mail::to($email)->send(new \App\Mail\CustomerMessageMail($message, $emailMode));
            } catch (\Throwable $e) {
                \Log::warning('Kundennachricht-Mail fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }

    public static function notifyStaffOfReply(CustomerMessage $message): void
    {
        $message->loadMissing('customer.user');
        $customer = $message->customer;
        if (!$customer) {
            return;
        }

        $name = $customer->user?->name ?: 'Kunde';
        $recipients = $customer->betreuer()->pluck('users.id')
            ->merge(User::whereIn('role', ['admin', 'manager'])->pluck('id'))
            ->unique()->values();

        $link = route('admin.customer', $customer->id) . '#tab-nachrichten';
        Notify::pushMany($recipients, [
            'type' => NotificationService::TYPE_MESSAGE,
            'title' => '💬 Neue Kundennachricht',
            'body' => $name . ' (Nr. ' . $customer->customer_number . '): „'
                . Str::limit(trim($message->body), 60) . '"',
            'link' => $link,
            'dedup_key' => 'cust-reply-' . $customer->id,
        ]);
    }
}
