<?php
namespace App\Services\Mailbox;

use App\Models\EmailAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Gmail-Anbindung über die REST-API (Phase 2, Architekturplan 3.1):
 * OAuth-Scope gmail.readonly (Minimalprinzip 3.3), Abruf über
 * messages.list/get, Anhänge über attachments.get. Kein IMAP nötig.
 */
class GmailApiMailboxProvider implements MailboxProviderInterface
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(private readonly OAuthTokenService $tokens)
    {
    }

    public function testConnection(EmailAccount $account): bool
    {
        $token = $this->tokens->accessToken($account);
        $response = Http::withToken($token)->get(self::BASE . '/profile');
        if (!$response->successful()) {
            throw new \RuntimeException('Gmail-Verbindung fehlgeschlagen: ' . mb_substr($response->body(), 0, 150));
        }
        return true;
    }

    public function fetchNewMessages(EmailAccount $account, int $limit = 50): array
    {
        $token = $this->tokens->accessToken($account);
        $since = $account->last_synced_at
            ? $account->last_synced_at->copy()->subHour()
            : now()->subDays(7);

        $list = Http::withToken($token)->get(self::BASE . '/messages', [
            'q' => 'in:inbox after:' . $since->timestamp,
            'maxResults' => $limit,
        ]);
        if (!$list->successful()) {
            throw new \RuntimeException('Gmail-Abruf fehlgeschlagen: ' . mb_substr($list->body(), 0, 150));
        }

        $results = [];
        foreach ($list->json('messages', []) as $ref) {
            $detail = Http::withToken($token)->get(self::BASE . '/messages/' . $ref['id'], ['format' => 'full']);
            if (!$detail->successful()) {
                continue; // einzelne defekte Mail überspringen statt Sync abbrechen
            }
            $results[] = $this->toMessageData($detail->json(), $token);
        }

        return $results;
    }

    private function toMessageData(array $payload, string $token): MailboxMessageData
    {
        $headers = collect($payload['payload']['headers'] ?? [])
            ->mapWithKeys(fn ($h) => [mb_strtolower($h['name']) => $h['value']]);

        [$fromName, $fromAddress] = $this->parseAddress($headers->get('from', ''));

        $bodyText = null;
        $bodyHtml = null;
        $attachments = [];
        $this->walkParts($payload['payload'] ?? [], $payload['id'], $token, $bodyText, $bodyHtml, $attachments);

        return new MailboxMessageData(
            uid: 'GMAIL:' . $payload['id'],
            fromAddress: $fromAddress,
            fromName: $fromName,
            toAddress: $this->parseAddress($headers->get('to', ''))[1],
            subject: $headers->get('subject'),
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
            receivedAt: isset($payload['internalDate']) ? Carbon::createFromTimestampMs((int) $payload['internalDate']) : null,
            attachments: $attachments,
            headers: $headers->only(['from', 'to', 'subject', 'date', 'message-id'])->all(),
        );
    }

    /** Gmail-MIME-Baum rekursiv: Text-/HTML-Body und Anhänge einsammeln. */
    private function walkParts(array $part, string $messageId, string $token, ?string &$text, ?string &$html, array &$attachments): void
    {
        $mime = $part['mimeType'] ?? '';
        $filename = $part['filename'] ?? '';
        $body = $part['body'] ?? [];

        if ($filename !== '' && !empty($body['attachmentId'])) {
            $data = Http::withToken($token)
                ->get(self::BASE . "/messages/$messageId/attachments/" . $body['attachmentId']);
            if ($data->successful() && $data->json('data')) {
                $attachments[] = [
                    'filename' => $filename,
                    'mime' => $mime ?: 'application/octet-stream',
                    'content' => $this->decode($data->json('data')),
                ];
            }
        } elseif ($mime === 'text/plain' && $text === null && !empty($body['data'])) {
            $text = $this->decode($body['data']);
        } elseif ($mime === 'text/html' && $html === null && !empty($body['data'])) {
            $html = $this->decode($body['data']);
        }

        foreach ($part['parts'] ?? [] as $child) {
            $this->walkParts($child, $messageId, $token, $text, $html, $attachments);
        }
    }

    private function decode(string $base64url): string
    {
        return (string) base64_decode(strtr($base64url, '-_', '+/'));
    }

    /** "Name <mail@x>" -> [Name, mail@x] */
    private function parseAddress(string $raw): array
    {
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>\s*$/', $raw, $m)) {
            return [trim($m[1]) ?: null, trim($m[2])];
        }
        return [null, trim($raw)];
    }
}
