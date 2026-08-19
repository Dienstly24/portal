<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Einladung fuer ein neues Mitarbeiter-Konto.
 *
 * WICHTIG (Betreiber-Vorgabe 18.08.2026): Diese Mail enthaelt KEIN
 * Passwort mehr. Frueher stand das Klartext-Passwort im Text - damit lag
 * es dauerhaft in zwei fremden Postfaechern, in jedem Mail-Backup und
 * (bei MAIL_MAILER=log) zusaetzlich im Logfile des Servers. Stattdessen
 * geht ein SIGNIERTER Link raus, ueber den der Mitarbeiter sein Passwort
 * selbst setzt; niemand sonst kennt es je.
 *
 * Bewusst NICHT queued (gleiche Lehre wie bei PasswordResetMail und
 * CustomerWelcomeMail): Diese Mail ist der EINZIGE Weg ins Konto. Haengt
 * sie an einem Queue-Worker und der steht, entsteht ein Mitarbeiter-Konto
 * ohne Zugang - und niemand merkt es, weil der Fehler in failed_jobs
 * verschwindet statt beim Anlegenden anzukommen. Direktversand laesst den
 * Fehler dort auftauchen, wo jemand darauf reagieren kann.
 */
class EmployeeWelcomeMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $employeeName,
        public string $employeeEmail,
        public string $setPasswordUrl,
        public array $permissions = [],
        public int $validDays = 14,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Willkommen bei Dienstly24 – Zugang einrichten',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employee_welcome',
        );
    }
}
