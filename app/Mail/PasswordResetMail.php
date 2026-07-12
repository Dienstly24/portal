<?php
namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Deutsche Passwort-Reset-Mail (ersetzt die englische Framework-
 * Notification). Bewusst NICHT queued: Der Kunde wartet aktiv auf
 * diese Mail, und der Controller fängt Versandfehler ab.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetUrl;

    public function __construct(public User $user, string $token)
    {
        $this->resetUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);
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
