<?php

namespace App\Mail;

use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Die Einladung zur Unterschrift - und zugleich die Erinnerung (dieselbe
 * Nachricht mit anderer Ueberschrift; zwei Vorlagen mit gleichem Inhalt
 * laufen erfahrungsgemaess auseinander).
 *
 * BEWUSST NICHT QUEUED - wie die Mitarbeiter-Einladung: sie ist der EINZIGE
 * Weg zum Dokument. Bleibt sie in einer Warteschlange liegen, weil kein
 * Worker laeuft, sieht der Mitarbeiter "gesendet" und der Kunde bekommt
 * nichts. Der Fehlschlag muss SOFORT sichtbar sein.
 *
 * Der Zugang steht NUR hier - im Klartext, ein einziges Mal. Er wird
 * nirgends gespeichert (in der Datenbank liegt nur sein Hash).
 */
class SignatureInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SignatureRequest $signatureRequest,
        public SignatureSigner $signer,
        public string $token,
        public bool $isReminder = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isReminder
                ? 'Erinnerung: Dokument zur Unterschrift – Dienstly24'
                : 'Dokument zur Unterschrift – Dienstly24',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.signature_invitation', with: [
            'signUrl' => route('signature.show', $this->token),
        ]);
    }
}
