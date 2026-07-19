<?php
namespace App\Console\Commands;

use App\Mail\DocumentRequestMail;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Fristen-Watchdog für Dokumentenanfragen (Phase 3, Prüfbericht M3 /
 * Plan Abschnitt 14): erinnert Kunden kurz vor Fristablauf und
 * benachrichtigt Mitarbeiter bei Überschreitung. Jede Stufe feuert
 * genau einmal (reminder_sent_at / overdue_notified_at).
 */
class RemindDocumentRequests extends Command
{
    protected $signature = 'document-requests:remind';
    protected $description = 'Erinnert Kunden vor Fristablauf offener Dokumentenanfragen und meldet Überschreitungen intern';

    public function handle(): int
    {
        $reminded = $this->remindUpcoming();
        $escalated = $this->notifyOverdue();

        $this->info("$reminded Erinnerung(en) an Kunden, $escalated Überfälligkeits-Hinweis(e) intern.");
        return self::SUCCESS;
    }

    /** Frist in <= 2 Tagen: Kunde einmalig erinnern. */
    private function remindUpcoming(): int
    {
        $due = DocumentRequest::with('customer.user')
            ->openForCustomer()
            ->whereNull('reminder_sent_at')
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [today(), today()->addDays(2)])
            ->get();

        $count = 0;
        foreach ($due as $request) {
            $email = $request->customer?->user?->email;
            if ($email && !str_contains($email, '@dienstly24.internal')) {
                Mail::to($email)->send(new DocumentRequestMail($request));
                $count++;
            }
            $request->forceFill(['reminder_sent_at' => now()])->save();
        }

        return $count;
    }

    /** Frist überschritten: Betreuer (Fallback admin/manager) einmalig informieren. */
    private function notifyOverdue(): int
    {
        $overdue = DocumentRequest::with('customer.user')
            ->openForCustomer()
            ->whereNull('overdue_notified_at')
            ->whereNotNull('deadline')
            ->whereDate('deadline', '<', today())
            ->get();

        $count = 0;
        foreach ($overdue as $request) {
            $recipients = $request->customer?->betreuer()->get() ?? collect();
            if ($recipients->isEmpty()) {
                $recipients = User::whereIn('role', ['admin', 'manager'])->where('is_active', true)->get();
            }
            \App\Support\Facades\Notify::pushMany($recipients->pluck('id'), [
                'type' => \App\Services\Notifications\NotificationService::TYPE_DOCUMENT,
                'title' => 'Dokumentenanfrage überfällig: ' . $request->title,
                'body' => ($request->customer?->user?->name ?? 'Kunde') . ' hat die Frist ' . $request->deadline->format('d.m.Y') . ' überschritten.',
                'link' => route('admin.document_requests'),
                'dedup_key' => 'doc-overdue-' . $request->id,
            ]);
            $request->forceFill(['overdue_notified_at' => now()])->save();
            $count++;
        }

        return $count;
    }
}
