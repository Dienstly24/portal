<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerPortalReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $customerName, public string $lang = 'de') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->lang === 'ar'
            ? 'تذكير: بوابة العملاء الخاصة بك في انتظارك'
            : 'Erinnerung: Ihr Kundenportal wartet auf Sie');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.customer_portal_reminder');
    }
}
