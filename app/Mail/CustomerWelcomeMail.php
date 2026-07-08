<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $customerName,
        public string $customerEmail,
        public string $plainPassword,
        public string $lang = 'de'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->lang === 'ar'
            ? 'أهلاً بك في Dienstly24 – بيانات الدخول الخاصة بك'
            : 'Willkommen bei Dienstly24 – Ihre Zugangsdaten');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.customer_welcome');
    }
}
