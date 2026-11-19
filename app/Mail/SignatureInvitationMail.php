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
 * Die Einladung zur Unterschrift - und zugleich die Erinnerung (dieselbe
 * Nachricht mit anderer Ueberschrift; zwei Vorlagen mit gleichem Inhalt
 * laufen erfahrungsgemaess auseinander).
 *
 * BEWUSST NICHT QUEUED - wie die Mitarbeiter-Einladung: sie ist der EINZIGE
 * Weg zum Dokument. Bleibt sie in einer Warteschlange liegen, weil kein
 * Worker laeuft, sieht der Mitarbeiter "gesendet" und der Kunde bekommt
 * nichts. Der Fehlschlag muss SOFORT sichtbar sein.
 *
 * Der Zugang steht NUR hier - im Klartext, ein einziges Mal. Er wird
 * nirgends gespeichert (in der Datenbank liegt nur sein Hash).
 */
class SignatureInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SignatureRequest $signatureRequest,
        public SignatureSigner $signer,
        public string $token,
        public bool $isReminder = false,
    ) {
        // DIE SPRACHE DES UNTERZEICHNERS - nicht die des Mitarbeiters, der
        // versendet, und nicht die Portal-Sprache des Kunden. Laravel wendet
        // sie auf Betreff UND Inhalt an; ohne sie bekaeme ein arabischer
        // Unterzeichner eine deutsche Einladung zu einer arabischen Seite.
        $this->locale($signer->localeCode());
    }

    public function envelope(): Envelope
    {
        $betreff = (string) ($this->isReminder
            ? __('signing.mail_subject_reminder')
            : __('signing.mail_subject_invitation'));
        // GLEICHE ADRESSE, MEHRERE PERSONEN (Betreiber-Meldung 19.11.2026):
        // zwei sonst gleichlautende Mails im selben Postfach unterscheiden
        // sich erst am Namen im Betreff - schon in der Postfach-Liste.
        if ($this->teiltAdresse()) {
            $betreff = (string) __('signing.mail_subject_for', ['subject' => $betreff, 'name' => $this->signer->name]);
        }

        return new Envelope(subject: $betreff);
    }

    /** Gehen an diese Adresse weitere Einladungen zum selben Dokument? */
    private function teiltAdresse(): bool
    {
        return $this->signer->teiltAdresseMit($this->signatureRequest->signers()->get())->isNotEmpty();
    }

    public function content(): Content
    {
        return new Content(view: 'emails.signature_invitation', with: [
            'signUrl' => route('signature.show', $this->token),
            // Der Ansprechpartner (Betreiber-Meldung 19.11.2026): ein Name
            // macht die Mail einordenbar. Per Abfrage statt Relation - die
            // Anfrage kommt aus Schleifen, in denen nichts vorgeladen ist.
            'mehrerePersonen' => $this->signatureRequest->signers()->count() > 1,
            'teiltAdresse' => $this->teiltAdresse(),
            'ansprechpartner' => $this->signatureRequest->created_by === null
                ? null
                : $this->signatureRequest->creator()->value('name'),
        ]);
    }
}
