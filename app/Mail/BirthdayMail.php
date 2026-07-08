<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BirthdayMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $birthdayName,
        public bool $isSelf,
        public string $lang = 'de'
    ) {}

    public function envelope(): Envelope
    {
        if ($this->lang === 'ar') {
            return new Envelope(subject: $this->isSelf
                ? 'عيد ميلاد سعيد! 🎉'
                : 'عيد ميلاد سعيد لـ ' . $this->birthdayName . ' 🎉');
        }
        return new Envelope(subject: $this->isSelf
            ? 'Herzlichen Glückwunsch zum Geburtstag! 🎉'
            : 'Alles Gute zum Geburtstag für ' . $this->birthdayName . ' 🎉');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.birthday');
    }
}
