<?php
namespace App\Mail;

use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Jaehrliche Erinnerung zur Verlaengerung eines Schutzbrief-/Mobilclub-
 * Vertrags (z.B. ADAC-Mitgliedschaft), Betreiber-Vorgabe 28.07.2026.
 *
 * Die Mitgliedschaft verlaengert sich automatisch um ein Jahr; gekuendigt
 * werden kann nur bis 3 Monate vor dem Stichtag. Die Mail geht deshalb
 * rechtzeitig (ab 7 Monaten nach Beginn) raus, nennt BEIDE Daten - den
 * Verlaengerungs-Stichtag und den letzten Kuendigungstag - und erklaert
 * ausdruecklich, WAS der Schutzbrief leistet. So versteht der Kunde, worauf er
 * verzichten wuerde, bevor er eine Kuendigung verlangt.
 *
 * Transaktionale Service-Mail zum bestehenden Vertrag (kein Marketing).
 */
class SchutzbriefRenewalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $lang;

    public function __construct(
        public Contract $contract,
        public Carbon $renewalDate,
        public Carbon $lastCancellationDate,
        public ?string $unsubscribeUrl = null,
    ) {
        $this->lang = $contract->customer?->preferred_lang ?? 'de';
    }

    public function envelope(): Envelope
    {
        $subject = $this->lang === 'ar'
            ? 'عقد المساعدة على الطريق (Schutzbrief): يتجدد تلقائياً في ' . $this->renewalDate->format('d.m.Y')
            : 'Ihr Schutzbrief verlaengert sich am ' . $this->renewalDate->format('d.m.Y') . ' um ein Jahr';
        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.schutzbrief_renewal', with: [
            'tierLabel' => $this->contract->subtype
                ? (Contract::SUBTYPES['schutzbrief'][$this->contract->subtype] ?? null)
                : null,
        ]);
    }
}
