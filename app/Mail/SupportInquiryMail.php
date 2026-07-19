<?php
namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Benachrichtigung an den Support bei neuer Website-Anfrage (Punkt 7).
 * Absendername trägt den Kundennamen; Reply-To ist die Kundenadresse,
 * damit der Support direkt antworten kann. Inhalt: Kundenname,
 * Kundennummer (falls zuordenbar), E-Mail, Betreff, Datum/Uhrzeit.
 */
class SupportInquiryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public ?string $customerNumber = null,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->ticket->guest_name ?: 'Webseite';
        return new Envelope(
            from: new Address(config('mail.from.address'), $name . ' (Webseite)'),
            replyTo: $this->ticket->guest_email ? [new Address($this->ticket->guest_email, $name)] : [],
            subject: 'Neue Anfrage: ' . $this->ticket->subject
                . ' – ' . $name
                . ($this->customerNumber ? ' (' . $this->customerNumber . ')' : ''),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.support_inquiry');
    }
}
