<?php
namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Antwort auf eine Gast-Anfrage (Website/E-Mail/Hilfe-Formular ohne
 * Kundenkonto): Gaeste haben keinen Portalzugang, deshalb enthaelt diese
 * Mail - anders als TicketReplyMail - den Antworttext direkt.
 */
class GuestTicketReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket, public string $replyBody)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Antwort auf Ihre Anfrage'
            . ($this->ticket->ticket_number ? ' [' . $this->ticket->ticket_number . ']' : '')
            . ': ' . $this->ticket->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket_guest_reply');
    }
}
