<?php
namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Willkommens-/Einladungsmail für das Kundenportal.
 * Modi:
 * - 'birthdate': Startpasswort = Geburtsdatum (TT.MM.JJJJ) - die Mail
 *   nennt die REGEL, nicht das Datum (kein Klartext-Passwort).
 * - 'setlink':  kein Geburtsdatum hinterlegt - sicherer Link zum
 *   Selbst-Setzen des Passworts.
 * - 'manual':   Berater hat ein Passwort vergeben und teilt es mit.
 *
 * Ziel: Der Kunde kann sich ohne Rückfrage beim Support einloggen.
 */
class CustomerWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $loginEmail;
    public string $customerName;
    public string $lang;

    /** Magischer Erst-Login: signierter Link, 90 Tage gültig (nur Kunden). */
    public ?string $magicLoginUrl = null;

    public function __construct(
        public Customer $customer,
        public string $mode,
        public ?string $plainPassword = null,
        public ?string $setPasswordUrl = null,
    ) {
        $this->loginEmail = (string) $customer->user?->email;
        $this->customerName = (string) ($customer->user?->name ?? '');
        $this->lang = $customer->preferred_lang ?? 'de';

        if ($customer->user) {
            $this->magicLoginUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'magic.login', now()->addDays(90), ['user' => $customer->user->id]
            );
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->lang === 'ar'
            ? '🎉 أهلاً بك في Dienstly24 – بوابتك جاهزة الآن'
            : '🎉 Willkommen bei Dienstly24 – Ihr Kundenportal ist bereit');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.customer_welcome');
    }
}
