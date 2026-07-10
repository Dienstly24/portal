<?php
namespace App\Services\Mailbox;

use App\Models\Document;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\Workflow\EmailWorkflowService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orchestriert den kompletten Zyklus je Postfach (Architekturplan
 * Abschnitt 3/4): abrufen -> roh speichern -> Kategorisierung/Matching
 * (EmailWorkflowService) -> Anhänge erst verknüpfen, sobald ein Kunde
 * feststeht (documents.customer_id ist Pflichtfeld - kein Anhang wird
 * einem falschen/unbekannten Kunden zugeordnet).
 */
class MailboxSyncService
{
    public function __construct(
        private readonly MailboxProviderFactory $providerFactory,
        private readonly EmailWorkflowService $workflow,
    ) {
    }

    /** @return array<string, int> Anzahl neu gespeicherter Mails je Postfach */
    public function syncAllActive(): array
    {
        $results = [];
        foreach (EmailAccount::where('is_active', true)->get() as $account) {
            $results[$account->email_address] = $this->syncAccount($account);
        }
        return $results;
    }

    public function syncAccount(EmailAccount $account): int
    {
        try {
            $messages = $this->providerFactory->make($account)->fetchNewMessages($account);
        } catch (\Throwable $e) {
            $account->update(['last_error' => $e->getMessage()]);
            return 0;
        }

        $stored = 0;
        foreach ($messages as $data) {
            $message = EmailMessage::firstOrCreate(
                ['email_account_id' => $account->id, 'message_uid' => $data->uid],
                [
                    'from_address' => $data->fromAddress,
                    'from_name' => $data->fromName,
                    'to_address' => $data->toAddress,
                    'subject' => $data->subject,
                    'body_text' => $data->bodyText,
                    'body_html' => $data->bodyHtml,
                    'received_at' => $data->receivedAt,
                    'raw_headers' => $data->headers,
                ]
            );

            if (!$message->wasRecentlyCreated) {
                continue; // bereits bekannt (Unique-Constraint email_account_id+message_uid) - kein Duplikat verarbeiten
            }

            $stored++;
            $this->workflow->process($message);
            $this->storeAttachmentsIfMatched($message->fresh(), $data->attachments);
        }

        $account->update(['last_synced_at' => now(), 'last_error' => null]);

        return $stored;
    }

    private function storeAttachmentsIfMatched(EmailMessage $message, array $attachments): void
    {
        if (!$message->customer_id || empty($attachments)) {
            return;
        }

        foreach ($attachments as $attachment) {
            $path = 'email_attachments/' . $message->id . '/' . Str::random(8) . '_' . $this->sanitizeFilename($attachment['filename']);
            Storage::disk('local')->put($path, $attachment['content']);

            Document::create([
                'customer_id' => $message->customer_id,
                'category' => 'other',
                'file_name' => $attachment['filename'],
                'file_path' => $path,
                'disk' => 'local',
                'visibility' => 'internal',
                'file_size' => strlen($attachment['content']),
            ]);
        }
    }

    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'anhang';
    }
}
