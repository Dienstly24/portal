<?php
namespace App\Services\Mailbox;

use App\Models\EmailAccount;

class MailboxProviderFactory
{
    public function make(EmailAccount $account): MailboxProviderInterface
    {
        return match ($account->provider) {
            'gmail_oauth', 'microsoft_oauth' => new OAuthMailboxProvider(),
            default => new ImapMailboxProvider(), // imap | hostinger_imap
        };
    }
}
