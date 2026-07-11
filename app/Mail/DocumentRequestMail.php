<?php
namespace App\Mail;

use App\Models\DocumentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Benachrichtigt den Kunden über eine (neue oder erneut angeforderte)
 * Dokumentenanfrage (Architekturplan Abschnitt 14). Versand erfolgt
 * ausschließlich durch die Mitarbeiter-Aktion im Admin - der Mensch,
 * der die Anfrage anlegt, IST die Freigabestufe.
 */
class DocumentRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public DocumentRequest $documentRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Dokument benötigt: ' . $this->documentRequest->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.document_request');
    }
}
