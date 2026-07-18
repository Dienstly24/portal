<?php
namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Frei verfasste E-Mail aus der Beraterwelt (Composer): an Kunden oder
 * Gesellschaften/Anbieter. Betreff/Text kommen fertig gerendert aus dem
 * Controller (Vorlagen + Platzhalter). Anhaenge werden direkt aus dem
 * Upload uebernommen (kein Zwischenspeichern).
 */
class DirectEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<int, array{data: string, name: string, mime: string}> $fileAttachments */
    public function __construct(
        public string $mailSubject,
        public string $mailBody,
        public ?Customer $customer = null,
        public array $fileAttachments = [],
        public string $senderName = '',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.direct_email');
    }

    public function attachments(): array
    {
        return array_map(
            fn($f) => \Illuminate\Mail\Mailables\Attachment::fromData(fn() => $f['data'], $f['name'])
                ->withMime($f['mime']),
            $this->fileAttachments
        );
    }
}
