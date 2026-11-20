<?php

namespace App\Mail;

use App\Services\Erinnerung\VertragsErinnerung;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Vertrags-Erinnerung im Tippgeber-Rahmen (Betreiber-Vorgabe 20.11.2026):
 * Vorlage A (Frist offen) bzw. B (Frist abgelaufen), HTML UND Klartext aus
 * denselben Werten (VertragsErinnerung). Kein Portal-Link.
 */
class VertragsErinnerungMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public VertragsErinnerung $erinnerung) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->erinnerung->betreff);
    }

    public function headers(): Headers
    {
        // Abmelden mit einem Klick (RFC 8058) - dieselbe Adresse wie im Fuss.
        $url = $this->erinnerung->abmeldeUrl;

        return new Headers(text: $url ? [
            'List-Unsubscribe' => '<'.$url.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : []);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.erinnerung.vertrag',
            text: 'emails.erinnerung.vertrag_text',
            with: ['e' => $this->erinnerung],
        );
    }
}
