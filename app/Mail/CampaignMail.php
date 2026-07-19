<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Marketing campaign mail. This class was imported and instantiated by
 * EmailMarketingController::send() but did not exist, so every campaign
 * send crashed with a fatal "class not found" error. (Audit C3)
 */
class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public string $recipientName,
        public ?string $unsubscribeUrl = null,
        public string $lang = 'de'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    /**
     * RFC-8058 List-Unsubscribe(-Post) fuer Ein-Klick-Abmeldung (Audit INT-5).
     * Gmail/Outlook erwarten das bei Massenmail; verbessert Zustellung/Reputation.
     */
    public function headers(): Headers
    {
        $custom = [];
        if ($this->unsubscribeUrl) {
            $custom['List-Unsubscribe'] = '<' . $this->unsubscribeUrl . '>';
            $custom['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
        return new Headers(text: $custom);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.campaign');
    }
}
