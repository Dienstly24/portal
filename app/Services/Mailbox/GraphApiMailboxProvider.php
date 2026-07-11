<?php
namespace App\Services\Mailbox;

use App\Models\EmailAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft-365-Anbindung über Microsoft Graph (Phase 2, Architektur-
 * plan 3.1): OAuth-Scope Mail.Read (Minimalprinzip 3.3), Abruf über
 * /me/mailFolders/inbox/messages, Anhänge über /messages/{id}/attachments.
 */
class GraphApiMailboxProvider implements MailboxProviderInterface
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    public function __construct(private readonly OAuthTokenService $tokens)
    {
    }

    public function testConnection(EmailAccount $account): bool
    {
        $token = $this->tokens->accessToken($account);
        $response = Http::withToken($token)->get(self::BASE . '/me');
        if (!$response->successful()) {
            throw new \RuntimeException('Microsoft-365-Verbindung fehlgeschlagen: ' . mb_substr($response->body(), 0, 150));
        }
        return true;
    }

    public function fetchNewMessages(EmailAccount $account, int $limit = 50): array
    {
        $token = $this->tokens->accessToken($account);
        $since = ($account->last_synced_at
            ? $account->last_synced_at->copy()->subHour()
            : now()->subDays(7))->toIso8601ZuluString();

        $list = Http::withToken($token)->get(self::BASE . '/me/mailFolders/inbox/messages', [
            '$filter' => "receivedDateTime ge $since",
            '$top' => $limit,
            '$orderby' => 'receivedDateTime asc',
            '$select' => 'id,subject,from,toRecipients,receivedDateTime,body,bodyPreview,hasAttachments,internetMessageId',
        ]);
        if (!$list->successful()) {
            throw new \RuntimeException('Microsoft-365-Abruf fehlgeschlagen: ' . mb_substr($list->body(), 0, 150));
        }

        $results = [];
        foreach ($list->json('value', []) as $message) {
            $attachments = [];
            if (!empty($message['hasAttachments'])) {
                $attachmentResponse = Http::withToken($token)
                    ->get(self::BASE . '/me/messages/' . $message['id'] . '/attachments');
                foreach ($attachmentResponse->json('value', []) as $attachment) {
                    if (($attachment['@odata.type'] ?? '') === '#microsoft.graph.fileAttachment' && !empty($attachment['contentBytes'])) {
                        $attachments[] = [
                            'filename' => $attachment['name'] ?? 'anhang',
                            'mime' => $attachment['contentType'] ?? 'application/octet-stream',
                            'content' => (string) base64_decode($attachment['contentBytes']),
                        ];
                    }
                }
            }

            $isHtml = ($message['body']['contentType'] ?? '') === 'html';
            $results[] = new MailboxMessageData(
                uid: 'GRAPH:' . $message['id'],
                fromAddress: $message['from']['emailAddress']['address'] ?? '',
                fromName: $message['from']['emailAddress']['name'] ?? null,
                toAddress: $message['toRecipients'][0]['emailAddress']['address'] ?? null,
                subject: $message['subject'] ?? null,
                bodyText: $isHtml ? ($message['bodyPreview'] ?? null) : ($message['body']['content'] ?? null),
                bodyHtml: $isHtml ? ($message['body']['content'] ?? null) : null,
                receivedAt: isset($message['receivedDateTime']) ? Carbon::parse($message['receivedDateTime']) : null,
                attachments: $attachments,
                headers: ['message-id' => $message['internetMessageId'] ?? null],
            );
        }

        return $results;
    }
}
