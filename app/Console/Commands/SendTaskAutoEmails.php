<?php

namespace App\Console\Commands;

use App\Mail\DirectEmailMail;
use App\Models\CustomerTimeline;
use App\Models\MessageTemplate;
use App\Models\Task;
use App\Services\Notifications\NotificationService;
use App\Support\Facades\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Versendet faellige automatische Aufgaben-E-Mails (Wiedervorlage):
 * beim Anlegen einer Aufgabe kann eine Kunden-E-Mail zum Stichtag geplant
 * werden ("in 14 Tagen nachfassen"). Am Stichtag geht sie ohne weiteres
 * Zutun raus - {{platzhalter}} werden erst BEIM VERSAND mit aktuellen
 * Kundendaten gefuellt (MessageTemplate::renderText). Erledigte Aufgaben
 * versenden nie; der Versand steht in Kundenakte (Timeline) und Glocke.
 */
class SendTaskAutoEmails extends Command
{
    protected $signature = 'tasks:send-auto-emails';
    protected $description = 'Versendet geplante Aufgaben-E-Mails an Kunden, deren Stichtag erreicht ist';

    public function handle(): int
    {
        $due = Task::with(['customer.user', 'assignedTo', 'createdBy'])
            ->where('auto_email_status', 'pending')
            ->whereDate('auto_email_send_on', '<=', today())
            ->get();

        $sent = 0;
        foreach ($due as $task) {
            if ($this->process($task)) $sent++;
        }

        $this->info("$sent Aufgaben-E-Mail(s) versendet.");
        return self::SUCCESS;
    }

    private function process(Task $task): bool
    {
        // Sicherheitsnetz - der Model-Hook setzt beim Erledigen bereits
        // auf 'skipped', hier faengt es Altbestand/Direkt-Updates ab.
        if ($task->status === 'done') {
            $task->forceFill([
                'auto_email_status' => 'skipped',
                'auto_email_error' => 'Aufgabe erledigt - geplanter Versand uebersprungen.',
            ])->save();
            return false;
        }

        $customer = $task->customer;
        if (! $customer || ! $customer->user?->hasRealEmail()) {
            $task->forceFill([
                'auto_email_status' => 'failed',
                'auto_email_error' => 'Keine echte Kunden-E-Mail-Adresse vorhanden.',
            ])->save();
            return false;
        }

        $sender = $task->assignedTo ?: $task->createdBy;
        $subject = MessageTemplate::renderText((string) $task->auto_email_subject, $customer, $sender);
        $body = MessageTemplate::renderText((string) $task->auto_email_body, $customer, $sender);

        try {
            Mail::to($customer->user->email)->send(new DirectEmailMail(
                mailSubject: $subject,
                mailBody: $body,
                customer: $customer,
                fileAttachments: [],
                senderName: (string) ($sender?->name ?? ''),
            ));
        } catch (\Throwable $e) {
            \Log::warning('Aufgaben-E-Mail fehlgeschlagen (Task '.$task->id.'): '.$e->getMessage());
            // Voruebergehende Fehler (SMTP weg) beim naechsten Lauf erneut
            // versuchen; nach 3 Tagen endgueltig als fehlgeschlagen markieren,
            // damit nicht endlos weiterprobiert wird.
            $finallyFailed = $task->auto_email_send_on
                && $task->auto_email_send_on->lte(today()->subDays(3));
            $task->forceFill([
                'auto_email_status' => $finallyFailed ? 'failed' : 'pending',
                'auto_email_error' => Str::limit($e->getMessage(), 480),
            ])->save();
            return false;
        }

        $task->forceFill([
            'auto_email_status' => 'sent',
            'auto_email_sent_at' => now(),
            'auto_email_error' => null,
        ])->save();

        // Nachvollziehbarkeit wie beim manuellen Versand im Composer.
        CustomerTimeline::create([
            'customer_id' => $customer->id,
            'user_id' => $sender?->id,
            'type' => 'email',
            'title' => 'Automatische E-Mail gesendet: '.$subject,
            'description' => 'Geplant über Aufgabe "'.$task->title.'" · an '.$customer->user->email,
        ]);
        $customer->update(['last_contact' => now()->toDateString()]);

        if ($task->assigned_to) {
            Notify::push((int) $task->assigned_to, [
                'type' => NotificationService::TYPE_MESSAGE,
                'title' => 'Automatische E-Mail an '.($customer->user?->name ?? 'Kunde').' gesendet',
                'body' => '„'.$subject.'" – Aufgabe: '.$task->title,
                'link' => route('admin.tasks').'#task-'.$task->id,
                'dedup_key' => 'task-auto-email-'.$task->id,
            ]);
        }

        return true;
    }
}
