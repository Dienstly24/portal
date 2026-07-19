<?php
namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $lang;

    public function __construct(public Ticket $ticket, public string $replyBody)
    {
        $this->lang = $ticket->customer?->preferred_lang ?? 'de';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->lang === 'ar'
            ? 'رد جديد على طلبك: ' . $this->ticket->subject
            : 'Neue Antwort auf Ihre Anfrage: ' . $this->ticket->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket_reply');
    }
}
