<?php
namespace App\Mail;

use App\Models\CustomerMessage;
use App\Models\SystemSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Begleit-Mail zu einer Portal-Direktnachricht an den Kunden.
 * Modi:
 * - 'hint': nur Hinweis "neue Nachricht im Portal" (datensparsam, Standard)
 * - 'full': kompletter Nachrichtentext in der Mail; Anhaenge bleiben
 *   bewusst NUR im Portal (kein unverschluesselter Dokumentversand).
 */
class CustomerMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $lang;
    public string $customerName;
    public int $attachmentCount;

    /** Alle Kundenlinks zeigen auf die Portal-Domain, nie auf admin.*. */
    public string $messagesUrl;

    public function __construct(public CustomerMessage $customerMessage, public string $mode = 'hint')
    {
        $customer = $customerMessage->customer;
        $this->lang = $customer?->preferred_lang ?? 'de';
        $this->customerName = (string) ($customer?->user?->name ?? '');
        $this->attachmentCount = $customerMessage->attachments()->count();

        $portalBase = rtrim(SystemSetting::get('portal_url', 'https://portal.dienstly24.de'), '/');
        $previousRoot = URL::to('/');
        URL::forceRootUrl($portalBase);
        URL::forceScheme('https');
        try {
            $this->messagesUrl = route('portal.messages');
        } finally {
            // Root UND Schema zuruecksetzen - sonst erzeugt die laufende
            // (Admin-)Anfrage danach falsche URLs (z. B. https bei http-Dev).
            URL::forceRootUrl($previousRoot);
            URL::forceScheme(str_starts_with($previousRoot, 'https://') ? 'https' : 'http');
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->lang === 'ar'
            ? '💬 رسالة جديدة من مستشارك – Dienstly24'
            : '💬 Neue Nachricht von Ihrem Berater – Dienstly24');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.customer_message');
    }
}
