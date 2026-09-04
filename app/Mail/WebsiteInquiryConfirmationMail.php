<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Eingangsbestaetigung an den Absender einer Website-Anfrage (P0-1):
 * in der Sprache, in der er das Formular ausgefuellt hat (de/ar).
 * Tabellenbasiert, Inline-Styles, kein SVG, kein Logo-Bild
 * (Outlook-Regeln, siehe CLAUDE.md E-Mails).
 */
class WebsiteInquiryConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public string $lang = 'de',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->lang === 'ar'
                ? 'وصلنا طلبكم – Dienstly24'
                : 'Ihre Anfrage ist bei uns angekommen – Dienstly24',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.website_inquiry_confirmation');
    }
}
