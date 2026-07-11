<?php
namespace App\Services\Mailbox;

use App\Models\EmailAccount;

class MailboxProviderFactory
{
    public function make(EmailAccount $account): MailboxProviderInterface
    {
        return match ($account->provider) {
            'gmail_oauth' => new GmailApiMailboxProvider(app(OAuthTokenService::class)),
            'microsoft_oauth' => new GraphApiMailboxProvider(app(OAuthTokenService::class)),
            default => new ImapMailboxProvider(), // imap | hostinger_imap
        };
    }
}
