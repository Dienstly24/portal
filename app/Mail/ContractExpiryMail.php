<?php
namespace App\Mail;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractExpiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $lang;

    public function __construct(public Contract $contract, public int $days)
    {
        $this->lang = $contract->customer?->preferred_lang ?? 'de';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->lang === 'ar'
            ? 'تنبيه: عقدك ينتهي بعد ' . $this->days . ' يوم'
            : 'Erinnerung: Ihr Vertrag läuft in ' . $this->days . ' Tagen ab');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contract_expiry');
    }
}
