<?php

namespace App\Console\Commands;

use App\Services\Mailbox\MailboxSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mailboxes:sync')]
#[Description('Ruft alle aktiven E-Mail-Postfächer ab, speichert neue Mails und stößt die Workflow-Pipeline an (Kategorisierung, Matching, Ticket-/Aufgaben-Erstellung).')]
class SyncMailboxes extends Command
{
    public function handle(MailboxSyncService $service): int
    {
        $results = $service->syncAllActive();

        if (empty($results)) {
            $this->info('Keine aktiven Postfächer konfiguriert.');
            return self::SUCCESS;
        }

        foreach ($results as $email => $count) {
            $this->info(sprintf('%s: %d neue Mail(s) verarbeitet.', $email, $count));
        }

        return self::SUCCESS;
    }
}
