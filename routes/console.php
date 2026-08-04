<?php

use App\Mail\BirthdayMail;
use App\Mail\CustomerPortalReminderMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

if (!function_exists('dienstly_mailable')) {
    function dienstly_mailable(?string $email): bool {
        return $email && !str_contains($email, '@dienstly24.internal');
    }
}

// Alle 2 Minuten — E-Mail-Postfächer abrufen (Architekturplan Abschnitt 3.1)
Schedule::command('mailboxes:sync')->everyTwoMinutes()->withoutOverlapping();

// 03:30 — DSGVO: nicht zugeordnete E-Mails nach Aufbewahrungsfrist löschen (Abschnitt 3.3)
Schedule::command('emails:prune-unmatched')->dailyAt('03:30');

// 04:00 — Geloeste Tickets ohne Kundenreaktion nach 7 Tagen automatisch schliessen
Schedule::command('tickets:auto-close')->dailyAt('04:00');

// 04:10 — DSGVO: unkonvertierte Website-Anfragen nach 6 Monaten loeschen (P0-1)
Schedule::command('tickets:purge-website-leads')->dailyAt('04:10');

// 04:15 — Medien-Papierkorb: endgueltig loeschen nach 30 Tagen (P1-1)
Schedule::command('media:purge-trash')->dailyAt('04:15');

// Alle 15 Minuten — verwaiste Arbeitssitzungen beenden (Browser zu ohne Logout)
Schedule::command('activity:close-stale')->everyFifteenMinutes();

// 03:45 — DSGVO: alte Seitenaufruf-Eintraege (IP/Geraet je Request) loeschen
Schedule::command('activity:prune')->dailyAt('03:45');

// 08:15 — Fristen-Watchdog Dokumentenanfragen (Phase 3): Kunden-Erinnerung + Überfälligkeits-Hinweis
Schedule::command('document-requests:remind')->dailyAt('08:15');

// 07:45 — Wiedervorlage: EIN gebuendelter Glocken-Hinweis je Mitarbeiter
// ueber heute faellige + ueberfaellige Aufgaben (Aufgaben-Ausbau 26.07.2026)
Schedule::command('tasks:remind')->dailyAt('07:45');

// Stuendlich tagsueber — geplante Aufgaben-E-Mails ("in 14 Tagen nachfassen")
// am Stichtag an den Kunden versenden; erledigte Aufgaben versenden nie.
Schedule::command('tasks:send-auto-emails')->hourly()->between('8:00', '18:00')->withoutOverlapping();

// Alle 10 Minuten — Sicherheitsnetz Smart Document Upload: haengende KI-Analysen neu anstossen
Schedule::command('documents:analyze-pending')->everyTenMinutes()->withoutOverlapping();

// 03:50 — DSGVO: nie zugeordnete Eingangs-Dokumente nach Aufbewahrungsfrist loeschen
Schedule::command('documents:prune-unassigned')->dailyAt('03:50');

// 06:30 — Faellige Krankenkassenwechsel aktivieren (pending Kranken-Vertraege
// am Stichtag -> aktiv + Kasse des Kunden umstellen)
Schedule::command('health:apply-due-switches')->dailyAt('06:30');

// 05:15 — Erreichte Vertragsenden anwenden: "gekuendigt zum X" wird am Tag X
// wirklich cancelled (Model-Hook bucht die Storno-Gegenbuchung der
// Vermittler-Provision), E-Scooter werden nach dem Saisonende expired.
// Status, Zahlen und Tab-Filter folgen so von selbst der Realitaet.
Schedule::command('contracts:apply-endings')->dailyAt('05:15');

// Stuendlich tagsueber — automatische Portal-Einladungen: neue Kunden ohne
// Klick einladen, Bestand alphabetisch im Tagesbudget (~100/Tag) abarbeiten,
// nicht Registrierte alle 7 Tage erinnern. Laeuft nur, wenn der Betreiber den
// Batch per SystemSetting freigeschaltet hat (Schutz vor Massenversand).
Schedule::command('portal:send-invitations')->hourly()->between('8:00', '19:00')->withoutOverlapping();

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

// 08:30 — Spartenspezifische Wechsel-Erinnerungen (Verbesserungsplan Paket C,
// ersetzt die pauschale 30/14/7-Logik). Regeln, Empfängerfilter und
// Doppelversand-Schutz liegen zentral im Service - derselbe Code wie hinter
// dem Button im E-Mail-Marketing.
Schedule::call(function () {
    $sent = app(\App\Services\ContractSwitchReminderService::class)->run();
    if ($sent > 0) \Log::info("Wechsel-Erinnerungen: {$sent} Mails versendet.");
})->dailyAt('08:30');

// 08:40 — E-Scooter-Erneuerung: aktive E-Scooter-Vertraege Anfang Februar an
// das ablaufende Kennzeichen erinnern (neues gilt ab 01.03.). Idempotent, das
// Fenster liegt naturgemaess nur im Februar. Derselbe Service wie hinter dem
// Button im Backend.
Schedule::command('escooter:renewal-reminders')->dailyAt('08:40');

// 08:45 — Schutzbrief/Mobilclub (z.B. ADAC-Mitgliedschaft): Erinnerung an die
// automatische Jahres-Verlaengerung. Fenster ab 5 Monaten vor dem Stichtag
// (= 7 Monate nach Beginn) bis zum letzten Kuendigungstag (3 Monate vorher) -
// danach ist eine Kuendigung nicht mehr moeglich. Einmal pro Jahr je Vertrag.
Schedule::command('schutzbrief:renewal-reminders')->dailyAt('08:45');

// Alle 5 Minuten — geplante E-Mail-Kampagnen anstoßen (Paket B1)
Schedule::call(fn() => \App\Jobs\SendCampaignJob::dispatchDueScheduled())->everyFiveMinutes();

// 09:00 — Portal-Erinnerung nach 3 Tagen ohne Login
Schedule::call(function () {
    // Nur Kunden erinnern, die sich auch WIRKLICH einloggen können:
    // ohne nutzbares Passwort (portal_password_set_at) wäre die
    // "Bitte einloggen"-Mail eine Sackgasse (Kundenproblem-Fix).
    $users = \App\Models\User::where('role', 'customer')
        ->whereNull('last_login_at')->whereNull('portal_reminder_sent_at')
        ->whereNotNull('portal_password_set_at')
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

// Alle 15 Minuten: faellige Social-Media-Posts (Banner-Social-Publishing)
// ueber die Meta Graph API veroeffentlichen (genau ein Auto-Versuch je Kanal).
Schedule::command('social:publish-scheduled')->everyFifteenMinutes()->withoutOverlapping();

// Alle 6 Stunden: Kennzahlen (Likes/Reichweite) der Social-Posts und den
// Seiten-Ueberblick von Meta holen - Anzeige ohne API-Aufruf beim Seitenaufbau.
Schedule::command('social:refresh-insights')->everySixHours()->withoutOverlapping();
