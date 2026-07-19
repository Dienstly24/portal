<?php
namespace App\Mail;

use App\Models\DocumentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Benachrichtigt den Kunden über eine (neue oder erneut angeforderte)
 * Dokumentenanfrage (Architekturplan Abschnitt 14). Versand erfolgt
 * ausschließlich durch die Mitarbeiter-Aktion im Admin - der Mensch,
 * der die Anfrage anlegt, IST die Freigabestufe.
 * Queued (Phase 3, Prüfbericht M2): ein hängender SMTP-Server blockiert
 * nicht mehr den Mitarbeiter-Request. Voraussetzung im Betrieb:
 * `php artisan queue:work` läuft (QUEUE_CONNECTION=database).
 */
class DocumentRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Sprache der Kundenansprache (DE/AR, Audit I18N-3). */
    public string $lang;

    public function __construct(public DocumentRequest $documentRequest)
    {
        $this->lang = $documentRequest->customer->preferred_lang ?? 'de';
    }

    public function envelope(): Envelope
    {
        $subject = $this->lang === 'ar'
            ? 'مستند مطلوب: ' . $this->documentRequest->title
            : 'Dokument benötigt: ' . $this->documentRequest->title;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.document_request');
    }
}
