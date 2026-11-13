<?php

namespace App\Mail;

use App\Models\User;
use App\Support\VertrauterHost;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Deutsche Passwort-Reset-Mail (ersetzt die englische Framework-
 * Notification). Bewusst NICHT queued: Der Kunde wartet aktiv auf
 * diese Mail, und der Controller fängt Versandfehler ab. Ein
 * ShouldQueue würde den Versand vom Queue-Worker abhängig machen –
 * steht der Worker, kommt die Mail nie an und der Fehler bleibt
 * unsichtbar (failed_jobs statt Fehlermeldung beim Kunden).
 */
class PasswordResetMail extends Mailable
{
    use SerializesModels;

    public string $resetUrl;

    /** Gueltigkeit in Minuten - steht so auch in der Mail (Transparenz). */
    public int $validMinutes;

    public function __construct(public User $user, string $token)
    {
        // Nie dem Host-Header der Anfrage folgen (Audit 12.11.2026, siehe VertrauterHost).
        $this->resetUrl = VertrauterHost::route('password.reset', ['token' => $token, 'email' => $user->email], $user);
        $this->validMinutes = (int) config('auth.passwords.users.expire', 60);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Passwort zurücksetzen – Dienstly24');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password_reset');
    }
}
