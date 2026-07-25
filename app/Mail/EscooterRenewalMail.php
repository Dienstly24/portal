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
 * Jaehrliche E-Scooter-Erneuerungs-Erinnerung (Betreiber-Vorgabe 25.07.2026).
 *
 * Das Versicherungskennzeichen eines E-Scooters gilt immer nur bis Ende
 * Februar. Faehrt der Kunde den Roller weiter, braucht er ab dem 1. Maerz ein
 * NEUES Kennzeichen. Anfang Februar bekommt der Kunde daher eine Erinnerung:
 * bitte bestaetigen, dass der Roller noch vorhanden ist - dann stellen wir ein
 * neues Kennzeichen (gueltig ab 01.03.) aus.
 *
 * Transaktionale Service-Mail (kein Marketing) - der Kunde braucht das neue
 * Kennzeichen, um weiter legal fahren zu duerfen.
 */
class EscooterRenewalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $lang;

    public function __construct(
        public Contract $contract,
        public ?string $unsubscribeUrl = null,
    ) {
        $this->lang = $contract->customer?->preferred_lang ?? 'de';
    }

    public function envelope(): Envelope
    {
        $subject = $this->lang === 'ar'
            ? 'سكوترك الكهربائي: احجز لوحة جديدة قبل 01.03'
            : 'Ihr E-Scooter: neues Kennzeichen vor dem 01.03. sichern';
        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $seasonEnd = $this->contract->end_date
            ? Carbon::parse($this->contract->end_date)
            : null;
        // Neues Kennzeichen gilt ab dem 1. Maerz der neuen Saison (Tag nach dem
        // Saison-Ende Ende Februar).
        $newSeasonStart = $seasonEnd
            ? $seasonEnd->copy()->addDay()->startOfDay()
            : null;

        return new Content(view: 'emails.escooter_renewal', with: [
            'vehicle' => $this->contract->vehicleDetail,
            'seasonEnd' => $seasonEnd,
            'newSeasonStart' => $newSeasonStart,
        ]);
    }
}
