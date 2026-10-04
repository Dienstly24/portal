<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tageszusammenfassung der Signatur-Qualitaet an die Administratoren
 * (Betreiber-Auftrag 04.10.2026, A5). Geht NUR hinaus, wenn etwas
 * fehlgeschlagen ist - eine taegliche "alles gut"-Mail liest nach einer
 * Woche niemand mehr, und dann auch die eine nicht, auf die es ankommt.
 *
 * Bewusst ohne Kundendaten: Titel des Dokuments und Befund, mehr nicht.
 * Bewusst NICHT queued: faellt der Worker aus, ist genau diese Mail der
 * Hinweis darauf, dass im Hintergrund etwas nicht laeuft.
 */
class SignaturQualitaetMail extends Mailable
{
    /**
     * @param  list<array{titel: string, status: string, befund: string}>  $zeilen
     */
    public function __construct(
        public User $recipient,
        public array $zeilen,
        public int $gesamt,
        public int $neueFehlschlaege,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Signaturen: '.$this->gesamt.' Vorgang/Vorgänge mit Befund – '.config('app.name', 'Dienstly24'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.signatur_qualitaet');
    }
}
