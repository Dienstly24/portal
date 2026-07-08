<?php

use App\Mail\BirthdayMail;
use App\Mail\ContractExpiryMail;
use App\Mail\CustomerPortalReminderMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

if (!function_exists('dienstly_mailable')) {
    function dienstly_mailable(?string $email): bool {
        return $email && !str_contains($email, '@dienstly24.internal');
    }
}

// 07:30 — Aufgabe: Kind wird in 4 Monaten 15
Schedule::call(function () {
    $target = now()->addMonths(4)->subYears(15)->toDateString();
    $kids = \App\Models\CustomerFamily::with('customer.user')
        ->where('relation', 'Kind')->whereDate('birth_date', $target)->get();
    foreach ($kids as $kid) {
        \App\Models\Task::forceCreate([
            'title' => '🎂 ' . $kid->name . ' wird in 4 Monaten 15 Jahre alt',
            'description' => 'Kunde: ' . ($kid->customer?->user?->name ?? '—') . ' (' . ($kid->customer?->customer_number ?? '—') . '). Beratungstermin zu Versicherungsoptionen ab 15 vereinbaren.',
            'type' => 'reminder',
            'status' => 'open',
            'priority' => 'medium',
            'due_date' => now()->addDays(14)->toDateString(),
            'created_by' => 1,
            'assigned_to' => 1,
            'customer_id' => $kid->customer_id,
        ]);
    }
})->dailyAt('07:30');

// 08:00 — Geburtstags-E-Mails
Schedule::call(function () {
    $today = now();
    $customers = \App\Models\Customer::with('user')
        ->whereNotNull('birth_date')
        ->whereMonth('birth_date', $today->month)->whereDay('birth_date', $today->day)->get();
    foreach ($customers as $c) {
        if (!dienstly_mailable($c->user?->email)) continue;
        try {
            Mail::to($c->user->email)->send(new BirthdayMail($c->user->name, $c->user->name, true, $c->preferred_lang ?? 'de'));
        } catch (\Throwable $e) { \Log::warning('Birthday mail failed: ' . $e->getMessage()); }
    }
    $family = \App\Models\CustomerFamily::with('customer.user')
        ->whereNotNull('birth_date')
        ->whereMonth('birth_date', $today->month)->whereDay('birth_date', $today->day)->get();
    foreach ($family as $f) {
        $email = $f->customer?->user?->email;
        if (!dienstly_mailable($email)) continue;
        try {
            Mail::to($email)->send(new BirthdayMail($f->customer->user->name, $f->name, false, $f->customer->preferred_lang ?? 'de'));
        } catch (\Throwable $e) { \Log::warning('Birthday mail failed: ' . $e->getMessage()); }
    }
})->dailyAt('08:00');

// 08:30 — Vertragsablauf-Erinnerungen
Schedule::call(function () {
    foreach ([30, 14, 7] as $days) {
        $contracts = \App\Models\Contract::with('customer.user')
            ->whereNotNull('end_date')->whereDate('end_date', now()->addDays($days))
            ->where('status', 'active')->get();
        foreach ($contracts as $contract) {
            if (!dienstly_mailable($contract->customer?->user?->email)) continue;
            try {
                Mail::to($contract->customer->user->email)->send(new ContractExpiryMail($contract, $days));
            } catch (\Throwable $e) { \Log::warning('Contract reminder failed: ' . $e->getMessage()); }
        }
    }
})->dailyAt('08:30');

// 09:00 — Portal-Erinnerung nach 3 Tagen ohne Login
Schedule::call(function () {
    $users = \App\Models\User::where('role', 'customer')
        ->whereNull('last_login_at')->whereNull('portal_reminder_sent_at')
        ->where('created_at', '<=', now()->subDays(3))->get();
    foreach ($users as $u) {
        if (!dienstly_mailable($u->email)) continue;
        $lang = \App\Models\Customer::where('user_id', $u->id)->value('preferred_lang') ?? 'de';
        try {
            Mail::to($u->email)->send(new CustomerPortalReminderMail($u->name, $lang));
            $u->forceFill(['portal_reminder_sent_at' => now()])->save();
        } catch (\Throwable $e) { \Log::warning('Portal reminder failed: ' . $e->getMessage()); }
    }
})->dailyAt('09:00');
