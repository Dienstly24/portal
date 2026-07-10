<?php
namespace App\Services\Mailbox;

use App\Models\EmailAccount;

/**
 * Abstraktion über die eigentliche Postfach-Anbindung (Architekturplan
 * Abschnitt 3.1). Neue Anbieter (weitere OAuth-Provider, andere
 * IMAP-Varianten) implementieren dieses Interface, ohne dass
 * MailboxSyncService oder der Sync-Command angepasst werden müssen.
 */
interface MailboxProviderInterface
{
    /** Verbindungstest für die Admin-UI ("Testen"-Button vor dem Speichern). */
    public function testConnection(EmailAccount $account): bool;

    /**
     * Holt neue Nachrichten seit dem letzten Sync.
     *
     * @return MailboxMessageData[]
     */
    public function fetchNewMessages(EmailAccount $account, int $limit = 50): array;
}
