<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Bestaetigung der E-Mail-Adresse nach der Selbst-Registrierung
 * (Audit SEC-1).
 *
 * Bewusst NICHT queued - gleiche Lehre wie bei EmployeeWelcomeMail,
 * PasswordResetMail und CustomerWelcomeMail: diese Mail ist der EINZIGE
 * Weg, aus der Vormerkung ein Konto zu machen. Haengt sie an einem
 * Queue-Worker und der steht, wartet ein Interessent vergeblich, und der
 * Fehler verschwindet in failed_jobs statt dort aufzutauchen, wo jemand
 * darauf reagieren kann.
 */
class RegistrationVerificationMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $name,
        public string $verifyUrl,
        public int $validHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Dienstly24 – E-Mail-Adresse bestätigen',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.registration_verification',
        );
    }
}
