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
 * Der sechsstellige Bestaetigungscode.
 *
 * Er belegt, dass die unterschreibende Person Zugriff auf DAS POSTFACH hat,
 * an das eingeladen wurde - ein weitergeleiteter Link allein belegt das
 * nicht. Bewusst NICHT queued: der Unterzeichner wartet gerade auf ihn.
 */
class SignatureVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SignatureRequest $signatureRequest,
        public SignatureSigner $signer,
        public string $code,
    ) {
        $this->locale($signer->localeCode());
    }

    public function envelope(): Envelope
    {
        // DER CODE STAND FRUEHER IM BETREFF. Ein Betreff erscheint in der
        // Sperrbildschirm-Vorschau, in der Postfachliste und in jedem
        // Weiterleitungs-Kopf: der zweite Faktor waere damit genau dort
        // gelandet, wo ihn jemand ohne Zugriff auf das Postfach lesen kann.
        return new Envelope(subject: (string) __('signing.mail_subject_code'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.signature_verification');
    }
}
