<?php
namespace App\Services\Mailbox;

use Illuminate\Support\Carbon;

/**
 * Provider-unabhängiges Datenobjekt für eine abgerufene E-Mail.
 * Jeder MailboxProviderInterface-Implementierung liefert dasselbe
 * Format, damit MailboxSyncService nicht wissen muss, ob die Mail per
 * IMAP oder (später) per Gmail/Graph-API kam.
 */
final class MailboxMessageData
{
    /**
     * @param array<int, array{filename: string, mime: string, content: string}> $attachments
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public readonly string $uid,
        public readonly string $fromAddress,
        public readonly ?string $fromName,
        public readonly ?string $toAddress,
        public readonly ?string $subject,
        public readonly ?string $bodyText,
        public readonly ?string $bodyHtml,
        public readonly ?Carbon $receivedAt,
        public readonly array $attachments = [],
        public readonly array $headers = [],
    ) {
    }
}
