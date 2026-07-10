<?php
namespace App\Services\Mailbox;

use App\Models\EmailAccount;
use Illuminate\Support\Carbon;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Generischer IMAP-Provider (Hostinger + jeder Standard-IMAP-Server,
 * Architekturplan Abschnitt 3.1). Nutzt webklex/php-imap, das ohne die
 * PHP-imap-Extension auskommt (reines Socket-Protokoll) - hier läuft
 * keine ext-imap zur Verfügung.
 */
class ImapMailboxProvider implements MailboxProviderInterface
{
    public function testConnection(EmailAccount $account): bool
    {
        $client = $this->buildClient($account);
        $client->connect();
        $client->disconnect();

        return true;
    }

    public function fetchNewMessages(EmailAccount $account, int $limit = 50): array
    {
        $client = $this->buildClient($account);
        $client->connect();

        $since = $account->last_synced_at
            ? $account->last_synced_at->copy()->subHour() // Sicherheitsmarge gegen Serverzeit-Abweichung
            : now()->subDays(7);

        $results = [];

        foreach ($account->watchedFolders() as $folderPath) {
            $folder = $client->getFolder($folderPath);
            if (!$folder) {
                continue;
            }

            $messages = $folder->messages()->since($since)->limit($limit)->get();

            foreach ($messages as $message) {
                /** @var Message $message */
                $results[] = $this->toMessageData($message, $folderPath);
            }
        }

        return $results;
    }

    private function buildClient(EmailAccount $account): Client
    {
        $credentials = $account->credentials ?? [];

        $config = [
            'host' => $account->imap_host,
            'port' => $account->imap_port ?: 993,
            'protocol' => 'imap',
            'encryption' => $account->imap_encryption ?: 'ssl',
            'validate_cert' => true,
            'username' => $account->username ?: $account->email_address,
            'password' => $credentials['password'] ?? '',
            'authentication' => null,
        ];

        return (new ClientManager())->make($config);
    }

    private function toMessageData(Message $message, string $folderPath): MailboxMessageData
    {
        $from = $message->getFrom()->first();
        /** @var Address|null $from */

        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = [
                'filename' => $attachment->name ?: 'anhang',
                'mime' => $attachment->getMimeType() ?? 'application/octet-stream',
                'content' => (string) $attachment->content,
            ];
        }

        $date = null;
        try {
            $date = $message->getDate()?->toDate();
        } catch (\Throwable) {
            // Manche Server liefern kein valides Datumsformat - dann received_at leer lassen.
        }

        return new MailboxMessageData(
            uid: $folderPath . ':' . $message->getUid(),
            fromAddress: $from?->mail ?? 'unbekannt@unbekannt.invalid',
            fromName: $from?->personal ?: null,
            toAddress: $message->getTo()->first()?->mail,
            subject: $message->getSubject()?->toString(),
            bodyText: $message->getTextBody() ?: null,
            bodyHtml: $message->getHTMLBody() ?: null,
            receivedAt: $date ? Carbon::instance($date) : null,
            attachments: $attachments,
            headers: $message->getHeader()?->getAttributes() ?? [],
        );
    }
}
