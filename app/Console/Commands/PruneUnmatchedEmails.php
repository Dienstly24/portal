<?php
namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

/**
 * DSGVO-Löschkonzept (Architekturplan Abschnitt 3.3): E-Mails, die
 * keinem Kunden zugeordnet werden konnten, dürfen nicht unbegrenzt
 * gespeichert werden. Standard-Aufbewahrung 90 Tage, änderbar über
 * SystemSetting 'email_retention_days'. Zugeordnete Mails (Teil der
 * Kundenakte) sind NICHT betroffen.
 */
class PruneUnmatchedEmails extends Command
{
    protected $signature = 'emails:prune-unmatched {--dry-run : Nur zählen, nichts löschen}';
    protected $description = 'Löscht nicht zugeordnete E-Mails nach Ablauf der Aufbewahrungsfrist (DSGVO)';

    public function handle(): int
    {
        $days = (int) (SystemSetting::get('email_retention_days') ?: 90);
        if ($days < 1) {
            $this->warn('email_retention_days < 1 – Bereinigung übersprungen.');
            return self::SUCCESS;
        }

        $query = EmailMessage::whereNull('customer_id')
            ->whereNotNull('processed_at')
            ->where('created_at', '<', now()->subDays($days));

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("$count nicht zugeordnete E-Mail(s) älter als $days Tage (dry-run, nichts gelöscht).");
            return self::SUCCESS;
        }

        // Audit-Fix H3/H1-Folge: auch die physischen Anhang-Dateien
        // entfernen, nicht nur die DB-Zeilen.
        $attachmentService = app(\App\Services\Mailbox\EmailAttachmentService::class);
        $deleted = 0;
        foreach ($query->cursor() as $message) {
            $attachmentService->deleteFiles($message);
            $message->delete();
            $deleted++;
        }

        if ($deleted > 0) {
            \App\Models\ActivityLog::create([
                'user_id' => null,
                'action' => 'emails_pruned',
                'entity_type' => 'email_message',
                'entity_id' => null,
                'meta' => json_encode(['deleted' => $deleted, 'retention_days' => $days], JSON_UNESCAPED_UNICODE),
            ]);
        }

        $this->info("$deleted nicht zugeordnete E-Mail(s) gelöscht (Aufbewahrung: $days Tage).");
        return self::SUCCESS;
    }
}
