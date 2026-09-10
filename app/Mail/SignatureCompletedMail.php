<?php

namespace App\Mail;

use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Die Kopie fuer den Unterzeichner - mit dem fertigen PDF im Anhang.
 *
 * ANHANG STATT LINK, und das ist Absicht: nach dem Abschluss wird der
 * Zugang widerrufen. Ein Link, der ein unterschriebenes Dokument dauerhaft
 * offen im Netz haelt, waere genau die Art von Dauer-URL, die dieses Modul
 * vermeiden soll. Das PDF geht an die Adresse, die zuvor bestaetigt wurde.
 *
 * Der Anhang liegt als Rohdaten im Objekt (nicht als Pfad): der Versand
 * darf nicht davon abhaengen, dass die Datei noch am selben Ort liegt.
 */
class SignatureCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SignatureRequest $signatureRequest,
        public SignatureSigner $signer,
        protected string $pdf,
    ) {
        $this->locale($signer->localeCode());
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('signing.mail_subject_completed', [
            'title' => $this->signatureRequest->title,
        ]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.signature_completed');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, 'Unterschrieben-'.$this->safeName().'.pdf')
                ->withMime('application/pdf'),
        ];
    }

    private function safeName(): string
    {
        $name = preg_replace('/[^A-Za-z0-9\-_ ]/', '', $this->signatureRequest->title) ?? '';

        return trim(mb_substr($name, 0, 60)) ?: 'Dokument';
    }
}
