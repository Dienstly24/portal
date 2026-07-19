<?php
namespace App\Mail;

use App\Models\InternalMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Optionale E-Mail bei @Mention im internen Chat (Einstellung
 * 'mention_email_enabled'). Empfänger sind ausschließlich Mitarbeiter.
 * Die E-Mail enthält bewusst nur eine kurze Vorschau - interne Inhalte
 * sollen primär im System gelesen werden.
 */
class InternalMentionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public InternalMessage $internalMessage,
        public User $recipient
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Sie wurden intern erwähnt – ' . config('app.name', 'Dienstly24'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.internal_mention');
    }
}
